# Changelog

## [Unreleased]

## [1.0.1] - 2026-11-14

### Changed

- Neutral example data in docs and tests.

## [1.0.0] - 2026-11-13

The first stable release.

### Release

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnisong: ^1.0`: stable versions, so `composer
  require omnisong/itunes` installs in a project at Composer's default minimum-stability.
  `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's
  php-package.yml) is callable, and `release.yml` runs it on a tag before glitchr-studio/ci's
  php-release.yml.

### Added

- Its usage policy as data, `policy.json` (`extra.omnisense.policy`, `usagePolicy()`), verified 2026-11-10: Apple's page (performance-partners.apple.com/search-api): about 20 calls a minute, the answers cacheable, the previews streamed only near a store badge with "Provided courtesy of iTunes". The factory gives the catalogue its wait (omnisense/contracts' `Backoff`): a 429's `Retry-After` (or a 403's), a 503's, or a `RateLimit` field with nothing left holds the next calls, a `RateLimitedException` with nothing sent; `sentRequests()` counts the requests sent, so the registry's guard counts no answer held in memory. Requires `omnisense/contracts`. PHPStan at level max, the existing errors in `phpstan-baseline.neon`.

### Changed

- PHPStan at level max with an empty baseline: types only, the answer's fields read through an internal `Data` (the kernel's, with the casts the code made: the same strings and integers from a scalar). A list or an object where a text or a number was read gives ""/0, not "Array"/1; a track's `collectionId` that is no scalar is not asked for as an album (it went out as a nested query).

### Fixed

- `Retry-After` written as an HTTP date gives the seconds until then (at least 1), where it gave 0.
- A track whose `trackTimeMillis` is not a number (a list, a word) makes the release a `ProviderException`, where the division threw a `TypeError`.
