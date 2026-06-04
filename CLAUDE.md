# CLAUDE.md — AI working guide for `padosoft/laravel-rebel-channel-bird`

> Working on this package with an AI agent (Claude Code, Cursor, Copilot, Codex)? Read this first.
> It's the "batteries" that make vibe-coding here land on the first try. Plain Markdown — every
> tool can read it.

## What this package is
Bird (formerly MessageBird) provider for Laravel Rebel Channels: phone verification via the Bird
Verify API (SMS), plain SMS delivery, and signed delivery-status webhooks.

Part of the **Laravel Rebel** suite — an enterprise authentication control plane over Laravel
Fortify. The shared language (value objects, contracts, the audit trail) lives in
`padosoft/laravel-rebel-core`; this package builds on it. It implements the contracts defined in
`padosoft/laravel-rebel-channels`.

## Non-negotiable conventions
- `declare(strict_types=1);` in every PHP file; `final` classes; constructor property promotion.
- **PHPStan level max** must stay green. Do NOT add `@phpstan-ignore`, baseline entries, or
  `assert()`/inline `@var` to silence errors — fix the root cause. Common recipes:
  - narrow `mixed` before casting: `is_scalar($x) ? (string) $x : null`;
  - `json_decode($s, true)` / `$response->json()` is `array<array-key, mixed>`;
  - the container's `make('request')` is already typed `Illuminate\Http\Request`.
- **Tests:** Pest, Testbench. Cover happy path, auth/fail-closed, tenant-scoping, empty state.
- **Style:** Pint (`composer pint`). **Docs/comments in English.**
- Package wiring uses `spatie/laravel-package-tools` (`configurePackage`).

## Security & telemetry rules (suite-wide)
- Never store PII in cleartext: identifiers, IPs and User-Agents are **keyed HMACs** (core
  `KeyedHasher`). Never log OTPs/secrets (the `Redactor` sanitizes audit metadata).
- **Telemetry completeness:** as a channel provider, it MUST capture everything that fills the
  admin panel (sends, **delivery receipts**, cost…). Record through the core `AuditLogger`
  contract — it persists to `rebel_auth_events` and supports **configurable sync|queue** dispatch.
  Skip a field only when Bird genuinely can't supply it, and surface an honest empty state — never
  fake data.

## How to extend it
- **The Bird gateway:** `Contracts\BirdGateway` is the seam over Bird's Verify + Messaging REST API.
  Production calls go through `Gateway\RestBirdGateway` (HTTP via `Illuminate\Http\Client\Factory`);
  extend it to support new Bird features. Tests bind `Testing\FakeBirdGateway` instead of the network.
- **The verification provider:** `Verification\BirdVerifyProvider` implements the Channels
  `VerificationProvider` (start/check) **and** `MessageDeliveryChannel` (send) — it is what the
  Channels `ProviderRegistry` routes to. Extend it to add channels or map new Bird statuses.
- **Delivery receipts (status webhook):** `Http\Controllers\BirdStatusController` ingests Bird's
  signed delivery-status webhooks and records `channel.verification.delivered` / `.undelivered`
  audit events (with price/cost) so the panel's Channel Performance shows real receipts. Signatures
  are verified by `Http\BirdSignatureValidator` (gated by `rebel-channel-bird.webhook.validate_signature`).
- **Add a status mapping or cost field:** edit the `DELIVERED`/`UNDELIVERED` sets and the extraction
  helpers in `BirdStatusController`; never fabricate a value the webhook didn't send.

## Definition of Done (per change)
1. Red→green with Pest; `composer phpstan` (max) + `composer pint -- --test` clean.
2. One feature branch, one PR to `main`. CI matrix **PHP 8.3/8.4/8.5 × Laravel 12/13** must be green.
3. Update `README.md` + `CHANGELOG.md`. Squash-merge.
4. **Release:** `git tag vX.Y.Z && git push origin vX.Y.Z` + `gh release create`. Stay in `0.1.x`
   (Composer `^0.1` excludes `0.2.0` and would break dependents).

## Skills
This repo ships invocable skills under `.claude/skills/` — at least `rebel-package-dev` (the dev
loop + PHPStan-max recipes). Invoke it before non-trivial work.

---

> **Operational rules (Italian):** see **`AGENTS.md`** for the full workflow contract (branching,
> Definition of Done, local loop + GitHub gates, guardrails, didactic READMEs, design-lock).
