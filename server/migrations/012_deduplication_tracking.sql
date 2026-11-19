ALTER TABLE recipe
    ADD COLUMN merged_into_id BIGINT UNSIGNED NULL AFTER dish_group_id,
    ADD KEY ix_recipe_merged_into (merged_into_id),
    ADD CONSTRAINT fk_recipe_merged_into FOREIGN KEY (merged_into_id) REFERENCES recipe (id);

INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('dedupe.time_tolerance_min', '30', 'integer', 'Maximum total-time difference for automatic copy detection', 1),
    ('dedupe.servings_tolerance', '2', 'decimal', 'Maximum serving-count difference for automatic copy detection', 1),
    ('dedupe.report_top_groups', '10', 'integer', 'Largest dish groups shown in the deduplication report', 1);
