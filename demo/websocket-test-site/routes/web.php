<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::get('/protected/{page}', function (string $page) {
    abort_unless(in_array($page, ['alpha', 'beta'], true), 404);

    return response()
        ->view('test-page', [
            'page' => strtoupper($page),
            'requestId' => bin2hex(random_bytes(6)),
            'generatedAt' => now()->toIso8601String(),
        ])
        ->header('Cache-Control', 'no-store, private');
})->name('protected.page');

Route::get('/api/ws-demo-health', static fn () => response()->json([
    'ok' => true,
    'app' => 'shugoi-websocket-test-site',
]))->name('health');
