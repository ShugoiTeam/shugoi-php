<?php
declare(strict_types=1);

namespace Shugoi\Tests\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as ApiResponse;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Psr\Http\Message\RequestInterface;
use Shugoi\ApiClient;
use Shugoi\Config;
use Shugoi\HtmlStore;
use Shugoi\Laravel\ShugoiMiddleware;
use Shugoi\Laravel\ShugoiServiceProvider;
use Shugoi\Pow;
use Shugoi\PowReceipt;

/** Real Laravel HTTP kernel and web middleware; only the external API transport is stubbed. */
final class HttpProtectionTest extends TestCase
{
    private const USER_AGENT = 'Mozilla/5.0 Chrome/131.0.0.0 Safari/537.36';
    private ?string $testStorage = null;

    protected function getPackageProviders($app): array
    {
        return [ShugoiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->testStorage = sys_get_temp_dir() . '/shugoi-laravel-http-' . bin2hex(random_bytes(10));
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('s', 32)));
        $app['config']->set('session.driver', 'array');
        $app['config']->set('shugoi.siteKey', 'sg_laravel_http_fixture');
        $app['config']->set('shugoi.secret', 'synthetic-laravel-http-secret');
        $app['config']->set('shugoi.baseUrl', 'https://shugoi-api.invalid/api/v1');
        $app['config']->set('shugoi.powDifficulty', 4);
        $app['config']->set('shugoi.renderStorePath', $this->testStorage . '/html');
        $app['config']->set('shugoi.powReceiptStorePath', $this->testStorage . '/receipts');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/protected/{slug?}', static function (string $slug = 'document') {
            return response('<!doctype html><html><head><title>Protected page</title></head><body>private-page-' . $slug
                . ':' . session()->get('fixture-marker', 'no-session') . '</body></html>')
                ->cookie('application_cookie', 'application-cookie-value');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $http = new Client(['handler' => static function (RequestInterface $request) {
            $path = $request->getUri()->getPath();
            $body = match ($path) {
                '/api/v1/whitelist' => json_encode(['skipPaths' => [], 'detectionFlags' => []]),
                '/api/v1/validate-key' => '{"valid":true}',
                '/api/v1/event' => '{"ok":true}',
                '/api/v1/guard-detect' => 'window.__sg_guardsReady=true;',
                '/api/v1/guard' => '',
                default => throw new \LogicException('Unexpected external API endpoint: ' . $path),
            };
            return Create::promiseFor(new ApiResponse(200, [], $body));
        }]);
        $this->app->instance(ApiClient::class, new ApiClient($this->app->make(Config::class), $http));
        $this->app->make(Kernel::class)->pushMiddleware(ShugoiMiddleware::class);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        Request::setTrustedProxies([], 0);
        parent::tearDown();
        if ($this->testStorage !== null) {
            foreach (['html', 'receipts'] as $name) {
                foreach (glob($this->testStorage . '/' . $name . '/*') ?: [] as $file) {
                    if (is_file($file)) unlink($file);
                }
                if (is_dir($this->testStorage . '/' . $name)) rmdir($this->testStorage . '/' . $name);
            }
            if (is_dir($this->testStorage)) rmdir($this->testStorage);
        }
    }

    private function browserHeaders(array $extra = []): array
    {
        return $extra + ['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html', 'Sec-Fetch-Dest' => 'document'];
    }

    private function receipt(string $ip = '127.0.0.1'): string
    {
        return (new PowReceipt($this->app->make(Config::class)))->issue($ip, self::USER_AGENT);
    }

    public function test_curl_is_blocked_by_global_middleware_before_application_html(): void
    {
        $response = $this->get('/protected', ['User-Agent' => 'curl/8.10.1']);
        $response->assertStatus(403)->assertDontSee('private-page', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_browser_receives_websocket_challenge_and_never_application_html(): void
    {
        $response = $this->get('/protected', $this->browserHeaders());
        $response->assertOk()->assertSee('pow-start', false)->assertSee('new WebSocket', false)
            ->assertDontSee('private-page', false)->assertDontSee('sg_proof=', false);
        $this->assertStringContainsString('/__sg_challenge/ws', str_replace('\\/', '/', $response->getContent()));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_websocket_receipt_redirect_preserves_raw_query_and_cookie(): void
    {
        $receipt = $this->receipt();
        $response = $this->get('/protected?x=1&sg_receipt=' . urlencode($receipt) . '&x=2&q=%2f%20', $this->browserHeaders());
        $response->assertStatus(303)->assertHeader('Location', '/protected?x=1&x=2&q=%2f%20');
        $cookies = array_filter($response->headers->getCookies(), static fn($cookie) => $cookie->getName() === '__sg_ok');
        $this->assertCount(1, $cookies);
        $cookie = array_values($cookies)[0];
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue((new Pow($this->app->make(Config::class)))->isSgOkValid($cookie->getValue(), '127.0.0.1', self::USER_AGENT));
        $this->assertSame('', $response->getContent());
    }

    public function test_replayed_receipt_and_http_proof_do_not_admit_browser(): void
    {
        $receipt = $this->receipt();
        $uri = '/protected?sg_receipt=' . urlencode($receipt);
        $this->get($uri, $this->browserHeaders())->assertStatus(303);
        $this->get($uri, $this->browserHeaders())->assertOk()->assertSee('pow-start', false)->assertDontSee('private-page', false);
        $config = $this->app->make(Config::class);
        $pow = new Pow($config);
        $challenge = $pow->challenge();
        for ($solution = 0; ; ++$solution) {
            $proof = $challenge['ts'] . ':' . $challenge['nonce'] . ':' . dechex($solution);
            if ($pow->isValid($proof)) break;
        }
        $this->get('/protected?sg_proof=' . urlencode($proof), $this->browserHeaders())
            ->assertOk()->assertSee('pow-start', false)->assertDontSee('private-page', false);
    }

    public function test_split_render_stores_assets_added_by_late_framework_listeners(): void
    {
        $events = $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class);
        $calls = 0;
        $events->listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, static function ($event) use (&$calls): void {
            ++$calls;
            if (!str_contains($event->response->getContent(), '</head>')) return;
            $event->response->setContent(str_replace('</head>', '<script src="/required-captcha.js"></script></head>', $event->response->getContent()));
            $event->response->headers->set('X-Late-Asset-Listener', 'retained');
        });
        $admission = $this->get('/protected?sg_receipt=' . urlencode($this->receipt()), $this->browserHeaders());
        $cookie = array_values(array_filter($admission->headers->getCookies(), static fn($value) => $value->getName() === '__sg_ok'))[0];
        $browserCookie = explode(';', (string)$cookie, 2)[0];
        $response = $this->get('/protected', $this->browserHeaders(['Cookie' => $browserCookie]));

        $response->assertOk()->assertSee('window.__sg_siteKey=', false)->assertDontSee('private-page', false)
            ->assertDontSee('/required-captcha.js', false)->assertHeader('X-Late-Asset-Listener', 'retained');
        $entry = $this->app->make(HtmlStore::class)->hasFreshToken('sg_laravel_http_fixture', true);
        $this->assertStringContainsString('<script src="/required-captcha.js"></script>', $entry['html']);
        $this->assertSame(1, substr_count($entry['html'], '/required-captcha.js'));
        $this->assertSame(1, substr_count($entry['html'], 'data-shugoi-livewire-navigation'));
        $response->assertDontSee('data-shugoi-livewire-navigation', false);
        $this->assertSame(2, $calls, 'RequestHandled must not be dispatched twice to finalize assets.');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_finalizer_failure_cannot_release_application_html(): void
    {
        $request = Request::create('/protected');
        $response = response('<html>private-failure-document</html>');
        $this->app->make(\Shugoi\Laravel\ResponseFinalizer::class)->defer($request, static function (): void {
            throw new \RuntimeException('synthetic finalization failure');
        });
        $event = new \Illuminate\Foundation\Http\Events\RequestHandled($request, $response);
        $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class)->dispatch($event);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('private-failure-document', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertFalse($request->attributes->has('shugoi.finalizeResponse'));
    }

    public function test_render_flow_preserves_web_session_cookies_and_single_use_storage(): void
    {
        // Cached Laravel configuration remains available even when APP_ENV is not
        // exported into the PHP worker's environment.
        $this->app['config']->set('app.env', 'production');
        $admission = $this->get('/protected/article?sg_receipt=' . urlencode($this->receipt()), $this->browserHeaders());
        $cookie = array_values(array_filter($admission->headers->getCookies(), static fn($value) => $value->getName() === '__sg_ok'))[0];
        // Send the serialized value a browser actually receives, including the
        // percent-encoding applied by Symfony's Set-Cookie serialization.
        $browserCookie = explode(';', (string)$cookie, 2)[0];
        $response = $this->withSession(['fixture-marker' => 'session-kept'])->get('/protected/article', $this->browserHeaders(['Cookie' => $browserCookie]));
        $response->assertOk()->assertSee('window.__sg_siteKey=', false)->assertDontSee('private-page', false);
        $this->assertContains('application_cookie', array_map(static fn($value) => $value->getName(), $response->headers->getCookies()));
        // Read the stored token through the real disk-backed store; the generated
        // browser script remains obfuscated and the store is recreated below.
        $entry = $this->app->make(HtmlStore::class)->hasFreshToken('sg_laravel_http_fixture', true);
        $this->assertNotNull($entry);
        $token = $entry['token'];
        $this->assertStringContainsString('private-page-article:session-kept', $entry['html']);
        foreach ([HtmlStore::class, \Shugoi\RenderService::class, \Shugoi\GuardInjector::class, \Shugoi\Middleware::class, ShugoiMiddleware::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
        $mid = str_repeat('a', 64);
        $timestamp = base_convert((string)time(), 10, 36);
        $grant = $timestamp . ':' . hash_hmac('sha256', 'render-grant:sg_laravel_http_fixture:' . $mid . ':' . $token . ':' . $timestamp, 'synthetic-laravel-http-secret');
        $query = http_build_query(['token' => $token, 'mid' => $mid, 'grant' => $grant]);
        $renderUri = 'https://localhost/__shugoi/render?' . $query;
        $this->get('/__shugoi/render?' . http_build_query(['token' => $token, 'mid' => $mid, 'grant' => 'forged']), $this->browserHeaders())
            ->assertExactJson(['error' => 'not_found']);
        $this->call('HEAD', $renderUri, [], [], [], ['HTTP_USER_AGENT' => self::USER_AGENT])->assertOk()->assertContent('');
        $render = $this->get($renderUri, $this->browserHeaders());
        $render->assertOk()->assertJsonStructure(['html']);
        $this->assertStringContainsString('private-page-article:session-kept', $render->json('html'));
        $this->assertStringContainsString('no-store', $render->headers->get('Cache-Control'));
        $authorizedCookie = array_values(array_filter($render->headers->getCookies(), static fn($value) => $value->getName() === '__sg_authorized'))[0];
        $this->assertTrue($authorizedCookie->isSecure());
        $this->assertTrue($authorizedCookie->isHttpOnly());
        $this->get($renderUri, $this->browserHeaders())->assertExactJson(['error' => 'not_found']);
    }

    public function test_untrusted_forwarded_ip_cannot_rebind_receipt(): void
    {
        $receipt = $this->receipt('198.51.100.10');
        $uri = '/protected?sg_receipt=' . urlencode($receipt);
        $headers = $this->browserHeaders(['X-Forwarded-For' => '198.51.100.10']);
        $this->get($uri, $headers)->assertOk()->assertSee('pow-start', false);
        TrustProxies::at(['127.0.0.1']);
        $this->get($uri, $headers)->assertStatus(303);
    }

    public function test_secure_receipt_sets_a_secure_cookie_behind_configured_proxy(): void
    {
        TrustProxies::at(['127.0.0.1']);
        $response = $this->get('/protected?sg_receipt=' . urlencode($this->receipt('198.51.100.12')), $this->browserHeaders([
            'X-Forwarded-For' => '198.51.100.12', 'X-Forwarded-Proto' => 'https',
        ]));
        $response->assertStatus(303);
        $cookie = array_values(array_filter($response->headers->getCookies(), static fn($value) => $value->getName() === '__sg_ok'))[0];
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue((new Pow($this->app->make(Config::class)))->isSgOkValid($cookie->getValue(), '198.51.100.12', self::USER_AGENT));
    }
}
