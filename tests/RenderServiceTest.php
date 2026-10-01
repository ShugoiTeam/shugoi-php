<?php
declare(strict_types=1);

namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\Config;
use Shugoi\ConfigCache;
use Shugoi\HtmlStore;
use Shugoi\RenderService;
use Shugoi\TokenSigner;

class RenderServiceTest extends TestCase
{
    private Config $config;
    private TokenSigner $signer;
    private HtmlStore $store;
    private RenderService $renderer;
    private string $mid;

    protected function setUp(): void
    {
        $this->config = new Config(['siteKey' => 'sg_sk_render_test', 'secret' => 'test-only-secret']);
        $this->signer = new TokenSigner($this->config);
        $this->store = new HtmlStore();
        $cache = $this->createMock(ConfigCache::class);
        $cache->expects($this->never())->method('get');
        $this->renderer = new RenderService($this->config, $this->store, $this->signer, $cache);
        $this->mid = str_repeat('c', 64);
    }

    private function grant(string $token, ?int $timestamp = null): string
    {
        $ts = base_convert((string)($timestamp ?? time()), 10, 36);
        $payload = 'render-grant:' . implode(':', [$this->config->siteKey, $this->mid, $token, $ts]);
        return $ts . ':' . hash_hmac('sha256', $payload, $this->config->secret);
    }

    public function test_valid_grant_renders_the_exact_document_once(): void
    {
        $token = $this->signer->sign(time() * 1000);
        $other = $this->signer->sign(time() * 1000);
        $this->store->store($token, '<html><head></head><body>requested-page</body></html>');
        $this->store->store($other, '<html><body>different-private-page</body></html>');
        $result = $this->renderer->render($token, $this->mid, $this->grant($token), '198.51.100.2');
        $this->assertStringContainsString('requested-page', $result['html']);
        $this->assertStringNotContainsString('different-private-page', $result['html']);
        $this->assertStringContainsString('strict-origin-when-cross-origin', $result['html']);
        $this->assertSame(['error' => 'not_found'], $this->renderer->render($token, $this->mid, $this->grant($token), '198.51.100.2'));
        $this->assertNotNull($this->store->consume($other));
    }

    public function test_invalid_grant_does_not_consume_the_document(): void
    {
        $token = $this->signer->sign(time() * 1000);
        $this->store->store($token, '<html><body>still-available</body></html>');
        foreach (['', 'malformed', $this->grant($token, time() - 61), $this->grant($token, time() + 10)] as $grant) {
            $this->assertSame(['error' => 'not_found'], $this->renderer->render($token, $this->mid, $grant, ''));
        }
        $this->assertArrayHasKey('html', $this->renderer->render($token, $this->mid, $this->grant($token), ''));
    }

    public function test_unknown_token_never_uses_another_page_from_the_same_site(): void
    {
        $token = $this->signer->sign(time() * 1000);
        $other = $this->signer->sign(time() * 1000);
        $this->store->store($other, '<html><body>other-user-private-page</body></html>', 120000, 1, true);
        $this->assertSame(['error' => 'not_found'], $this->renderer->render($token, $this->mid, $this->grant($token), ''));
        $this->assertNotNull($this->store->consume($other));
    }

    public function test_expired_or_forged_token_cannot_render_even_with_a_signed_grant(): void
    {
        $expired = $this->signer->sign(time() * 1000 - TokenSigner::TOKEN_TTL_MS - 1000);
        $forged = substr($this->signer->sign(time() * 1000), 0, -64) . str_repeat('0', 64);
        foreach ([$expired, $forged] as $token) {
            $this->store->store($token, '<html>private</html>');
            $this->assertSame(['error' => 'not_found'], $this->renderer->render($token, $this->mid, $this->grant($token), ''));
        }
    }
}
