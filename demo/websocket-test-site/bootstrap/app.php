<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Shugoi\Laravel\ShugoiMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__ . '/../routes/web.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(ShugoiMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Keep Laravel's default exception handling for this demo.
    })
    ->create();
