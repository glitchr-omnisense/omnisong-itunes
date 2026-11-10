# Changelog

## [Unreleased]

### Added

- Its usage policy as data, `policy.json` (`extra.omnisense.policy`, `usagePolicy()`), verified 2026-11-10: Apple's page (performance-partners.apple.com/search-api): about 20 calls a minute, the answers cacheable, the previews streamed only near a store badge with "Provided courtesy of iTunes". The factory gives the catalogue its wait (omnisense/contracts' `Backoff`): a 429's `Retry-After` (or a 403's), a 503's, or a `RateLimit` field with nothing left holds the next calls, a `RateLimitedException` with nothing sent; `sentRequests()` counts the requests sent, so the registry's guard counts no answer held in memory. Requires `omnisense/contracts`. PHPStan at level max, the existing errors in `phpstan-baseline.neon`.

### Fixed

- `Retry-After` written as an HTTP date gives the seconds until then (at least 1), where it gave 0.
