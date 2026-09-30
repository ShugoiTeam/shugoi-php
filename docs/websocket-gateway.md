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
