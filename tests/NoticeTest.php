<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\Notice;

class NoticeTest extends TestCase
{
    public function test_inject_adds_overlay_and_mid(): void
    {
        $html = '<html><head></head><body>x</body></html>';
        $mid = str_repeat('a', 64);
        $out = Notice::inject($html, $mid, 'sg_sk_test_abc');
        $this->assertStringContainsString('__sg_o', $out);
        $this->assertStringContainsString($mid, $out);
        $this->assertStringContainsString('</body>', $out);
    }

    public function test_inject_replaces_mid_and_site_key(): void
    {
        $html = '<html><body>x</body></html>';
        $mid = str_repeat('b', 64);
        $out = Notice::inject($html, $mid, 'sg_sk_site_xyz');
        $this->assertStringContainsString('var mid="' . $mid . '"||\'\';', $out);
        $this->assertStringContainsString('var sk="sg_sk_site_xyz"||\'\';', $out);
    }

    public function test_inject_injects_base_url(): void
    {
        $html = '<html><body>x</body></html>';
        $out = Notice::inject($html, str_repeat('c', 64), 'sg_sk_test', 'https://shugoi.com/api/v1');
        $this->assertStringContainsString('var base="https://shugoi.com/api/v1"||window.__sg_baseUrl||\'\';', $out);
    }

    public function test_inject_appends_when_no_body(): void
    {
        $out = Notice::inject('<div>x</div>', str_repeat('d', 64), 'sg_sk_test');
        $this->assertStringContainsString('<div>x</div><script>', $out);
    }

    public function test_inject_referrer_policy(): void
    {
        $html = '<html><head><title>t</title></head><body>x</body></html>';
        $out = Notice::injectReferrerPolicy($html);
        $this->assertStringContainsString('<head><meta name="referrer" content="strict-origin-when-cross-origin">', $out);
    }

    public function test_inject_referrer_policy_without_head(): void
    {
        $out = Notice::injectReferrerPolicy('<html><body>x</body></html>');
        $this->assertStringContainsString('<html><meta name="referrer" content="strict-origin-when-cross-origin">', $out);
    }
}
