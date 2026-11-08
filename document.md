# document.md — current technical documentation

> **Document status:** Approved — Peyvast Auth 1.0.2 (2026-10-29).

Reference for the behaviour of **Peyvast Auth 1.0.2** as it exists today. It describes the code, not an aspiration. Layer boundaries live in `ARCHITECTURE.md`; file-level layout lives in `STRUCTURE.md`.

## 1. Identity and requirements

| | |
|---|---|
| Plugin | `peyvast-auth`, version `1.0.2` (`peyvast-auth.php` header, `PEYVAST_AUTH_VERSION`, `readme.txt` stable tag, `changelog.txt`) |
| Requirements | WordPress 6.8+, PHP 8.1+ |
| Database | MySQL/MariaDB with InnoDB required in production. `Installer::schema_ready()` verifies the InnoDB engine via `SHOW TABLE STATUS`, so it reports not-ready on non-MySQL engines (Studio's SQLite layer is a local-development convenience) |
| Bundled dependency | Action Scheduler 4.2.0 in `lib/action-scheduler`, loaded through its official bootstrap so the newest registered version wins when WooCommerce or another plugin also bundles it |
| Text domain | `peyvast-auth` (`languages/peyvast-auth.pot`, `-fa_IR.po`, `-fa_IR.mo`) |

## 2. Runtime map

1. `peyvast-auth.php` defines constants (`PEYVAST_AUTH_VERSION`, `PEYVAST_AUTH_FILE`, `PEYVAST_AUTH_DIR`, `PEYVAST_AUTH_OPTION`, and the five table constants), loads the bundled Action Scheduler bootstrap, registers a PSR-4-style autoloader, and registers activation/deactivation hooks.
2. `Core\Plugin::boot()` on `plugins_loaded` wires settings, scheduling, REST routes, admin screens, integrations and the phone-identity index.
3. `Scheduler::boot()` registers hooks and recurring actions (from `action_scheduler_init`; nothing is scheduled before Action Scheduler is initialised).
4. `Installer::activate()` refuses multisite, creates/verifies the five tables, installs settings defaults, creates the identifier HMAC key (`peyvast_auth_identifier_key`), writes schema health and persists the index rebuild state.

## 3. Data model

Five plugin-owned tables (prefix `{wpdb->prefix}`, names from constants):

| Table | Purpose | Notable columns |
|---|---|---|
| `peyvast_auth_otp_challenges` | OTP challenges and verification tokens | `challenge_id` (UUID, unique), `identifier_hash` (HMAC), `user_id`, `purpose`, `channels`, `otp_hash` (HMAC digest, replaced by the token digest on verify), `expires_at`, `resend_available_at`, `attempts`, `verified_at`, `consumed_at`, `processing_at`, `guest_session_hash` |
| `peyvast_auth_otp_deliveries` | Encrypted delivery staging (and the queue for pre-1.0.1 jobs) | `challenge_id` (PK), `payload`/`iv`/`tag` (AES-256-GCM), `attempts`, `updated_at` |
| `peyvast_auth_phone_identity` | Phone identity index | `user_id`, `source` (`primary`/`legacy_meta`), `canonical_hash`, `canonical_phone`, unique `(user_id, source)` |
| `peyvast_auth_logs` | Operational logs | `level`, `channel`, `event`, `provider`, `user_id`, `request_id`, `message`, `context`, `created_at` |
| `peyvast_auth_security_state` | Authoritative rate-limit/block state | `bucket_key` (PK, HMAC), `algo` (token bucket = 1, fixed window = 2), `tokens`, `capacity`, `count`, `window_started_at`, `stage`, `blocked_until`, `token_hash`, `version` |

Plugin-owned options include `peyvast_auth_settings`, `peyvast_auth_identifier_key`, `peyvast_auth_schema_health`, `peyvast_auth_phone_identity_index_state`, `peyvast_auth_phone_identity_index_generation`, and the migration job/cancel/lock/notice options. No schema migration is required for the 1.0.1 fixes.

## 4. OTP lifecycle

### 4.1 New request (`POST peyvast-auth/v1/send-otp`)

The order below is the security contract; nothing may be moved earlier.

1. **REST permission** — nonce (`peyvast_auth` action) and same-origin check (`RestRouter::public_permission`).
2. **Plugin enabled** — `general.enabled`.
3. **Purpose validation** — `otp_login` or `password_reset` only.
4. **Identifier validation** — phone (canonical), email (`sanitize_email` + `is_email`), or username when the purpose permits; invalid input is itself rate-limited via the `invalid_identifier` guard.
5. **Security guard** — `Guard::evaluate( SCOPE_OTP_SEND | SCOPE_PASSWORD_RESET, ['identifier' => ...] )` over IP/network/identifier/combination buckets; denials return `security_blocked`, `send_rate_limited` or `security_unavailable`.
6. **Identity resolution** — `IdentityResolver` (phone index, user meta, `user_login`); conflicts/denials are reported as `identity_conflict`/`identity_denied` except for password reset, which stays neutral.
7. **Method availability** — `AuthenticationCapabilities`; `registration.enabled` is enforced for new accounts.
8. **Enumeration neutrality** — an unknown email for OTP login and an unknown identifier for password reset return a success-shaped response with the same cooldown/expiry shape and create/store nothing.
9. **Per-identifier lock** — `DatabaseLock::acquire( 'otp-send:{purpose}:{hash}' )`, so concurrent duplicate requests serialise.
10. **Cooldown / reuse** — an active challenge inside `resend_available_at` is returned as `restored: true` (unless `force_resend=1`, which returns the rate-limit error instead of silently reusing).
11. **Challenge creation** — a UUID challenge with an HMAC OTP digest (`OtpPolicy::hash_code`), `expires_at`, `resend_available_at`, and the guest-session hash when not logged in.
12. **Encrypted staging** — `OtpDeliveryStore::store()` writes the code and per-channel recipients as an AES-256-GCM row; failure is a fail-fast `storage_failed`/`reset_unavailable` with **no provider call**. The plaintext never reaches scheduler arguments, logs or responses.
13. **Synchronous delivery** — `OtpDeliveryHandler::deliver_now()` takes the per-challenge lifecycle lock, re-validates the challenge (exists, not consumed, not verified, not expired), decrypts the payload and sends **every configured channel** (all mandatory).
14. **Outcome**
    - Success: staging row deleted, challenge stays valid, response `delivery_status: delivered` with `challenge_id`, `identifier_type`, `restored`, `retry_after`, `expires_in`, `channels`, privacy-safe `information`.
    - Failure: `invalidate_and_cleanup_challenge()` invalidates the challenge and discards the payload **only after reading back `consumed_at`**; if the lifecycle lock cannot be acquired it retries once with a 1 s wait and, when still unconfirmed, keeps the payload (so a usable challenge is never left without delivery evidence) and logs `otp_delivery_cleanup_deferred`. The request returns `delivery_failed`. No background retry is scheduled.

No Action Scheduler action is created on this path.

### 4.2 Resend

`force_resend=1` re-enters the same flow: it invalidates the previous challenge (confirmed cleanup as above) and creates a replacement. Cooldown, rate limits, guest-session binding, expiry and attempt limits are unchanged.

### 4.3 Verification (`POST verify-otp`)

Guest-session binding and the `SCOPE_OTP_VERIFY` guard are checked first, then `OtpChallengeStore::verify_code()` runs under `SELECT ... FOR UPDATE`: it rejects consumed/verified/expired rows, compares the HMAC digest with `hash_equals`, increments `attempts` on mismatch (consuming the challenge at `security.verify_limit`, default 5), and on success stores the SHA-256 digest of a fresh 48-character token in `otp_hash` and sets `verified_at`. The response carries `verification_token`, `user_exists`, `next_stage` (`login`/`register`), `purpose` and `identifier_type`.

### 4.4 Delivery status (`POST delivery-status`)

Retained for compatibility. A staging row is reported as `queued` (0 attempts) or `retrying` (≥1 attempts) **only while a matching `peyvast_auth/deliver_otp` action is still scheduled** — that is what distinguishes a transaction left over from an earlier version from a failed synchronous attempt whose payload was retained by design; without a scheduled action it reports `failed`. A consumed or expired challenge reports `failed`, and a live challenge with no staging row reports `delivered` (in this data model the payload is only removed once delivery settled). When Action Scheduler itself is unavailable, the pending state is reported rather than downgraded to `failed`, so a missing scheduler can never turn a real pending delivery into a failure. It requires a valid challenge and guest-session match, and never exposes payloads, recipients or provider data. New 1.0.1 requests do not need it; the frontend only polls when a response reports `queued`/`retrying`.

The same discriminator governs the cooldown-reuse response (`OtpService::response_for()`), so a user retrying inside the resend cooldown is never sent to the OTP stage for a challenge nothing will deliver.

## 5. Registration, password reset, password login, Google

- **Registration** (`POST register`): the verified token is claimed atomically (`begin_verified_token_processing`, `processing_at`, 300 s staleness window), the user is created, the token is finalised (`finish_verified_token_processing`) only after every coupled write succeeds, and the phone identity index is updated. Failures release the token (`release_verified_token_processing`) so the user can retry. No Action Scheduler involvement.
- **Password reset** (`POST reset-password`): a verified `password_reset` token is previewed, the password changes, sessions are revoked, and the response returns `next_stage: password`. **Password reset never authenticates the user.**
- **Password login** (`POST password-login`): phone/email/username resolution, `wp_authenticate`, account policy (e.g. multisite restrictions), remember-me and redirect handling. Security failures are recorded with progressive blocking.
- **Google login** (`POST google-login`): nonce, RS256 + JWKS (cached in a transient), issuer/audience/expiry/email-verified validation, then account linking by a keyed identity hash.

## 6. REST surface

Namespace `peyvast-auth/v1`, all routes `POST` with an explicit permission callback and per-argument schemas.

- Public (nonce + same-origin): `send-otp`, `delivery-status`, `verify-otp`, `otp-login`, `register`, `password-login`, `reset-password`, `google-login`, `logout`, `security-status`.
- Admin (`manage_options` + nonce): `settings`, `security-blocks`, `migrate-user-phone`, `migration-status`, `migration-notice`, `validate-phone-meta`, `test-provider`.

Responses are `{ success, data }`; public payloads are whitelisted in `RestResponse::PUBLIC_FIELDS`, and error responses pass only `field`, `token`, `retry_after` and `client_block` through, so internal reasons never leave the server. Status mapping: `auth_disabled`/`security_unavailable`/`storage_failed`/`storage_busy`/`delivery_unavailable`/`identity_unavailable` → 503, `security_blocked`/`send_rate_limited`/`verify_rate_limited`/`security_rate_limited` → 429, `invalid_request`/`forbidden` → 403, otherwise 400.

## 7. Security model

- **Identifiers at rest:** keyed HMAC (`hash_hmac('sha256', "{type}:{value}", peyvast_auth_identifier_key)`); raw identifiers are never stored in security buckets.
- **Rate limiting:** `SecurityStateStore` runs token-bucket and fixed-window algorithms in one short transaction, loading `{wpdb->prefix}peyvast_auth_security_state` with a fixed dimension order (`ip`, `network`, `identifier`, `combination`), version-guarded success resets and atomic no-downgrade blocks.
- **Guest sessions:** `GuestSession` binds challenges to a rotating guest cookie; guest nonces use negative user IDs so they cannot collide with real users.
- **Delivery secrecy:** AES-256-GCM with a key derived from `wp_salt('auth')`; only the staging row holds the code, and it is deleted once the attempt settles.
- **Progressive blocking, replay protection and one-time tokens:** blocks escalate with a multiplier/decay policy; a verified token is single-use and processed exactly once (`processing_at`, `consumed_at`).
- **Provider hygiene:** `ProviderManager` redacts credentials/bodies in diagnostics, records duration per attempt, and registers the OTP with `Logger::add_secret()`.
- **Logging:** never contains the OTP, credentials, recipient secrets beyond masked presentation, or provider response bodies.

## 8. Background work (Action Scheduler, group `peyvast-auth`)

| Hook | Cadence | Purpose |
|---|---|---|
| `HOOK_PURGE_OTP_CHALLENGES` | 15 min | Purge expired/consumed challenges (200-row batches, ≤50 iterations) |
| `HOOK_PURGE_LOGS` | daily | Log retention cleanup |
| `HOOK_PURGE_SECURITY_STATE` | daily | Purge stale security rows |
| `HOOK_SCHEMA_WATCHDOG` | daily | Re-verify/repair schema and record health |
| `HOOK_MIGRATION_WATCHDOG` | daily | Re-arm a stalled migration chain |
| `HOOK_PHONE_MIGRATION_BATCH` | chained | One migration batch (50 users) per action, idempotent, lock-serialised, bounded retries (5) with exponential backoff, 600 s stall detection |
| `HOOK_PHONE_INDEX_REBUILD` | chained | One index rebuild batch (250 rows) per action; phases `clear_primary` → `build_primary` → `clear_legacy` → `build_legacy` → `complete`, generation-guarded so stale chains are dropped |
| `HOOK_LAZY_PHONE_MIGRATION` | per user, unique | Background lazy migration |
| `HOOK_DELIVER_OTP` | legacy only | Drains OTP delivery actions created before 1.0.1 with challenge re-validation, idempotency and per-channel retry; new requests never enqueue it |

`Scheduler::schedule_async()` and the rest of the abstraction remain available; deactivation cancels only plugin-owned actions.

## 9. Frontend

`assets/js/auth.js` drives the identifier → OTP → verify → login/register/password stages. It enters the OTP stage only when the send response reports `channels` and `delivery_status: delivered`; `queued`/`retrying` (legacy challenges only) additionally start the bounded `delivery_status` poll (≤7 attempts, exponential). Delivery failure keeps the user on the current stage. Back navigation tracks the visited stage stack, including browser Back changes; the initial phone stage delegates to browser history so the button returns to the previous page. Web OTP autofill and the information/privacy presentation are unchanged.

Blocks (`blocks/auth`, `blocks/back-button`) are registered from their `block.json` metadata through WordPress block APIs and render server-side. The editor script declares its `wp.data` dependency for block selection and uses server-side rendering for previews. Bricks element text controls resolve dynamic data before preparing the shared authentication state; Bricks CSS controls remain responsible for their native styling bindings. Both integrations and WooCommerce use the same REST endpoints.

## 10. Privacy, uninstall, integrations

- WordPress privacy: export/erase integrate with plugin data.
- Uninstall removes plugin tables, options, transients, phone meta and scheduled actions only when `general.delete_data_on_uninstall` is enabled; multisite activation is refused, so single-site removal is the only case.
- Email delivery always goes through `wp_mail()` (with a customisable template); SMS through the six bundled adapters (Kavenegar, Melipayamak, Msgway, Sms.ir, IPPanel API-key and username/password). SMS requests use `providers.sms.timeout` (default 10 s, clamped 5–60 s). Provider tests are independent of the OTP lifecycle and never reuse a user challenge.

## 11. Known limitations and residual risks

- Provider latency is external: the send request cannot promise an absolute delivery time, only that it waits for the attempt.
- If a synchronous delivery fails and its cleanup cannot confirm challenge invalidation (the lifecycle lock is held for >6 s), the challenge stays live with its staged payload until expiry or orphan purge. It is now reported as `failed` rather than pending, and the request is logged as `otp_delivery_cleanup_deferred`.
- If `OtpDeliveryStore::store()` fails, the request fails with `storage_failed` and the challenge is invalidated without waiting for its lifecycle lock: there is no payload to retain, so a live challenge would later be read as `delivered`. A direct conditional `UPDATE` is used as the fallback and a failure to confirm it is logged as `otp_challenge_invalidation_failed` — that log is the only remaining window, and it requires the `UPDATE` itself to fail.
- Per-channel delivery is sequential by design (no concurrency infrastructure), so a multi-channel send waits for every configured channel in turn.
- `msgfmt` is unavailable in this environment, so `.po` changes need the maintainer's tooling to recompile `.mo`.

## 12. Documentation set

| Document | Role | Status |
|---|---|---|
| `ARCHITECTURE.md` | Component, layer and flow reference | Approved |
| `STRUCTURE.md` | File and directory map | Approved |
| `document.md` | Current technical documentation (this file) | Approved |
| `CONTROLLER_AUDIT.md` | Bricks and Blocks editor controllers, render targets, and connection status | Static audit |
| `README.md` | Installation and behaviour reference | Approved |
| `SECURITY.md` | Security policy, design and reporting | Approved |
| `changelog.txt` | Release history (distribution file) | Approved |
| `readme.txt` | WordPress.org plugin readme (distribution file) | Approved |

Every document above is approved for the Peyvast Auth 1.0.1 release. The two distribution files keep their parser-defined formats, so their approval is recorded in this table rather than as an in-file marker.
