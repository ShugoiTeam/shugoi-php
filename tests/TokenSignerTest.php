<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\TokenSigner;
use Shugoi\Config;

class TokenSignerTest extends TestCase
{
    private Config $config;
    private TokenSigner $signer;

    protected function setUp(): void
    {
        $this->config = new Config([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'my_test_secret_key_for_hmac',
        ]);
        $this->signer = new TokenSigner($this->config);
    }

    public function test_sign_returns_4_part_token(): void
    {
        $ts = 1722000000000;
        $token = $this->signer->sign($ts);
        $parts = explode(':', $token);
        $this->assertCount(4, $parts);
        $this->assertEquals('sg_sk_test_abc', $parts[0]);
        $this->assertEquals((string)$ts, $parts[1]);
        $this->assertEquals(16, strlen($parts[2]));
        $this->assertEquals(64, strlen($parts[3]));
    }

    public function test_verify_valid_token_returns_parts(): void
    {
        $token = $this->signer->sign(time() * 1000);
        $result = $this->signer->verify($token);
        $this->assertNotNull($result);
        $this->assertEquals('sg_sk_test_abc', $result['siteKey']);
    }

    public function test_verify_tampered_token_returns_null(): void
    {
        $token = $this->signer->sign(time() * 1000);
        $parts = explode(':', $token);
        $parts[3] = str_repeat('0', 64);
        $this->assertNull($this->signer->verify(implode(':', $parts)));
    }

    public function test_verify_wrong_secret_returns_null(): void
    {
        $token = $this->signer->sign(time() * 1000);
        $this->assertNull($this->signer->verify($token, 'wrong_secret'));
    }

    public function test_token_requires_current_tenant_format_and_time_window(): void
    {
        $other = new TokenSigner(new Config(['siteKey' => 'sg_sk_other', 'secret' => $this->config->secret]));
        $now = (int)(microtime(true) * 1000);
        $this->assertNull($this->signer->verify($other->sign($now)));
        $this->assertNull($this->signer->verify($this->signer->sign($now - TokenSigner::TOKEN_TTL_MS - 1000)));
        $this->assertNull($this->signer->verify($this->signer->sign($now + 10_000)));
        foreach (['', 'a:b:c', $this->signer->sign($now) . ':extra'] as $badToken) {
            $this->assertNull($this->signer->verify($badToken));
        }
        foreach (['0', '1e12', '-1', '0001722000000000', str_repeat('9', 20)] as $timestamp) {
            $payload = $this->config->siteKey . ':' . $timestamp . ':' . str_repeat('a', 16);
            $signed = $payload . ':' . hash_hmac('sha256', $payload, $this->config->secret);
            $this->assertNull($this->signer->verify($signed));
        }
    }

    public function test_render_grant_current_protocol_is_not_ip_bound(): void
    {
        $token = $this->signer->sign(time() * 1000);
        $mid = str_repeat('a', 64);
        $grant = $this->grant($token, $mid);
        $this->assertTrue($this->signer->verifyRenderGrant($mid, $grant, $token, '203.0.113.1', $this->config->siteKey));
        $this->assertTrue($this->signer->verifyRenderGrant($mid, $grant, $token, '2001:db8::1', $this->config->siteKey));
    }

    public function test_render_grant_cannot_be_rebound_to_another_document_machine_or_site(): void
    {
        $token = $this->signer->sign(time() * 1000);
        $mid = str_repeat('a', 64);
        $grant = $this->grant($token, $mid);
        $this->assertFalse($this->signer->verifyRenderGrant(str_repeat('b', 64), $grant, $token, '', $this->config->siteKey));
        $this->assertFalse($this->signer->verifyRenderGrant($mid, $grant, $this->signer->sign(time() * 1000), '', $this->config->siteKey));
        $this->assertFalse($this->signer->verifyRenderGrant($mid, $grant, $token, '', 'sg_sk_other'));
        $this->assertFalse($this->signer->verifyRenderGrant($mid, $grant, $token, '', null));
        $foreignToken = (new TokenSigner(new Config(['siteKey' => 'sg_sk_other', 'secret' => $this->config->secret])))->sign(time() * 1000);
        $this->assertFalse($this->signer->verifyRenderGrant($mid, $this->grant($foreignToken, $mid), $foreignToken, '', $this->config->siteKey));
    }

    public function test_render_grant_rejects_malformed_forged_expired_or_future_values(): void
    {
        $token = $this->signer->sign(time() * 1000);
        $mid = str_repeat('a', 64);
        $valid = $this->grant($token, $mid);
        foreach ([null, '', 'no-colon', '0:' . str_repeat('a', 64), '!' . $valid, $valid . ':extra', strtoupper($valid),
            $this->grant($token, $mid, time() - 61), $this->grant($token, $mid, time() + 10),
            explode(':', $valid)[0] . ':' . str_repeat('0', 64)] as $grant) {
            $this->assertFalse($this->signer->verifyRenderGrant($mid, $grant, $token, '', $this->config->siteKey));
        }
        foreach ([null, '', 'short', str_repeat('a', 65), str_repeat('A', 64)] as $badMid) {
            $this->assertFalse($this->signer->verifyRenderGrant($badMid, $valid, $token, '', $this->config->siteKey));
        }
        $forgedToken = substr($token, 0, -64) . str_repeat('0', 64);
        $this->assertFalse($this->signer->verifyRenderGrant($mid, $this->grant($forgedToken, $mid), $forgedToken, '', $this->config->siteKey));
    }

    public function test_missing_or_empty_secret_fails_closed(): void
    {
        $token = $this->signer->sign(time() * 1000);
        $mid = str_repeat('a', 64);
        foreach ([null, ''] as $secret) {
            $signer = new TokenSigner(new Config(['siteKey' => $this->config->siteKey, 'secret' => $secret]));
            $this->assertNull($signer->verify($token));
            $this->assertFalse($signer->verifyRenderGrant($mid, $this->grant($token, $mid), $token, '', $this->config->siteKey));
            try {
                $signer->sign(time() * 1000);
                $this->fail('Signing without a secret must fail');
            } catch (\RuntimeException $error) {
                $this->assertSame('No signing secret configured', $error->getMessage());
            }
        }
    }

    private function grant(string $token, string $mid, ?int $timestamp = null): string
    {
        $ts = base_convert((string)($timestamp ?? time()), 10, 36);
        $payload = 'render-grant:' . implode(':', [$this->config->siteKey, $mid, $token, $ts]);
        return $ts . ':' . hash_hmac('sha256', $payload, $this->config->secret);
    }
}
