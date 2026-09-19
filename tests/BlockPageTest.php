<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\BlockPage;

class BlockPageTest extends TestCase
{
    public function testRateLimitCountdownDoesNotReloadAutomatically(): void
    {
        $html = \Shugoi\BlockPage::rateLimit([
            'locale' => 'fr',
            'remainingSeconds' => 60,
            'host' => 'example.test',
        ]);

        $this->assertStringContainsString('clearInterval(i)', $html);
        $this->assertStringNotContainsString('location.reload()', $html);
    }

    public function testBlockingCardUsesBlockingBrandAssets(): void
    {
        $html = \Shugoi\BlockPage::blocked(['locale' => 'fr', 'host' => 'example.test']);

        $this->assertStringContainsString('favicon-block.png', $html);
        $this->assertStringContainsString('brand-block.png', $html);
        $this->assertStringNotContainsString('https://shugoi.com/favicon.png', $html);
    }
    public function testShieldContainsDoctype(): void
    {
        $html = BlockPage::shield('en', 'Test Title', 'Test Message', 'BADGE');
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
    }

    public function testShieldContainsTitle(): void
    {
        $html = BlockPage::shield('en', 'Test Title', 'Test Message', 'BADGE');
        $this->assertStringContainsString('<title>Test Title · Shugoi</title>', $html);
    }

    public function testShieldContainsBadge(): void
    {
        $html = BlockPage::shield('en', 'Test Title', 'Test Message', 'MYBADGE');
        $this->assertStringContainsString('>MYBADGE<', $html);
    }

    public function testShieldContainsMessage(): void
    {
        $html = BlockPage::shield('en', 'Test Title', 'Test Message', 'BADGE');
        $this->assertStringContainsString('>Test Message<', $html);
    }

    public function testShieldContainsHostWhenProvided(): void
    {
        $html = BlockPage::shield('en', 'Test Title', 'Test Message', 'BADGE', 'example.com');
        $this->assertStringContainsString('· example.com', $html);
    }

    public function testShieldOmitsHostWhenNotProvided(): void
    {
        $html = BlockPage::shield('en', 'Test Title', 'Test Message', 'BADGE');
        $this->assertStringNotContainsString('· </footer>', $html);
    }

    public function testShieldContainsCountdownWhenRemainingSecondsProvided(): void
    {
        $html = BlockPage::shield('en', 'Test Title', 'Test Message', 'BADGE', null, 30);
        $this->assertStringContainsString('id="sg-countdown"', $html);
        $this->assertStringContainsString('var s=30', $html);
    }

    public function testShieldOmitsCountdownWhenRemainingSecondsNull(): void
    {
        $html = BlockPage::shield('en', 'Test Title', 'Test Message', 'BADGE');
        $this->assertStringNotContainsString('sg-countdown', $html);
    }

    public function testShieldHtmlEncodesTitle(): void
    {
        $html = BlockPage::shield('en', '<script>alert(1)</script>', 'msg', 'BADGE');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testShieldHtmlEncodesMessage(): void
    {
        $html = BlockPage::shield('en', 'Title', '<b>bold</b>', 'BADGE');
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
    }

    public function testShieldContainsAlexBrushFont(): void
    {
        $html = BlockPage::shield('en', 'Title', 'Msg', 'BADGE');
        $this->assertStringContainsString('Alex Brush', $html);
        $this->assertStringContainsString('alex-brush.woff2', $html);
    }

    public function testShieldContainsBlockingFavicon(): void
    {
        $html = BlockPage::shield('en', 'Title', 'Msg', 'BADGE');
        $this->assertStringContainsString('favicon-block.png', $html);
    }

    public function testShieldContainsShugoiDotCom(): void
    {
        $html = BlockPage::shield('en', 'Title', 'Msg', 'BADGE');
        $this->assertStringContainsString('shugoi.com', $html);
    }

    public function testShieldUsesLocaleInHtmlTag(): void
    {
        $html = BlockPage::shield('fr', 'Titre', 'Message', 'BADGE');
        $this->assertStringContainsString('<html lang="fr">', $html);
    }

    public function testBlockedUsesEnglishByDefault(): void
    {
        $html = BlockPage::blocked([]);
        $this->assertStringContainsString('Access Blocked', $html);
        $this->assertStringContainsString('>Blocked<', $html);
    }

    public function testBlockedUsesFrenchWhenSpecified(): void
    {
        $html = BlockPage::blocked(['locale' => 'fr']);
        $this->assertStringContainsString('Accès bloqué', $html);
        $this->assertStringContainsString('>Blocage<', $html);
    }

    public function testBlockedIncludesHost(): void
    {
        $html = BlockPage::blocked(['locale' => 'en', 'host' => 'example.com']);
        $this->assertStringContainsString('· example.com', $html);
    }

    public function testRateLimitEnglish(): void
    {
        $html = BlockPage::rateLimit(['locale' => 'en']);
        $this->assertStringContainsString('Too Many Requests', $html);
        $this->assertStringContainsString('id="sg-countdown"', $html);
    }

    public function testRateLimitFrench(): void
    {
        $html = BlockPage::rateLimit(['locale' => 'fr']);
        $this->assertStringContainsString('Trop de requêtes', $html);
        $this->assertStringContainsString('id="sg-countdown"', $html);
    }

    public function testRateLimitUsesDefault60Seconds(): void
    {
        $html = BlockPage::rateLimit(['locale' => 'en']);
        $this->assertStringContainsString('var s=60', $html);
    }

    public function testRateLimitUsesCustomRemainingSeconds(): void
    {
        $html = BlockPage::rateLimit(['locale' => 'en', 'remainingSeconds' => 120]);
        $this->assertStringContainsString('var s=120', $html);
    }

    public function testHeadlessEnglish(): void
    {
        $html = BlockPage::headless(['locale' => 'en']);
        $this->assertStringContainsString('DevTools', $html);
        $this->assertStringContainsString('Access Blocked', $html);
    }

    public function testHeadlessFrench(): void
    {
        $html = BlockPage::headless(['locale' => 'fr']);
        $this->assertStringContainsString('DevTools', $html);
        $this->assertStringContainsString('Accès bloqué', $html);
    }

    public function testHeadlessIncludesHost(): void
    {
        $html = BlockPage::headless(['locale' => 'en', 'host' => 'attack-site.com']);
        $this->assertStringContainsString('· attack-site.com', $html);
    }
}
