<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\ApiClient;
use Shugoi\Config;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

class ApiClientTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
            'baseUrl' => 'https://api.shugoi.test/v1',
            'internalUrl' => 'http://127.0.0.1:3098/v1',
        ]);
    }

    public function test_fetch_whitelist_returns_parsed_response(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'whitelistedMachines' => ['04cbcbff'],
                'detectionFlags' => ['headless', 'datacenter'],
                'skipPaths' => ['/docs', '/login'],
            ])),
        ]);
        $handler = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handler]);
        $api = new ApiClient($this->config, $client);

        $result = $api->fetchWhitelist();

        $this->assertIsArray($result);
        $this->assertEquals(['04cbcbff'], $result['whitelistedMachines']);
        $this->assertEquals(['headless', 'datacenter'], $result['detectionFlags']);
        $this->assertEquals(['/docs', '/login'], $result['skipPaths']);
    }

    public function test_fetch_guard_scripts(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'console.log("detect");'),
            new Response(200, [], 'console.log("guard");'),
        ]);
        $handler = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handler]);
        $api = new ApiClient($this->config, $client);

        $detect = $api->fetchGuardDetect();
        $guard = $api->fetchGuard();

        $this->assertEquals('console.log("detect");', $detect);
        $this->assertEquals('console.log("guard");', $guard);
    }

    public function test_fetch_guard_sends_signed_cb(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'ok'),
            new Response(200, [], 'ok'),
        ]);
        $handler = HandlerStack::create($mock);
        $history = [];
        $client = new GuzzleClient(['handler' => $handler, 'on_stats' => function ($stats) use (&$history) {
            $history[] = (string)$stats->getEffectiveUri();
        }]);
        $api = new ApiClient($this->config, $client);

        $api->fetchGuardDetect();
        $api->fetchGuard();

        foreach ($history as $url) {
            $this->assertMatchesRegularExpression('/cb=[0-9a-f]{8}/', $url);
            $cb = (preg_match('/cb=([0-9a-f]+)/', $url, $m)) ? $m[1] : '';
            $expected = hash_hmac('sha256', $cb, 'test_secret');
            $this->assertStringContainsString('sig=' . $expected, $url);
        }
    }

    public function test_check_rate_limit(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'allowed' => false,
                'remaining' => 0,
                'resetAt' => 9999999999,
            ])),
        ]);
        $handler = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handler]);
        $api = new ApiClient($this->config, $client);

        $result = $api->checkRateLimit('192.168.1.1');

        $this->assertIsArray($result);
        $this->assertFalse($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
        $this->assertEquals(9999999999, $result['resetAt']);
    }

    public function test_validate_key(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'valid' => true,
                'mode' => 'test',
            ])),
        ]);
        $handler = HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $handler]);
        $api = new ApiClient($this->config, $client);

        $result = $api->validateKey();

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
        $this->assertEquals('test', $result['mode']);
    }
}
