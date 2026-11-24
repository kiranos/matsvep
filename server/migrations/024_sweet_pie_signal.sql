INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('feature.sweet_pie_sugar_share', '0.25', 'decimal', 'Minimum food-line sugar share for a sweet pie', 1)
ON DUPLICATE KEY UPDATE value = engine_config.value;

ALTER TABLE recipe
    DROP CHECK ck_recipe_dinner_exclusion_reason,
    ADD CONSTRAINT ck_recipe_dinner_exclusion_reason CHECK (
        dinner_exclusion_reason IS NULL OR dinner_exclusion_reason IN (
            'source_category', 'baking_ingredients', 'dessert_title',
            'breakfast_or_side', 'non_dinner_title', 'insufficient_evidence', 'manual_override'
        )
    );
