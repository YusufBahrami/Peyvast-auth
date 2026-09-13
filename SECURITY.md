# Security Policy

> **Document status:** Approved — Peyvast Auth 1.0.1 (2026-09-13).

## Supported release

The current plugin version is 1.0.1.

## Security design

Peyvast Auth treats browser requests as untrusted. State-changing REST requests require a plugin nonce and same-origin validation. Administrative routes additionally require the `manage_options` capability. Guest nonce identities use a negative namespace distinct from WordPress user IDs, and guest challenges are bound to an opaque random HttpOnly SameSite cookie. OTPs, verification tokens, and identifier hashes are represented by site-keyed HMACs in the database, expire, have bounded attempts, and are consumed once.

Server-side security state applies limits by relevant scope and dimensions such as IP, network, identifier, and IP/identifier combination. Fixed-window enforcement rejects requests at the configured limit, while password failures are handled with generic responses. Unknown email OTP logins and unknown password-reset identifiers receive success-shaped neutral responses — same status, cooldown, and expiry shape, with nothing stored or delivered — so account existence cannot be inferred from the API; rate limits still apply identically to probing attempts. Login page settings accept only published or private pages, and custom redirects are restricted to the local site. Provider credentials and sensitive response fields are excluded from safe REST success payloads, credentials are never placed in request URLs, provider diagnostics redact credentials and raw response bodies, and operational logs use stable error categories rather than raw database error text.

OTP provider delivery happens inside the authentication request in 1.0.1: the plaintext code is never placed in Action Scheduler arguments, logs, or API responses. It is held only in the request scope and in an AES-256-GCM encrypted delivery staging row (the delivery table also remains the queue for transactions created by earlier versions). The encryption key is derived from the WordPress deployment secrets via `wp_salt('auth')` — never from a database-stored option — so a database dump alone cannot decrypt staged or queued codes. The challenge is re-validated under its lifecycle lock immediately before sending, so an invalidated, replaced, expired, or consumed challenge can never be delivered. All configured channels are mandatory: the request succeeds only when every configured channel accepted the code, and a failed attempt invalidates the challenge and discards the staged payload only once the database confirms the challenge can no longer be used (the payload is retained otherwise), so a session can never be restored from an undelivered code, a usable challenge is never left without delivery evidence, and no failed code can be retried into an unexpected late delivery. Failure to persist the encrypted staging row fails fast before any provider HTTP work.

## Deployment requirements

Use HTTPS, keep WordPress/PHP and integrations updated, protect administrator accounts, use provider credentials with the minimum available privileges, configure realistic provider timeouts, and monitor delivery failures without recording OTPs or secrets. Test reverse proxies so the application sees the correct site URL and secure-cookie behavior. Google JWKS refreshes preserve the prior cache if Google is unreachable. WordPress Studio's SQLite compatibility layer is for local development; production should use MySQL or MariaDB because the plugin uses MySQL-native locking and SQL behavior. The plugin detects a non-MySQL engine at runtime and surfaces an admin notice instead of silently reporting a ready schema.

## Reporting a vulnerability

Do not disclose an unpatched vulnerability in a public issue. Contact me through the repository owner's verified private security channel and provide the affected version, environment, impact, reproduction steps or proof of concept, and a proposed mitigation. Remove credentials, user data, OTPs, cookies, and provider secrets from the report.

The maintainer should acknowledge the report, reproduce it, assess severity, prepare a fix, and coordinate disclosure. Configure GitHub private vulnerability reporting or a verified security contact before public release.