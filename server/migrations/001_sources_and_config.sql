-- Phase 1 foundation. All timestamps are UTC.
CREATE TABLE source (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    base_url VARCHAR(2048) NOT NULL,
    adapter VARCHAR(120) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    crawl_delay_s INT UNSIGNED NULL,
    last_crawl_at DATETIME(6) NULL,
    display_mode ENUM('full', 'index_only') NOT NULL DEFAULT 'full',
    UNIQUE KEY uq_source_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE engine_config (
    `key` VARCHAR(160) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL,
    type ENUM('integer', 'decimal', 'boolean', 'string', 'json') NOT NULL,
    description VARCHAR(500) NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('crawl.delay_s', '5', 'integer', 'Minimum seconds between requests to the same source', 1),
    ('crawl.recheck_share', '0.01', 'decimal', 'Nightly fraction of recipes checked for disappearance', 1),
    ('dedupe.auto_merge', '0.95', 'decimal', 'Similarity at or above which copies merge automatically', 1),
    ('dedupe.review', '0.80', 'decimal', 'Similarity at or above which copies enter review', 1),
    ('dedupe.group_overlap', '0.5', 'decimal', 'Minimum ingredient overlap for a dish group', 1),
    ('dedupe.source_priority', '["koket","ica","arla","coop"]', 'json', 'Preferred source order when equally complete copies merge', 1);
