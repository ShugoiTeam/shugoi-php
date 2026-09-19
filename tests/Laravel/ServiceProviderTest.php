<?php
namespace Shugoi\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shugoi\Laravel\ShugoiServiceProvider;
use Shugoi\Config;
use Shugoi\Core;
use Shugoi\Middleware;
use Shugoi\RenderService;
use Shugoi\Laravel\ShugoiController;
use Illuminate\Http\Request;

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
    }
}
