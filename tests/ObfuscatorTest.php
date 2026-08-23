<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\Obfuscator;

class ObfuscatorTest extends TestCase
{
    private Obfuscator $obfuscator;

    protected function setUp(): void
    {
        $this->obfuscator = new Obfuscator();
    }

    public function test_strip_comments_removes_single_line(): void
    {
        $code = "var a = 1; // this is a comment\nvar b = 2;";
        $result = $this->obfuscator->stripComments($code);
        $this->assertStringNotContainsString('//', $result);
        $this->assertStringContainsString('var b = 2;', $result);
    }

    public function test_strip_comments_removes_multi_line(): void
    {
        $code = "var a = 1; /* block\ncomment */ var b = 2;";
        $result = $this->obfuscator->stripComments($code);
        $this->assertStringNotContainsString('/*', $result);
    }

    public function test_strip_trace_removes_sg_log_cp(): void
    {
        $code = "function _sgLogCP(a){console.log(a)}\n_sgLogCP('test');";
        $result = $this->obfuscator->stripTrace($code);
        $this->assertStringNotContainsString('_sgLogCP', $result);
    }

    public function test_rename_functions(): void
    {
        $code = "function buildOverlay(){return 1}\nfunction checkNotice(){return 2}";
        $result = $this->obfuscator->renameFunctions($code, 'test_seed');
        $this->assertStringContainsString('_wf', $result);
        $this->assertStringContainsString('_wg', $result);
        $this->assertStringNotContainsString('buildOverlay', $result);
    }

    public function test_seeded_rng_produces_deterministic_output(): void
    {
        $code1 = $this->obfuscator->shuffleLines("R.a = 1;\nR.b = 2;\nR.c = 3;", 'seed1');
        $code2 = $this->obfuscator->shuffleLines("R.a = 1;\nR.b = 2;\nR.c = 3;", 'seed1');
        $this->assertEquals($code1, $code2);
    }

    public function test_encrypt_strings_replaces_with_D_calls(): void
    {
        $code = 'var msg = "hello world";';
        $result = $this->obfuscator->encryptStrings($code, 'test_seed');
        $this->assertStringContainsString('_D("', $result);
        $this->assertStringNotContainsString('"hello world"', $result);
    }

    public function test_inject_decoder_adds_D_function(): void
    {
        $result = $this->obfuscator->injectDecoder('test_seed');
        $this->assertStringContainsString('var _D=function', $result);
    }

    public function test_escape_closing_tags(): void
    {
        $result = $this->obfuscator->escapeClosingTags('</script>');
        $this->assertStringContainsString('<\\/script>', $result);
    }

    public function test_fix_computed_properties(): void
    {
        $result = $this->obfuscator->fixComputedProperties('{ _D("key"): val }');
        $this->assertStringContainsString('[_D("key")]: val', $result);
    }

    public function test_full_pipeline_produces_obfuscated_code(): void
    {
        $input = 'var R=function(){return "hello"};';
        $result = $this->obfuscator->obfuscate($input, 'test_seed');
        $this->assertNotEquals($input, $result);
        $this->assertStringContainsString('var _D=function', $result);
    }

    public function test_invisible_eval_payload_contains_only_private_use_plane_chars(): void
    {
        $code = "var x='hi \" < / \\\\';\nwindow.y=\"caf\xC3\xA9 \xF0\x9F\x91\x8B\";";
        $result = $this->obfuscator->invisibleEval($code, 'seed');

        $this->assertStringContainsString("eval([...'", $result);
        $payload = $this->extractPayload($result);
        $this->assertStringNotContainsString("'", $payload);
        $this->assertStringNotContainsString('"', $payload);
        $this->assertStringNotContainsString('\\', $payload);
        $this->assertStringNotContainsString('<', $payload);
        $this->assertStringNotContainsString('`', $payload);
        $this->assertStringNotContainsString('/script', $payload);
        foreach (preg_split('//u', $payload, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $this->assertGreaterThanOrEqual(0xE0000, mb_ord($ch, 'UTF-8'));
        }
    }

    public function test_invisible_eval_round_trips_to_original_code(): void
    {
        $code = "var a=1;window.b=\"caf\xC3\xA9 \xF0\x9F\x91\x8B\";";
        $result = $this->obfuscator->invisibleEval($code, 'seed');
        $this->assertSame($code, $this->decodePayload($this->extractPayload($result)));
    }

    public function test_invisible_eval_wrapper_has_valid_structure(): void
    {
        $result = $this->obfuscator->invisibleEval('var a=1;', 'seed');
        $this->assertMatchesRegularExpression(
            "/^var _[a-z][0-9][0-9a-z]+=917504,_[a-z][0-9][0-9a-z]+=String\.fromCodePoint;"
            . "eval\(\[\.\.\.'[^']*'\]\.map\(function\(_[a-z][0-9][0-9a-z]+\)\{"
            . "return _[a-z][0-9][0-9a-z]+\(_[a-z][0-9][0-9a-z]+\.codePointAt\(0\)-_[a-z][0-9][0-9a-z]+\)\}\)\.join\(''\)\);$/",
            $result
        );
    }

    public function test_invisible_eval_is_deterministic_per_seed(): void
    {
        $a1 = $this->obfuscator->invisibleEval('var a=1;', 'seed');
        $a2 = $this->obfuscator->invisibleEval('var a=1;', 'seed');
        $b = $this->obfuscator->invisibleEval('var a=1;', 'other_seed');

        $this->assertSame($a1, $a2);
        $this->assertNotSame($a1, $b);
    }

    public function test_invisible_eval_handles_multibyte_utf8(): void
    {
        $code = "var msg=\"\xE4\xB8\x96\xE7\x95\x8C caf\xC3\xA9 \xF0\x9F\x91\x8B\";";
        $result = $this->obfuscator->invisibleEval($code, 'seed');
        $this->assertSame($code, $this->decodePayload($this->extractPayload($result)));
    }

    private function extractPayload(string $wrapper): string
    {
        if (!preg_match("/\[\.\.\.'([^']*)'\]/", $wrapper, $m)) {
            $this->fail('no invisible payload found in wrapper');
        }
        return $m[1];
    }

    private function decodePayload(string $payload): string
    {
        $out = '';
        foreach (preg_split('//u', $payload, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $out .= mb_chr(mb_ord($ch, 'UTF-8') - 917504, 'UTF-8');
        }
        return $out;
    }
}
