# API foundation authentication and idempotency

The app API uses random opaque access and refresh tokens for both admin and member users. Only SHA-256 hashes are stored. Each device has its own rotating refresh token. An old refresh token's reuse revokes that device's tokens. Admin pages continue to use their separate session login and IP allowlist; app bearer tokens cannot enter them. The server counts failed logins independently by email and IP. Token lifetimes, login limits and idempotency retention are engine configuration values.

Retryable writes scope `Idempotency-Key` to the authenticated user and endpoint, with a hash of the raw request body. The same key and body replay the original status and JSON result. Reuse with a changed body returns HTTP 422. A replay check runs before plan version validation. API responses contain codes and parameters, never display text.
