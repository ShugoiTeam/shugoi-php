<?php
declare(strict_types=1);

namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\Config;
use Shugoi\PowReceipt;

class PowReceiptTest extends TestCase
{
    private string $directory;
    private Config $config;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/shugoi-receipt-test-' . bin2hex(random_bytes(12));
        $this->config = $this->makeConfig();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $path) unlink($path);
            rmdir($this->directory);
        }
    }

    private function makeConfig(array $overrides = []): Config
    {
        return new Config($overrides + ['siteKey' => 'sg_sk_receipt_test', 'secret' => 'test-secret', 'powReceiptStorePath' => $this->directory]);
    }

    private function signedAt(int $timestamp, string $ip = '203.0.113.2', string $ua = 'Mozilla/5.0'): string
    {
        $payload = $timestamp . '.' . bin2hex(random_bytes(16)) . '.' . hash('sha256', $ip . "\n" . $ua);
        return 'v1.' . $payload . '.' . hash_hmac('sha256', 'pow-ws-receipt:' . $this->config->siteKey . ':' . str_replace('.', ':', $payload), $this->config->getSigningSecret());
    }

    public function test_receipt_is_single_use_across_independent_instances(): void
    {
        $receipt = (new PowReceipt($this->config))->issue('203.0.113.2', 'Mozilla/5.0');
        $this->assertTrue((new PowReceipt($this->config))->consume($receipt, '203.0.113.2', 'Mozilla/5.0'));
        $this->assertFalse((new PowReceipt($this->config))->consume($receipt, '203.0.113.2', 'Mozilla/5.0'));
    }

    public function test_invalid_context_does_not_burn_a_valid_receipt(): void
    {
        $receipt = (new PowReceipt($this->config))->issue('203.0.113.2', 'Mozilla/5.0');
        $service = new PowReceipt($this->config);
        $this->assertFalse($service->consume($receipt, '203.0.113.3', 'Mozilla/5.0'));
        $this->assertFalse($service->consume($receipt, '203.0.113.2', 'curl/8'));
        $this->assertFalse((new PowReceipt($this->makeConfig(['siteKey' => 'sg_sk_foreign'])))->consume($receipt, '203.0.113.2', 'Mozilla/5.0'));
        $this->assertFalse((new PowReceipt($this->makeConfig(['secret' => 'wrong-secret'])))->consume($receipt, '203.0.113.2', 'Mozilla/5.0'));
        $this->assertTrue($service->consume($receipt, '203.0.113.2', 'Mozilla/5.0'));
    }

    public function test_timestamp_and_format_are_checked_before_consumption(): void
    {
        $service = new PowReceipt($this->config);
        $valid = $this->signedAt(time());
        foreach (['', 'bad', $valid . '.extra', str_replace('v1.', 'v2.', $valid),
            substr($valid, 0, -64) . str_repeat('0', 64), $this->signedAt(time() - 61), $this->signedAt(time() + 10)] as $receipt) {
            $this->assertFalse($service->consume($receipt, '203.0.113.2', 'Mozilla/5.0'));
        }
        $this->assertTrue($service->consume($valid, '203.0.113.2', 'Mozilla/5.0'));
        $this->assertTrue($service->consume($this->signedAt(time() + 4), '203.0.113.2', 'Mozilla/5.0'));
    }

    public function test_missing_or_empty_secret_never_issues_or_accepts_receipts(): void
    {
        $valid = $this->signedAt(time());
        foreach ([null, ''] as $secret) {
            $service = new PowReceipt($this->makeConfig(['secret' => $secret]));
            $this->assertFalse($service->consume($valid, '203.0.113.2', 'Mozilla/5.0'));
            try {
                $service->issue('203.0.113.2', 'Mozilla/5.0');
                $this->fail('An admission receipt requires a secret');
            } catch (\RuntimeException $error) {
                $this->assertSame('No signing secret configured', $error->getMessage());
            }
        }
    }

    public function test_unavailable_claim_storage_denies_admission(): void
    {
        mkdir($this->directory, 0700);
        $file = $this->directory . '/not-a-directory';
        file_put_contents($file, 'test');
        $service = new PowReceipt($this->makeConfig(['powReceiptStorePath' => $file]));
        $this->assertFalse($service->consume($this->signedAt(time()), '203.0.113.2', 'Mozilla/5.0'));
    }

    public function test_only_one_process_can_consume_the_same_receipt(): void
    {
        if (!function_exists('proc_open')) $this->markTestSkipped('proc_open is required for the independent worker test');
        mkdir($this->directory, 0700);
        $receipt = $this->signedAt(time());
        $start = $this->directory . '/start';
        $options = ['siteKey' => $this->config->siteKey, 'secret' => $this->config->secret, 'powReceiptStorePath' => $this->directory];
        $code = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
            . '$service = new \\Shugoi\\PowReceipt(new \\Shugoi\\Config(' . var_export($options, true) . '));'
            . '$deadline = microtime(true) + 10; while (!file_exists(' . var_export($start, true) . ') && microtime(true) < $deadline) { usleep(1000); }'
            . 'echo $service->consume(' . var_export($receipt, true) . ', "203.0.113.2", "Mozilla/5.0") ? "1" : "0";';
        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $workers[] = [$process, $pipes];
        }
        touch($start);
        $accepted = 0;
        foreach ($workers as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stderr);
            $this->assertContains($stdout, ['0', '1']);
            $accepted += (int)$stdout;
        }
        $this->assertSame(1, $accepted);
    }
}
