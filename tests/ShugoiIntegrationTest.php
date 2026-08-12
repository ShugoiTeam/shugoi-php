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

class ShugoiIntegrationTest extends TestCase
{
    private function createFullStack(int $responses = 4): Middleware
    {
        $config = new Config([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
            'powDifficulty' => 10,
        ]);
        $queue = [];
        $whitelist = ['whitelistedMachines' => [], 'detectionFlags' => [], 'skipPaths' => []];
        // Parité middleware.ts : le check skipPaths (GET /whitelist) est AVANT
        // core.evaluate (donc avant validate-key).
        $queue[] = new Response(200, [], json_encode($whitelist));
        $queue[] = new Response(200, [], json_encode(['valid' => true]));
        while (count($queue) < $responses) {
            $queue[] = new Response(200, [], count($queue) % 2 === 0 ? 'console.log("detect");' : json_encode($whitelist));
        }
        $mockHandler = new MockHandler($queue);
        $http = new Client(['handler' => HandlerStack::create($mockHandler)]);
        $api = new ApiClient($config, $http);
        $configCache = new ConfigCache($api);
        $guardCache = new GuardCache($api);
        $htmlStore = new HtmlStore();
        $tokenSigner = new TokenSigner($config);
        $cspBuilder = new CspBuilder($config);
        $core = new Core($config, $api, new Pow($config), $configCache);
        $injector = new GuardInjector($config, $tokenSigner, $htmlStore, $guardCache, $configCache);
        return new Middleware($config, $core, $api, $configCache, $guardCache, $htmlStore, $cspBuilder, $injector, $tokenSigner, new Pow($config));
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

    public function test_full_flow_browser_passes(): void
    {
        $middleware = $this->createFullStack();
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
        $this->assertStringContainsString('default-src', $response->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString('window.__sg_siteKey=', (string)$response->getBody());
        $this->assertStringNotContainsString('eval(', (string)$response->getBody());
    }

    public function test_full_flow_curl_blocked(): void
    {
        $middleware = $this->createFullStack(2);
        $request = new ServerRequest('GET', '/');
        $request = $request->withHeader('User-Agent', 'curl/7.68');
        $request = $request->withHeader('Cookie', '__sg_ok=' . (new Pow(new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10])))->sgOkValue('', 'curl/7.68'));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');
        $response = $middleware->process($request, $handler);
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_full_flow_render_with_grant_serves_html_with_notice(): void
    {
        $middleware = $this->createFullStack();
        $config = new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10]);
        $proof = $this->solvePow($config, 10);

        // 1. Navigation → skeleton + token stocké.
        $request = new ServerRequest('GET', '/?sg_proof=' . urlencode($proof));
        $request = $request->withHeader('User-Agent', 'Mozilla/5.0 Chrome/120');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Psr7Response(200, ['Content-Type' => 'text/html'], '<html><head></head><body>Hello</body></html>'));
        $response = $middleware->process($request, $handler);
        $this->assertEquals(200, $response->getStatusCode());
        $skeleton = (string)$response->getBody();

        preg_match('/window\.__sg_token="([^"]+)"/', $skeleton, $tm);
        $token = $tm[1] ?? null;
        $this->assertNotNull($token);

        // 2. Render avec grant valide.
        $mid = str_repeat('a', 64);
        $ts = time();
        $ts36 = base_convert((string)$ts, 10, 36);
        $sig = hash_hmac('sha256', 'render-grant:sg_sk_test_abc:' . $mid . ':' . $token . ':127.0.0.1:' . $ts36, 'test_secret');
        $grant = $ts36 . ':' . $sig;

        $render = new ServerRequest('GET', '/__shugoi/render?token=' . urlencode($token) . '&mid=' . $mid . '&grant=' . urlencode($grant), [], null, '1.1', ['REMOTE_ADDR' => '127.0.0.1']);
        $renderHandler = $this->createMock(RequestHandlerInterface::class);
        $response = $middleware->process($render, $renderHandler);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('__sg_authorized=', $response->getHeaderLine('Set-Cookie'));
        $data = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('html', $data);
        $this->assertStringContainsString('Hello', $data['html']);
        // Notice injectée (base URL + overlay) + referrer meta.
        $this->assertStringContainsString('__sg_o', $data['html']);
        $this->assertStringContainsString('name="referrer" content="strict-origin-when-cross-origin"', $data['html']);
    }

    public function test_render_without_grant_is_not_found(): void
    {
        $middleware = $this->createFullStack();
        $config = new Config(['siteKey' => 'sg_sk_test_abc', 'secret' => 'test_secret', 'powDifficulty' => 10]);
        $proof = $this->solvePow($config, 10);
        $request = new ServerRequest('GET', '/?sg_proof=' . urlencode($proof));
        $request = $request->withHeader('User-Agent', 'Mozilla/5.0 Chrome/120');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Psr7Response(200, ['Content-Type' => 'text/html'], '<html><body>Hello</body></html>'));
        $middleware->process($request, $handler);

        $render = new ServerRequest('GET', '/__shugoi/render?token=abcdef1234567890abcdef');
        $renderHandler = $this->createMock(RequestHandlerInterface::class);
        $response = $middleware->process($render, $renderHandler);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('not_found', $data['error']);
        $this->assertEquals('', $response->getHeaderLine('Set-Cookie'));
    }
}
