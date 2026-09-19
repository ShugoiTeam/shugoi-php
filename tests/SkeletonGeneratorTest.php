<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\SkeletonGenerator;
use Shugoi\Obfuscator;
use Shugoi\TokenSigner;
use Shugoi\Config;

class SkeletonGeneratorTest extends TestCase
{
    private SkeletonGenerator $generator;

    protected function setUp(): void
    {
        $config = new Config([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
        ]);
        $signer = new TokenSigner($config);
        // Production path: the service provider wires the current XOR string
        // obfuscator. The old invisible-eval wrapper is intentionally retired.
        $this->generator = new SkeletonGenerator($signer, new Obfuscator());
    }

    public function test_output_contains_script_tags(): void
    {
        $result = $this->generator->generate(
            token: 'test:1234:abcd:sig',
            guards: ['detect' => '', 'guard' => ''],
            config: ['whitelistedMachines' => [], 'detectionFlags' => [], 'skipPaths' => []],
            restrictedAccess: false,
            locale: 'en',
            baseUrl: 'https://shugoi.com/api/v1',
        );

        $this->assertStringStartsWith('<script>', $result);
        $this->assertStringEndsWith('</script>', $result);
        $this->assertStringContainsString('var _D=function', $result);
        $this->assertStringNotContainsString('eval([...', $result);
        $this->assertStringContainsString('window.__sg_siteKey=', $this->decodeSkeleton($result));
    }

    public function test_rd_function_present_in_decoded_code(): void
    {
        $result = $this->generator->generate(
            token: 'sg_sk_test:1722000000000:nonce12345678:sig',
            guards: ['detect' => '', 'guard' => ''],
            config: [],
            restrictedAccess: false,
            locale: 'en',
            baseUrl: 'https://shugoi.com/api/v1',
        );

        $decoded = $this->decodeSkeleton($result);
        $this->assertStringContainsString('function rd(', $decoded);
    }

    public function test_closing_script_tags_are_escaped(): void
    {
        $result = $this->generator->generate(
            token: 't:1:a:s',
            guards: ['detect' => 'window.x="</script><script>alert(1)</script>"', 'guard' => ''],
            config: [],
            restrictedAccess: false,
            locale: 'en',
            baseUrl: 'https://shugoi.com/api/v1',
        );

        $this->assertSame(1, preg_match_all('#</script>#i', $result));
        $this->assertStringNotContainsString('</script><script>', $result);
        $decoded = $this->decodeSkeleton($result);
        $this->assertStringNotContainsString('alert(1)', $decoded);
        $this->assertStringContainsString('window.x=', $decoded);
    }

    public function test_showBlock_function_present_in_decoded_code(): void
    {
        $result = $this->generator->generate(
            token: 't:1:a:s',
            guards: ['detect' => '', 'guard' => ''],
            config: [],
            restrictedAccess: false,
            locale: 'en',
            baseUrl: 'https://shugoi.com/api/v1',
        );

        $decoded = $this->decodeSkeleton($result);
        $this->assertStringContainsString('__sg_showBlock', $decoded);
    }

    public function test_restricted_fallback_normalizes_legacy_labels_and_does_not_reload(): void
    {
        // Inspect the plain contract here; obfuscation is tested separately and
        // must not make this behavioral regression test brittle.
        $plainGenerator = new SkeletonGenerator(new TokenSigner(new Config([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
        ])));
        $decoded = $plainGenerator->generate(
            token: 't:1:a:s',
            guards: ['detect' => '', 'guard' => ''],
            config: ['supportEmail' => 'support@example.test'],
            restrictedAccess: false,
            locale: 'fr',
            baseUrl: 'https://shugoi.com/api/v1',
        );

        $this->assertStringContainsString("support@example.test", $decoded);
        $this->assertStringContainsString("Accès restreint", $decoded);
        $this->assertStringContainsString("Service temporairement indisponible", $decoded);
        $this->assertStringNotContainsString("location.reload()", $decoded);
    }

    public function test_cleanup_function_present_in_decoded_code(): void
    {
        $result = $this->generator->generate(
            token: 't:1:a:s',
            guards: ['detect' => '', 'guard' => ''],
            config: [],
            restrictedAccess: false,
            locale: 'en',
            baseUrl: 'https://shugoi.com/api/v1',
        );

        $decoded = $this->decodeSkeleton($result);
        $this->assertStringContainsString('_sgCl', $decoded);
    }

    public function test_restricted_access_omits_disable_flag_assignment(): void
    {
        $result = $this->generator->generate(
            token: 't:1:a:s',
            guards: ['detect' => '', 'guard' => ''],
            config: [],
            restrictedAccess: true,
            locale: 'en',
            baseUrl: 'https://shugoi.com/api/v1',
        );

        $decoded = $this->decodeSkeleton($result);
        $this->assertStringNotContainsString('window.__sg_disableRestrictedAccess=true', $decoded);
    }

    public function test_unrestricted_includes_disable_flag(): void
    {
        $result = $this->generator->generate(
            token: 't:1:a:s',
            guards: ['detect' => '', 'guard' => ''],
            config: [],
            restrictedAccess: false,
            locale: 'en',
            baseUrl: 'https://shugoi.com/api/v1',
        );

        $decoded = $this->decodeSkeleton($result);
        $this->assertStringContainsString('window.__sg_disableRestrictedAccess=true', $decoded);
    }

    public function test_obfuscated_wrapper_is_stable_and_payload_is_seeded(): void
    {
        $render = fn(string $siteKey): string =>
            $this->generator->generate(
                token: 't:1:a:s',
                guards: ['detect' => '', 'guard' => ''],
                config: [],
                restrictedAccess: false,
                locale: 'en',
                baseUrl: 'https://shugoi.com/api/v1',
                siteKey: $siteKey,
            );

        $a = $render('sk_a');
        $b = $render('sk_b');

        $this->assertStringContainsString('var _D=function', $a);
        $this->assertStringContainsString('var _D=function', $b);
        $this->assertNotSame($a, $b);
    }

    private function decodeSkeleton(string $skeleton): string
    {
        if (!preg_match('#<script>(.*)</script>#s', $skeleton, $matches)) {
            return '';
        }
        $inner = $matches[1];
        if (preg_match("/\[\.\.\.'([^']*)'\]/", $inner, $m)) {
            return $this->decodeInvisible($m[1]);
        }
        return $inner;
    }

    private function decodeInvisible(string $payload): string
    {
        $out = '';
        foreach (preg_split('//u', $payload, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $out .= mb_chr(mb_ord($ch, 'UTF-8') - 917504, 'UTF-8');
        }
        return $out;
    }
}
