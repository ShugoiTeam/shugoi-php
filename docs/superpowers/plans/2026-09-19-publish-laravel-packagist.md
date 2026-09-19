# Laravel Packagist Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Validate the current Laravel/PHP SDK changes and publish a reproducible tagged release of `shugoi/shugoi-php` to Packagist only after all package and installation checks pass.

**Architecture:** The PHP package remains framework-agnostic at its core, with Laravel integration registered through the existing service provider and middleware/controller bindings. Packagist discovery is driven by the GitHub repository and a semantic version tag; no runtime configuration or client integration changes are introduced by the release operation.

**Tech Stack:** PHP 8.2+, Composer, PHPUnit 11, Laravel 11/Testbench 10, GitHub tags, Packagist metadata.

## Global Constraints

- Keep the client integration key-in-hand: Laravel configuration must continue to require only the existing site key and secret inputs.
- Do not add secrets, Packagist tokens, GitHub tokens, or environment credentials to the repository or command output.
- Preserve unrelated working-tree changes and release only the intended PHP package changes.
- Do not publish until lint, unit/integration tests, Composer validation, clean archive installation, and SDK parity checks pass.
- Use a new semantic version after the existing `v0.4.10`; do not overwrite an existing tag.

---

### Task 1: Establish release baseline

**Files:**
- Inspect: `composer.json`, `composer.lock`, `phpunit.xml`, `README.md`, `CHANGELOG.md`, `src/Laravel/*`, `tests/Laravel/*`, `tests/ParityContractTest.php`

- [ ] Confirm the working tree changes are the intended Laravel release scope and identify the next unused semantic version.
- [ ] Validate package metadata with `composer validate --strict` and inspect autoload/Laravel discovery entries.
- [ ] Run `composer install --no-interaction --prefer-dist` only if the lockfile/vendor state is incomplete; otherwise avoid dependency churn.

Expected result: package metadata is valid, the version is unambiguous, and no credentials or unrelated files are part of the release scope.

### Task 2: Run the PHP and Laravel verification matrix

**Files:**
- Test: `tests/*.php`, `tests/Laravel/*.php`, `tests/ParityContractTest.php`

- [ ] Run `composer run lint` and fail on any PHP syntax error.
- [ ] Run `composer test` and confirm all PHPUnit tests pass, including service-provider boot, middleware, render, metadata and parity contracts.
- [ ] Run the package's Laravel fixture/integration checks if present, including a fresh application boot with only the documented configuration.
- [ ] Compare the Laravel render path, CSP, crawler metadata, blocking card and WebSocket/HTTP fallback contracts against the Node SDK's current behavior without changing the Node package in this release.

Expected result: all automated tests pass and the minimal Laravel integration remains compatible.

### Task 3: Verify a clean consumer installation

**Files:**
- Test fixture: `tests/fixtures/client-composer.json`

- [ ] Build a temporary consumer outside the repository from the package archive using `composer archive --format=tar --dir=<temporary-dir>` or an equivalent Composer-dist archive command.
- [ ] Install the archive into a fresh Laravel/Testbench consumer with `composer install --no-interaction --prefer-dist`.
- [ ] Boot the provider and instantiate the middleware using only the documented site key and secret configuration.
- [ ] Run a smoke request proving public HTML, protected HTML, render endpoint, block response and crawler metadata do not throw dependency/autoload errors.

Expected result: a clean consumer can install and boot the tagged package without relying on this repository's vendor directory.

### Task 4: Prepare and publish the release

**Files:**
- Modify: `CHANGELOG.md` only if the release entry is missing.
- Tag: next unused semantic version, expected `v0.4.11` after baseline confirmation.

- [ ] Run `git diff --check`, inspect the final diff, and stage only intended PHP package files.
- [ ] Commit the release with a focused message and create the annotated semantic version tag.
- [ ] Push the commit and tag to the configured GitHub origin so Packagist can import the release.
- [ ] Verify Packagist metadata/version availability using Composer/Packagist lookup without exposing tokens.
- [ ] Run `composer create-project`/fresh `composer require` against the published version in a temporary directory and rerun the provider boot smoke test.

Expected result: the new version is visible on Packagist and installs successfully from the public registry.

### Task 5: Record evidence and close the goal

- [ ] Record the exact published version, commit/tag, test commands and registry installation result in the release notes or handoff without secrets.
- [ ] Confirm no previous fixes were reverted and no generated vendor/archive artifact was unintentionally committed.
- [ ] Mark the goal complete only after the registry installation succeeds; otherwise keep it active and use a reversible fix or report the precise external blocker.

## Verification Commands

```bash
composer validate --strict
composer run lint
composer test
git diff --check
git tag --list 'v*' --sort=-version:refname | head
```

Expected result: every command exits successfully before any tag push or Packagist publication.
