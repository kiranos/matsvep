-- Opaque app tokens, refresh replay detection, and endpoint-scoped idempotency.
ALTER TABLE device_token
    ADD COLUMN access_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER refresh_token_hash,
    ADD COLUMN access_expires_at DATETIME(6) NULL AFTER expires_at,
    ADD UNIQUE KEY uq_device_token_access_hash (access_token_hash);

CREATE TABLE device_refresh_history (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    device_token_id BIGINT UNSIGNED NOT NULL,
    rotated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_device_refresh_history_device (device_token_id),
    CONSTRAINT fk_device_refresh_history_device FOREIGN KEY (device_token_id)
        REFERENCES device_token (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE app_login_attempt (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ip_address VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_app_login_attempt_email (email_hash, attempted_at),
    KEY ix_app_login_attempt_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

ALTER TABLE api_idempotency
    DROP PRIMARY KEY,
    ADD COLUMN endpoint VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER user_id,
    ADD PRIMARY KEY (user_id, endpoint, idempotency_key);

ALTER TABLE api_idempotency ALTER COLUMN endpoint DROP DEFAULT;

INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('auth.login_max_attempts', '5', 'integer', 'Failed app logins per email or IP within the window', 1),
    ('auth.login_window_s', '900', 'integer', 'App login failure counting window in seconds', 1),
    ('auth.password_min_length', '12', 'integer', 'Minimum member registration password length', 1),
    ('auth.token_bytes', '32', 'integer', 'Random bytes in each opaque app token', 1),
    ('api.idempotency_lock_s', '10', 'integer', 'Maximum wait for a concurrent request with the same key', 1),
    ('api.search_page_size', '50', 'integer', 'Recipe search results per page', 1);
