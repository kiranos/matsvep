CREATE TABLE schema_migrations (
    migration VARCHAR(255) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('started', 'applied') NOT NULL,
    started_at DATETIME(6) NOT NULL,
    applied_at DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;
