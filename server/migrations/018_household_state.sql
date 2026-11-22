-- Household and app identity. Existing admin users remain unlinked.
CREATE TABLE household (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    size SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Stockholm',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT ck_household_size CHECK (size BETWEEN 1 AND 100),
    CONSTRAINT ck_household_timezone CHECK (CHAR_LENGTH(TRIM(timezone)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

ALTER TABLE `user`
    MODIFY COLUMN role ENUM('admin', 'member') NOT NULL DEFAULT 'admin',
    ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER role,
    ADD KEY ix_user_household (household_id),
    ADD UNIQUE KEY uq_user_id_household (id, household_id),
    ADD CONSTRAINT fk_user_household FOREIGN KEY (household_id) REFERENCES household (id),
    ADD CONSTRAINT ck_user_member_household CHECK (role <> 'member' OR household_id IS NOT NULL);

CREATE TABLE device_token (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    platform ENUM('android', 'windows') NOT NULL,
    refresh_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    push_token VARCHAR(512) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    last_seen_at DATETIME(6) NULL,
    UNIQUE KEY uq_device_token_refresh_hash (refresh_token_hash),
    KEY ix_device_token_user (user_id, revoked_at),
    KEY ix_device_token_expiry (expires_at),
    CONSTRAINT fk_device_token_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
    CONSTRAINT ck_device_token_expiry CHECK (expires_at > created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE household_invite (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    used_by BIGINT UNSIGNED NULL,
    used_at DATETIME(6) NULL,
    UNIQUE KEY uq_household_invite_code_hash (code_hash),
    KEY ix_household_invite_household (household_id, expires_at),
    KEY ix_household_invite_creator (created_by, household_id),
    KEY ix_household_invite_used_by (used_by, household_id),
    CONSTRAINT fk_household_invite_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_invite_creator FOREIGN KEY (created_by, household_id)
        REFERENCES `user` (id, household_id),
    CONSTRAINT fk_household_invite_used_by FOREIGN KEY (used_by, household_id)
        REFERENCES `user` (id, household_id),
    CONSTRAINT ck_household_invite_expiry CHECK (expires_at > created_at),
    CONSTRAINT ck_household_invite_use_pair CHECK ((used_by IS NULL) = (used_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE api_idempotency (
    user_id BIGINT UNSIGNED NOT NULL,
    idempotency_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_status SMALLINT UNSIGNED NOT NULL,
    response_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_id, idempotency_key),
    KEY ix_api_idempotency_expiry (expires_at),
    CONSTRAINT fk_api_idempotency_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
    CONSTRAINT ck_api_idempotency_key_length CHECK (CHAR_LENGTH(idempotency_key) = 36),
    CONSTRAINT ck_api_idempotency_expiry CHECK (expires_at > created_at),
    CONSTRAINT ck_api_idempotency_status CHECK (response_status BETWEEN 200 AND 599)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE household_setting (
    household_id BIGINT UNSIGNED NOT NULL,
    `key` VARCHAR(120) NOT NULL,
    value JSON NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (household_id, `key`),
    CONSTRAINT fk_household_setting_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

-- Global defaults are separate because MySQL foreign keys cannot coexist with
-- the generated scope column needed to make nullable default rows unique.
CREATE TABLE day_profile_default (
    weekday TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    profile_code VARCHAR(80) NOT NULL,
    CONSTRAINT ck_day_profile_default_weekday CHECK (weekday BETWEEN 0 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

INSERT INTO day_profile_default (weekday, profile_code) VALUES
    (0, 'weekday_leftovers'),
    (1, 'weekday_leftovers'),
    (2, 'weekday_leftovers'),
    (3, 'weekday_leftovers'),
    (4, 'weekday_leftovers'),
    (5, 'friday_treat'),
    (6, 'saturday_dinner');

CREATE TABLE day_profile (
    household_id BIGINT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    profile_code VARCHAR(80) NOT NULL,
    PRIMARY KEY (household_id, weekday),
    CONSTRAINT fk_day_profile_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE,
    CONSTRAINT ck_day_profile_weekday CHECK (weekday BETWEEN 0 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE household_recipe (
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    status ENUM('library', 'removed', 'blocked') NOT NULL DEFAULT 'library',
    wishlist BOOLEAN NOT NULL DEFAULT FALSE,
    rating TINYINT UNSIGNED NULL,
    rating_source ENUM('onboarding', 'user') NULL,
    rated_at DATETIME(6) NULL,
    last_cooked_at DATE NULL,
    times_cooked INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (household_id, recipe_id),
    KEY ix_household_recipe_recipe (recipe_id),
    KEY ix_household_recipe_wishlist (household_id, wishlist),
    KEY ix_household_recipe_rating (household_id, rating),
    CONSTRAINT fk_household_recipe_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_recipe_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id),
    CONSTRAINT ck_household_recipe_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),
    CONSTRAINT ck_household_recipe_rating_pair CHECK (
        (rating IS NULL AND rating_source IS NULL AND rated_at IS NULL)
        OR (rating IS NOT NULL AND rating_source IS NOT NULL AND rated_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE rating_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    old_rating TINYINT UNSIGNED NULL,
    new_rating TINYINT UNSIGNED NOT NULL,
    source ENUM('onboarding', 'user') NOT NULL,
    changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_rating_history_household_time (household_id, changed_at),
    KEY ix_rating_history_household_recipe (household_id, recipe_id),
    CONSTRAINT fk_rating_history_household_recipe FOREIGN KEY (household_id, recipe_id)
        REFERENCES household_recipe (household_id, recipe_id) ON DELETE CASCADE,
    CONSTRAINT ck_rating_history_old CHECK (old_rating IS NULL OR old_rating BETWEEN 1 AND 5),
    CONSTRAINT ck_rating_history_new CHECK (new_rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE affinity (
    household_id BIGINT UNSIGNED NOT NULL,
    feature_type ENUM('ingredient', 'cuisine', 'protein', 'carb', 'meal_type', 'tag') NOT NULL,
    feature_value VARCHAR(255) NOT NULL,
    score DECIMAL(6,5) NOT NULL DEFAULT 0,
    weight_sum DECIMAL(12,6) NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (household_id, feature_type, feature_value),
    CONSTRAINT fk_affinity_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE,
    CONSTRAINT ck_affinity_score CHECK (score BETWEEN -1 AND 1),
    CONSTRAINT ck_affinity_weight CHECK (weight_sum >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE onboarding_answer (
    household_id BIGINT UNSIGNED NOT NULL,
    question_id VARCHAR(120) NOT NULL,
    answer JSON NOT NULL,
    answered_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (household_id, question_id),
    CONSTRAINT fk_onboarding_answer_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('household.invite_ttl_hours', '72', 'integer', 'One-time household invite validity in hours', 1),
    ('api.idempotency_ttl_hours', '48', 'integer', 'Per-user idempotency result retention in hours', 1),
    ('auth.access_ttl_minutes', '15', 'integer', 'App access token lifetime in minutes', 1),
    ('auth.refresh_ttl_days', '30', 'integer', 'App refresh token lifetime in days', 1);
