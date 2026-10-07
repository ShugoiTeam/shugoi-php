<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No additional services are needed for the demo.
    }

    public function boot(): void
    {
        // Shugoi's package service provider is registered by Composer discovery.
    }
}
