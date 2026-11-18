INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('normalise.suggestion_min_similarity', '0.82', 'decimal', 'Minimum Unicode edit similarity for an ingredient review suggestion', 1),
    ('normalise.suggestion_min_affix_length', '4', 'integer', 'Minimum character count for a compound head or prefix suggestion', 1);
