# Shugoi WebSocket Lab

Small Laravel app for checking Shugoi PHP's real WebSocket render path on a separate test domain. It is intended to run from this repository so Composer installs the local SDK source from `../../`; it does not depend on a published SDK release.

## What it exercises

- `/` provides the test links and browser verification steps.
- `/protected/alpha` and `/protected/beta` return unique HTML payloads through Shugoi split-render.
- `/api/ws-demo-health` is a lightweight JSON endpoint for the host's uptime monitor and uses the SDK's default `/api` allowlist.
- The browser connects to the same-origin `/api/v1/ws-wlc` and `/__shugoi/render/ws` routes; Shugoi server API calls remain server-side HTTP.

## Install

Use a host with PHP 8.2+, Composer 2, Laravel-compatible extensions, and the OpenSwoole extension enabled for the CLI PHP runtime. FPM and CLI must use the same PHP version and be able to read the shared render directory.

From this directory:

```sh
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set the real test site's Shugoi site key and secret, plus the exact public HTTPS origin. The values in `.env.example` are deliberately unusable. Configure the same domain in DNS and TLS before testing.

Create the shared storage directory and give it to the same Unix account used by PHP-FPM and the sidecar. For example, if both use `www-data`:

```sh
sudo install -d -o www-data -g www-data -m 0700 /var/lib/shugoi-websocket-demo/render
sudo chown -R www-data:www-data storage bootstrap/cache
```

Set `APP_KEY`, `SHUGOI_SITE_KEY`, `SHUGOI_SECRET`, `APP_URL`, `SHUGOI_PUBLIC_ORIGIN`, and `SHUGOI_RENDER_STORE_PATH` for the actual host. Keep `APP_DEBUG=false`, and do not commit `.env`.

## Run the sidecar

Run the long-lived sidecar under systemd, Supervisor, or the host's process manager, using the same environment and user as the Laravel app. A sample unit is in `deploy/shugoi-websocket.service`; replace its project path if the app is installed elsewhere, then install and start it:

```sh
sudo cp deploy/shugoi-websocket.service /etc/systemd/system/shugoi-websocket.service
sudo systemctl daemon-reload
sudo systemctl enable --now shugoi-websocket
sudo systemctl status shugoi-websocket
```

To run it manually while diagnosing startup:

```sh
php artisan shugoi:websocket
```

It binds to `127.0.0.1:8787` by default. Do not expose port 8787 publicly; the reverse proxy should forward only the two WebSocket paths below. Both PHP-FPM and this process must see the same render-store directory.

## Nginx example

Put the exact locations in the HTTPS virtual host for the test domain, before the Laravel front-controller location:

```nginx
location = /__shugoi/render/ws {
    proxy_pass http://127.0.0.1:8787;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header Origin $http_origin;
    proxy_read_timeout 75s;
}

location = /api/v1/ws-wlc {
    proxy_pass http://127.0.0.1:8787;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header Origin $http_origin;
    proxy_read_timeout 75s;
}
```

The WebSocket upgrade remains on the site's HTTPS origin, so the browser uses `wss://`. `SHUGOI_PUBLIC_ORIGIN` must match exactly, including scheme and any non-default port, and must not include a path or trailing slash. Keep the regular Laravel `location /` and `location ~ \.php$` rules from your host configuration.

## Verify in a browser

1. Open the test domain in a normal browser and open DevTools → Network → WS.
2. Open Alpha or Beta from the landing page.
3. Check for `101 Switching Protocols` on `/api/v1/ws-wlc` and `/__shugoi/render/ws`.
4. Confirm the final page displays “Rendu ALPHA/BETA reçu” and a fresh render ID.
5. Reload once and confirm the new page shows another ID. The WebSocket render request should not silently switch to HTTP if the socket is unavailable.
6. If testing the whitelist, enable it for this test site in the Shugoi dashboard. A non-whitelisted browser should see the restricted card; whitelist it and retry to confirm the allowed render completes.

`/api/ws-demo-health` only confirms Laravel is reachable. It does not test the Shugoi API or WebSocket sidecar.

## Troubleshooting

- **No `101` response:** check the Nginx upgrade headers, exact proxy paths, TLS, and that the sidecar is listening on `127.0.0.1:8787`.
- **Handshake rejected:** compare the browser's HTTPS origin to `SHUGOI_PUBLIC_ORIGIN` character-for-character.
- **Render times out or is rejected:** check that PHP-FPM and the sidecar use the same writable `SHUGOI_RENDER_STORE_PATH`, that Laravel and CLI load the same `.env`, and that the site key/secret are the test site's credentials.
- **Composer can't resolve the local SDK:** run Composer from this directory and make sure the SDK root remains at `../../` in the checkout.
- **Test pages display a Shugoi access card:** verify the test site's guard and whitelist settings in the Shugoi dashboard. The card means the middleware is active; it is separate from the WebSocket handshake result.
