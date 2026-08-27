# Shugoi PHP SDK architecture

The package is organized around a small runtime pipeline:

- `Config` owns normalized options and defaults.
- `ApiClient` handles HTTP communication with the Shugoi API.
- `ConfigCache` and `GuardCache` provide bounded, time-aware configuration caches.
- `Core` evaluates a request and returns a typed decision.
- `Middleware` adapts that decision to the host framework.
- `GuardInjector`, `SkeletonGenerator`, and `HtmlStore` handle response transformation and storage.
- `TokenSigner`, `Pow`, and `Obfuscator` contain isolated cryptographic and challenge operations.
- `Laravel/` contains framework adapters only. It does not own protection rules.

All public entry points use strict types. Network and persistence boundaries are isolated behind dedicated classes, and the test suite covers the request pipeline, cache behavior, challenge validation, framework adapters, and generated responses.
