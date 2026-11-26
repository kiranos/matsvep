-- Authentication policy and a deterministic rotation order for refresh grace.
ALTER TABLE device_refresh_history
    ADD COLUMN rotation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD UNIQUE KEY uq_device_refresh_history_rotation (rotation_id);

INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('auth.open_registration', '0', 'boolean', 'Allow new household registration after bootstrap', 1),
    ('auth.registration_lock_s', '10', 'integer', 'Maximum wait for the first-household registration lock', 1),
    ('auth.refresh_reuse_grace_s', '60', 'integer', 'Grace for the most recently replaced refresh token', 1),
    ('auth.login_max_attempts_ip', '20', 'integer', 'Failed app logins per IP within the window', 1);

UPDATE engine_config SET description = 'Failed app logins per email within the window'
WHERE `key` = 'auth.login_max_attempts';
