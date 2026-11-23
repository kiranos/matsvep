INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('feature.baking_sugar_flour_share', '0.5', 'decimal', 'Minimum fraction of food ingredient lines that are sugar or flour when both are present', 1),
    ('feature.report_dinner_audit_size', '30', 'integer', 'Sample size for dinner candidate removal audit', 1)
ON DUPLICATE KEY UPDATE value = engine_config.value;
