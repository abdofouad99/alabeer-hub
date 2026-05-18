<?php
/**
 * Competitor Cache Layer
 * يخزّن نتائج enrichment لمدة قابلة للتحكم لتقليل تكلفة Apify
 */

declare(strict_types=1);

class CompetitorCache {
    private string $cacheDir;
    private int    $ttlSeconds;

    public function __construct(array $cfg) {
        $this->cacheDir   = sys_get_temp_dir() . '/alabeer_competitor_cache';
        $this->ttlSeconds = (int)($cfg['analysis']['competitor_cache_hours'] ?? 6) * 3600;

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * @param string $key URL أو معرّف فريد
     * @return array|null البيانات المخزّنة أو null لو منتهية
     */
    public function get(string $key): ?array {
        $file = $this->getFilePath($key);
        if (!file_exists($file)) return null;

        // فحص TTL
        $mtime = @filemtime($file);
        if ($mtime === false || (time() - $mtime) > $this->ttlSeconds) {
            @unlink($file);
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) return null;

        $data = json_decode($content, true);
        if (!is_array($data)) return null;

        return $data;
    }

    public function set(string $key, array $data): bool {
        $file = $this->getFilePath($key);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return @file_put_contents($file, $json) !== false;
    }

    public function delete(string $key): bool {
        $file = $this->getFilePath($key);
        return @unlink($file);
    }

    public function clear(): int {
        $deleted = 0;
        $files = glob($this->cacheDir . '/*.json');
        if (is_array($files)) {
            foreach ($files as $f) {
                if (@unlink($f)) $deleted++;
            }
        }
        return $deleted;
    }

    private function getFilePath(string $key): string {
        // hash للأمان + قابلية للقراءة
        $safe = preg_replace('/[^a-z0-9]/i', '_', mb_substr($key, 0, 30));
        $hash = substr(md5($key), 0, 12);
        return $this->cacheDir . '/' . $safe . '_' . $hash . '.json';
    }
}
