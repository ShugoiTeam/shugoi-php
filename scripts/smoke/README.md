# Local browser smoke fixture

This fixture uses synthetic credentials and a minimal mock guard, not the real Shugoi detection guard. It checks the PHP middleware, browser transport, signed token/grant handoff, split HTML rendering, CSP, and normal page interactions. It does not prove production detection behavior.

Requirements: PHP 8.2+, installed Composer development dependencies, Node 20+, and `ws` resolvable by Node. Debian packages `nodejs node-ws` work without changing this library's Composer dependencies. `SHUGOI_SMOKE_WS_MODULE` can alternatively point to an existing compatible `ws` module.

Start these processes from the repository root in separate terminals:

```sh
node scripts/smoke/api.cjs
php -S 127.0.0.1:4195 scripts/smoke/router.php
SHUGOI_SITE_KEY=sg_sk_test_php_smoke SHUGOI_SIGNING_SECRET=synthetic-local-php-smoke-secret-not-production SHUGOI_ORIGIN=http://127.0.0.1:4195 SHUGOI_POW_DIFFICULTY=4 php bin/shugoi-websocket.php
```

Open `http://127.0.0.1:4195/` in a normal browser. The SDK performs its proof-of-work WebSocket exchange, asks the mock API over WebSocket with `{q}`, and redeems the grant at the same-origin PHP render endpoint. The success page contains a working button and links to a second page, a 1.9-second response, a nested render route, and a denied case.

`http://127.0.0.1:4196/__smoke/status` exposes aggregate fixture request counters. All listeners bind only to loopback. Stop all processes with Ctrl+C. The PHP fixture stores temporary HTML under the operating system's temporary directory in `shugoi-php-local-smoke`; all credentials and data there are synthetic.
