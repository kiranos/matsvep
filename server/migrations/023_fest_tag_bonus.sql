INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('feature.fest_tag_bonus', '0.2', 'decimal', 'Additional festive score for the seeded Fest occasion tag', 1)
ON DUPLICATE KEY UPDATE value = engine_config.value;
