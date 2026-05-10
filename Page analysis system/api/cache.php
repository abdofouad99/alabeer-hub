<?php
// ============================================================
// api/cache.php — نظام Caching متعدد الطبقات v1.0
// ============================================================

interface CacheInterface {
    public function get(string $key);
    public function set(string $key, $value, int $ttl = null): bool;
    public function delete(string $key): bool;
    public function clear(): bool;
    public function has(string $key): bool;
}

class FileCache implements CacheInterface {
    private $cacheDir;
    private $defaultTtl;

    public function __construct(string $cacheDir, int $defaultTtl = 3600) {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->defaultTtl = $defaultTtl;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function get(string $key) {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = unserialize(file_get_contents($file));
        if (!$data || !isset($data['expires']) || time() > $data['expires']) {
            unlink($file);
            return null;
        }

        return $data['value'];
    }

    public function set(string $key, $value, int $ttl = null): bool {
        $file = $this->getFilePath($key);
        $ttl = $ttl ?? $this->defaultTtl;

        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
        ];

        return file_put_contents($file, serialize($data), LOCK_EX) !== false;
    }

    public function delete(string $key): bool {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return true;
    }

    public function clear(): bool {
        $files = glob($this->cacheDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        return true;
    }

    public function has(string $key): bool {
        return $this->get($key) !== null;
    }

    private function getFilePath(string $key): string {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }
}

class RedisCache implements CacheInterface {
    private $redis;
    private $defaultTtl;

    public function __construct(string $url, string $token, int $defaultTtl = 3600) {
        $this->defaultTtl = $defaultTtl;

        try {
            $this->redis = new Redis();
            $parsedUrl = parse_url($url);
            $this->redis->connect($parsedUrl['host'], $parsedUrl['port'] ?? 6379);
            $this->redis->auth($token);
        } catch (Exception $e) {
            throw new Exception('Redis connection failed: ' . $e->getMessage());
        }
    }

    public function get(string $key) {
        $value = $this->redis->get($key);
        return $value === false ? null : unserialize($value);
    }

    public function set(string $key, $value, int $ttl = null): bool {
        $ttl = $ttl ?? $this->defaultTtl;
        return $this->redis->setex($key, $ttl, serialize($value));
    }

    public function delete(string $key): bool {
        return $this->redis->del($key) > 0;
    }

    public function clear(): bool {
        return $this->redis->flushdb();
    }

    public function has(string $key): bool {
        return $this->redis->exists($key);
    }
}

class Cache {
    private $driver;

    public function __construct(array $config) {
        $cacheConfig = $config['cache'];

        if (!$cacheConfig['enabled']) {
            $this->driver = new class implements CacheInterface {
                public function get(string $key) { return null; }
                public function set(string $key, $value, int $ttl = null): bool { return true; }
                public function delete(string $key): bool { return true; }
                public function clear(): bool { return true; }
                public function has(string $key): bool { return false; }
            };
            return;
        }

        switch ($cacheConfig['driver']) {
            case 'redis':
                try {
                    $this->driver = new RedisCache(
                        $cacheConfig['redis']['url'],
                        $cacheConfig['redis']['token'],
                        $cacheConfig['ttl']
                    );
                } catch (Exception $e) {
                    // Fallback to file cache
                    $this->driver = new FileCache($cacheConfig['file']['path'], $cacheConfig['ttl']);
                }
                break;
            case 'apcu':
                if (function_exists('apcu_enabled') && apcu_enabled()) {
                    $this->driver = new class($cacheConfig['ttl']) implements CacheInterface {
                        private $defaultTtl;
                        public function __construct(int $ttl) { $this->defaultTtl = $ttl; }
                        public function get(string $key) { $val = apcu_fetch($key, $success); return $success ? $val : null; }
                        public function set(string $key, $value, int $ttl = null): bool { return apcu_store($key, $value, $ttl ?? $this->defaultTtl); }
                        public function delete(string $key): bool { return apcu_delete($key); }
                        public function clear(): bool { return apcu_clear_cache(); }
                        public function has(string $key): bool { return apcu_exists($key); }
                    };
                } else {
                    $this->driver = new FileCache($cacheConfig['file']['path'], $cacheConfig['ttl']);
                }
                break;
            case 'file':
            default:
                $this->driver = new FileCache($cacheConfig['file']['path'], $cacheConfig['ttl']);
                break;
        }
    }

    public function get(string $key) {
        return $this->driver->get($key);
    }

    public function set(string $key, $value, int $ttl = null): bool {
        return $this->driver->set($key, $value, $ttl);
    }

    public function delete(string $key): bool {
        return $this->driver->delete($key);
    }

    public function clear(): bool {
        return $this->driver->clear();
    }

    public function has(string $key): bool {
        return $this->driver->has($key);
    }

    public function remember(string $key, callable $callback, int $ttl = null) {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }
}

// Helper function
function getCache(array $config): Cache {
    static $cache = null;
    if ($cache === null) {
        $cache = new Cache($config);
    }
    return $cache;
}
?>