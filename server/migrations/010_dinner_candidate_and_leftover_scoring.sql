ALTER TABLE recipe
    ADD COLUMN is_dinner_candidate BOOLEAN NULL AFTER meal_type,
    ADD INDEX ix_recipe_dinner_candidate (is_dinner_candidate, active);

INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('feature.leftover_long_cook_min', '45', 'integer', 'Minimum step cooking duration for a long-cook signal', 1),
    ('feature.leftover_long_cook_signal', '1', 'decimal', 'Method signal for a long-cooking step', 1),
    ('feature.report_ranked_size', '10', 'integer', 'Dinner candidates at each end of the leftover ranking', 1);
