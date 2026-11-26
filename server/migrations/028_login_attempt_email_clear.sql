-- Preserve IP failure counts after a successful login clears an email's failures.
ALTER TABLE app_login_attempt
    MODIFY COLUMN email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL;
