-- Planning dates are local calendar dates in household.timezone; timestamps are UTC.
CREATE TABLE plan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    week_start DATE NOT NULL,
    part TINYINT UNSIGNED NOT NULL DEFAULT 1,
    days JSON NOT NULL,
    seed CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reshuffle_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('preparing', 'ready', 'completed') NOT NULL DEFAULT 'preparing',
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    config_version BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_plan_household_week_part (household_id, week_start, part),
    UNIQUE KEY uq_plan_id_household (id, household_id),
    CONSTRAINT fk_plan_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE,
    CONSTRAINT ck_plan_week_start CHECK (DAYOFWEEK(week_start) = 1),
    CONSTRAINT ck_plan_part CHECK (part IN (1, 2)),
    CONSTRAINT ck_plan_version CHECK (version >= 1),
    CONSTRAINT ck_plan_config_version CHECK (config_version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE plan_slot (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    day_profile VARCHAR(80) NOT NULL,
    recipe_id BIGINT UNSIGNED NULL,
    chosen_by ENUM('engine', 'family') NULL,
    status ENUM('proposed', 'chosen', 'cooked', 'not_cooked', 'unconfirmed') NOT NULL DEFAULT 'proposed',
    kind ENUM('favourite', 'discovery') NULL,
    score DECIMAL(8,5) NULL,
    predicted_pref DECIMAL(5,4) NULL,
    explanation_json JSON NULL,
    replaced_from_recipe_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_plan_slot_plan_date (plan_id, date),
    UNIQUE KEY uq_plan_slot_id_household (id, household_id),
    KEY ix_plan_slot_plan_household (plan_id, household_id),
    KEY ix_plan_slot_recipe (recipe_id),
    KEY ix_plan_slot_replaced_recipe (replaced_from_recipe_id),
    CONSTRAINT fk_plan_slot_plan FOREIGN KEY (plan_id, household_id)
        REFERENCES plan (id, household_id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_slot_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id),
    CONSTRAINT fk_plan_slot_replaced_recipe FOREIGN KEY (replaced_from_recipe_id) REFERENCES recipe (id),
    CONSTRAINT ck_plan_slot_recipe_status CHECK (status = 'proposed' OR recipe_id IS NOT NULL),
    CONSTRAINT ck_plan_slot_predicted_pref CHECK (predicted_pref IS NULL OR predicted_pref BETWEEN 0 AND 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE carryover (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    from_slot_id BIGINT UNSIGNED NOT NULL,
    target_week DATE NOT NULL,
    target_part TINYINT UNSIGNED NOT NULL,
    status ENUM('pending', 'placed', 'expired') NOT NULL DEFAULT 'pending',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_carryover_from_slot (from_slot_id),
    KEY ix_carryover_slot_household (from_slot_id, household_id),
    KEY ix_carryover_household_target (household_id, target_week, target_part, status),
    KEY ix_carryover_recipe (recipe_id),
    CONSTRAINT fk_carryover_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE,
    CONSTRAINT fk_carryover_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id),
    CONSTRAINT fk_carryover_from_slot FOREIGN KEY (from_slot_id, household_id)
        REFERENCES plan_slot (id, household_id),
    CONSTRAINT ck_carryover_target_week CHECK (DAYOFWEEK(target_week) = 1),
    CONSTRAINT ck_carryover_target_part CHECK (target_part IN (1, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE slot_skip (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    reason ENUM('replaced', 'swipe_rejects') NOT NULL,
    skipped_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    KEY ix_slot_skip_household_recipe (household_id, recipe_id, expires_at),
    KEY ix_slot_skip_recipe (recipe_id),
    CONSTRAINT fk_slot_skip_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE,
    CONSTRAINT fk_slot_skip_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id),
    CONSTRAINT ck_slot_skip_expiry CHECK (expires_at > skipped_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE swipe_event (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    action ENUM('pick', 'next', 'undo') NOT NULL,
    session_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_swipe_event_session (household_id, session_id, created_at),
    KEY ix_swipe_event_plan_date (plan_id, date),
    KEY ix_swipe_event_plan_household (plan_id, household_id),
    KEY ix_swipe_event_recipe (recipe_id),
    CONSTRAINT fk_swipe_event_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE,
    CONSTRAINT fk_swipe_event_plan FOREIGN KEY (plan_id, household_id)
        REFERENCES plan (id, household_id) ON DELETE CASCADE,
    CONSTRAINT fk_swipe_event_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE meal_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    cooked_on DATE NOT NULL,
    plan_slot_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_meal_log_plan_slot (plan_slot_id),
    KEY ix_meal_log_slot_household (plan_slot_id, household_id),
    KEY ix_meal_log_household_date (household_id, cooked_on),
    KEY ix_meal_log_recipe (recipe_id),
    CONSTRAINT fk_meal_log_household FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE,
    CONSTRAINT fk_meal_log_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id),
    CONSTRAINT fk_meal_log_plan_slot FOREIGN KEY (plan_slot_id, household_id)
        REFERENCES plan_slot (id, household_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;
