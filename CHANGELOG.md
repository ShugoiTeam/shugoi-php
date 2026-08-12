# Changelog

## Unreleased

### Security
- Removed dynamic JavaScript evaluation from the split-render bootstrap.
- Removed `unsafe-eval` from the default Content Security Policy.

### Performance
- Removed Unicode-tag expansion from skeleton responses, reducing transfer size and parse work.
