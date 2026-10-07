<?php
namespace Shugoi\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shugoi\Laravel\ShugoiServiceProvider;
use Shugoi\Config;
use Shugoi\Core;
use Shugoi\Middleware;
use Shugoi\RenderService;
use Shugoi\Laravel\ShugoiController;
use Shugoi\Laravel\ShugoiMiddleware;
use Shugoi\HtmlStore;
use Shugoi\SkeletonGenerator;
use Shugoi\GuardInjector;
use Shugoi\Pow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ShugoiServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('shugoi.siteKey', 'sg_sk_test_laravel');
        $app['config']->set('shugoi.secret', 'test_secret');
    }

    public function test_config_is_bound()
    {
        $config = $this->app->make(Config::class);
        $this->assertInstanceOf(Config::class, $config);
        $this->assertEquals('sg_sk_test_laravel', $config->siteKey);
    }

    public function test_core_is_bound()
    {
        $core = $this->app->make(Core::class);
        $this->assertInstanceOf(Core::class, $core);
    }

    public function test_websocket_transport_settings_are_exposed_to_laravel(): void
    {
        $this->assertSame('http', $this->app->make(Config::class)->browserTransport);
        $this->assertArrayHasKey('shugoi:websocket', Artisan::all());
    }

    public function test_laravel_uses_shared_render_service_and_middleware(): void
    {
        $this->assertInstanceOf(RenderService::class, $this->app->make(RenderService::class));
        $this->assertInstanceOf(Middleware::class, $this->app->make(Middleware::class));
        $this->assertSame($this->app->make(RenderService::class), $this->app->make(RenderService::class));
    }

    public function test_laravel_render_controller_uses_the_shared_contract(): void
    {
        $response = $this->app->make(ShugoiController::class)->render(
            Request::create('/__shugoi/render?token=invalid', 'GET')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['error' => 'not_found'], $response->getData(true));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_skeleton_and_injector_bindings_resolve(): void
    {
        $this->assertInstanceOf(SkeletonGenerator::class, $this->app->make(SkeletonGenerator::class));
        $this->assertInstanceOf(GuardInjector::class, $this->app->make(GuardInjector::class));
    }

    public function test_framework_defaults_keep_detection_and_share_html_between_instances(): void
    {
        $config = $this->app->make(Config::class);
        $this->assertTrue($config->multiProcess);
        $this->assertSame(Config::DEFAULT_HEADLESS_PATTERNS, $config->headlessPatterns);
        $this->assertSame(Config::DEFAULT_BOT_WHITELIST, $config->botWhitelist);
        $token = 'sg_sk_test_laravel:' . bin2hex(random_bytes(16));
        $store = $this->app->make(HtmlStore::class);
        $store->store($token, '<html>saved response</html>', 120_000, 1, true);
        $this->app->forgetInstance(HtmlStore::class);
        $nextRequestStore = $this->app->make(HtmlStore::class);
        $this->assertNotSame($store, $nextRequestStore);
        $this->assertSame('<html>saved response</html>', $nextRequestStore->retrieve($token)['html']);
        $nextRequestStore->remove($token);
    }

    public function test_adapter_keeps_query_original_request_and_all_response_cookies(): void
    {
        $request = Request::create('https://example.test/profile?sg_proof=proof&token=token&mid=mid&grant=grant', 'GET', [], ['session-cookie' => 'session-value']);
        $request->attributes->set('bound-model', 'bound-value');
        $request->setLaravelSession(new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(120)));
        $request->session()->put('authenticated-user', '123');
        $route = new \Illuminate\Routing\Route('GET', 'profile', fn() => null);
        $request->setRouteResolver(fn() => $route);
        $downstreamResponse = response('profile', 200)->withCookie(cookie('existing', 'retained'));
        $psrMiddleware = $this->createMock(Middleware::class);
        $psrMiddleware->expects($this->once())->method('process')->willReturnCallback(function ($psrRequest, $handler) {
            $this->assertSame('proof', $psrRequest->getQueryParams()['sg_proof']);
            $this->assertSame('token', $psrRequest->getQueryParams()['token']);
            $this->assertSame('mid', $psrRequest->getQueryParams()['mid']);
            $this->assertSame('grant', $psrRequest->getQueryParams()['grant']);
            $this->assertSame('session-value', $psrRequest->getCookieParams()['session-cookie']);
            return $handler->handle($psrRequest)->withAddedHeader('Set-Cookie', '__sg_authorized=proof; Path=/; HttpOnly');
        });
        $response = (new ShugoiMiddleware($psrMiddleware))->handle($request, function ($actual) use ($request, $route, $downstreamResponse) {
            $this->assertSame($request, $actual);
            $this->assertSame($route, $actual->route());
            $this->assertSame('bound-value', $actual->attributes->get('bound-model'));
            $this->assertSame('123', $actual->session()->get('authenticated-user'));
            return $downstreamResponse;
        });
        $this->assertSame($downstreamResponse, $response);
        $cookies = array_map(fn($cookie) => $cookie->getName(), $response->headers->getCookies());
        $this->assertContains('existing', $cookies);
        $this->assertContains('__sg_authorized', $cookies);
    }

    public function test_controller_head_does_not_consume_saved_html(): void
    {
        $store = $this->createMock(HtmlStore::class);
        $store->expects($this->never())->method('consume');
        $this->app->instance(HtmlStore::class, $store);
        $response = $this->app->make(ShugoiController::class)->render(Request::create('/__shugoi/render?token=token', 'HEAD'));
        $this->assertSame('', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_controller_rejects_array_parameters_without_warnings(): void
    {
        $response = $this->app->make(ShugoiController::class)->render(Request::create('/__shugoi/render?token[]=x&mid[]=x&grant[]=x'));
        $this->assertSame(['error' => 'not_found'], $response->getData(true));
    }
}
