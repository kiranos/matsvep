-- Score changes reuse the saved shortlist. Changes to hard filters or the
-- planning settings require a fresh candidate pool.
ALTER TABLE household ADD COLUMN filter_version BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE plan ADD COLUMN shortlist_filter_version BIGINT UNSIGNED NULL;

DROP TRIGGER tr_household_recipe_insert;
DROP TRIGGER tr_household_recipe_update;
DROP TRIGGER tr_household_recipe_delete;
DROP TRIGGER tr_household_setting_insert;
DROP TRIGGER tr_household_setting_update;
DROP TRIGGER tr_household_setting_delete;
DROP TRIGGER tr_day_profile_insert;
DROP TRIGGER tr_day_profile_update;
DROP TRIGGER tr_day_profile_delete;
DROP TRIGGER tr_carryover_insert;
DROP TRIGGER tr_carryover_update;
DROP TRIGGER tr_carryover_delete;

CREATE TRIGGER tr_household_recipe_insert AFTER INSERT ON household_recipe
FOR EACH ROW UPDATE household SET state_version = state_version + 1,
    filter_version = filter_version + IF(NEW.status <> 'library'
        OR NEW.rating <= CAST((SELECT value FROM engine_config WHERE `key` = 'filter.hide_at_or_below') AS UNSIGNED)
        OR NEW.last_cooked_at IS NOT NULL, 1, 0) WHERE id = NEW.household_id;
CREATE TRIGGER tr_household_recipe_update AFTER UPDATE ON household_recipe
FOR EACH ROW UPDATE household SET state_version = state_version + 1,
    filter_version = filter_version + IF(NOT (OLD.status <=> NEW.status)
        OR (NOT (OLD.rating <=> NEW.rating) AND (
            OLD.rating <= CAST((SELECT value FROM engine_config WHERE `key` = 'filter.hide_at_or_below') AS UNSIGNED)
            OR NEW.rating <= CAST((SELECT value FROM engine_config WHERE `key` = 'filter.hide_at_or_below') AS UNSIGNED)))
        OR NOT (OLD.last_cooked_at <=> NEW.last_cooked_at), 1, 0) WHERE id = NEW.household_id;
CREATE TRIGGER tr_household_recipe_delete AFTER DELETE ON household_recipe
FOR EACH ROW UPDATE household SET state_version = state_version + 1,
    filter_version = filter_version + IF(OLD.status <> 'library'
        OR OLD.rating <= CAST((SELECT value FROM engine_config WHERE `key` = 'filter.hide_at_or_below') AS UNSIGNED)
        OR OLD.last_cooked_at IS NOT NULL, 1, 0) WHERE id = OLD.household_id;

CREATE TRIGGER tr_household_setting_insert AFTER INSERT ON household_setting
FOR EACH ROW UPDATE household SET state_version = state_version + 1, filter_version = filter_version + 1
WHERE id = NEW.household_id;
CREATE TRIGGER tr_household_setting_update AFTER UPDATE ON household_setting
FOR EACH ROW UPDATE household SET state_version = state_version + 1, filter_version = filter_version + 1
WHERE id = NEW.household_id;
CREATE TRIGGER tr_household_setting_delete AFTER DELETE ON household_setting
FOR EACH ROW UPDATE household SET state_version = state_version + 1, filter_version = filter_version + 1
WHERE id = OLD.household_id;

CREATE TRIGGER tr_day_profile_insert AFTER INSERT ON day_profile
FOR EACH ROW UPDATE household SET state_version = state_version + 1, filter_version = filter_version + 1
WHERE id = NEW.household_id;
CREATE TRIGGER tr_day_profile_update AFTER UPDATE ON day_profile
FOR EACH ROW UPDATE household SET state_version = state_version + 1, filter_version = filter_version + 1
WHERE id = NEW.household_id;
CREATE TRIGGER tr_day_profile_delete AFTER DELETE ON day_profile
FOR EACH ROW UPDATE household SET state_version = state_version + 1, filter_version = filter_version + 1
WHERE id = OLD.household_id;

CREATE TRIGGER tr_carryover_insert AFTER INSERT ON carryover
FOR EACH ROW UPDATE household SET state_version = state_version + 1, filter_version = filter_version + 1
WHERE id = NEW.household_id;
CREATE TRIGGER tr_carryover_update AFTER UPDATE ON carryover
FOR EACH ROW UPDATE household SET state_version = state_version + 1, filter_version = filter_version + 1
WHERE id = NEW.household_id;
CREATE TRIGGER tr_carryover_delete AFTER DELETE ON carryover
FOR EACH ROW UPDATE household SET state_version = state_version + 1, filter_version = filter_version + 1
WHERE id = OLD.household_id;
