# Peyvast Auth Architecture

> **Document status:** Peyvast Auth 1.0.2

## Scope

Peyvast Auth is a WordPress plugin organized around domain rules, application use cases, WordPress infrastructure, REST presentation, and optional integrations. The bootstrap file is `peyvast-auth.php`; the PHP namespace is `Peyvast\Auth`.

## Bootstrap and lifecycle

The bootstrap defines version `1.0.2`, the text domain `peyvast-auth`, filesystem/URL constants, the settings option name, and five canonical table constants (including the OTP delivery queue). It loads the bundled Action Scheduler bootstrap from `lib/action-scheduler` at plugin load time — registering the bundled version and letting Action Scheduler itself select the newest registered runtime version, so the plugin coexists with WooCommerce or any other plugin that bundles Action Scheduler. It registers a PSR-4-like local autoloader mapping `Peyvast\Auth\X\Y` to `src/X/Y.php`, activation/deactivation callbacks, and the `plugins_loaded` boot callback.

`Core\Plugin::boot()` runs once. It registers the runtime database-engine notice, starts the guest session and phone identity index, wires the scheduler layer and recurring actions, registers privacy hooks, settings, migration services, admin UI, REST routes, frontend assets, WooCommerce, Bricks, Blocks, and redirect behavior.

## Scheduling layer

`Infrastructure\Scheduling\Scheduler` is the single owner of every plugin background action. It defines stable namespaced hook names, a dedicated `peyvast-auth` group, unique scheduling helpers, recurring-action creation/recovery (the daily `action_scheduler_ensure_recurring_actions` hook is used unconditionally, kept scheduled through `ActionScheduler_RecurringActionScheduler`), deactivation cancellation. There is no runtime capability detection or version fallback: the plugin requires the bundled Action Scheduler 4.2.0 feature set. Handlers:

- `Scheduler::HOOK_PURGE_OTP_CHALLENGES` (recurring, 15 min) — expired OTP challenge cleanup.
- `Scheduler::HOOK_PURGE_LOGS` (recurring, daily) — log retention cleanup.
- `Scheduler::HOOK_PURGE_SECURITY_STATE` (recurring, daily) — stale security-state cleanup.
- `Scheduler::HOOK_SCHEMA_WATCHDOG` (recurring, daily) — idempotent `dbDelta` maintenance.
- `Scheduler::HOOK_PHONE_MIGRATION_BATCH` (chained single actions) — phone migration batches.
- `Scheduler::HOOK_PHONE_INDEX_REBUILD` (chained single actions) — identity-index rebuild batches.
- `Scheduler::HOOK_MIGRATION_WATCHDOG` (recurring, daily) — re-arms a stalled migration/index chain.
- `Scheduler::HOOK_LAZY_PHONE_MIGRATION` (async) — background lazy phone migration.
- `Scheduler::HOOK_DELIVER_OTP` (single actions) — asynchronous provider delivery for OTP transactions queued by versions before 1.0.1. version 1.0.1 OTP requests deliver inside the request and never enqueue this action.

Every job is idempotent and bounded; retries use unique action arguments with exponential backoff; duplicate workers are prevented by database locks.

## Layer responsibilities

### Core

- `Core\Plugin`: composition root and lifecycle registration.
- `Core\Config\Settings`: defaults, merging, sanitization, provider credentials, validated page/host-safe redirect settings, phone-source configuration, and settings cache.

### Domain

- `Domain\Phone\PhoneNumber`: digit normalization, Iranian mobile validation, canonical/storage/display values, keyed hashes, lookup formats, and masking.
- `Domain\Identity\IdentityResolver`: phone/email/username resolution, conflict detection, primary metadata authority, optional configured source metadata, and index fallback.
- `Domain\Identity\IdentityResolution`: typed resolution result.
- `Domain\OTP\OtpPolicy`: OTP length, expiry, resend cooldown, cryptographically secure generation, and HMAC digesting.
- `Domain\Security\SecurityPolicy`: security scope and bucket policy derived from settings.
- `Domain\Security\Guard`: server-side rate-limit evaluation, threshold-correct fixed-window prechecks, failure recording, progressive blocks, status, snapshots, and reset behavior.
- `Domain\Authentication`: account capability and login-policy decisions.

### Application

- `Application\OTP\OtpService`: parse identifiers, resolve accounts, apply capabilities/security, create challenges, deliver the code synchronously through the provider layer, verify codes, and issue verification tokens. Unknown email logins and unknown reset identifiers receive success-shaped neutral responses (no storage, no delivery) to prevent account enumeration.
- `Application\Password\PasswordService`: password login and verified password reset. Login runs `wp_authenticate_user` plus the full core `authenticate` chain seeded with the verified user; reset holds the token in a recoverable processing state until the password is persisted, verified, and sessions revoked.
- `Application\Registration\RegistrationService`: locked account creation, metadata/index persistence, session creation, and compensating cleanup. The verified token stays in the processing state until every coupled write succeeds, and is released on every pre-finalization failure path.
- `Application\Authentication\AuthenticationSessionService`: WordPress session creation, OTP token consumption, redirect selection, and optional lazy phone migration.
- `Application\Authentication\GoogleAuthenticationService`: Google JWT and JWKS verification.
- `Application\Migration`: configured user-meta phone migration, chained batch scheduling, status, named-lock serialization for migration state, and identity-index rebuild coordination.
- `Infrastructure\Scheduling\Scheduler`: centralized Action Scheduler ownership, recurring-job lifecycle, and unique scheduling.
- `Infrastructure\Scheduling\OtpDeliveryHandler`: the single provider-delivery implementation. `deliver_now()` performs one challenge-validated delivery attempt inside the current request (the 1.0.1 path) and returns an explicit success/failure result; `deliver()` drains actions queued by earlier versions with idempotency and bounded retries. All configured channels are mandatory in both paths: a failed channel is retried independently on the queued path (the payload is rewritten to the remaining channels), and the challenge is invalidated once a channel permanently fails.

### Email delivery and templates

`Infrastructure\Providers\Email\WordPressMail` is the built-in HTML mail adapter. Email configuration is stored under `providers.email` (`sender_name`, `sender_address`, `subject`, `body`). `Settings::default_email_body()` contains the default content template as an HTML fragment, including the supported placeholders `{otp}`, `{expiration}`, `{site_name}`, `{email}`, and `{identifier}`. The default template does not embed a hardcoded Persian locale or document-level RTL attributes.

Before calling `wp_mail()`, the adapter replaces placeholders and ensures the message is a complete HTML document when the stored body is a fragment. The generated document shell derives `lang` from `get_locale()` and direction/alignment from `is_rtl()`. If an administrator supplies a complete `<html>` document, the adapter leaves its document structure and direction attributes untouched. This keeps custom templates authoritative instead of silently forcing Persian/RTL markup.

The adapter passes `Content-Type: text/html; charset=UTF-8` directly to `wp_mail()`, which is the WordPress-supported way to send an HTML message without changing the global `wp_mail_content_type` filter.

### Infrastructure

- `Infrastructure\Persistence\OtpChallengeStore`: challenge CRUD, cooldown lookup, row locking, verification, token processing, consumption, and bounded purge.
- `Infrastructure\Persistence\OtpDeliveryStore`: AES-256-GCM encrypted at-rest staging store for OTP delivery (and the delivery queue for transactions created before 1.0.1), with retry accounting and orphan cleanup. Encryption keys derive from `wp_salt('auth')` (deployment secrets, never a database-stored option).
- `Infrastructure\Persistence\PhoneIdentityIndex`: primary/configured-source index maintenance and chained rebuild jobs.
- `Infrastructure\Persistence\SecurityStateStore`: atomic security bucket state.
- `Infrastructure\Persistence\DatabaseLock`: MySQL named locks derived from a site secret, with an in-process serialization fallback when the native `GET_LOCK()` function is unavailable (for example under Studio's SQLite layer).
- `Infrastructure\Logging\Logger`: structured redacted logs, request IDs, and retention purge.
- `Infrastructure\Providers`: SMS/email adapters and normalized provider results.
- `Infrastructure\WordPress`: installer, guest cookie/nonce namespace, privacy handlers, redirects, and migration notices.

### Presentation

- `Presentation\Rest`: route registration, nonce/origin verification, permission checks, response filtering, and controllers.
- `Presentation\Frontend`: asset enqueueing and server-prepared configuration.
- `Presentation\Admin`: settings, logs, migration controls, and user profile fields.
- `Presentation\Privacy`: safe phone/email display masking.

### Integrations

WooCommerce integration is optional and guarded by function/class availability. Bricks integration registers elements/tags only when Bricks is available; element text controls resolve Bricks dynamic data before shared authentication state is prepared. Blocks integration registers the `blocks/auth` and `blocks/back-button` metadata and server renderers with WordPress block APIs, and its editor script declares the WordPress data dependency it uses.

## End-to-end flows

### OTP send

The REST controller verifies the `peyvast_auth` nonce and origin, validates purpose and configured identifier capabilities, and calls `OtpService`. The service canonicalizes the identifier, evaluates the relevant security scope, resolves the account, checks registration/reset policy, acquires a deterministic database lock, reuses a valid challenge during cooldown when appropriate, creates a new challenge, and encrypts the code and destinations into the delivery staging row. It then delivers the code inside the same request through `OtpDeliveryHandler::deliver_now()`: the challenge is re-validated under its lifecycle lock, every configured channel is mandatory, and the call returns an explicit aggregate result. On success the staging row is deleted, the challenge stays valid, and the response reports `delivery_status: delivered`; on failure the challenge is invalidated and the staging row is discarded only once that invalidation is confirmed from the database (a bounded retry follows if the per-challenge lifecycle lock is contended, and the payload is retained when confirmation fails so a usable challenge is never left without delivery evidence), and the request returns `delivery_failed` — so a code the user was told failed can never arrive later and no background job is enqueued. Failure to persist the encrypted staging row is a fail-fast `storage_failed`/`reset_unavailable` error before any provider call. Failed challenges are invalidated, and only safe challenge/information fields are returned. Unknown email logins and unknown reset identifiers get a fabricated success response with the same shape, status, cooldown, and expiry as a real challenge, so probing cannot distinguish existing from non-existing accounts.

### OTP verification and consumption

Verification checks the challenge and guest-session binding, evaluates verification limits, compares the HMAC OTP digest with bounded attempts, and stores a hashed verification token. Login/registration/reset services verify token purpose, identifier, user binding, and processing state before atomically consuming it. Tokens cannot be reused after finalization.

### Account creation

Registration locks phone, optional email, and username claims. It performs repeated authoritative resolution immediately before insertion, creates the user with the configured/default WordPress role, stores primary phone metadata, synchronizes the phone index, synchronizes WooCommerce, creates the session, and only then finalizes the verified token. The token stays in the recoverable processing state across every coupled write; any pre-finalization failure releases it and rolls the account back (recording recovery metadata if deletion fails). A finalization failure after the session exists never deletes the account or session — it is logged for recovery and the challenge expires and is purged automatically.

### Password login/reset

Password login applies settings, resolves identity, prechecks and records failures, checks the WordPress password, invokes `wp_authenticate_user` and the full core `authenticate` chain seeded with the verified user (core handlers short-circuit on a `WP_User` seed, so the password check cannot be skipped while SSO/2FA/audit plugins still participate), applies the account policy, optionally rehashes through `wp_password_needs_rehash()`, and creates a session. Reset requires a verified `password_reset` token, holds it in the processing state, updates the password, verifies persistence, destroys all existing session tokens, and only then finalizes the token; every pre-finalization failure releases it.

### Google login

Google credentials are parsed and verified with OpenSSL against a bounded WordPress HTTP response containing Google JWKS keys. Issuer, audience, algorithm, signature, subject, email, and verification claims are checked before identity resolution and login. A refresh on key miss writes a successful replacement cache and leaves the existing cache intact when the upstream request fails.

## Data model

Canonical physical tables are centralized in bootstrap constants:

- `{$wpdb->prefix}peyvast_auth_otp_challenges`: challenge ID, identity hash/type, user ID, purpose, channel list, OTP digest, expiry, attempts, verification/consumption timestamps, processing lock, guest binding, and timestamps.
- `{$wpdb->prefix}peyvast_auth_phone_identity`: user/source ownership, canonical phone hash/value, and update time.
- `{$wpdb->prefix}peyvast_auth_logs`: level, channel, event, provider, user/request IDs, message, context, and timestamps.
- `{$wpdb->prefix}peyvast_auth_security_state`: bucket key, algorithm, token/count state, progressive stage, block expiry, token hash, version, and timestamps.
- `{$wpdb->prefix}peyvast_auth_otp_deliveries`: challenge binding, AES-256-GCM ciphertext (payload/IV/tag), attempt count, and timestamps. Payloads are capped at 2048 bytes; the column is `varbinary(2048)`.

Settings, cache keys, scheduled hooks, cookies, DOM identifiers, and plugin metadata use the `peyvast_auth` naming family. Primary user metadata is `_peyvast_auth_phone` and `_peyvast_auth_phone_canonical`.

## Installation and uninstall

Activation creates/updates the five InnoDB tables with `dbDelta`, verifies required columns/indexes, installs defaults, creates the identifier HMAC key, and persists the background index state; Action Scheduler jobs are enqueued after scheduler initialization. A recurring schema watchdog re-runs the same table creation and schema verification and records schema health. Deactivation cancels only Peyvast Auth-owned Action Scheduler actions and preserves data. Uninstall removes data only when enabled in settings, including options (such as migration cancellation state), transients, scheduled actions, tables, phone metadata, related OTP rows, delivery rows, identity rows, and user logs. Production deployments should use MySQL or MariaDB; Studio's SQLite layer is intended for local development compatibility.
## 1.0.1 Production Runtime / Performance Notes

- Peyvast Auth 1.0.1 is a single-site plugin. WordPress Multisite is explicitly unsupported and activation is rejected on Multisite.
- Action Scheduler APIs are used only after `action_scheduler_init`. Activation creates/verifies schema and persists rebuild state, but does not enqueue actions before scheduler initialization.
- The phone identity index has separate schema/runtime states: `schema_missing`, `schema_invalid`, `index_building`, `index_ready`, `index_failed`, and `scheduler_unavailable`.
- Phone identity rebuild is background-only and uses bounded keyset pagination on `wp_usermeta.umeta_id`; it does not use OFFSET pagination or per-batch full `COUNT(*)` scans.
- Primary and configured legacy phone rebuilds select the authoritative first meta row per user and process each batch in a bounded transaction. This prevents stale rebuild writes from racing concurrent user-meta changes.
- Authentication does not rebuild or scan the phone index. During an index rebuild, phone-shaped usernames remain a bounded fallback; normal indexed phone resolution uses the dedicated identity table.
- Initial OTP provider delivery is synchronous: `send-otp` returns only after the attempt settles, so the user-visible latency is application processing plus provider latency, with no queue dispatch delay. `delivery-status` and its bounded polling remain available for transactions queued by versions before 1.0.1; the frontend only polls when a response reports `queued`/`retrying`.
- Dual phone OTP delivery exposes channel presence but never exposes the server-derived account email address. Server-derived email destinations are masked by the presentation layer.
