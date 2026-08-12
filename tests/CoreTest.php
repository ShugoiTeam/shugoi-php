<?php

namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\Core;
use Shugoi\Config;
use Shugoi\ApiClient;
use Shugoi\ConfigCache;
use Shugoi\Pow;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Client;

class CoreTest extends TestCase
{
    private function makeCore(array $configOverrides = [], ?MockHandler $mock = null): Core
    {
        $config = new Config(array_merge([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
            'powDifficulty' => 10,
        ], $configOverrides));
        if (!$mock) {
            $mock = new MockHandler([
                new Response(200, [], json_encode(['valid' => true])),
                new Response(200, [], json_encode([
                    'whitelistedMachines' => [],
                    'detectionFlags' => [],
                    'skipPaths' => [],
                ])),
            ]);
        }
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $api = new ApiClient($config, $http);
        $configCache = new ConfigCache($api);
        return new Core($config, $api, new Pow($config), $configCache);
    }

    private function makeConfig(array $overrides = []): Config
    {
        return new Config(array_merge([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
            'powDifficulty' => 10,
        ], $overrides));
    }

    private function solvePow(Config $config, int $difficulty): string
    {
        $ts = time();
        $nonce = bin2hex(random_bytes(8));
        $salt = hash_hmac('sha256', $ts . ':' . $nonce, $config->getSigningSecret());
        $n = 0;
        $pow = new Pow($config);
        while (true) {
            $digest = hash('sha256', $salt . ':' . dechex($n));
            if ($pow->leadingZeroBits($digest) >= $difficulty) {
                return $ts . ':' . $nonce . ':' . dechex($n);
            }
            $n++;
        }
    }

    public function test_allowlisted_path_returns_null(): void
    {
        $core = $this->makeCore();
        $result = $core->evaluate([
            'path' => '/api/test',
            'ua' => 'curl/7.68',
            'ip' => '1.2.3.4',
        ]);
        $this->assertNull($result);
    }

    public function test_allowlist_is_exact_or_prefix_plus_slash(): void
    {
        $core = $this->makeCore();
        $this->assertNull($core->evaluate(['path' => '/api/test', 'ua' => 'curl/7.68', 'ip' => '1.2.3.4']));
        $this->assertNotNull($core->evaluate(['path' => '/apiscraper', 'ua' => 'Mozilla/5.0', 'ip' => '1.2.3.4']));
    }

    public function test_headless_ua_returns_block(): void
    {
        $core = $this->makeCore();
        $result = $core->evaluate([
            'path' => '/some-page',
            'ua' => 'curl/7.68',
            'ip' => '1.2.3.4',
            'sgOk' => (new Pow($this->makeConfig(['powDifficulty' => 10])))->sgOkValue('1.2.3.4', 'curl/7.68'),
        ]);
        $this->assertNotNull($result);
        $this->assertTrue($result['block']);
        $this->assertEquals(403, $result['status']);
    }

    public function test_whitelisted_bot_not_blocked(): void
    {
        $core = $this->makeCore(['verifyBots' => false]);
        $result = $core->evaluate([
            'path' => '/',
            'ua' => 'Slurp/1.0 (+http://www.yahoo.net/slurp)',
            'ip' => '1.2.3.4',
            'sgOk' => (new Pow($this->makeConfig(['powDifficulty' => 10])))->sgOkValue('1.2.3.4', 'Slurp/1.0 (+http://www.yahoo.net/slurp)'),
        ]);
        $this->assertNull($result);
    }

    public function test_mozilla_whitelisted_bot_is_challenged_like_npm(): void
    {
        $core = $this->makeCore(['verifyBots' => false]);
        $result = $core->evaluate([
            'path' => '/',
            'ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'ip' => '1.2.3.4',
        ]);
        $this->assertNotNull($result);
        $this->assertEquals(307, $result['status']);
    }

    public function test_browser_without_proof_gets_307_challenge(): void
    {
        $core = $this->makeCore();
        $result = $core->evaluate([
            'path' => '/',
            'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120',
            'ip' => '1.2.3.4',
            'acceptLanguage' => 'en-US,en;q=0.9',
            'secFetchDest' => 'document',
            'secFetchMode' => 'navigate',
        ]);
        $this->assertNotNull($result);
        $this->assertEquals(307, $result['status']);
        $this->assertStringContainsString('/__sg_challenge', $result['headers']['Location']);
    }

    public function test_browser_with_valid_proof_passes(): void
    {
        $config = new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10]);
        $mock = new MockHandler([
            new Response(200, [], json_encode(['valid' => true])),
            new Response(200, [], json_encode(['whitelistedMachines' => [], 'detectionFlags' => [], 'skipPaths' => []])),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $api = new ApiClient($config, $http);
        $configCache = new ConfigCache($api);
        $core = new Core($config, $api, new Pow($config), $configCache);

        $proof = $this->solvePow($config, 10);
        $result = $core->evaluate([
            'path' => '/',
            'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120',
            'ip' => '1.2.3.4',
            'acceptLanguage' => 'en-US',
            'secFetchDest' => 'document',
            'secFetchMode' => 'navigate',
            'sgProof' => $proof,
        ]);
        $this->assertNull($result);
    }

    public function test_sg_ok_cookie_skips_preflight(): void
    {
        $config = new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10]);
        $mock = new MockHandler([
            new Response(200, [], json_encode(['valid' => true])),
            new Response(200, [], json_encode(['whitelistedMachines' => [], 'detectionFlags' => [], 'skipPaths' => []])),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $api = new ApiClient($config, $http);
        $core = new Core($config, $api, new Pow($config));

        $sgOk = (new Pow($config))->sgOkValue('1.2.3.4', 'Mozilla/5.0 Chrome/120');
        $result = $core->evaluate([
            'path' => '/',
            'ua' => 'Mozilla/5.0 Chrome/120',
            'ip' => '1.2.3.4',
            'sgOk' => $sgOk,
        ]);
        $this->assertNull($result);
    }

    public function test_proof_is_single_use(): void
    {
        $config = new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10]);
        $mock = new MockHandler([
            new Response(200, [], json_encode(['valid' => true])),
            new Response(200, [], json_encode(['whitelistedMachines' => [], 'detectionFlags' => [], 'skipPaths' => []])),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $api = new ApiClient($config, $http);
        $core = new Core($config, $api, new Pow($config), new ConfigCache($api));

        $proof = $this->solvePow($config, 10);
        $ctx = ['path' => '/', 'ua' => 'Mozilla/5.0 Chrome/120', 'ip' => '1.2.3.4', 'sgProof' => $proof];
        $this->assertNull($core->evaluate($ctx));
        $second = $core->evaluate($ctx);
        $this->assertNotNull($second);
        $this->assertEquals(307, $second['status']);
        $crossIp = $core->evaluate(['path' => '/', 'ua' => 'Mozilla/5.0 Chrome/120', 'ip' => '9.9.9.9', 'sgProof' => $proof]);
        $this->assertNotNull($crossIp);
        $this->assertEquals(307, $crossIp['status']);
    }

    public function test_challenge_page_served(): void
    {
        $core = $this->makeCore();
        $result = $core->evaluate([
            'path' => '/__sg_challenge',
            'ua' => 'Mozilla/5.0 Chrome/120',
            'ip' => '1.2.3.4',
        ]);
        $this->assertEquals(200, $result['status']);
        $this->assertStringContainsString('crypto.subtle', $result['body']);
        $this->assertStringContainsString('(b&8)?0:(b&4)?1:(b&2)?2:3', $result['body']);
    }

    public function test_assets_blocked_without_authorized_cookie(): void
    {
        $core = $this->makeCore();
        $result = $core->evaluate([
            'path' => '/assets/app.js',
            'ua' => 'TestApp/1.0',
            'ip' => '1.2.3.4',
        ]);
        $this->assertNotNull($result);
        $this->assertEquals(403, $result['status']);
    }

    public function test_assets_served_with_authorized_cookie(): void
    {
        $config = new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10]);
        $mock = new MockHandler([
            new Response(200, [], json_encode(['valid' => true])),
            new Response(200, [], json_encode(['whitelistedMachines' => [], 'detectionFlags' => [], 'skipPaths' => []])),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $api = new ApiClient($config, $http);
        $core = new Core($config, $api, new Pow($config), new ConfigCache($api));
        $result = $core->evaluate([
            'path' => '/assets/app.js',
            'ua' => 'TestApp/1.0',
            'ip' => '1.2.3.4',
            'sgAuthorized' => (new Pow($config))->sgAuthorizedValue(),
            'sgOk' => (new Pow($config))->sgOkValue('1.2.3.4', 'TestApp/1.0'),
        ]);
        $this->assertNull($result);
    }

    public function test_isAllowlisted(): void
    {
        $core = $this->makeCore();
        $this->assertTrue($core->isAllowlisted('/api/test'));
        $this->assertTrue($core->isAllowlisted('/legal'));
        $this->assertFalse($core->isAllowlisted('/'));
        $this->assertFalse($core->isAllowlisted('/apiscraper'));
    }

    public function test_isWhitelistedBot(): void
    {
        $core = $this->makeCore();
        $this->assertTrue($core->isWhitelistedBot('Googlebot/2.1'));
        $this->assertTrue($core->isWhitelistedBot('Bingbot'));
        $this->assertFalse($core->isWhitelistedBot('Mozilla/5.0 Chrome/120'));
    }

    public function test_rate_limit_check_blocks_with_block_page(): void
    {
        $config = new Config([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
            'powDifficulty' => 10,
            'blockPage' => fn(array $ctx) => 'CUSTOM:' . $ctx['reason'] . ':' . $ctx['remainingSeconds'],
        ]);
        $reset = (time() + 90) * 1000;
        $mock = new MockHandler([
            new Response(200, [], json_encode(['valid' => true])),
            new Response(200, [], json_encode(['whitelistedMachines' => [], 'detectionFlags' => ['enableRateLimit' => true], 'skipPaths' => []])),
            new Response(200, [], json_encode(['allowed' => false, 'resetAt' => $reset])),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $api = new ApiClient($config, $http);
        $core = new Core($config, $api, new Pow($config), new ConfigCache($api));

        $result = $core->evaluate(['path' => '/x', 'ua' => '', 'ip' => '1.2.3.4', 'acceptLanguage' => 'en', 'sgOk' => (new Pow($config))->sgOkValue('1.2.3.4', '')]);
        $this->assertNotNull($result);
        $this->assertEquals(429, $result['status']);
        $this->assertStringContainsString('CUSTOM:rate_limit:90', $result['body']);
    }

    public function test_rate_limit_check_not_called_when_flag_absent(): void
    {
        $config = new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10]);
        $mock = new MockHandler([
            new Response(200, [], json_encode(['valid' => true])),
            new Response(200, [], json_encode(['whitelistedMachines' => [], 'detectionFlags' => [], 'skipPaths' => []])),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $api = new ApiClient($config, $http);
        $core = new Core($config, $api, new Pow($config), new ConfigCache($api));

        $result = $core->evaluate(['path' => '/x', 'ua' => '', 'ip' => '1.2.3.4', 'acceptLanguage' => 'en', 'sgOk' => (new Pow($config))->sgOkValue('1.2.3.4', '')]);
        $this->assertNull($result);
    }

    public function test_enable_headless_check_false_disables_headless_block(): void
    {
        $config = new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10]);
        $mock = new MockHandler([
            new Response(200, [], json_encode(['valid' => true])),
            new Response(200, [], json_encode(['whitelistedMachines' => [], 'detectionFlags' => ['enableHeadlessCheck' => false], 'skipPaths' => []])),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $api = new ApiClient($config, $http);
        $core = new Core($config, $api, new Pow($config), new ConfigCache($api));

        $result = $core->evaluate(['path' => '/x', 'ua' => 'curl/7.68', 'ip' => '1.2.3.4', 'sgOk' => (new Pow($config))->sgOkValue('1.2.3.4', 'curl/7.68')]);
        $this->assertNull($result);
    }

    public function test_headless_block_posts_event_with_reason(): void
    {
        $config = new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10]);
        $mock = new MockHandler([
            new Response(200, [], json_encode(['valid' => true])),
            new Response(200, [], json_encode(['whitelistedMachines' => [], 'detectionFlags' => [], 'skipPaths' => []])),
            new Response(200, [], '{}'),
        ]);
        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $http = new Client(['handler' => $stack]);
        $api = new ApiClient($config, $http);
        $core = new Core($config, $api, new Pow($config), new ConfigCache($api));

        $result = $core->evaluate(['path' => '/x', 'ua' => 'curl/7.68', 'ip' => '1.2.3.4', 'sgOk' => (new Pow($config))->sgOkValue('1.2.3.4', 'curl/7.68')]);
        $this->assertEquals(403, $result['status']);
        $event = null;
        foreach ($history as $h) {
            if (str_ends_with($h['request']->getUri()->getPath(), '/event')) {
                $event = $h;
                break;
            }
        }
        $this->assertNotNull($event);
        $body = json_decode((string)$event['request']->getBody(), true);
        $this->assertEquals('headless', $body['reason']);
        $this->assertArrayNotHasKey('type', $body);
    }
}
