# Changelog

All notable changes to the `smallpict/smallpict-php` package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Compression `mode` option on `optimize()` (FND-07): `OptimizeOptions` constructor argument `$mode` (last position) or array key `mode`, with the values in `SmallPict\Models\CompressionMode` (`LOSSY`, `EXTREME`, `LOSSLESS`). When no mode is set the key is omitted and the server applies `lossy`. An unknown mode throws `ValidationException` before any HTTP call. The server checks the mode against the plan: `403 mode_not_in_plan` raises `PermissionDeniedException` with `getDetails()['mode']` and `getDetails()['allowed_modes']`.
- `JobStatusResult::getModeApplied()` and `getQualityApplied()`, read from the job status response; `null` for jobs created before modes existed.
- **Upload folder on `optimize()` (FLD-03).** `OptimizeOptions` constructor argument `$folder` (last position) or array key `folder`, sent as `options.folder` and omitted when unset. The folder the server stored (lower case) comes back in `OptimizeResult::getFolder()` and `JobStatusResult::getFolder()`, `null` without one.
- **Folder purge (FLD-03).** `purgeCdn([], PurgeType::FOLDER, 'blog/2026')` purges every file uploaded into that folder; the request carries `purge_type` and `folder` only. `PurgeType::URL` and `PurgeType::ALL` requests are unchanged. A missing folder for `PurgeType::FOLDER` throws `ValidationException` before any HTTP call.
- **Light folder check on the client.** Empty segments (`a//b`, leading or trailing `/`), `..` segments and more than 3 segments throw `ValidationException` (`getDetails()['folder']`) before any HTTP call. The server stays the authority: its `400 invalid_folder` raises `ValidationException` with the server's message.

### Changed
- `OptimizeOptions` quality is now `?int` and defaults to `null` (constructor `$quality`, array key `quality`, `getQuality()`). When unset, `quality` is no longer sent, so the server picks the default for the mode: `lossy` stays at 80, `extreme` now gets its per-codec default instead of 80. A quality you set is still clamped to 1-100 and sent. Possibly breaking: `getQuality()` returns `null` instead of `80` when no quality was set.
- README: example of `mode` `CompressionMode::EXTREME` without a quality.
- A `429` with error code `purge_rate_limited` (hourly limit of folder and all purges) raises `RateLimitException` at once and is no longer retried; other `429` responses keep the retry with backoff.

### Deprecated
- The `lossless` option and `OptimizeOptions::isLossless()`. Use `mode` = `CompressionMode::LOSSLESS` instead. Setting `lossless = true` raises an `E_USER_DEPRECATED` notice and is sent as `mode: "lossless"`; combined with a different explicit `mode` it throws `ValidationException`. Removal not before the next major version.

### Fixed
- `mode` `extreme` without a quality used to send quality 80 and produce lossy-sized files; quality is now omitted when unset (CMP-04).
- `lossless = true` used to be ignored by the server; it is now honoured, because the SDK sends it as `mode: "lossless"` and no longer sends the `lossless` key.

## [1.0.0] - 2026-08-22

### Added
- Official PHP SDK implementation for SmallPict OpenAPI 3.1.0 API.
- Broad compatibility supporting **PHP 7.4 through PHP 8.4**.
- Zero external dependencies with high-performance native cURL client (`CurlHttpClient`).
- PSR-18 HTTP client interface support (`HttpClientInterface`).
- 4 unified core client methods: `optimize()`, `getQuota()`, `purgeCdn()`, and `validateKey()`.
- Helper `getJobStatus()` for polling asynchronous image conversion tasks.
- Optional Laravel 10/11 auto-discovery support via `SmallPictServiceProvider` and `SmallPict` Facade.
- Automatic secret masking to ensure API keys and HMAC signatures never leak in error messages or exception stack traces.
- Resilient HTTP transport with 30s timeouts, exponential backoff, jitter, and automatic `Idempotency-Key` UUID injection.
- Optional `FallbackMode::PASSTHROUGH` mode for uninterrupted availability on quota limit exhaustion.
