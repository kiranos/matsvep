ALTER TABLE ingest_url
    MODIFY status ENUM('queued', 'fetched', 'failed', 'skipped_robots', 'gone', 'not_recipe', 'duplicate')
        NOT NULL DEFAULT 'queued';

CREATE TABLE crawl_discovery (
    source_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    sitemap_hash BINARY(32) NOT NULL,
    completed_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_crawl_discovery_source FOREIGN KEY (source_id) REFERENCES source (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;
