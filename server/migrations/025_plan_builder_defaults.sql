-- Planning defaults remain configurable rather than embedded in the engine.
INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('builder.default_favourite_share', '0.7', 'decimal', 'Favourite share for households without a setting', 1),
    ('builder.default_plan_days', '7', 'integer', 'Planned days for households without a setting', 1),
    ('builder.plan_days.all_seven', '7', 'integer', 'Days in an all-seven planning week', 1),
    ('builder.plan_days.sunday_thursday', '5', 'integer', 'Days in a Sunday-through-Thursday planning week', 1),
    ('builder.profile_order', '["friday_treat","saturday_dinner","weekday_leftovers"]', 'json', 'Most constrained day profiles first', 1)
ON DUPLICATE KEY UPDATE value = engine_config.value;

-- A planning keeps its discovery prefilter so a later swipe can rerank without
-- scanning the full recipe catalogue again. Current hard filters still apply.
CREATE TABLE plan_candidate (
    plan_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (plan_id, recipe_id),
    KEY ix_plan_candidate_recipe (recipe_id),
    CONSTRAINT fk_plan_candidate_plan FOREIGN KEY (plan_id) REFERENCES plan (id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_candidate_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;
