<?php
declare(strict_types=1);

namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\MetadataOnly;

final class MetadataOnlyTest extends TestCase
{
    public function testExtractsOnlyAllowedMetadataAndCanonical(): void
    {
        $html = '<!doctype html><html><head><title>Example &amp; test</title>'
            . '<meta name="description" content="Desc">'
            . '<meta property="og:title" content="OG title">'
            . '<meta property="og:image" content="https://example.test/og.png">'
            . '<meta name="secret" content="must-not-leak">'
            . '<link rel="canonical" href="https://example.test/page">'
            . '</head><body><h1>private body</h1><script>alert(1)</script></body></html>';

        $result = MetadataOnly::extract($html, 'https://example.test/page');

        $this->assertStringContainsString('<title>Example &amp; test</title>', $result);
        $this->assertStringContainsString('property="og:title"', $result);
        $this->assertStringContainsString('rel="canonical"', $result);
        $this->assertStringNotContainsString('must-not-leak', $result);
        $this->assertStringNotContainsString('private body', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testRejectsMissingHeadAndOversizedDocuments(): void
    {
        $this->assertSame('', MetadataOnly::extract('<html><body>no head</body></html>'));
        $this->assertSame('', MetadataOnly::extract(str_repeat('x', MetadataOnly::MAX_BYTES + 1)));
    }

    public function testFallbackNeverContainsPageBody(): void
    {
        $result = MetadataOnly::fallback('https://example.test/private');

        $this->assertStringContainsString('<title>Protected application</title>', $result);
        $this->assertStringContainsString('canonical', $result);
        $this->assertStringContainsString('<body></body>', $result);
    }

    public function testMetadataDocumentIsSafeEvenWhenCrawlerUserAgentIsSpoofed(): void
    {
        $result = MetadataOnly::extract(
            '<html><head><title>Public preview</title><meta property="og:title" content="Preview"></head><body>private application</body></html>',
            'https://example.test/'
        );

        $this->assertStringContainsString('Preview', $result);
        $this->assertStringNotContainsString('private application', $result);
    }
}
