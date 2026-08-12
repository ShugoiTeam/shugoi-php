<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\ConfigCache;
use Shugoi\ApiClient;
use Shugoi\Config;

class ConfigCacheTest extends TestCase
{
    public function test_get_fetches_and_caches(): void
    {
        $config = new Config([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
        ]);

        $api = $this->createMock(ApiClient::class);
        $api->method('getConfig')->willReturn($config);

        $whitelistData = [
            'whitelistedMachines' => ['04cbcbff'],
            'detectionFlags' => ['headless'],
            'skipPaths' => ['/docs'],
        ];

        $api->expects($this->once())
            ->method('fetchWhitelist')
            ->willReturn($whitelistData);

        $cache = new ConfigCache($api);

        $result1 = $cache->get();
        $this->assertEquals($whitelistData, $result1);

        $result2 = $cache->get();
        $this->assertSame($result1, $result2);
    }

    public function test_get_returns_stale_when_api_fails(): void
    {
        $config = new Config([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
        ]);

        $whitelistData = [
            'whitelistedMachines' => ['04cbcbff'],
            'detectionFlags' => [],
            'skipPaths' => [],
        ];

        $api = $this->createMock(ApiClient::class);
        $api->method('getConfig')->willReturn($config);
        $api->expects($this->exactly(2))
            ->method('fetchWhitelist')
            ->willReturnOnConsecutiveCalls($whitelistData, $this->throwException(new \RuntimeException('API down')));

        $cache = new ConfigCache($api);

        $result1 = $cache->get();
        $this->assertEquals($whitelistData, $result1);

        $ref = new \ReflectionProperty($cache, 'store');
        $store = $ref->getValue($cache);
        $store['default']['fetchedAt'] = microtime(true) - 100;
        $ref->setValue($cache, $store);

        $result2 = $cache->get();
        $this->assertEquals($whitelistData, $result2);
    }
}
