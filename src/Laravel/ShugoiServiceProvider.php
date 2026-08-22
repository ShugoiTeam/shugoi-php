<?php
declare(strict_types=1);
namespace Shugoi\Laravel;

use Illuminate\Support\ServiceProvider;
use Shugoi\Config;
use Shugoi\Core;
use Shugoi\ApiClient;
use Shugoi\ConfigCache;
use Shugoi\GuardCache;
use Shugoi\HtmlStore;
use Shugoi\CspBuilder;
use Shugoi\GuardInjector;
use Shugoi\TokenSigner;
use Shugoi\SkeletonGenerator;
use Shugoi\BotVerifier;
use Shugoi\Pow;
use Shugoi\Middleware;
use Shugoi\ScriptTags;
use Shugoi\Laravel\Commands\ShugoiSetupCommand;
use Shugoi\Laravel\Commands\ShugoiCheckCommand;

class ShugoiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/shugoi.php', 'shugoi');

        $this->app->singleton(Config::class, fn($app) => new Config($app['config']['shugoi']));
        $this->app->singleton(ApiClient::class, fn($app) => new ApiClient($app->make(Config::class)));
        $this->app->singleton(ConfigCache::class, fn($app) => new ConfigCache($app->make(ApiClient::class)));
        $this->app->singleton(GuardCache::class, fn($app) => new GuardCache($app->make(ApiClient::class)));
        $this->app->singleton(HtmlStore::class, fn($app) => new HtmlStore($app->make(Config::class)->multiProcess));
        $this->app->singleton(TokenSigner::class, fn($app) => new TokenSigner($app->make(Config::class)));
        $this->app->singleton(Pow::class, fn($app) => new Pow($app->make(Config::class)));
        $this->app->singleton(CspBuilder::class, fn($app) => new CspBuilder($app->make(Config::class)));
        $this->app->singleton(SkeletonGenerator::class, fn($app) => new SkeletonGenerator(
            $app->make(TokenSigner::class),
            $app->make(Obfuscator::class),
        ));
        $this->app->singleton(Core::class, function ($app) {
            $config = $app->make(Config::class);
            $api = $app->make(ApiClient::class);
            $configCache = $app->make(ConfigCache::class);
            $botVerifier = $config->verifyBots ? new BotVerifier() : null;
            return new Core($config, $api, $app->make(Pow::class), $configCache, $botVerifier);
        });
        $this->app->singleton(GuardInjector::class, fn($app) => new GuardInjector(
            $app->make(Config::class),
            $app->make(TokenSigner::class),
            $app->make(HtmlStore::class),
            $app->make(GuardCache::class),
            $app->make(ConfigCache::class),
        ));
        $this->app->singleton(Middleware::class, function ($app) {
            $c = $app->make(Config::class);
            return new Middleware(
                config: $c,
                core: $app->make(Core::class),
                api: $app->make(ApiClient::class),
                configCache: $app->make(ConfigCache::class),
                guardCache: $app->make(GuardCache::class),
                htmlStore: $app->make(HtmlStore::class),
                cspBuilder: $app->make(CspBuilder::class),
                injector: $app->make(GuardInjector::class),
                tokenSigner: $app->make(TokenSigner::class),
                pow: $app->make(Pow::class),
            );
        });
        $this->app->singleton(ScriptTags::class, function ($app) {
            return new ScriptTags(
                $app->make(Config::class),
                $app->make(TokenSigner::class),
                $app->make(GuardCache::class),
                $app->make(ConfigCache::class),
                $app->make(SkeletonGenerator::class),
            );
        });

        $this->app->alias(Middleware::class, 'shugoi.middleware');
        $this->app->alias(ScriptTags::class, 'shugoi.scripts');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../../config/shugoi.php' => config_path('shugoi.php')], 'shugoi-config');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/shugoi.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ShugoiSetupCommand::class, ShugoiCheckCommand::class]);
        }
    }
}
