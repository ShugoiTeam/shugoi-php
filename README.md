# Shugoi PHP — Anti-abuse protection for Laravel & PHP

![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4)
![Laravel](https://img.shields.io/badge/Laravel-11%2B-ff2d20)

Shugoi is a full-featured anti-abuse protection layer for PHP applications. It combines edge-level request blocking, client-side browser fingerprinting via Web Workers, split-render technology with single-use tokens, code obfuscation, and multi-layered detection to protect your web apps from bots, scrapers, automated attacks, and fraud.

### Features

- **Edge blocking** — Headless browser detection (curl, wget, python, puppeteer, etc.), fake browser detection (missing Sec-Fetch headers), rate limiting with configurable thresholds
- **Client-side fingerprinting** — Tor Browser detection, VM/machine detection, anti-detect browser detection, headless Chrome/Puppeteer detection via Web Worker
- **Whitelist** — machineId-based whitelist managed from the Shugoi dashboard, bypasses all client-side checks
- **Split-render** - Original HTML stays server-side behind an exact, single-use signed token. Browser detection exchanges use WebSocket; a signed grant releases HTML through same-origin HTTP by default, or through the optional WebSocket render sidecar.
- **WebSocket proof of work** - A separate PHP CLI gateway issues and verifies the preflight challenge. The browser receives a signed receipt bound to its IP and user agent; PHP consumes it once before setting its admission cookie.
- **Crawler metadata isolation** — Recognized crawler user agents receive only an escaped document containing the original page's title, description, OpenGraph/Twitter tags and canonical link. The protected page body is never returned to this branch; FCrDNS remains required for any trusted-bot access decision.
- **Content replacement detection** — If the render endpoint is called with an invalid/consumed token and `enableContentReplacementCheck` is enabled, a block card "Remplacement de contenu client détecté" is shown with full neobrutalist styling
- **CSP injection** — Automatic Content-Security-Policy header with proper origins for scripts, fonts, images; optionally extensible via `extraDirectives`
- **Bot verification** — Reverse DNS (FCrDNS) verification for Googlebot, Bingbot, YandexBot, Applebot, etc.
- **Obfuscation** — The bootstrap is obfuscated (function renaming, line shuffling, XOR string encryption and decoder injection) without the retired invisible-eval wrapper
- **Block page** — Shugoi-styled shield page with browser-matched background palettes, brand images, Reggae One display font, and a countdown for rate limits. Consistent plain-text block page for non-browser clients
- **Multi-process support** — Shared disk-based HTML token storage for PHP built-in server, Laravel Octane, or any multi-worker setup
- **PSR-15 middleware** — Framework-agnostic, compatible with any PSR-15 implementation
- **Laravel integration** — ServiceProvider with auto-wiring, HTTP middleware wrapper, Facade, Blade directives (`@shugoiHead`, `@shugoiBody`), and Artisan commands (`shugoi:setup`, `shugoi:check`, `shugoi:websocket`)
- **Localization** — Full FR/EN support for block pages and error messages

## Requirements

- PHP 8.2+
- Laravel 11+ (for Laravel integration)
- OpenSwoole PHP extension (only when using the optional render WebSocket sidecar)
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

Configure and start the [PHP WebSocket gateway](docs/websocket-gateway.md), including the Nginx upgrade route and matching environment. The protected browser flow requires this service; PHP-FPM alone does not accept WebSocket upgrades.

Laravel uses this same package and PoW gateway; no separate Laravel SDK is needed.
Follow the [Laravel deployment notes](docs/websocket-gateway.md#laravel-deployment)
for middleware order, private storage, cached configuration and gateway secrets.

The same-origin render WebSocket is an optional transport. Enable it with
`SHUGOI_BROWSER_TRANSPORT=websocket`, `SHUGOI_RENDER_STORE=disk`,
`SHUGOI_RENDER_STORE_PATH`, and `SHUGOI_PUBLIC_ORIGIN`, then run
`php artisan shugoi:websocket` behind a TLS reverse proxy. The
[Laravel WebSocket demo](demo/websocket-test-site/README.md) includes a
configuration example.

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
    'secret' => getenv('SHUGOI_SECRET'),
    'allowlist' => ['/api'],
    'autoInject' => true,
    'csp' => true,
    'verifyBots' => true,
]);

$api = new ApiClient($config);
$configCache = new ConfigCache($api);
$guardCache = new GuardCache($api);
$htmlStore = new HtmlStore('/tmp/shugoi-render-shared');
$tokenSigner = new TokenSigner($config);
$cspBuilder = new CspBuilder($config);
$pow = new \Shugoi\Pow($config);
$core = new Core($config, $api, $pow, $configCache, new \Shugoi\BotVerifier());
$injector = new GuardInjector($config, $tokenSigner, $htmlStore, $guardCache, $configCache);

$middleware = new Middleware(
    config: $config, core: $core, api: $api,
    configCache: $configCache, guardCache: $guardCache,
    htmlStore: $htmlStore, cspBuilder: $cspBuilder,
    injector: $injector, tokenSigner: $tokenSigner,
);

$request = new ServerRequest($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], getallheaders(), file_get_contents('php://input'), '1.1', $_SERVER);
$request = $request->withQueryParams($_GET)->withCookieParams($_COOKIE);

$handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html'], '<html><body>OK</body></html>');
    }
};

$response = $middleware->process($request, $handler);
```

### Important: Shared disk store

With PHP-FPM, the built-in server, or multiple workers, HtmlStore must use a private shared disk path so tokens survive across requests:

```php
$htmlStore = new HtmlStore('/tmp/shugoi-render-shared');
```

Laravel defaults to a site-scoped storage directory. In-memory storage is only suitable when all requests reach the same persistent instance. See the [gateway setup](docs/websocket-gateway.md) for the shared receipt directory and trusted proxy configuration.

## How it works

### Request flow

1. The middleware blocks configured non-browser user agents and applies rate limits.
2. A browser without a valid admission cookie receives a verification page, without protected content.
3. The browser obtains and solves a challenge over `/__sg_challenge/ws`. No raw proof is accepted over HTTP.
4. The gateway returns a signed, short-lived receipt. The application consumes it atomically and redirects to the clean URL with an HttpOnly admission cookie.
5. The application stores its HTML and sends a bootstrap containing the Shugoi guard and WebSocket transport hooks.
6. The guard obtains its signed WLC decision and render grant over the Shugoi API WebSocket.
7. The same-origin render endpoint verifies token, tenant, grant and expiry, then consumes the exact HTML once.

Invalid, expired and consumed render tokens are always refused, including when `enableContentReplacementCheck` is disabled. This flag never permits a fallback to another stored page. A user-agent filter or PoW alone cannot prove that a client is human; the signed render decision remains required.

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
| `splitRender` | bool | `true` | Enable guarded bootstrap injection |
| `multiProcess` | bool | `false` | Shared HTML storage (Laravel defaults to true) |
| `browserTransport` | string | `http` | Render transport (`http` or optional `websocket`) |
| `renderStore` | string | `memory` | Render-store mode; WebSocket render needs `disk` |
| `renderStorePath` | string | site-scoped in Laravel | Private shared HTML directory |
| `websocketBind` | string | `127.0.0.1` | Local bind address for the render sidecar |
| `websocketPort` | int | `8787` | Local port for the render sidecar |
| `publicOrigin` | string | - | Exact public HTTPS origin accepted by the render sidecar |
| `powWebSocketUrl` | string | `/__sg_challenge/ws` | Public PoW gateway URL |
| `powReceiptStorePath` | string | site-scoped temporary directory | Private shared consumed-receipt directory |
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
