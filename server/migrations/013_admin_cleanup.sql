CREATE TABLE `user` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin') NOT NULL DEFAULT 'admin',
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE admin_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(120) NOT NULL,
    target_type VARCHAR(120) NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_admin_audit_created (created_at),
    KEY ix_admin_audit_user (user_id),
    CONSTRAINT fk_admin_audit_user FOREIGN KEY (user_id) REFERENCES `user` (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE recipe_feature_override (
    recipe_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    main_protein VARCHAR(120) NULL,
    carb_base VARCHAR(120) NULL,
    cuisine VARCHAR(120) NULL,
    meal_type VARCHAR(120) NULL,
    is_dinner_candidate BOOLEAN NULL,
    leftover_score DECIMAL(5,4) NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_feature_override_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE,
    CONSTRAINT fk_feature_override_user FOREIGN KEY (updated_by) REFERENCES `user` (id),
    CONSTRAINT ck_feature_override_leftover CHECK (leftover_score BETWEEN 0 AND 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

ALTER TABLE recipe
    ADD COLUMN dish_group_manual BOOLEAN NOT NULL DEFAULT FALSE AFTER dish_group_id;

INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('admin.login_max_attempts', '5', 'integer', 'Failed admin logins before lockout', 1),
    ('admin.login_lock_s', '900', 'integer', 'Admin account lock duration in seconds', 1),
    ('admin.session_idle_s', '1800', 'integer', 'Admin session idle timeout in seconds', 1);
