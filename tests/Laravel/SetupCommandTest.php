<?php
namespace Shugoi\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shugoi\ApiClient;
use Shugoi\Laravel\ShugoiServiceProvider;

class SetupCommandTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ShugoiServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('shugoi.siteKey', 'sg_sk_test_setup');
        $app['config']->set('shugoi.secret', 'test_secret');
    }

    public function test_setup_fails_when_a_valid_key_cannot_fetch_guards(): void
    {
        $api = $this->createMock(ApiClient::class);
        $api->method('validateKey')->willReturn(['valid' => true]);
        $api->method('fetchGuardDetect')->willThrowException(new \RuntimeException('signed URL must not be printed', 403));
        $api->expects($this->never())->method('fetchGuard');
        $this->app->instance(ApiClient::class, $api);

        $this->artisan('shugoi:setup')
            ->expectsOutputToContain('Could not fetch guard scripts (error 403).')
            ->doesntExpectOutputToContain('signed URL must not be printed')
            ->assertExitCode(1);
    }

    public function test_setup_succeeds_after_fetching_both_guards(): void
    {
        $api = $this->createMock(ApiClient::class);
        $api->method('validateKey')->willReturn(['valid' => true]);
        $api->expects($this->once())->method('fetchGuardDetect')->willReturn('detect');
        $api->expects($this->once())->method('fetchGuard')->willReturn('guard');
        $this->app->instance(ApiClient::class, $api);

        $this->artisan('shugoi:setup')->assertExitCode(0);
    }
}
