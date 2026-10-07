# PHP WebSocket Demo Site Implementation Plan

> **For agentic workers:** Execute this plan inline; the user already authorized implementation.

**Goal:** Add a deployable Laravel demo app to validate Shugoi's optional same-origin WebSocket transport on a separate host and domain.

**Architecture:** Keep the demo application under `demo/websocket-test-site/` and reference this SDK repository through a Composer path repository. Use normal Laravel HTTP handling for pages and a separately supervised OpenSwoole sidecar for the two WebSocket routes. Document environment setup, shared storage permissions, Nginx routing, and browser verification.

**Tech Stack:** PHP 8.2+, Laravel 11, Shugoi PHP SDK, Composer, OpenSwoole, Nginx.

## Global Constraints

- Keep the demo isolated from the production Shugoi site and credentials.
- WebSocket mode requires `SHUGOI_BROWSER_TRANSPORT=websocket`, shared disk render storage, and an exact public origin.
- The demo must not contain real site keys or secrets.
- Do not add or run tests unless requested; perform static diff checks only in this environment.

---

### Task 1: Add a standalone Laravel demo skeleton

**Files:**
- Create: `demo/websocket-test-site/composer.json`
- Create: `demo/websocket-test-site/artisan`
- Create: `demo/websocket-test-site/bootstrap/app.php`
- Create: `demo/websocket-test-site/bootstrap/providers.php`
- Create: `demo/websocket-test-site/app/Providers/AppServiceProvider.php`
- Create: `demo/websocket-test-site/.env.example`

- [ ] Configure Laravel 11 and reference `shugoi/shugoi-php` through the sibling repository path.
- [ ] Register the Shugoi middleware in Laravel's web request pipeline.
- [ ] Set WebSocket, shared-store, bind, port, and exact-origin environment variables in the example environment.

### Task 2: Add test pages that prove render behavior

**Files:**
- Create: `demo/websocket-test-site/routes/web.php`
- Create: `demo/websocket-test-site/resources/views/layout.blade.php`
- Create: `demo/websocket-test-site/resources/views/home.blade.php`
- Create: `demo/websocket-test-site/resources/views/test-page.blade.php`
- Create: `demo/websocket-test-site/public/demo.css`
- Modify: `README.md`

- [ ] Add a landing page with links to two protected HTML pages.
- [ ] Put an explicit marker and request timestamp in the protected response payload so the final rendered document proves that split-render completed.
- [ ] Include browser DevTools instructions for checking HTTP 101 handshakes to `/api/v1/ws-wlc` and `/__shugoi/render/ws`.

### Task 3: Document deployment to the user's separate host

**Files:**
- Create: `demo/websocket-test-site/README.md`
- Create: `demo/websocket-test-site/deploy/shugoi-websocket.service`
- Modify: `demo/websocket-test-site/.gitignore`

- [ ] Document Composer install, `.env` setup, Laravel storage permissions, sidecar process supervision, and Nginx WebSocket proxy locations.
- [ ] Describe domain DNS/TLS and the exact `SHUGOI_PUBLIC_ORIGIN` value.
- [ ] Explain expected success evidence and common failures without adding any production credentials.

### Task 4: Review deliverable

**Files:**
- Review: all files above

- [ ] Run `git diff --check` and inspect repository status.
- [ ] Do not claim PHP runtime validation because the current shell has no PHP or Composer executable.
