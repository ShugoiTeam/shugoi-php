<?php

namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\Middleware;
use Shugoi\Config;
use Shugoi\Core;
use Shugoi\ApiClient;
use Shugoi\ConfigCache;
use Shugoi\GuardCache;
use Shugoi\GuardInjector;
use Shugoi\CspBuilder;
use Shugoi\HtmlStore;
use Shugoi\TokenSigner;
use Shugoi\Pow;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Client;

class MiddlewareTest extends TestCase
{
    private function createMiddleware(array $configOverrides = [], int $queueSize = 2): Middleware
    {
        $config = new Config(array_merge([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
            'powDifficulty' => 10,
        ], $configOverrides));

        $responses = [];
        $responses[] = new Response(200, [], json_encode([
            'whitelistedMachines' => [],
            'detectionFlags' => [],
            'skipPaths' => [],
        ]));
        $responses[] = new Response(200, [], json_encode(['valid' => true]));
        while (count($responses) < $queueSize) {
            $responses[] = new Response(200, [], json_encode([
                'whitelistedMachines' => [],
                'detectionFlags' => [],
                'skipPaths' => [],
            ]));
        }
        $mockHandler = new MockHandler($responses);
        $http = new Client(['handler' => HandlerStack::create($mockHandler)]);
        $api = new ApiClient($config, $http);
        $configCache = new ConfigCache($api);
        $guardCache = new GuardCache($api);
        $htmlStore = new HtmlStore();
        $tokenSigner = new TokenSigner($config);
        $cspBuilder = new CspBuilder($config);
        $core = new Core($config, $api, new Pow($config), $configCache);
        $injector = new GuardInjector($config, $tokenSigner, $htmlStore, $guardCache, $configCache);

        return new Middleware(
            config: $config,
            core: $core,
            api: $api,
            configCache: $configCache,
            guardCache: $guardCache,
            htmlStore: $htmlStore,
            cspBuilder: $cspBuilder,
            injector: $injector,
            tokenSigner: $tokenSigner,
            pow: new Pow($config),
        );
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

    public function test_render_endpoint_returns_json(): void
    {
        $middleware = $this->createMiddleware();
        $request = new ServerRequest('GET', '/__shugoi/render?token=test123');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $middleware->process($request, $handler);
        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_render_endpoint_rejects_post_405(): void
    {
        $middleware = $this->createMiddleware();
        $request = new ServerRequest('POST', '/__shugoi/render');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $middleware->process($request, $handler);
        $this->assertEquals(405, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertEquals('method_not_allowed', $body['error']);
    }

    public function test_allowlisted_path_passes_through(): void
    {
        $middleware = $this->createMiddleware();
        $request = new ServerRequest('GET', '/api/test');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn(new Psr7Response(200, [], '<html>OK</html>'));
        $response = $middleware->process($request, $handler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_headless_ua_gets_blocked(): void
    {
        $middleware = $this->createMiddleware();
        $request = new ServerRequest('GET', '/');
        $request = $request->withHeader('User-Agent', 'curl/7.68');
        $request = $request->withHeader('Cookie', '__sg_ok=' . (new Pow(new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10])))->sgOkValue('', 'curl/7.68'));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');
        $response = $middleware->process($request, $handler);
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertStringContainsString('Shugoi', (string)$response->getBody());
    }

    public function test_browser_gets_307_challenge_without_proof(): void
    {
        $middleware = $this->createMiddleware();
        $request = new ServerRequest('GET', '/');
        $request = $request->withHeader('User-Agent', 'Mozilla/5.0 Chrome/120');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');
        $response = $middleware->process($request, $handler);
        $this->assertEquals(307, $response->getStatusCode());
        $this->assertStringContainsString('/__sg_challenge', $response->getHeaderLine('Location'));
    }

    public function test_browser_with_proof_passes_and_gets_ok_cookie(): void
    {
        $middleware = $this->createMiddleware([], 5);
        $config = new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10]);
        $proof = $this->solvePow($config, 10);
        $request = new ServerRequest('GET', '/?sg_proof=' . urlencode($proof));
        $request = $request
            ->withHeader('User-Agent', 'Mozilla/5.0 Chrome/120')
            ->withHeader('Accept-Language', 'en-US')
            ->withHeader('Sec-Fetch-Dest', 'document')
            ->withHeader('Sec-Fetch-Mode', 'navigate');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Psr7Response(200, ['Content-Type' => 'text/html'], '<html><body>Hello</body></html>'));
        $response = $middleware->process($request, $handler);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('__sg_ok=', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_csp_header_is_set(): void
    {
        $middleware = $this->createMiddleware();
        $request = new ServerRequest('GET', '/api/test');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Psr7Response(200, [], ''));
        $response = $middleware->process($request, $handler);
        $this->assertStringContainsString("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function test_csp_merges_with_existing_header(): void
    {
        $middleware = $this->createMiddleware();
        $request = new ServerRequest('GET', '/api/test');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Psr7Response(200, ['Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'"], ''));
        $response = $middleware->process($request, $handler);
        $csp = $response->getHeaderLine('Content-Security-Policy');
        $this->assertMatchesRegularExpression('/frame-ancestors \'none\'/', $csp);
    }

    public function test_skip_path_outside_allowlist_passes_without_challenge(): void
    {
        $config = new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10]);
        $mock = new MockHandler([
            new Response(200, [], json_encode(['whitelistedMachines' => [], 'detectionFlags' => [], 'skipPaths' => ['/docs']])),
            new Response(200, [], json_encode(['valid' => true])),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $api = new ApiClient($config, $http);
        $configCache = new ConfigCache($api);
        $guardCache = new GuardCache($api);
        $htmlStore = new HtmlStore();
        $tokenSigner = new TokenSigner($config);
        $cspBuilder = new CspBuilder($config);
        $core = new Core($config, $api, new Pow($config), $configCache);
        $injector = new GuardInjector($config, $tokenSigner, $htmlStore, $guardCache, $configCache);
        $middleware = new Middleware($config, $core, $api, $configCache, $guardCache, $htmlStore, $cspBuilder, $injector, $tokenSigner, new Pow($config));

        $request = new ServerRequest('GET', '/docs');
        $request = $request->withHeader('User-Agent', 'Mozilla/5.0 Chrome/120');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn(new Psr7Response(200, ['Content-Type' => 'text/html'], '<html><body>docs</body></html>'));
        $response = $middleware->process($request, $handler);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('docs', (string)$response->getBody());
    }
}
