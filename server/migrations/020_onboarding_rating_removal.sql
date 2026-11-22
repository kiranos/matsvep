-- A replaced onboarding answer may remove a starting rating.
ALTER TABLE rating_history
    MODIFY COLUMN new_rating TINYINT UNSIGNED NULL,
    ADD CONSTRAINT ck_rating_history_has_value CHECK (old_rating IS NOT NULL OR new_rating IS NOT NULL);

INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('onboarding.rating.favourite', '5', 'integer', 'Starting star rating for a favourite dish', 1),
    ('onboarding.rating.ok', '4', 'integer', 'Starting star rating for an accepted dish', 1),
    ('onboarding.rating.not_for_us', '2', 'integer', 'Starting star rating for a rejected dish', 1),
    ('affinity.disliked_ingredient_score', '-0.8', 'decimal', 'Affinity seed for a disliked ingredient', 1);
