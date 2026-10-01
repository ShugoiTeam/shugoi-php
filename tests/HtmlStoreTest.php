<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\HtmlStore;

class HtmlStoreTest extends TestCase
{
    private ?string $diskPath = null;

    protected function tearDown(): void
    {
        if ($this->diskPath !== null) {
            foreach (glob($this->diskPath . '/*') ?: [] as $path) unlink($path);
            rmdir($this->diskPath);
        }
    }

    private function diskStore(): HtmlStore
    {
        $this->diskPath ??= sys_get_temp_dir() . '/shugoi-store-test-' . bin2hex(random_bytes(12));
        return new HtmlStore($this->diskPath);
    }

    public function test_store_and_retrieve(): void
    {
        $store = new HtmlStore();
        $store->store('token1', '<html>hello</html>');
        $result = $store->retrieve('token1');
        $this->assertNotNull($result);
        $this->assertEquals('<html>hello</html>', $result['html']);
    }

    public function test_return_null_for_unknown_token(): void
    {
        $store = new HtmlStore();
        $this->assertNull($store->retrieve('nonexistent'));
    }

    public function test_expired_token_returns_null(): void
    {
        $store = new HtmlStore();
        $store->store('token_exp', '<html>expired</html>', 1);
        usleep(2000);
        $this->assertNull($store->retrieve('token_exp'));
    }

    public function test_single_read_token_expires_after_first_read(): void
    {
        $store = new HtmlStore();
        $store->store('token_once', '<html>once</html>', 5000, 1);
        $first = $store->retrieve('token_once');
        $this->assertNotNull($first);
        $second = $store->retrieve('token_once');
        $this->assertNull($second);
    }

    public function test_respects_max_tokens(): void
    {
        $store = new HtmlStore();
        for ($i = 0; $i < 5010; $i++) {
            $store->store("token_{$i}", "<html>{$i}</html>", 5000);
        }
        $this->assertNull($store->retrieve('token_0'));
        $this->assertNotNull($store->retrieve('token_5009'));
    }

    public function test_remove(): void
    {
        $store = new HtmlStore();
        $store->store('token_rm', '<html>remove</html>');
        $store->remove('token_rm');
        $this->assertNull($store->retrieve('token_rm'));
    }

    public function test_consume_is_single_use_even_for_unlimited_read_entries(): void
    {
        $store = new HtmlStore();
        $store->store('document', '<html>one</html>');
        $this->assertSame('<html>one</html>', $store->consume('document')['html']);
        $this->assertNull($store->consume('document'));
        $this->assertNull($store->retrieve('document'));
        $store->store('expired', 'expired', -1);
        $this->assertNull($store->consume('expired'));
        $store->store('limited', 'one-read', 5000, 1);
        $this->assertNotNull($store->retrieve('limited'));
        $this->assertNull($store->consume('limited'));
    }

    public function test_persistent_store_survives_request_lifetime_and_uses_full_token_hash(): void
    {
        $producer = $this->diskStore();
        $producer->store('first-token-12345678', '<html>first</html>');
        $producer->store('other-token-12345678', '<html>other</html>');
        unset($producer);
        $consumer = $this->diskStore();
        $this->assertSame('<html>first</html>', $consumer->consume('first-token-12345678')['html']);
        $this->assertSame('<html>other</html>', $consumer->consume('other-token-12345678')['html']);
        $this->assertNull($consumer->consume('first-token-12345678'));
    }

    public function test_shared_consumption_never_falls_back_to_creators_memory(): void
    {
        $producer = $this->diskStore();
        $producer->store('document', 'private');
        $worker = $this->diskStore();
        $this->assertSame('private', $worker->consume('document')['html']);
        $this->assertNull($producer->consume('document'));
        $this->assertNull($producer->retrieve('document'));
    }

    public function test_default_persistent_directory_is_shared_between_requests(): void
    {
        $token = 'test-' . bin2hex(random_bytes(32));
        $producer = new HtmlStore(true);
        $producer->store($token, 'cross-request');
        unset($producer);
        $consumer = new HtmlStore(true);
        $this->assertSame('cross-request', $consumer->consume($token)['html']);
        $this->assertNull((new HtmlStore(true))->consume($token));
    }

    public function test_disk_metadata_preserves_ttl_read_limits_and_arbitrary_bytes(): void
    {
        $producer = $this->diskStore();
        $html = "<html>\xff\x00</html>";
        $producer->store('bytes', $html, 5000, 2);
        $consumer = $this->diskStore();
        $this->assertSame($html, $consumer->retrieve('bytes')['html']);
        $this->assertSame($html, $consumer->retrieve('bytes')['html']);
        $this->assertNull($consumer->retrieve('bytes'));
        $producer->store('expired', 'never', -1);
        $this->assertNull($consumer->consume('expired'));
        $producer->store('replace', 'once', 5000, -1, true);
        $this->assertSame('once', $consumer->retrieve('replace')['html']);
        $this->assertNull($consumer->retrieve('replace'));
    }

    public function test_disk_rejects_malformed_expired_or_cross_token_metadata(): void
    {
        $store = $this->diskStore();
        $store->store('source', 'private');
        $sourcePath = $this->diskPath . '/' . hash('sha256', 'source') . '.json';
        $targetPath = $this->diskPath . '/' . hash('sha256', 'target') . '.json';
        copy($sourcePath, $targetPath);
        $this->assertNull($store->consume('target'));
        $this->assertSame('private', $store->consume('source')['html']);
        foreach (['not-json', serialize(['html' => 'unsafe']), '{"version":1}', '{"expiresAt":1e400}'] as $invalid) {
            file_put_contents($targetPath, $invalid);
            $this->assertNull($store->consume('target'));
        }
        $store->store('target', 'expired');
        $metadata = json_decode(file_get_contents($targetPath), true);
        $metadata['expiresAt'] = microtime(true) - 1;
        file_put_contents($targetPath, json_encode($metadata));
        $this->assertNull($store->consume('target'));
        $this->assertSame([], glob($this->diskPath . '/*'));
    }

    public function test_atomic_claim_allows_only_one_independent_process(): void
    {
        if (!function_exists('proc_open')) $this->markTestSkipped('proc_open is required for the independent worker test');
        $store = $this->diskStore();
        $store->store('document', 'exactly-once');
        $start = $this->diskPath . '/start';
        $code = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
            . '$store = new \\Shugoi\\HtmlStore(' . var_export($this->diskPath, true) . ');'
            . '$deadline = microtime(true) + 10; while (!file_exists(' . var_export($start, true) . ') && microtime(true) < $deadline) { usleep(1000); }'
            . 'echo json_encode($store->consume("document"));';
        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $workers[] = [$process, $pipes];
        }
        touch($start);
        $winners = 0;
        foreach ($workers as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stderr);
            $result = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
            if ($result !== null) {
                ++$winners;
                $this->assertSame('exactly-once', $result['html']);
            }
        }
        $this->assertSame(1, $winners);
        $this->assertNull($store->consume('document'));
    }
}
