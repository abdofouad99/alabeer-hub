<?php
/**
 * ════════════════════════════════════════════════════════════════
 * Diagnostics Logger — نظام تشخيص عام لكل فحص
 * ────────────────────────────────────────────────────────────────
 * يلتقط snapshot لكل بيانات تمر بالنظام بدون تحديد ما هو "مهم".
 * يحفظ كل شيء في عمود assessments.diagnostics_log كـ JSON.
 *
 * الاستخدام:
 *   Diag::init($scanId);                          // مرة واحدة في analyze.php
 *   Diag::snapshot('stage.name', $data);          // أي مكان
 *   Diag::error('stage.name', $errorDetails);     // عند خطأ
 *   Diag::warn('stage.name', $details);           // عند تحذير
 *   Diag::flush($pdo);                            // قبل نهاية الـ request
 *
 * أسماء المراحل (free-form, dot-notation):
 *   "facebook.scrape.input"
 *   "facebook.scrape.raw_response"
 *   "ads.openai.prompt"
 *   "ads.openai.response"
 *   "db.save.scan_result"
 *   "frontend.payload.ads"
 *   ...
 *
 * كل event يُسجَّل تلقائياً مع:
 *   t       = الزمن منذ init() بالثواني
 *   stage   = اسم المرحلة
 *   data    = البيانات (مع sanitization آمن — يخفي tokens)
 *
 * الـ Logger حذِر بحيث لا يُسبّب فشل الفحص لو هو نفسه فشل (try/catch محيط بـ flush).
 */

class Diag {
    private static array  $events   = [];
    private static array  $errors   = [];
    private static array  $warnings = [];
    private static ?float $startTime = null;
    private static ?int   $scanId    = null;
    private static bool   $enabled   = true;

    /** يُستدعى مرة واحدة في بداية الفحص. بدون هذا، كل snapshot يُتجاهل. */
    public static function init(int $scanId): void {
        self::$scanId    = $scanId;
        self::$startTime = microtime(true);
        self::$events    = [];
        self::$errors    = [];
        self::$warnings  = [];
        self::$enabled   = true;
    }

    /** snapshot عام: يقبل أي data ويحفظها كما هي بعد sanitize. */
    public static function snapshot(string $stage, $data = null): void {
        if (!self::$enabled || self::$scanId === null) return;
        try {
            self::$events[] = [
                't'     => round(microtime(true) - (self::$startTime ?? microtime(true)), 3),
                'stage' => $stage,
                'data'  => self::sanitize($data),
            ];
        } catch (\Throwable $e) {
            error_log('[Diag::snapshot] ' . $e->getMessage());
        }
    }

    public static function error(string $stage, $details = null): void {
        if (!self::$enabled || self::$scanId === null) return;
        try {
            $entry = [
                't'       => round(microtime(true) - (self::$startTime ?? microtime(true)), 3),
                'stage'   => $stage,
                'details' => self::sanitize($details),
            ];
            self::$errors[] = $entry;
            self::$events[] = ['t' => $entry['t'], 'stage' => $stage . '.ERROR', 'data' => $entry['details']];
        } catch (\Throwable $e) {
            error_log('[Diag::error] ' . $e->getMessage());
        }
    }

    public static function warn(string $stage, $details = null): void {
        if (!self::$enabled || self::$scanId === null) return;
        try {
            $entry = [
                't'       => round(microtime(true) - (self::$startTime ?? microtime(true)), 3),
                'stage'   => $stage,
                'details' => self::sanitize($details),
            ];
            self::$warnings[] = $entry;
            self::$events[] = ['t' => $entry['t'], 'stage' => $stage . '.WARN', 'data' => $entry['details']];
        } catch (\Throwable $e) {
            error_log('[Diag::warn] ' . $e->getMessage());
        }
    }

    /** يُستدعى في نهاية الفحص لحفظ كل شيء في DB. */
    public static function flush(\PDO $pdo): void {
        if (!self::$enabled || self::$scanId === null) return;
        try {
            $log = [
                'scan_id'        => self::$scanId,
                'started_at'     => date('Y-m-d H:i:s', (int)self::$startTime),
                'finished_at'    => date('Y-m-d H:i:s'),
                'duration_total' => round(microtime(true) - self::$startTime, 2),
                'event_count'    => count(self::$events),
                'error_count'    => count(self::$errors),
                'warning_count'  => count(self::$warnings),
                'events'         => self::$events,
                'errors'         => self::$errors,
                'warnings'       => self::$warnings,
            ];
            $json = json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                error_log('[Diag::flush] json_encode failed');
                return;
            }
            $stmt = $pdo->prepare("UPDATE assessments SET diagnostics_log = ? WHERE id = ?");
            $stmt->execute([$json, self::$scanId]);
        } catch (\Throwable $e) {
            error_log('[Diag::flush] ' . $e->getMessage());
        }
    }

    /** قراءة الـ log لـ scan معيّن (للـ view endpoint). */
    public static function read(\PDO $pdo, int $scanId): ?array {
        try {
            $stmt = $pdo->prepare("SELECT diagnostics_log FROM assessments WHERE id = ?");
            $stmt->execute([$scanId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || empty($row['diagnostics_log'])) return null;
            return json_decode($row['diagnostics_log'], true);
        } catch (\Throwable $e) {
            error_log('[Diag::read] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * sanitize ذكي لكن غير مدمّر:
     * - يقطع نصوص أطول من 200KB لكل قيمة (لمنع OOM، نادر جداً)
     * - يخفي أنماط شائعة لـ tokens/keys/passwords (لكن يُبقي طولها)
     * - يحدّ العمق إلى 15 (لمنع recursion infinite في objects ذاتية المرجعية)
     */
    private static function sanitize($data, int $depth = 0) {
        if ($depth > 15) return '[max recursion depth]';
        if (is_string($data)) {
            if (strlen($data) > 200000) {
                $orig = strlen($data);
                $data = substr($data, 0, 200000) . "\n... [TRUNCATED — original length: {$orig} bytes]";
            }
            // إخفاء tokens محتملة (keep keys visible, redact values)
            return preg_replace(
                '/((?:api[_-]?key|access[_-]?token|bearer\s+|password|secret|authorization)["\']?\s*[:=]\s*["\']?)[A-Za-z0-9_\-\.]+/i',
                '$1[REDACTED]',
                $data
            );
        }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                // إخفاء قيم المفاتيح الحساسة بشكل كامل
                $kLower = is_string($k) ? strtolower($k) : '';
                if (preg_match('/^(api_?key|access_?token|password|secret|authorization|bearer|openai_?key|gemini_?keys?|nvidia_?key|apify_?token|meta_?ads_?token)$/', $kLower)) {
                    if (is_array($v)) {
                        $out[$k] = '[REDACTED — array of ' . count($v) . ' items]';
                    } elseif (is_string($v)) {
                        $out[$k] = '[REDACTED — len=' . strlen($v) . ']';
                    } else {
                        $out[$k] = '[REDACTED]';
                    }
                    continue;
                }
                $out[$k] = self::sanitize($v, $depth + 1);
            }
            return $out;
        }
        if (is_object($data)) {
            return self::sanitize((array)$data, $depth + 1);
        }
        // numbers, bools, null — يُتركون كما هم
        return $data;
    }
}
