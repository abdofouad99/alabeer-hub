<?php
// ============================================================
// tests/RateLimitTest.php — اختبارات نظام تقييد المعدل
// ============================================================

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/init.php';

class RateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        // تنظيف سجلات rate limiting قبل كل اختبار
        $db->exec("DELETE FROM rate_limits WHERE ip = '127.0.0.1'");
    }

    public function testRateLimitAllowsRequests()
    {
        $limiter = getRateLimiter($db, $config, $logger);

        // يجب السماح بالطلبات الأولى
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->check('test_action', '127.0.0.1'));
        }
    }

    public function testRateLimitBlocksExcessiveRequests()
    {
        $limiter = getRateLimiter($db, $config, $logger);

        // تجاوز الحد المسموح
        for ($i = 0; $i < 15; $i++) {
            $result = $limiter->check('test_action', '127.0.0.1');
            if ($i >= 10) { // الحد 10 لكل دقيقة
                $this->assertFalse($result, "Request $i should be blocked");
            }
        }
    }

    public function testRateLimitHeaders()
    {
        $limiter = getRateLimiter($db, $config, $logger);

        $headers = $limiter->getHeaders('test_action', '127.0.0.1');

        $this->assertArrayHasKey('X-RateLimit-Limit', $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
        $this->assertArrayHasKey('X-RateLimit-Reset', $headers);

        $this->assertEquals(10, $headers['X-RateLimit-Limit']);
        $this->assertIsInt($headers['X-RateLimit-Remaining']);
        $this->assertIsInt($headers['X-RateLimit-Reset']);
    }

    public function testRateLimitReset()
    {
        $limiter = getRateLimiter($db, $config, $logger);

        // استهلاك جميع الطلبات
        for ($i = 0; $i < 10; $i++) {
            $limiter->check('test_action', '127.0.0.1');
        }

        // يجب حظر الطلب التالي
        $this->assertFalse($limiter->check('test_action', '127.0.0.1'));

        // محاكاة مرور الوقت (تعديل قاعدة البيانات)
        $db->exec("UPDATE rate_limits SET created_at = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE ip = '127.0.0.1'");

        // يجب السماح مرة أخرى
        $this->assertTrue($limiter->check('test_action', '127.0.0.1'));
    }

    public function testRateLimitDifferentActions()
    {
        $limiter = getRateLimiter($db, $config, $logger);

        // استهلاك حد action واحد
        for ($i = 0; $i < 10; $i++) {
            $limiter->check('action1', '127.0.0.1');
        }

        // action آخر يجب أن يكون مسموحاً
        $this->assertTrue($limiter->check('action2', '127.0.0.1'));
    }
}
?>