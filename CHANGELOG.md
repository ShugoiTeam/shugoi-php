# Changelog

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
