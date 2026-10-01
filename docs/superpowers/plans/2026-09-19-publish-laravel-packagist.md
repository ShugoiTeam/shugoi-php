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

- [x] Confirm the working tree changes are the intended Laravel release scope and identify the next unused semantic version (`0.4.12`, after remote `0.4.11`).
- [x] Validate package metadata with `composer validate --strict` and inspect autoload/Laravel discovery entries.
- [x] Verify the existing lock/vendor state without dependency churn.

Expected result: package metadata is valid, the version is unambiguous, and no credentials or unrelated files are part of the release scope.

### Task 2: Run the PHP and Laravel verification matrix

**Files:**
- Test: `tests/*.php`, `tests/Laravel/*.php`, `tests/ParityContractTest.php`

- [x] Run the PHP syntax lint in a PHP 8.3 container; all source and test files passed.
- [x] Run PHPUnit 11 in PHP 8.3; 183 tests and 475 assertions passed.
- [x] Verify the Laravel provider, middleware/controller wiring and parity contracts through the package test suite.
- [x] Compare the Laravel render path, CSP, crawler metadata, blocking card and HTTP fallback contracts against the current SDK tests without changing the Node package in this release.

Expected result: all automated tests pass and the minimal Laravel integration remains compatible.

### Task 3: Verify a clean consumer installation

**Files:**
- Test fixture: `tests/fixtures/client-composer.json`

- [x] Build a temporary Composer archive and install its production dependencies in a clean temporary directory.
- [x] Install the published `shugoi/shugoi-php:v0.4.12` into a fresh Composer consumer from Packagist.
- [x] Confirm the Laravel provider and middleware classes are present and autoloadable from the published package.
- [x] Confirm Composer resolved the exact published version and generated autoload files without advisories.

Expected result: a clean consumer can install and boot the tagged package without relying on this repository's vendor directory.

### Task 4: Prepare and publish the release

**Files:**
- Modify: `CHANGELOG.md` only if the release entry is missing.
- Tag: next unused semantic version, expected `v0.4.11` after baseline confirmation.

- [x] Run `git diff --check`, inspect the final diff, and stage only intended PHP package files.
- [x] Commit the release as `08f5988` and create the annotated tag `v0.4.12`.
- [x] Push the commit to `main` and tag `v0.4.12` to the configured GitHub origin.
- [x] Verify Packagist metadata contains `v0.4.12`.
- [x] Run a fresh Composer require against Packagist; Composer installed `shugoi/shugoi-php (v0.4.12)` successfully.

Expected result: the new version is visible on Packagist and installs successfully from the public registry.

### Task 5: Record evidence and close the goal

- [x] Record the exact published version, commit/tag, test commands and registry installation result in this plan without secrets.
- [x] Confirm no generated vendor/archive artifact was committed; the working tree is clean after the release push.
- [x] Mark the goal complete after the registry installation succeeded.

## Verification Commands

```bash
composer validate --strict
composer run lint
composer test
git diff --check
git tag --list 'v*' --sort=-version:refname | head
```

Expected result: every command exits successfully before any tag push or Packagist publication.
