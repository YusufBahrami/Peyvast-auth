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