CREATE TABLE ingredient (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    canonical_name VARCHAR(255) NOT NULL,
    category VARCHAR(120) NULL,
    is_staple BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY uq_ingredient_name (canonical_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE ingredient_alias (
    alias VARCHAR(255) NOT NULL PRIMARY KEY,
    ingredient_id BIGINT UNSIGNED NOT NULL,
    KEY ix_ingredient_alias_ingredient (ingredient_id),
    CONSTRAINT fk_ingredient_alias_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredient (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE dish_group (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    core_name VARCHAR(255) NOT NULL,
    main_protein VARCHAR(120) NULL,
    manual_override BOOLEAN NOT NULL DEFAULT FALSE,
    KEY ix_dish_group_core (core_name, main_protein)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE recipe (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    url VARCHAR(2048) NOT NULL,
    title VARCHAR(500) NOT NULL,
    image_url VARCHAR(2048) NULL,
    total_time_min SMALLINT UNSIGNED NULL,
    servings DECIMAL(6,2) NULL,
    cuisine VARCHAR(120) NULL,
    meal_type VARCHAR(120) NULL,
    main_protein VARCHAR(120) NULL,
    carb_base VARCHAR(120) NULL,
    is_vegetarian BOOLEAN NULL,
    leftover_score DECIMAL(5,4) NULL,
    festive_score DECIMAL(5,4) NULL,
    effort TINYINT UNSIGNED NULL,
    dish_group_id BIGINT UNSIGNED NULL,
    source_status ENUM('ok', 'source_gone') NOT NULL DEFAULT 'ok',
    parsed_at DATETIME(6) NULL,
    parser_version VARCHAR(80) NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    KEY ix_recipe_source (source_id),
    KEY ix_recipe_group (dish_group_id),
    KEY ix_recipe_active (active, source_status),
    FULLTEXT KEY ft_recipe_title (title),
    CONSTRAINT fk_recipe_source FOREIGN KEY (source_id) REFERENCES source (id),
    CONSTRAINT fk_recipe_group FOREIGN KEY (dish_group_id) REFERENCES dish_group (id),
    CONSTRAINT ck_recipe_leftover CHECK (leftover_score BETWEEN 0 AND 1),
    CONSTRAINT ck_recipe_festive CHECK (festive_score BETWEEN 0 AND 1),
    CONSTRAINT ck_recipe_effort CHECK (effort BETWEEN 1 AND 3),
    CONSTRAINT ck_recipe_servings CHECK (servings > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE recipe_ingredient (
    recipe_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    group_name VARCHAR(255) NULL,
    raw_text TEXT NOT NULL,
    quantity DECIMAL(10,3) NULL,
    unit VARCHAR(80) NULL,
    ingredient_id BIGINT UNSIGNED NULL,
    is_main BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (recipe_id, position),
    KEY ix_recipe_ingredient_ingredient (ingredient_id),
    FULLTEXT KEY ft_recipe_ingredient_text (raw_text),
    CONSTRAINT fk_recipe_ingredient_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_ingredient_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredient (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE recipe_step (
    recipe_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    group_name VARCHAR(255) NULL,
    text TEXT NOT NULL,
    PRIMARY KEY (recipe_id, position),
    CONSTRAINT fk_recipe_step_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

CREATE TABLE recipe_tag (
    recipe_id BIGINT UNSIGNED NOT NULL,
    tag VARCHAR(120) NOT NULL,
    PRIMARY KEY (recipe_id, tag),
    KEY ix_recipe_tag_tag (tag),
    CONSTRAINT fk_recipe_tag_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;
