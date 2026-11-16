ALTER TABLE ingest_url
    MODIFY status ENUM('queued', 'fetched', 'failed', 'skipped_robots', 'gone', 'not_recipe')
        NOT NULL DEFAULT 'queued';

INSERT INTO source (name, base_url, adapter, enabled, crawl_delay_s, display_mode)
SELECT 'Köket', 'https://www.koket.se', 'koket', FALSE, NULL, 'full'
WHERE NOT EXISTS (SELECT 1 FROM source WHERE adapter = 'koket');

INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('crawl.retry_base_s', '60', 'integer', 'Base delay in seconds before retrying a failed fetch', 1),
    ('crawl.retry_max_s', '3600', 'integer', 'Maximum exponential retry delay in seconds', 1),
    ('crawl.max_attempts', '5', 'integer', 'Maximum fetch attempts before leaving a URL failed', 1),
    ('crawl.timeout_s', '30', 'integer', 'HTTP connection and response timeout in seconds', 1);
