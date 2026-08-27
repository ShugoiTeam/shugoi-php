<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\Config;
use Shugoi\CspBuilder;

class CspBuilderTest extends TestCase
{
    public function testDefaultCspContainsExpectedDirectives(): void
    {
        $config = new Config(['siteKey' => 'test_key', 'baseUrl' => 'https://shugoi.com/api/v1']);
        $csp = new CspBuilder($config);
        $result = $csp->build();

        $this->assertStringContainsString("default-src 'self'", $result);
        // invisible-eval retiré (crash WebKit) → plus d''unsafe-eval'
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline'", $result);
        $this->assertStringNotContainsString("'unsafe-eval'", $result);
        $this->assertStringContainsString("connect-src 'self'", $result);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $result);
        $this->assertStringContainsString("font-src 'self' data:", $result);
        $this->assertStringContainsString("img-src 'self' data: blob:", $result);
        $this->assertStringContainsString("frame-ancestors 'self'", $result);
        $this->assertStringContainsString("object-src 'none'", $result);
        $this->assertStringContainsString("base-uri 'self'", $result);
        $this->assertStringContainsString("form-action 'self'", $result);
    }

    public function testSplitRenderFalseRemovesUnsafeEval(): void
    {
        $config = new Config(['siteKey' => 'test_key', 'splitRender' => false]);
        $csp = new CspBuilder($config);
        $result = $csp->build();

        // invisible-eval retiré → CSP strict sans 'unsafe-eval' dans tous les cas
        $this->assertStringNotContainsString("'unsafe-eval'", $result);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline'", $result);
    }

    public function testExtraDirectivesAreMerged(): void
    {
        $config = new Config([
            'siteKey' => 'test_key',
            'extraDirectives' => ['frame-ancestors' => ["'none'"], 'report-uri' => ['https://example.com/csp']],
        ]);
        $csp = new CspBuilder($config);
        $result = $csp->build();

        $this->assertStringContainsString("frame-ancestors 'self' 'none'", $result);
        $this->assertStringContainsString("report-uri https://example.com/csp", $result);
    }

    public function testApiOriginAddedToScriptConnectStyleFontImg(): void
    {
        $config = new Config(['siteKey' => 'test_key', 'baseUrl' => 'https://api.example.com/v1']);
        $csp = new CspBuilder($config);
        $result = $csp->build();

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' https://api.example.com https://shugoi.com", $result);
        $this->assertStringContainsString("connect-src 'self' https://api.example.com https://shugoi.com", $result);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline' https://api.example.com https://shugoi.com", $result);
        $this->assertStringContainsString("font-src 'self' data: https://api.example.com https://shugoi.com", $result);
        $this->assertStringContainsString("img-src 'self' data: blob: https://api.example.com https://shugoi.com", $result);
    }

    public function testMergeCombinesTwoCspStrings(): void
    {
        $existing = "default-src 'self'; script-src 'self'";
        $added = "script-src https://analytics.example.com; img-src https://images.example.com";

        $result = CspBuilder::merge($existing, $added);

        $this->assertStringContainsString("default-src 'self'", $result);
        $this->assertStringContainsString("script-src 'self' https://analytics.example.com", $result);
        $this->assertStringContainsString("img-src https://images.example.com", $result);
    }

    public function testMergeNullReturnsAdded(): void
    {
        $added = "default-src 'self'; script-src https://example.com";
        $result = CspBuilder::merge(null, $added);

        $this->assertSame($added, $result);
    }

    public function testCspDisabledReturnsEmptyString(): void
    {
        $config = new Config(['siteKey' => 'test_key', 'csp' => false]);
        $csp = new CspBuilder($config);
        $result = $csp->build();

        $this->assertSame('', $result);
    }

    public function testShugoiOriginNotDuplicatedWhenSameAsApiOrigin(): void
    {
        $config = new Config(['siteKey' => 'test_key', 'baseUrl' => 'https://shugoi.com/api/v1']);
        $csp = new CspBuilder($config);
        $result = $csp->build();

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval' https://shugoi.com", $result);
    }

    public function testMergeDeduplicatesValues(): void
    {
        $existing = "default-src 'self'; script-src 'self' https://example.com";
        $added = "script-src https://example.com https://other.com";
        $result = CspBuilder::merge($existing, $added);

        $this->assertStringContainsString("script-src 'self' https://example.com https://other.com", $result);
        $this->assertDoesNotMatchRegularExpression('/https:\/\/example\.com.*https:\/\/example\.com/', $result);
    }
}
