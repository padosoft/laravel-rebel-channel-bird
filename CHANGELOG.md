# Changelog

All notable changes to `padosoft/laravel-rebel-channel-bird` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-06-04

### Added
- **`BirdVerifyProvider`**: a Rebel Channels `VerificationProvider` **and**
  `MessageDeliveryChannel` backed by Bird (formerly MessageBird). `start`/`check` use the
  Bird Verify API (SMS), `send` delivers a plain SMS via the Bird Messaging API. Maps Bird
  statuses explicitly (`sent` → pending, `verified` → approved, anything else → failure) and
  converts any transport/API error into a clean `provider_error` so the router can fall back.
- **Gateway seam** (`BirdGateway` + `RestBirdGateway`) over Bird's Verify + Messaging REST API,
  called through Laravel's HTTP client, with a `FakeBirdGateway` for offline tests.
- **Auto-registration** into the Channels registry when `BIRD_ACCESS_KEY` is present
  (no authenticated gateway is ever constructed otherwise), gated by `register_provider`.
- **Delivery-receipt webhook**: a `POST` endpoint (`rebel/bird/status`, named
  `rebel-bird.status`) that receives Bird delivery-status callbacks and records a Rebel audit
  event so the admin panel's Channel Performance can show real delivered / failed / cost. Maps
  statuses to `channel.verification.delivered` / `.undelivered` / `.dispatched`, stores the
  recipient as a keyed HMAC (never in clear), and the absolute price + `price_unit` /
  `message_sid` / `error_code` in metadata.
- **`BirdStatusController`** (`__invoke`) + **`BirdSignatureValidator`** implementing Bird's
  `MessageBird-Signature` scheme (HMAC-SHA256 of timestamp + URL + body hash): validates the
  signature when `webhook.validate_signature` is on (403 on bad/missing signature), and is
  defensive (malformed/empty payload → 204, never 500).
- **Live test suite** (`tests/Live`, opt-in via `REBEL_BIRD_LIVE=1`) that hits the real Bird
  Verify API; self-skips when credentials are absent.
- Config file, CI matrix (PHP 8.3/8.4/8.5 × Laravel 12/13), Pest suite, PHPStan level max, Pint.

[Unreleased]: https://github.com/padosoft/laravel-rebel-channel-bird/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/padosoft/laravel-rebel-channel-bird/releases/tag/v0.1.0
