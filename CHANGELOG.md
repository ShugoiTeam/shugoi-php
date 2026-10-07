# Changelog

## 0.5.0 - 2026-10-07

- Move preflight PoW to a supervised PHP WebSocket gateway, with short-lived,
  IP/UA-bound admission receipts consumed once across web workers.
- Restore browser guard WebSocket transport and require signed, single-use HTML
  rendering for standalone PHP and Laravel.
- Preserve Laravel's raw query string, sessions and application cookies through
  the protected request flow; keep authorization cookies Secure on HTTPS even
  when environment values are available only in Laravel's cached configuration.
- Add Laravel HTTP-kernel regression coverage and deployment instructions for
  global middleware, trusted proxies, private storage and the gateway service.
- Add an optional OpenSwoole render/WebSocket sidecar with a shared disk-backed
  store, Laravel environment settings, and the `shugoi:websocket` Artisan command.
- Align direct `Config` defaults with Laravel's proof-of-work settings and keep
  the browser-block page tests aligned with the current Shugoi display font.

## 0.4.12

### Maintenance
- Separated the PHP package from the Shugoi platform repository.
- Added standalone CI validation for PHP 8.2, 8.3 and 8.4.
- Documented the package architecture and expanded regression coverage.

### Security
- Removed dynamic JavaScript evaluation from the split-render bootstrap.
- Removed `unsafe-eval` from the default Content Security Policy.

### Performance
- Removed Unicode-tag expansion from skeleton responses, reducing transfer size and parse work.

### Compatibility
- Aligned restricted-access fallback labels with the current guard contract, including support and machine-ID guidance.
- Removed automatic reloads from render failures and rate-limit countdowns.
- Updated blocking cards to use the dedicated blocking brand assets.
- Added PHP/Node parity regression coverage and a clean-install fixture.
- Unified the Laravel controller and PSR middleware through the same signed `RenderService`.
- Removed the Laravel internal-route bypass and aligned crawler metadata isolation with the Node middleware.
