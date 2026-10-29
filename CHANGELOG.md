# Changelog

All notable changes to the `smallpict/smallpict-php` package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Compression `mode` option on `optimize()` (FND-07): `OptimizeOptions` constructor argument `$mode` (last position) or array key `mode`, with the values in `SmallPict\Models\CompressionMode` (`LOSSY`, `EXTREME`, `LOSSLESS`). When no mode is set the key is omitted and the server applies `lossy`. An unknown mode throws `ValidationException` before any HTTP call. The server checks the mode against the plan: `403 mode_not_in_plan` raises `PermissionDeniedException` with `getDetails()['mode']` and `getDetails()['allowed_modes']`.
- `JobStatusResult::getModeApplied()` and `getQualityApplied()`, read from the job status response; `null` for jobs created before modes existed.

### Deprecated
- The `lossless` option and `OptimizeOptions::isLossless()`. Use `mode` = `CompressionMode::LOSSLESS` instead. Setting `lossless = true` raises an `E_USER_DEPRECATED` notice and is sent as `mode: "lossless"`; combined with a different explicit `mode` it throws `ValidationException`. Removal not before the next major version.

### Fixed
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
