<?php
// ============================================================
// tests/CacheTest.php — اختبارات نظام التخزين المؤقت
// ============================================================

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/init.php';

class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        // تنظيف cache قبل كل اختبار
        $cache = getCache($config, $logger);
        $cache->clear();
    }

    public function testCacheSetAndGet()
    {
        $cache = getCache($config, $logger);
        $key = 'test_key_' . time();
        $data = ['test' => 'data', 'number' => 42];

        // اختبار التخزين
        $result = $cache->set($key, $data, 300);
        $this->assertTrue($result);

        // اختبار الاسترجاع
        $retrieved = $cache->get($key);
        $this->assertEquals($data, $retrieved);
    }

    public function testCacheRemember()
    {
        $cache = getCache($config, $logger);
        $key = 'remember_test_' . time();

        $result = $cache->remember($key, function() {
            return ['computed' => true, 'value' => 'test'];
        }, 300);

        $this->assertEquals(['computed' => true, 'value' => 'test'], $result);

        // الاسترجاع من cache
        $cached = $cache->get($key);
        $this->assertEquals($result, $cached);
    }

    public function testCacheExpiration()
    {
        $cache = getCache($config, $logger);
        $key = 'expire_test_' . time();
        $data = ['expires' => true];

        // تخزين لمدة ثانية واحدة
        $cache->set($key, $data, 1);
        $this->assertEquals($data, $cache->get($key));

        // انتظار انتهاء الصلاحية
        sleep(2);
        $this->assertNull($cache->get($key));
    }

    public function testCacheDelete()
    {
        $cache = getCache($config, $logger);
        $key = 'delete_test_' . time();
        $data = ['to_delete' => true];

        $cache->set($key, $data, 300);
        $this->assertEquals($data, $cache->get($key));

        $cache->delete($key);
        $this->assertNull($cache->get($key));
    }
}
?>