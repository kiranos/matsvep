-- Keep any configured unanswered-dinner interval when adopting the explicit key.
INSERT INTO engine_config (`key`, value, type, description, version)
SELECT 'after_dinner.unconfirmed_days', value, 'integer',
    'Days after a chosen dinner before it counts as eaten without an answer', version
FROM engine_config WHERE `key` = 'confirm.auto_days'
ON DUPLICATE KEY UPDATE value = engine_config.value;

DELETE FROM engine_config WHERE `key` = 'confirm.auto_days';
