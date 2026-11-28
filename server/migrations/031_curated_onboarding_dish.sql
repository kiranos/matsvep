CREATE TABLE onboarding_dish (
    recipe_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    position INT UNSIGNED NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    KEY ix_onboarding_dish_order (active, position, recipe_id),
    CONSTRAINT fk_onboarding_dish_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('onboarding.dish_count_min', '30', 'integer', 'Minimum recommended active onboarding dishes', 1),
    ('onboarding.dish_count_max', '40', 'integer', 'Maximum recommended active onboarding dishes', 1);
