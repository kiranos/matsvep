ALTER TABLE recipe
    ADD COLUMN total_time_source ENUM('unknown', 'given', 'estimated') NOT NULL DEFAULT 'unknown'
    AFTER total_time_min;

UPDATE recipe SET total_time_source = 'given' WHERE total_time_min IS NOT NULL;
