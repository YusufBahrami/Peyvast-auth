=== Peyvast Auth ===
Contributors: yusufbahrami
Tags: authentication, otp, sms, login, one-time password
WordPress: 6.8+
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Phone & email OTP login, password sign-in, registration, and password recovery for WordPress — with Google sign-in, SMS/email providers, and WooCommerce support.

== Description ==

Peyvast Auth brings modern, phone-first authentication to WordPress. Visitors sign in with a one-time code delivered by SMS or email — no password to remember — while existing users can still log in with their password. Registration, password recovery, and optional Google sign-in are built in.

= Authentication features =

* OTP login and registration via SMS or email, with resend cooldowns, expiry, and bounded attempts
* Password login supporting phone, email, or username resolution
* Password recovery that revokes all existing sessions and never auto-signs the user in
* Optional secondary OTP delivered to the user's email when one exists
* Optional Google sign-in with full local ID-token verification (signature, issuer, audience, and claims)

= Built for your stack =

* Server-rendered Blocks and Bricks elements with editor controls and dynamic data support
* Back buttons return to the previous authentication stage, or to the previous page from the initial stage
* WooCommerce integration: customer phone synchronization, account/checkout/order-receipt redirects, and HPOS compatibility
* Pluggable SMS and email provider adapters, configured under the Providers settings with a built-in test endpoint
* Customizable HTML OTP email template with locale-aware document direction and no forced Persian/RTL markup
* Tools to migrate existing users to verified phone numbers
* Translation-ready, including a bundled Persian language pack

= Security first =

* OTPs, verification tokens, and identifier hashes use site-keyed HMACs — never plaintext
* Nonce and origin validation plus argument schemas on every REST route, with public permission callbacks enforcing both
* Guest nonce identities use a negative namespace, and guest challenges are bound to an HttpOnly, SameSite cookie
* Server-side rate limiting with IP, network, and identifier buckets, threshold-correct fixed-window enforcement, and progressive blocking
* Enumeration-neutral responses: unknown email logins and unknown reset identifiers return a success-shaped response with nothing stored or sent
* Initial OTP delivery happens inside the request and all configured delivery channels are mandatory: the OTP stage appears only after every configured channel accepted the code, and a failed send returns a real error instead of a queued action
* Redirect page IDs are validated against published/private pages and custom URLs are restricted to the local site
* Google key refreshes preserve an existing JWKS cache when the upstream endpoint is unavailable
* Provider credentials are sanitized, never sent in request URLs, never returned in API responses, and redacted from diagnostics; delivery encryption keys derive from the WordPress deployment secrets, not the database
* Privacy export and erasure for phone data, aligned with WordPress privacy tools

Activation creates five dedicated InnoDB tables for OTP challenges, the phone identity index, operational logs, security state, and the encrypted OTP delivery store. Initial OTP provider delivery is attempted synchronously during the authentication request — the code is encrypted at rest while it is staged and is deleted once the attempt settles — so no queue latency or frontend polling is involved in the normal send path. Operational logs redact sensitive values and support retention cleanup. Background maintenance (challenge/log/security-state cleanup, schema watchdog, phone migration, and identity-index rebuild) still runs on the bundled Action Scheduler, which coexists with WooCommerce and other plugins that provide Action Scheduler, and any OTP delivery action queued by a version before 1.0.1 is drained safely. Data removal on uninstall is conditional on the "delete data on uninstall" setting and includes migration cancellation state. Production deployments should use MySQL or MariaDB; WordPress Studio's SQLite compatibility layer is intended for local development.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/peyvast-auth`, or install the ZIP via Plugins → Add New.
2. Activate **Peyvast Auth**.
3. Open **Peyvast Authentication → Settings**.
4. Configure at least one SMS provider or WordPress email delivery.
5. Add the **Peyvast Auth** Blocks or Bricks element to a page.
6. Test all enabled flows on staging before production use.

== Frequently Asked Questions ==

= Is WooCommerce required? =

No. The WooCommerce integration is optional and only activates when WooCommerce is present. When enabled it synchronizes customer phone numbers and supports account, checkout, and order-receipt redirects.

= Can users sign in without a password? =

Yes. Visitors request a one-time code by phone number or email, receive it through the configured SMS or email provider, verify it, and a session is created. If no account exists yet, the same verification can complete registration (Registration is phone number only / Email Login is Optional).

= Does Google sign-in need extra configuration? =

Yes. Enable it in settings and provide your Google client ID; the plugin verifies ID tokens locally — structure, algorithm, signature, issuer, audience, and claims — before creating the session.

= What happens to my sessions after a password reset? =

All existing WordPress sessions are revoked and the user must sign in again. The verified reset code is bound to the intended user and consumed during the password update.

= Where is my data stored? =

Settings and operational keys live under the `peyvast_auth_` namespace. Five custom tables store OTP challenges, the phone identity index, redacted operational logs, security state, and the encrypted OTP delivery store. Uninstall cleanup is conditional on the administrator's "delete data on uninstall" setting.

== Upgrade Notice ==

= 1.0.1 =
OTP delivery is now performed inside the send-otp request, so the verification-code stage appears only after the configured channels actually accepted the code; a queued background action is never reported as a sent code. A failed initial delivery returns a real error and invalidates the challenge instead of leaving a code that could arrive later, and new requests no longer create Action Scheduler delivery jobs. Action Scheduler still runs maintenance, cleanup, watchdogs, phone migration, the identity-index rebuild, and any OTP delivery action queued by an earlier version. The default SMS provider timeout is now 10 seconds (was 15) because the request waits for the provider; saved settings keep their configured value. WordPress 6.8+ and PHP 8.1+ are required.

= 1.0.0 =
First release. Background work runs on the bundled Action Scheduler: OTP delivery is asynchronous and encrypted at rest, recurring maintenance is batch-limited, and the phone migration and identity-index rebuild are chained jobs with retries and recovery. Registration and password reset finalize their verification tokens only after every coupled write succeeds, all configured delivery channels are mandatory with per-channel retries, and unknown accounts receive enumeration-neutral responses. REST routes have argument schemas and nonce/origin permission callbacks, provider credentials are never placed in URLs or logs, and delivery encryption keys derive from the WordPress deployment secrets. Identifier hashes are site-keyed, guest nonces use a separate namespace, redirect page settings are validated, and migration state locking is serialized. WordPress 6.8+ and PHP 8.1+ are required. Production deployments should use MySQL or MariaDB.

== Changelog ==

= 1.0.1 =
* Changed: initial OTP delivery is attempted synchronously inside the send-otp request, so the verification-code stage is shown only after the configured SMS/email channels accepted the code
* Changed: the normal send path no longer depends on Action Scheduler dispatch latency or on frontend delivery-status polling; the delivery-status endpoint and its bounded polling remain for OTP transactions created by earlier versions
* Changed: the authentication area shows one notice at a time — each new message replaces the previous one, so a sequence of actions no longer stacks notices; WordPress and other plugins' notices are untouched
* Changed: the default SMS provider timeout is now 10 seconds (still configurable from 5 to 60) because the request waits for the provider
* Fixed: a queued background job was reported as successful OTP delivery, so the verification-code stage could open before the code had been sent; a failed delivery now returns a real error and keeps the user on the identifier or password-reset stage
* Fixed: a failed delivery could leave a valid challenge behind after its staged delivery payload had already been discarded, and the delivery-status and resend paths then read that challenge as a completed delivery; the challenge is now invalidated and the payload is discarded only once the database confirms the challenge can no longer be used
* Fixed: the delivery-status check could report a live challenge with no staged delivery payload as delivered instead of reporting its real state
* Fixed: phone identity index readiness could stay false for the remainder of a request after the database schema had already been repaired

= 1.0.0 =
* Action Scheduler integration with bundled 4.2.0 bootstrap and multi-plugin version arbitration
* Asynchronous encrypted OTP provider delivery with challenge re-validation and bounded retries
* Recurring maintenance: OTP challenge cleanup (15 min), log retention and security-state cleanup (daily), schema watchdog (daily)
* Phone migration and identity-index rebuild as chained, idempotent, retryable background jobs with watchdog recovery
* Lazy phone migration moved to background processing
* WordPress 6.8+ and PHP 8.1+ required
* All background work runs on the bundled Action Scheduler
* New `{prefix}peyvast_auth_otp_deliveries` table for the encrypted delivery queue
* Site-keyed OTP identifier hashes, isolated guest nonce IDs, validated login-page redirects, threshold-correct rate-limit prechecks, and serialized migration state locks
* Safe JWKS cache refresh behavior and complete uninstall cleanup for migration cancellation state
* Token-ordering fix for registration and password reset (token consumed only after all coupled writes succeed, released on failure, account rolled back)
* Mandatory-channel OTP delivery with per-channel retries; enumeration-neutral responses for unknown email/reset identifiers
* Full core `authenticate` chain parity for password login; delivery encryption keys derived from deployment secrets; no backward compatibility with previous plugin versions
* REST argument schemas and nonce/origin permission callbacks; provider credentials never in URLs or diagnostics; redacted operational logs
* Runtime database-engine detection with an admin notice; in-process lock fallback for non-MySQL environments
