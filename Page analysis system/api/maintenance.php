<?php
/**
 * maintenance.php — أداة الصيانة الشاملة
 * الوصول: http://localhost/alabeer-hub/Page%20analysis%20system/api/maintenance.php?action=XXX
 * 
 * الإجراءات المتاحة:
 *   ?action=clear_cache   → مسح ملفات الـ Cache
 *   ?action=reset_ai      → إعادة تعيين نتائج الـ AI
 *   ?action=debug         → عرض بيانات آخر تقييم
 *   ?action=all           → كل ما سبق معاً
 */

// منع الوصول من خارج الـ localhost فقط
$allowedIPs = ['127.0.0.1', '::1', 'localhost'];
$clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!in_array($clientIP, $allowedIPs)) {
    http_response_code(403);
    die(json_encode(['error' => 'Access denied. Localhost only.']));
}

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'debug';
$result = [];

// ── 1. مسح الـ Cache ────────────────────────────────────────
if (in_array($action, ['clear_cache', 'all', 'reset'])) {
    $cacheDir = __DIR__ . '/../cache';
    $deleted = 0;
    if (is_dir($cacheDir)) {
        foreach (glob($cacheDir . '/*.cache') as $file) {
            if (unlink($file)) $deleted++;
        }
    }
    $result['cache_cleared'] = $deleted . ' files deleted';
}

// ── 2. إعادة تعيين نتائج الذكاء الاصطناعي ───────────────────────────────
if (in_array($action, ['reset_ai', 'all', 'reset'])) {
    try {
        $db = getDB();
        // حذف ai_report وإعادة status لـ 'analyzed' ليُجبر analyze.php على إعادة التحليل
        $stmt = $db->prepare(
            'UPDATE assessments SET ai_report = NULL, status = "pending" WHERE id IN (SELECT id FROM (SELECT id FROM assessments ORDER BY id DESC LIMIT 10) t)'
        );
        $stmt->execute();
        $result['ai_reset'] = $stmt->rowCount() . ' assessments ai_report cleared — سيُعاد التحليل عند فتح التقرير';
    } catch (Exception $e) {
        $result['ai_reset_error'] = $e->getMessage();
    }
}

// ── 3. بيانات التشخيص ────────────────────────────────────────────────
if (in_array($action, ['debug', 'all'])) {
    try {
        $db = getDB();

        // آخر تقييم
        $stmt = $db->query('SELECT id, score, full_name, company_name, project_type, platform, country, created_at, status, LENGTH(ai_report) as ai_report_size, LENGTH(scan_result) as scan_result_size FROM assessments ORDER BY id DESC LIMIT 1');
        $assessment = $stmt->fetch();

        if ($assessment) {
            $result['last_assessment'] = $assessment;

            // إجابات الاستبيان
            $stmt = $db->prepare('SELECT question_key, answer FROM answers WHERE assessment_id=?');
            $stmt->execute([$assessment['id']]);
            $answers = [];
            foreach ($stmt->fetchAll() as $row) {
                $dec = json_decode($row['answer'], true);
                $answers[$row['question_key']] = $dec !== null ? $dec : $row['answer'];
            }
            $result['answers_count'] = count($answers);
            $result['answers_keys'] = array_keys($answers);

            // scan_result
            $stmt = $db->prepare('SELECT scan_result, ai_result, breakdown FROM assessments WHERE id=?');
            $stmt->execute([$assessment['id']]);
            $row = $stmt->fetch();

            $scan = $row['scan_result'] ? json_decode($row['scan_result'], true) : null;
            $ai   = $row['ai_result']   ? json_decode($row['ai_result'], true)   : null;
            $bd   = $row['breakdown']   ? json_decode($row['breakdown'], true)   : null;

            if ($scan) {
                $result['scan_data'] = [
                    'keys'         => array_keys($scan),
                    'hasSSL'       => $scan['hasSSL'] ?? $scan['has_ssl'] ?? 'NOT_FOUND',
                    'hasPixel'     => $scan['hasPixel'] ?? $scan['has_fb_pixel'] ?? 'NOT_FOUND',
                    'hasWhatsApp'  => $scan['hasWhatsApp'] ?? $scan['has_whatsapp'] ?? 'NOT_FOUND',
                    'hasGA'        => $scan['hasGA'] ?? $scan['has_ga'] ?? 'NOT_FOUND',
                    'ig_is_business' => $scan['instagram']['is_business'] ?? 'NOT_FOUND',
                    'ig_followers'   => $scan['instagram']['followers'] ?? 'NOT_FOUND',
                    'fb_has_ads'     => $scan['facebook']['has_ads'] ?? 'NOT_FOUND',
                ];
            } else {
                $result['scan_data'] = 'EMPTY OR NULL';
            }

            if ($ai) {
                $result['ai_data'] = [
                    'source'     => $ai['source'] ?? 'UNKNOWN',
                    'strengths'  => $ai['strengths'] ?? 'NOT_FOUND',
                    'weaknesses' => $ai['weaknesses'] ?? 'NOT_FOUND',
                    'has_content_analysis' => isset($ai['content_analysis']) ? 'YES (' . count($ai['content_analysis']['q'] ?? []) . ' questions)' : 'NO',
                ];
            } else {
                $result['ai_data'] = 'EMPTY OR NULL — AI has not run yet';
            }

            $result['breakdown'] = $bd ?? 'EMPTY';
        } else {
            $result['last_assessment'] = 'No assessments found';
        }
    } catch (Exception $e) {
        $result['debug_error'] = $e->getMessage();
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
