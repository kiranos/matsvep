ALTER TABLE recipe
    ADD COLUMN dinner_exclusion_reason VARCHAR(40) NULL AFTER is_dinner_candidate,
    ADD KEY ix_recipe_dinner_exclusion_reason (dinner_exclusion_reason),
    ADD CONSTRAINT ck_recipe_dinner_exclusion_reason CHECK (
        dinner_exclusion_reason IS NULL OR dinner_exclusion_reason IN (
            'source_category', 'baking_ingredients', 'dessert_title',
            'breakfast_or_side', 'insufficient_evidence', 'manual_override'
        )
    );
