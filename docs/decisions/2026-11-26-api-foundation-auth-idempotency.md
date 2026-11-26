# API foundation authentication and idempotency

The app API uses random opaque access and refresh tokens for both admin and member users. Only SHA-256 hashes are stored. Each device has its own rotating refresh token. Admin pages continue to use their separate session login and IP allowlist; app bearer tokens cannot enter them. The server counts failed logins independently by email and IP. Token lifetimes, login limits and idempotency retention are engine configuration values.

New household registration is closed by default after the first household exists; invite registration is always allowed. A registration lock protects the bootstrap exception. The most recently replaced refresh token has a short configured retry grace, but only for an active, unexpired device. Older or late reuse revokes the device. Email and IP have separate login limits, and a successful login clears only email failures. A nightly job purges expired API state.

Retryable writes scope `Idempotency-Key` to the authenticated user and endpoint, with a hash of the raw request body. The same key and body replay the original status and JSON result. A revoked access token may only replay its own stored revoke result until that access token expires; it cannot perform a new action. Reuse with a changed body returns HTTP 422. A replay check runs before plan version validation. API responses contain codes and parameters, never display text.

Idempotency owns the outer API write transaction, including storage of the response. Write services begin a transaction only when none is active and otherwise join the caller's transaction. This supports both API calls and standalone CLI or nightly jobs; PlanManager must join before PR C uses it for idempotent plan mutations.
