# Shugoi PHP — Anti-abuse protection for Laravel & PHP

![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4)
![Laravel](https://img.shields.io/badge/Laravel-11%2B-ff2d20)

Shugoi is a full-featured anti-abuse protection layer for PHP applications. It combines edge-level request blocking, client-side browser fingerprinting via Web Workers, split-render technology with single-use tokens, code obfuscation, and multi-layered detection to protect your web apps from bots, scrapers, automated attacks, and fraud.

### Features

- **Edge blocking** — Headless browser detection (curl, wget, python, puppeteer, etc.), fake browser detection (missing Sec-Fetch headers), rate limiting with configurable thresholds
- **Client-side fingerprinting** — Tor Browser detection, VM/machine detection, anti-detect browser detection, headless Chrome/Puppeteer detection via Web Worker
- **Whitelist** — machineId-based whitelist managed from the Shugoi dashboard, bypasses all client-side checks
- **Split-render** — Original HTML is stored server-side with a signed single-use token; the client receives a bootstrap skeleton. Guards run, and if the browser is legitimate, `rd()` fetches the real HTML via the same-origin `/__shugoi/render` endpoint. The PHP package deliberately uses this signed HTTP fallback: a PSR-15 middleware cannot accept a WebSocket upgrade without a separate long-lived server integration.
- **Crawler metadata isolation** — Recognized crawler user agents receive only an escaped document containing the original page's title, description, OpenGraph/Twitter tags and canonical link. The protected page body is never returned to this branch; FCrDNS remains required for any trusted-bot access decision.
- **Content replacement detection** — If the render endpoint is called with an invalid/consumed token and `enableContentReplacementCheck` is enabled, a block card "Remplacement de contenu client détecté" is shown with full neobrutalist styling
- **CSP injection** — Automatic Content-Security-Policy header with proper origins for scripts, fonts, images; optionally extensible via `extraDirectives`
- **Bot verification** — Reverse DNS (FCrDNS) verification for Googlebot, Bingbot, YandexBot, Applebot, etc.
- **Obfuscation** — The bootstrap is obfuscated (function renaming, line shuffling, XOR string encryption and decoder injection) without the retired invisible-eval wrapper
- **Block page** — Full neobrutalist shield page with Alex Brush font, favicon + brand images, pink h2, noise SVG background, countdown timer for rate limits. Consistent ASCII block page for non-browser clients
- **Multi-process support** — Shared disk-based HTML token storage for PHP built-in server, Laravel Octane, or any multi-worker setup
- **PSR-15 middleware** — Framework-agnostic, compatible with any PSR-15 implementation
- **Laravel integration** — ServiceProvider with auto-wiring, HTTP middleware wrapper, Facade, Blade directives (`@shugoiHead`, `@shugoiBody`), Artisan commands (`shugoi:setup`, `shugoi:check`)
- **Localization** — Full FR/EN support for block pages and error messages

## Requirements

- PHP 8.2+
- Laravel 11+ (for Laravel integration)
- Guzzle 7+
- PSR-15 compatible middleware (for standalone usage)

## Installation

```bash
composer require shugoi/shugoi-php
```

## Quick Start (Laravel)

```bash
composer require shugoi/shugoi-php
```

Add to `.env`:
```env
SHUGOI_SITE_KEY=sg_sk_live_xxxx
SHUGOI_SECRET=your_site_secret
```

Add to `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Shugoi\Laravel\ShugoiMiddleware::class);
})
```

Verify:
```bash
php artisan shugoi:setup
```

Done. All HTTP requests are now protected.

## Demo

A complete demo script is available in `demo/setup.sh`:

```bash
cd demo && bash setup.sh
```
This creates a fresh Laravel project with Shugoi pre-configured.

## Quick Start (PSR-15 standalone)

```php
use Shugoi\Config;
use Shugoi\Core;
use Shugoi\ApiClient;
use Shugoi\ConfigCache;
use Shugoi\GuardCache;
use Shugoi\HtmlStore;
use Shugoi\CspBuilder;
use Shugoi\GuardInjector;
use Shugoi\TokenSigner;
use Shugoi\Middleware;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;

$config = new Config([
    'siteKey' => 'sg_sk_live_xxx',
    'secret' => '80a320ee3904...',
    'allowlist' => ['/api', '/__shugoi'],
    'autoInject' => true,
    'csp' => true,
    'verifyBots' => false,
]);

$api = new ApiClient($config);
$configCache = new ConfigCache($api);
$guardCache = new GuardCache($api);
$htmlStore = new HtmlStore('/tmp/shugoi-render-shared');
$tokenSigner = new TokenSigner($config);
$cspBuilder = new CspBuilder($config);
$core = new Core($config, $api, $configCache);
$injector = new GuardInjector($config, $tokenSigner, $htmlStore, $guardCache, $configCache);

$middleware = new Middleware(
    config: $config, core: $core, api: $api,
    configCache: $configCache, guardCache: $guardCache,
    htmlStore: $htmlStore, cspBuilder: $cspBuilder,
    injector: $injector, tokenSigner: $tokenSigner,
);

$request = new ServerRequest('GET', '/', getallheaders(), file_get_contents('php://input'), '1.1', $_SERVER);

$handler = new class($uri) implements \Psr\Http\Server\RequestHandlerInterface {
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html'], '<html><body>OK</body></html>');
    }
};

$response = $middleware->process($request, $handler);
```

### Important: Shared disk store

When using the PHP built-in server (new process per request), the HtmlStore must use a SHARED disk path so tokens persist across requests:

```php
$htmlStore = new HtmlStore('/tmp/shugoi-render-shared');
```

For Laravel with a persistent process (Octane, Swoole), the in-memory store works.

## How it works

### Request flow

1. **Request arrives** → Middleware evaluates the request (UA, headers, IP, rate limits)
2. **If blocked** → Returns `BLOCKED BY SHUGOI` (text for bots) or shield page (HTML for browsers)
3. **If allowed** → Injects guard scripts into the HTML response
4. **Split-render** → Original HTML is stored with a signed token; the client receives an obfuscated bootstrap skeleton
5. **Client boots** → Guards run fingerprinting (Tor, VM, headless, anti-detect checks)
6. **If guards pass** → `rd()` fetches `/__shugoi/render?token=...` to get the real HTML
7. **If guards detect issue** → `__sg_showBlock()` displays a neobrutalist card with block reason

### Content Replacement Check

When `detectionFlags.enableContentReplacementCheck` is:
- **`true`** (default) — If the render token is invalid/expired, the block card "Remplacement de contenu client détecté" is shown
- **`false`** — The render endpoint skips token validation and returns any available stored HTML

## Configuration reference

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `siteKey` | string | **required** | Your Shugoi site key |
| `secret` | string | - | Site secret for key validation |
| `signingSecret` | string | `secret` | HMAC secret for token signing |
| `allowlist` | string[] | `['/api', '/legal']` | Paths bypassing protection |
| `headlessPatterns` | RegExp[] | curl, wget, python, ... | UA patterns to block |
| `botWhitelist` | RegExp[] | Googlebot, Bingbot, ... | Legit bots exempt from blocking |
| `baseUrl` | string | `https://shugoi.com/api/v1` | API base URL |
| `internalUrl` | string | `baseUrl` | Internal URL for server-to-server calls |
| `autoInject` | bool | `true` | Auto-inject guard scripts |
| `csp` | bool | `true` | Enable CSP header |
| `splitRender` | bool | `true` | Enable skeleton/eval injection |
| `multiProcess` | bool | `false` | Disk-based HTML storage for PM2 |
| `verifyBots` | bool | `true` | Reverse DNS bot verification |
| `debug` | bool | `false` | Console logs |
| `restrictedAccess` | bool | `false` | Show restricted block page |
| `blockStatus` | int | `403` | HTTP status for blocks |
| `locale` | string | - | Block page language (`fr`/`en`) |
| `extraDirectives` | array | - | Additional CSP directives |
| `blockPage` | callable | - | Custom block page HTML |

## Internal route

The middleware serves `/__shugoi/render` itself and returns stored HTML only after token, grant, site and expiry validation. Laravel uses the same `RenderService` through its adapter/controller; do not exclude this route from the Shugoi middleware.

For Laravel, the ServiceProvider and adapter handle this route. For standalone PSR-15 usage, keep the middleware in the request chain so it can handle the route before the application handler.

## Testing

```bash
$ curl http://localhost:3100/
+---------------------------------------------+
|           BLOCKED BY SHUGOI                 |
+---------------------------------------------+
|  Bots, scrapers and headless clients        |
|  are blocked by Shugoi protection.          |
|                                             |
|  Use a standard browser to access           |
|  this site.                                 |
|                                             |
|  - contact: support@shugoi.com -            |
+---------------------------------------------+
```

All automated requests (curl, wget, python, etc.) are blocked. Only legitimate browsers with proper fingerprint signals pass through the protection.

## Architecture

```
Request → Middleware → Core::evaluate()
  ├── Blocked → BLOCK_PAGE text or shield HTML + CSP
  └── Allowed → GuardInjector → store HTML → skeleton (eval bootcode)
       └── Browser executes skeleton → guards → rd() → render endpoint → HTML
```

## License

MIT
