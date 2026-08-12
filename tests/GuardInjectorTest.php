<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\GuardInjector;
use Shugoi\Config;
use Shugoi\TokenSigner;
use Shugoi\HtmlStore;
use Shugoi\GuardCache;
use Shugoi\ConfigCache;
use Shugoi\ApiClient;

class GuardInjectorTest extends TestCase
{
    public function test_inject_returns_string_with_script(): void
    {
        $config = new Config([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
            'internalUrl' => 'http://127.0.0.1:3098/v1',
        ]);

        $tokenSigner = new TokenSigner($config);

        $htmlStore = $this->createMock(HtmlStore::class);
        $htmlStore->expects($this->once())->method('store');

        $apiMock = $this->createMock(ApiClient::class);
        $apiMock->method('getConfig')->willReturn($config);
        $apiMock->method('fetchGuardDetect')->willReturn('console.log("detect");');
        $apiMock->method('fetchGuard')->willReturn('console.log("guard");');
        $apiMock->method('fetchWhitelist')->willReturn([
            'whitelistedMachines' => [],
            'detectionFlags' => [],
            'skipPaths' => [],
        ]);

        $guardCache = new GuardCache($apiMock);
        $configCache = new ConfigCache($apiMock);

        $injector = new GuardInjector($config, $tokenSigner, $htmlStore, $guardCache, $configCache);

        $result = $injector->inject(
            html: '<html><head></head><body>test</body></html>',
            path: '/',
            ua: 'Mozilla/5.0',
            ip: '127.0.0.1',
            host: 'example.com',
        );

        $this->assertIsString($result);
        $this->assertStringContainsString('<script>', $result);
        $this->assertStringContainsString('window.__sg_siteKey=', $result);
        $this->assertStringNotContainsString('eval(', $result);
    }

    public function test_inject_with_restricted_access_adds_config_script(): void
    {
        $config = new Config([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
            'restrictedAccess' => true,
            'internalUrl' => 'http://127.0.0.1:3098/v1',
        ]);

        $tokenSigner = new TokenSigner($config);

        $htmlStore = $this->createMock(HtmlStore::class);
        $htmlStore->expects($this->once())->method('store');

        $apiMock = $this->createMock(ApiClient::class);
        $apiMock->method('getConfig')->willReturn($config);
        $apiMock->method('fetchGuardDetect')->willReturn('');
        $apiMock->method('fetchGuard')->willReturn('');
        $apiMock->method('fetchWhitelist')->willReturn([
            'whitelistedMachines' => [],
            'detectionFlags' => [],
            'skipPaths' => [],
        ]);

        $guardCache = new GuardCache($apiMock);
        $configCache = new ConfigCache($apiMock);

        $injector = new GuardInjector($config, $tokenSigner, $htmlStore, $guardCache, $configCache);

        $result = $injector->inject(
            html: '<html><head></head><body>test</body></html>',
            path: '/',
            ua: 'Mozilla/5.0',
            ip: '127.0.0.1',
            host: 'example.com',
        );

        $this->assertStringContainsString('<script>', $result);
    }
}
