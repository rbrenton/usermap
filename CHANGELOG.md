# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-05-29

A security, correctness, and architecture pass following a structured review.

### Security
- Fixed a stored XSS: the non-airport branch of the data endpoint now
  HTML-escapes the station value before rendering.
- All database access converted to parameterized queries (`pg_query_params`)
  in both `cron.php` and `index.php`.
- gcmap.com geocoding requests upgraded from HTTP to HTTPS.

### Fixed
- **Schema was non-deployable.** `sql/setup.sql` created `rflying_locations`
  while the code targets `usermap_locations`, and it omitted the `time_updated`
  column referenced by every insert/update. Both are corrected; `created_at`
  added.
- `updatePilot95` referenced an undefined `$count` variable and its
  `ON CONFLICT DO UPDATE` lacked a `locked = false` guard, allowing the cron to
  overwrite manually pinned records. Both fixed.
- `updatePilot93` now geocodes only when a user's station actually changes
  instead of on every run (the previous `$forceLatLonUpdate` flag made the
  station-change check dead code).
- `index.php` now uses the `GMAP_DEFAULT_*` constants instead of separate
  hard-coded defaults that silently diverged from configuration.
- The `?a=data.json` output no longer emits a trailing comma and sends a
  `Content-Type` header.

### Changed
- Extracted the Reddit OAuth + pagination logic from a 130-line
  static-variable function into a `RedditFlairClient` class (`lib/`).
- Extracted rate limiting into a `RateLimiter` class (`lib/`).
- Database connection is now passed explicitly rather than relied upon as an
  implicit global.
- Front-end: replaced the deprecated `DOMSubtreeModified` listener and jQuery
  dependency with a vanilla-JS `MutationObserver`; documented the tile-URL
  workaround.

### Added
- `flock`-based overlap prevention in `cron.php`.
- Partial indexes and `CHECK` constraints (lat/lon ranges) in the schema.
- Project documentation: `LICENSE`, expanded `README.md`, this changelog,
  `BACKLOG.md`, and `composer.json`.
- Removed an unreachable `if (0)` dead-code block.

## [1.2.1] - prior

- Mask secrets in logs, add rate limits, filter known non-airport flair codes,
  add PostgreSQL 9.5+ upsert support.
- Switch to OAuth authentication.
- Use `.dist` template files so credentials stay out of version control.

[1.3.0]: https://github.com/rbrenton/usermap/compare/v1.2.1...v1.3.0
