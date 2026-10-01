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
use Shugoi\RenderService;
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

    public function test_browser_gets_websocket_challenge_without_admission_cookie(): void
    {
        $middleware = $this->createMiddleware();
        $request = new ServerRequest('GET', '/');
        $request = $request->withHeader('User-Agent', 'Mozilla/5.0 Chrome/120');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');
        $response = $middleware->process($request, $handler);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('/__sg_challenge/ws', str_replace('\\/', '/', (string)$response->getBody()));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function test_browser_with_websocket_receipt_gets_cookie_and_preserves_clean_query(): void
    {
        $middleware = $this->createMiddleware([], 5);
        $config = new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10]);
        $receipt = (new \Shugoi\PowReceipt($config))->issue('', 'Mozilla/5.0 Chrome/120');
        $request = new ServerRequest('GET', 'https://example.test/?x=1&sg_receipt=' . urlencode($receipt) . '&x=2&q=%2F%20');
        $request = $request
            ->withQueryParams(['sg_receipt' => $receipt, 'x' => '2', 'q' => '/ '])
            ->withHeader('User-Agent', 'Mozilla/5.0 Chrome/120')
            ->withHeader('Accept-Language', 'en-US')
            ->withHeader('Sec-Fetch-Dest', 'document')
            ->withHeader('Sec-Fetch-Mode', 'navigate');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');
        $response = $middleware->process($request, $handler);
        $this->assertEquals(303, $response->getStatusCode());
        $this->assertSame('/?x=1&x=2&q=%2F%20', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('__sg_ok=', $response->getHeaderLine('Set-Cookie'));
        $this->assertStringContainsString('; Secure', $response->getHeaderLine('Set-Cookie'));
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

    private function isolatedMiddleware(?GuardInjector $injector = null, ?RenderService $renderService = null): Middleware
    {
        $config = new Config(['siteKey' => 'sg_sk_test_isolated', 'secret' => 'test_secret']);
        $api = $this->createMock(ApiClient::class);
        $core = $this->createMock(Core::class);
        $core->method('evaluate')->willReturn(null);
        $cache = $this->createMock(ConfigCache::class);
        $cache->method('get')->willReturn(['skipPaths' => []]);
        return new Middleware($config, $core, $api, $cache, new GuardCache($api), new HtmlStore(), new CspBuilder($config), $injector ?? $this->createMock(GuardInjector::class), new TokenSigner($config), new Pow($config), $renderService);
    }

    public function test_replaces_stream_without_leaking_suffix_and_uses_same_origin_render_url(): void
    {
        $injector = $this->createMock(GuardInjector::class);
        $injector->expects($this->once())->method('inject')
            ->with($this->anything(), '/', '', '', 'example.com', null, '/__shugoi/render')
            ->willReturn('<html>guard</html>');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $original = new Psr7Response(200, ['Content-Type' => 'text/html', 'Content-Length' => '1000', 'ETag' => 'private'], '<html>' . str_repeat('private-content', 50) . '</html>');
        $handler->method('handle')->willReturn($original);
        $response = $this->isolatedMiddleware($injector)->process(new ServerRequest('GET', 'https://example.com/'), $handler);
        $this->assertSame('<html>guard</html>', (string)$response->getBody());
        $this->assertStringContainsString('private-content', (string)$original->getBody());
        $this->assertFalse($response->hasHeader('Content-Length'));
        $this->assertFalse($response->hasHeader('ETag'));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function test_injection_failure_never_returns_unprotected_html(): void
    {
        $injector = $this->createMock(GuardInjector::class);
        $injector->method('inject')->willThrowException(new \RuntimeException('guard unavailable'));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Psr7Response(200, ['Content-Type' => 'text/html'], '<html>private-content</html>'));
        $response = $this->isolatedMiddleware($injector)->process(new ServerRequest('GET', '/'), $handler);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('private-content', (string)$response->getBody());
    }

    public function test_head_render_does_not_consume_token_or_return_html(): void
    {
        $config = new Config(['siteKey' => 'test', 'secret' => 'test-secret']);
        $store = $this->createMock(HtmlStore::class);
        $store->expects($this->never())->method('consume');
        $render = new RenderService($config, $store, new TokenSigner($config), new ConfigCache($this->createMock(ApiClient::class)));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');
        $response = $this->isolatedMiddleware(renderService: $render)->process((new ServerRequest('HEAD', '/__shugoi/render'))->withQueryParams(['token' => 'token', 'grant' => 'grant', 'mid' => 'mid']), $handler);
        $this->assertSame('', (string)$response->getBody());
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function test_render_array_parameters_are_rejected_without_cast_warnings(): void
    {
        $response = $this->isolatedMiddleware()->process((new ServerRequest('GET', '/__shugoi/render'))->withQueryParams(['token' => [], 'mid' => [], 'grant' => []]), $this->createMock(RequestHandlerInterface::class));
        $this->assertSame(['error' => 'not_found'], json_decode((string)$response->getBody(), true));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function test_metadata_response_replaces_stream_without_non_psr_truncate(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Psr7Response(200, ['Content-Type' => 'text/html'], '<html><head><title>Public title</title></head><body>' . str_repeat('private-content', 100) . '</body></html>'));
        $response = $this->isolatedMiddleware()->process(new ServerRequest('GET', 'https://example.com/', ['User-Agent' => 'Discordbot']), $handler);
        $this->assertStringContainsString('Public title', (string)$response->getBody());
        $this->assertStringNotContainsString('private-content', (string)$response->getBody());
    }

    public function test_render_url_respects_application_base_path(): void
    {
        $injector = $this->createMock(GuardInjector::class);
        $injector->expects($this->once())->method('inject')
            ->with($this->anything(), '/app/page', '', '', '', null, '/app/__shugoi/render')
            ->willReturn('<html>guard</html>');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Psr7Response(200, ['Content-Type' => 'text/html'], '<html>private-content</html>'));
        $this->isolatedMiddleware($injector)->process((new ServerRequest('GET', '/app/page'))->withAttribute('shugoi.basePath', '/app'), $handler);
    }

    public function test_protected_streaming_html_fails_closed(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Psr7Response(200, ['Content-Type' => 'text/html', 'X-Shugoi-Unbuffered' => '1']));
        $response = $this->isolatedMiddleware()->process(new ServerRequest('GET', '/'), $handler);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('buffered HTML', (string)$response->getBody());
        $this->assertFalse($response->hasHeader('X-Shugoi-Unbuffered'));
    }

    public function test_non_html_streams_pass_through(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Psr7Response(200, ['Content-Type' => 'text/event-stream', 'X-Shugoi-Unbuffered' => '1']));
        $response = $this->isolatedMiddleware()->process(new ServerRequest('GET', '/'), $handler);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('X-Shugoi-Unbuffered'));
    }
}
