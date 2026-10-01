# PHP proof-of-work WebSocket gateway

The PHP SDK uses a real WebSocket exchange for proof of work. PHP-FPM renders the
challenge page; the separate PHP CLI process issues a challenge, verifies its
solution on that connection, and signs a short-lived, single-use admission
receipt. PHP-FPM consumes the receipt and sets its normal admission cookie. Raw
proofs in `sg_proof` no longer grant admission.

The CLI process uses [AM PHP's WebSocket server](https://amphp.org/websocket-server)
for the RFC 6455 handshake, framing, masking, ping/pong and protocol limits. It
requires PHP 8.2+ with Composer dependencies installed and a process supervisor.
PHP-FPM alone cannot provide this persistent WebSocket endpoint. The browser
detection and whitelist transport still connects to the Shugoi API; this local
gateway is specifically for proof of work.

## Shared configuration

Use the same site key, signing secret and proof difficulty in the web application
and CLI gateway. Keep secrets in environment files outside the web root. The CLI
does not load a Laravel `.env` file automatically.

```ini
SHUGOI_SITE_KEY=your_site_key
SHUGOI_SIGNING_SECRET=your_signing_secret
SHUGOI_ORIGIN=https://example.com
SHUGOI_WS_PORT=4197
SHUGOI_POW_DIFFICULTY=14
SHUGOI_TRUST_PROXY=1
```

`SHUGOI_ORIGIN` is one exact public origin, without a trailing slash or a path.
Use `SHUGOI_TRUST_PROXY=1` only for the local reverse proxy configuration below:
it accepts `X-Real-IP` only from a loopback connection. Without this setting the
socket peer IP is used. Do not pass arbitrary client-supplied forwarding headers.
The gateway always listens on `127.0.0.1`, never a public network interface.

Start it from the application directory:

```sh
php vendor/bin/shugoi-websocket.php
```

When working directly in this package's checkout, use
`php bin/shugoi-websocket.php` instead.

## Laravel deployment

The `shugoi/shugoi-php` package includes Laravel's auto-discovered service provider,
HTTP middleware and render controller. Register the middleware globally with
`$middleware->append(\Shugoi\Laravel\ShugoiMiddleware::class)` in
`bootstrap/app.php`, as shown in the README. This runs protection after Laravel's
global proxy handling and outside the route-level cookie/session middleware.
Keep it global rather than adding it a second time to the `web` group: the PoW
admission and render cookies must retain their signed values across requests.

Publish the configuration when installing into an existing application:

```sh
php artisan vendor:publish --tag=shugoi-config
```

If `config/shugoi.php` was already published, merge the new options into that
file. Retain the default `headlessPatterns`, enable `multiProcess`, and configure
`powWebSocketUrl` and `powReceiptStorePath`; old published configuration can
override package defaults. For example, use a private path under the application's
writable storage directory:

```ini
SHUGOI_POW_WEBSOCKET_URL=/__sg_challenge/ws
SHUGOI_POW_RECEIPT_STORE_PATH=/var/www/example/storage/framework/cache/shugoi-pow
```

After changing application environment/configuration, rebuild its configuration
cache with `php artisan config:cache` and reload persistent application workers.
Restart the gateway service separately when its environment changes. The gateway
is a standalone CLI process: `config:cache` and Laravel's `.env` loader do not
configure it. Its service environment must contain the same `SHUGOI_SITE_KEY`
and effective signing secret as the Laravel app (`SHUGOI_SIGNING_SECRET` when
set, otherwise `SHUGOI_SECRET`). Never pass secrets as command-line arguments.

Both `storage/framework/cache/shugoi-render/<site-key-hash>` and the receipt
directory must remain private and shared between the Laravel web workers. Keep
`/__shugoi/render` in the normal Laravel HTTP entry point; only
`/__sg_challenge/ws` is forwarded to the gateway. Do not put either protection
route on the Shugoi allowlist. API exclusions keep their own application
authentication and authorization.

### Livewire pages

Laravel split-render protection captures the final HTML after normal
`RequestHandled` listeners have injected assets, including Livewire's `@assets`.
Protected pages convert `wire:navigate` and `Livewire.navigate()` transitions to
full document navigation so each page gets a fresh Shugoi verification lifecycle.
Back/forward transitions preserve browser history. Livewire component/form
requests still use their normal endpoint; it does not need an HTML-protection
exception for this integration.

## Nginx

Add this exact location to the TLS virtual host serving the PHP application:

```nginx
location = /__sg_challenge/ws {
    proxy_pass http://127.0.0.1:4197;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header Origin $http_origin;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 70s;
    proxy_send_timeout 10s;
    proxy_buffering off;
}
```

For a subdirectory deployment, set the application's `powWebSocketUrl` to the
public endpoint you route to this gateway, and rewrite that one proxy location
to `/__sg_challenge/ws`. Keep `__shugoi/render` routed to the PHP application.

Both the gateway and PHP application must resolve the **same client IP**. With
Nginx/FPM, use the normal `fastcgi_param REMOTE_ADDR $remote_addr`. If a CDN or
another proxy precedes Nginx, configure Nginx's real-IP module for that proxy's
specific trusted networks. In Laravel, configure its trusted proxies when
applicable. The PSR middleware does not trust arbitrary `X-Forwarded-For` headers.

## systemd example

Save the environment above in `/etc/example/shugoi-websocket.env`, readable only
by root. Adapt the application path and service user to your deployment.

```ini
[Unit]
Description=Shugoi PHP proof-of-work WebSocket
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/example
EnvironmentFile=/etc/example/shugoi-websocket.env
ExecStart=/usr/bin/php /var/www/example/vendor/bin/shugoi-websocket.php
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true

[Install]
WantedBy=multi-user.target
```

The gateway only signs receipts; the FPM application stores consumed receipts.
Its `powReceiptStorePath` must be writable and shared by all web workers for this
site. HTML split rendering likewise requires a shared `HtmlStore` directory;
Laravel uses `storage/framework/cache/shugoi-render/<site-key-hash>` by default.
Do not serve either storage directory publicly. Multiple web hosts must share
the relevant storage or route requests to the same application instance.

The gateway permits two text messages per connection, at most 4 KiB per message,
a maximum challenge lifetime of 60 seconds, and 30 connection attempts per IP per
minute. It bounds its rate-limit table and total sockets. An invalid origin,
cross-connection proof, binary payload, timeout, or unavailable gateway never
grants an admission receipt.

Protected streamed HTML responses are rejected with HTTP 503 because split
rendering needs the entire HTML response. Use ordinary buffered Blade/HTML
responses on protected pages. Non-HTML streams and file responses keep their
normal framework behavior.
