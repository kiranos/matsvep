CREATE TABLE recipe_source_link (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recipe_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    url VARCHAR(2048) NOT NULL,
    url_hash BINARY(32) NOT NULL COMMENT 'SHA-256 of canonical URL',
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    primary_recipe_id BIGINT UNSIGNED GENERATED ALWAYS AS
        (CASE WHEN is_primary THEN recipe_id ELSE NULL END) STORED,
    UNIQUE KEY uq_source_link_url (source_id, url_hash),
    UNIQUE KEY uq_source_link_primary (primary_recipe_id),
    KEY ix_source_link_recipe (recipe_id),
    CONSTRAINT fk_source_link_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id),
    CONSTRAINT fk_source_link_source FOREIGN KEY (source_id) REFERENCES source (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE recipe_raw (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recipe_id BIGINT UNSIGNED NOT NULL,
    fetched_at DATETIME(6) NOT NULL,
    etag VARCHAR(500) NULL,
    format ENUM('jsonld', 'html') NOT NULL,
    payload_gz LONGBLOB NOT NULL,
    KEY ix_recipe_raw_recipe_fetched (recipe_id, fetched_at),
    CONSTRAINT fk_recipe_raw_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ingest_url (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    url VARCHAR(2048) NOT NULL,
    url_hash BINARY(32) NOT NULL COMMENT 'SHA-256 of canonical URL',
    discovered_via ENUM('sitemap', 'category') NOT NULL,
    status ENUM('queued', 'fetched', 'failed', 'skipped_robots', 'gone') NOT NULL DEFAULT 'queued',
    etag VARCHAR(500) NULL,
    last_modified VARCHAR(255) NULL,
    http_status SMALLINT UNSIGNED NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NULL,
    last_fetched_at DATETIME(6) NULL,
    recipe_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_ingest_source_url (source_id, url_hash),
    KEY ix_ingest_due (status, next_attempt_at),
    KEY ix_ingest_recipe (recipe_id),
    CONSTRAINT fk_ingest_source FOREIGN KEY (source_id) REFERENCES source (id),
    CONSTRAINT fk_ingest_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ingredient_unknown (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    raw_text TEXT NOT NULL,
    normalised_token VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    occurrences INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen_at DATETIME(6) NOT NULL,
    status ENUM('pending', 'mapped', 'staple', 'ignored') NOT NULL DEFAULT 'pending',
    suggested_ingredient_id BIGINT UNSIGNED NULL,
    mapped_ingredient_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_unknown_token (normalised_token),
    KEY ix_unknown_status (status, occurrences),
    KEY ix_unknown_suggested (suggested_ingredient_id),
    KEY ix_unknown_mapped (mapped_ingredient_id),
    CONSTRAINT fk_unknown_suggested FOREIGN KEY (suggested_ingredient_id) REFERENCES ingredient (id),
    CONSTRAINT fk_unknown_mapped FOREIGN KEY (mapped_ingredient_id) REFERENCES ingredient (id),
    CONSTRAINT ck_unknown_occurrences CHECK (occurrences > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE leftover_keyword (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    keyword VARCHAR(255) NOT NULL,
    signal ENUM('dish_type', 'method', 'protein_form') NOT NULL,
    value DECIMAL(5,4) NOT NULL,
    UNIQUE KEY uq_leftover_keyword_signal (keyword, signal),
    CONSTRAINT ck_leftover_keyword_value CHECK (value BETWEEN -1 AND 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE duplicate_candidate (
    recipe_a BIGINT UNSIGNED NOT NULL,
    recipe_b BIGINT UNSIGNED NOT NULL,
    similarity DECIMAL(5,4) NOT NULL,
    status ENUM('pending', 'merged', 'not_duplicate') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (recipe_a, recipe_b),
    KEY ix_duplicate_candidate_b (recipe_b),
    KEY ix_duplicate_candidate_status (status, similarity),
    CONSTRAINT fk_duplicate_a FOREIGN KEY (recipe_a) REFERENCES recipe (id),
    CONSTRAINT fk_duplicate_b FOREIGN KEY (recipe_b) REFERENCES recipe (id),
    CONSTRAINT ck_duplicate_order CHECK (recipe_a < recipe_b),
    CONSTRAINT ck_duplicate_similarity CHECK (similarity BETWEEN 0 AND 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
