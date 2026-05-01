<?php
// ============================================================
// tests/LoggerTest.php — اختبارات نظام التسجيل
// ============================================================

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/init.php';

class LoggerTest extends TestCase
{
    protected $logFile;

    protected function setUp(): void
    {
        $this->logFile = __DIR__ . '/../logs/test.log';
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testLoggerLogsMessages()
    {
        $logger = new Logger([
            'file_path' => $this->logFile,
            'level' => 'DEBUG'
        ]);

        $logger->info('Test info message', ['key' => 'value']);
        $logger->warning('Test warning message');
        $logger->error('Test error message');

        $this->assertFileExists($this->logFile);

        $content = file_get_contents($this->logFile);
        $this->assertStringContains('Test info message', $content);
        $this->assertStringContains('Test warning message', $content);
        $this->assertStringContains('Test error message', $content);
        $this->assertStringContains('key', $content);
        $this->assertStringContains('value', $content);
    }

    public function testLoggerFiltersByLevel()
    {
        $logger = new Logger([
            'file_path' => $this->logFile,
            'level' => 'WARNING' // فقط WARNING وما فوق
        ]);

        $logger->debug('Debug message'); // يجب تجاهله
        $logger->info('Info message');   // يجب تجاهله
        $logger->warning('Warning message'); // يجب تسجيله
        $logger->error('Error message');     // يجب تسجيله

        $content = file_get_contents($this->logFile);
        $this->assertStringNotContains('Debug message', $content);
        $this->assertStringNotContains('Info message', $content);
        $this->assertStringContains('Warning message', $content);
        $this->assertStringContains('Error message', $content);
    }

    public function testLoggerRotation()
    {
        $logger = new Logger([
            'file_path' => $this->logFile,
            'level' => 'INFO',
            'max_file_size' => 100, // حجم صغير للاختبار
            'max_files' => 3
        ]);

        // كتابة رسائل كبيرة لتجاوز الحد
        for ($i = 0; $i < 20; $i++) {
            $logger->info('This is a long message that should help fill up the log file quickly ' . str_repeat('x', 50));
        }

        // يجب أن يكون هناك ملفات متعددة
        $this->assertFileExists($this->logFile);
        $this->assertFileExists($this->logFile . '.1');
    }

    public function testLoggerContextFormatting()
    {
        $logger = new Logger([
            'file_path' => $this->logFile,
            'level' => 'INFO'
        ]);

        $logger->info('Message with context', [
            'user_id' => 123,
            'action' => 'login',
            'ip' => '192.168.1.1'
        ]);

        $content = file_get_contents($this->logFile);
        $this->assertStringContains('user_id', $content);
        $this->assertStringContains('123', $content);
        $this->assertStringContains('login', $content);
        $this->assertStringContains('192.168.1.1', $content);
    }
}
?>