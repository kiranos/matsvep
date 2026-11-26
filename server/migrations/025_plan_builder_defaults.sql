-- Planning defaults remain configurable rather than embedded in the engine.
INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('builder.default_favourite_share', '0.7', 'decimal', 'Favourite share for households without a setting', 1),
    ('builder.default_plan_days', '7', 'integer', 'Planned days for households without a setting', 1),
    ('builder.plan_days.all_seven', '7', 'integer', 'Days in an all-seven planning week', 1),
    ('builder.plan_days.sunday_thursday', '5', 'integer', 'Days in a Sunday-through-Thursday planning week', 1),
    ('builder.profile_order', '["friday_treat","saturday_dinner","weekday_leftovers"]', 'json', 'Most constrained day profiles first', 1),
    ('explain.similar_rating_min', '4', 'integer', 'Lowest rating used for a similar recipe reason', 1)
ON DUPLICATE KEY UPDATE value = engine_config.value;

-- Planning state changes are counted at the database boundary so every writer
-- invalidates a saved shortlist, including later API and admin actions.
ALTER TABLE household ADD COLUMN state_version BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE plan
    ADD COLUMN shortlist_state_version BIGINT UNSIGNED NULL,
    ADD COLUMN shortlist_config_version BIGINT UNSIGNED NULL;

CREATE TRIGGER tr_household_recipe_insert AFTER INSERT ON household_recipe
FOR EACH ROW UPDATE household SET state_version = state_version + 1 WHERE id = NEW.household_id;
CREATE TRIGGER tr_household_recipe_update AFTER UPDATE ON household_recipe
FOR EACH ROW UPDATE household SET state_version = state_version + 1 WHERE id = NEW.household_id;
CREATE TRIGGER tr_household_recipe_delete AFTER DELETE ON household_recipe
FOR EACH ROW UPDATE household SET state_version = state_version + 1 WHERE id = OLD.household_id;

CREATE TRIGGER tr_household_setting_insert AFTER INSERT ON household_setting
FOR EACH ROW UPDATE household SET state_version = state_version + 1 WHERE id = NEW.household_id;
CREATE TRIGGER tr_household_setting_update AFTER UPDATE ON household_setting
FOR EACH ROW UPDATE household SET state_version = state_version + 1 WHERE id = NEW.household_id;
CREATE TRIGGER tr_household_setting_delete AFTER DELETE ON household_setting
FOR EACH ROW UPDATE household SET state_version = state_version + 1 WHERE id = OLD.household_id;

CREATE TRIGGER tr_day_profile_insert AFTER INSERT ON day_profile
FOR EACH ROW UPDATE household SET state_version = state_version + 1 WHERE id = NEW.household_id;
CREATE TRIGGER tr_day_profile_update AFTER UPDATE ON day_profile
FOR EACH ROW UPDATE household SET state_version = state_version + 1 WHERE id = NEW.household_id;
CREATE TRIGGER tr_day_profile_delete AFTER DELETE ON day_profile
FOR EACH ROW UPDATE household SET state_version = state_version + 1 WHERE id = OLD.household_id;

CREATE TRIGGER tr_carryover_insert AFTER INSERT ON carryover
FOR EACH ROW UPDATE household SET state_version = state_version + 1 WHERE id = NEW.household_id;
CREATE TRIGGER tr_carryover_update AFTER UPDATE ON carryover
FOR EACH ROW UPDATE household SET state_version = state_version + 1 WHERE id = NEW.household_id;
CREATE TRIGGER tr_carryover_delete AFTER DELETE ON carryover
FOR EACH ROW UPDATE household SET state_version = state_version + 1 WHERE id = OLD.household_id;

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
