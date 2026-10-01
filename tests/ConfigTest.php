<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\Config;

class ConfigTest extends TestCase
{
    public function testDefaultValuesWhenOnlySiteKeyGiven()
    {
        $config = new Config(['siteKey' => 'test_key']);

        $this->assertSame('test_key', $config->siteKey);
        $this->assertNull($config->secret);
        $this->assertNull($config->signingSecret);
        $this->assertSame(Config::DEFAULT_ALLOWLIST, $config->allowlist);
        $this->assertSame(Config::DEFAULT_HEADLESS_PATTERNS, $config->headlessPatterns);
        $this->assertSame(Config::DEFAULT_BOT_WHITELIST, $config->botWhitelist);
        $this->assertSame(Config::DEFAULT_BASE_URL, $config->baseUrl);
        $this->assertSame($config->baseUrl, $config->internalUrl);
        $this->assertFalse($config->debug);
        $this->assertTrue($config->autoInject);
        $this->assertFalse($config->restrictedAccess);
        $this->assertNull($config->extraDirectives);
        $this->assertTrue($config->csp);
        $this->assertSame(Config::DEFAULT_BLOCK_STATUS, $config->blockStatus);
        $this->assertNull($config->locale);
        $this->assertNull($config->blockPage);
        $this->assertTrue($config->splitRender);
        $this->assertFalse($config->multiProcess);
        $this->assertNull($config->renderStorePath);
        $this->assertTrue($config->verifyBots);
    }

    public function testSigningSecretFallsBackToSecret()
    {
        $config = new Config([
            'siteKey' => 'test_key',
            'secret' => 'my_secret',
        ]);

        $this->assertSame('my_secret', $config->getSigningSecret());
    }

    public function testSigningSecretCanBeSetExplicitly()
    {
        $config = new Config([
            'siteKey' => 'test_key',
            'secret' => 'fallback_secret',
            'signingSecret' => 'explicit_secret',
        ]);

        $this->assertSame('explicit_secret', $config->signingSecret);
        $this->assertSame('explicit_secret', $config->getSigningSecret());
    }

    public function testThrowsInvalidArgumentExceptionWhenSiteKeyMissing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('siteKey is required');

        new Config([]);
    }

    public function testInternalUrlDefaultsToBaseUrl()
    {
        $config = new Config(['siteKey' => 'test_key']);

        $this->assertSame($config->baseUrl, $config->internalUrl);
    }

    public function testInternalUrlCanBeSetExplicitly()
    {
        $config = new Config([
            'siteKey' => 'test_key',
            'internalUrl' => 'http://localhost:3098/api/v1',
        ]);

        $this->assertSame('http://localhost:3098/api/v1', $config->internalUrl);
        $this->assertNotSame($config->internalUrl, $config->baseUrl);
    }

    public function testMultiProcessDefaultsToFalse()
    {
        $config = new Config(['siteKey' => 'test_key']);

        $this->assertFalse($config->multiProcess);
    }

    public function testRenderStorePathCanBeConfigured(): void
    {
        $config = new Config(['siteKey' => 'test', 'renderStorePath' => '/var/cache/application/shugoi']);
        $this->assertSame('/var/cache/application/shugoi', $config->renderStorePath);
    }

    public function testAllowlistCanBeOverridden()
    {
        $config = new Config([
            'siteKey' => 'test_key',
            'allowlist' => ['/custom', '/paths'],
        ]);

        $this->assertSame(['/custom', '/paths'], $config->allowlist);
    }

    public function testHeadlessPatternsCountIsTwelve()
    {
        $config = new Config(['siteKey' => 'test_key']);

        $this->assertCount(12, $config->headlessPatterns);
    }

    public function testBotWhitelistCountIsThirteen()
    {
        $config = new Config(['siteKey' => 'test_key']);

        $this->assertCount(13, $config->botWhitelist);
    }

    public function testExtraDirectivesIsNullByDefaultAndCanBeSet()
    {
        $config = new Config(['siteKey' => 'test_key']);
        $this->assertNull($config->extraDirectives);

        $config2 = new Config([
            'siteKey' => 'test_key',
            'extraDirectives' => ['frame-ancestors' => "'none'"],
        ]);

        $this->assertSame(['frame-ancestors' => "'none'"], $config2->extraDirectives);
    }

    public function testBlockStatusDefaultsTo403()
    {
        $config = new Config(['siteKey' => 'test_key']);

        $this->assertSame(403, $config->blockStatus);
    }

    public function testLocaleIsNullByDefault()
    {
        $config = new Config(['siteKey' => 'test_key']);

        $this->assertNull($config->locale);
    }

    public function testApiOriginReturnsCorrectOriginFromBaseUrl()
    {
        $config = new Config([
            'siteKey' => 'test_key',
            'baseUrl' => 'https://shugoi.com:3443/api/v1',
        ]);

        $this->assertSame('https://shugoi.com:3443', $config->apiOrigin());
    }

    public function testApiOriginReturnsNullForInvalidUrl()
    {
        $config = new Config([
            'siteKey' => 'test_key',
            'baseUrl' => 'not-a-valid-url',
        ]);

        $this->assertNull($config->apiOrigin());
    }
}
