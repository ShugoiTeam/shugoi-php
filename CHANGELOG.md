# Changelog

## Unreleased

### Maintenance
- Separated the PHP package from the Shugoi platform repository.
- Added standalone CI validation for PHP 8.2, 8.3 and 8.4.
- Documented the package architecture and expanded regression coverage.

### Security
- Removed dynamic JavaScript evaluation from the split-render bootstrap.
- Removed `unsafe-eval` from the default Content Security Policy.

### Performance
- Removed Unicode-tag expansion from skeleton responses, reducing transfer size and parse work.
