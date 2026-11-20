# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.26.0 — Unreleased

### Added

- `AtomicCacheInterface`, implemented by every store: `add()` stores only when the key holds nothing and says whether it
  did; `increment()` returns the new count, and an existing count keeps its expiry. Two requests at the same instant
  cannot both find a key absent, nor both read the same count. `FileCache` holds an exclusive lock on the key's `.lock`
  file between its read and its write, and throws rather than run unlocked; `clear()` removes the lock files too.
  `CacheInterface` itself is unchanged.

## 2.4.0 — 2026-10-26

### Changed

- Factory registrations declare the services they resolve with `uses()`, so the container checks
  their lifetimes at startup (FE-40).
- Requires `mj-zana/core` ^2.4.

## 2.3.0 — 2026-10-26

### Changed

- Tests and tooling (`tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`) are no longer in Composer
  archives, so they stay out of production `vendor/` folders (FE-17).

## 2.0.0 — 2026-10-26

The final 2.0 release. No changes since 2.0.0-rc.1.

## 2.0.0-alpha.3 — 2026-10-24

First release of the 2.0 line. PSR-16 caches with `remember()` and `pull()`, and named stores from configuration. Requires PHP 8.5 or later.

Documentation: [the cache guide](https://github.com/mjnable/zana-docs/blob/main/guides/cache.md).
