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

    public function test_all_signed_endpoints_use_fresh_millisecond_callbacks(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'detect'),
            new Response(200, [], 'guard'),
            new Response(200, [], '{}'),
        ]);
        $history = [];
        $handler = HandlerStack::create($mock);
        $handler->push(\GuzzleHttp\Middleware::history($history));
        $api = new ApiClient($this->config, new GuzzleClient(['handler' => $handler]));
        $before = (int)floor(microtime(true) * 1000);
        $api->fetchGuardDetect();
        $api->fetchGuard();
        $api->fetchWhitelist();
        $after = (int)floor(microtime(true) * 1000);

        $this->assertCount(3, $history);
        foreach ($history as $exchange) {
            parse_str($exchange['request']->getUri()->getQuery(), $query);
            // Match the deployed API's freshness and HMAC contract, not just its signature.
            $this->assertMatchesRegularExpression('/^[1-9][0-9]{0,15}$/D', $query['cb']);
            $this->assertGreaterThanOrEqual($before, (int)$query['cb']);
            $this->assertLessThanOrEqual($after, (int)$query['cb']);
            $this->assertSame(hash_hmac('sha256', $query['cb'], 'test_secret'), $query['sig']);
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
