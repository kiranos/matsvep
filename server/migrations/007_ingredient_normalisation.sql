ALTER TABLE recipe_ingredient
    ADD COLUMN ingredient_text VARCHAR(255) NULL AFTER raw_text,
    ADD COLUMN note TEXT NULL AFTER ingredient_text,
    ADD COLUMN quantity_max DECIMAL(10,3) NULL AFTER quantity,
    ADD COLUMN unparsed_unit VARCHAR(80) NULL AFTER unit,
    ADD COLUMN normalise_status ENUM('matched', 'partial', 'unknown', 'unsafe_split') NULL AFTER ingredient_id,
    ADD CONSTRAINT ck_recipe_ingredient_quantity_range CHECK (quantity_max IS NULL OR quantity_max >= quantity);

CREATE TABLE recipe_ingredient_part (
    recipe_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    part_position SMALLINT UNSIGNED NOT NULL,
    ingredient_text VARCHAR(255) NOT NULL,
    ingredient_id BIGINT UNSIGNED NULL,
    ingredient_unknown_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (recipe_id, position, part_position),
    KEY ix_recipe_ingredient_part_ingredient (ingredient_id),
    KEY ix_recipe_ingredient_part_unknown (ingredient_unknown_id),
    CONSTRAINT fk_recipe_ingredient_part_line FOREIGN KEY (recipe_id, position)
        REFERENCES recipe_ingredient (recipe_id, position) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_ingredient_part_ingredient FOREIGN KEY (ingredient_id)
        REFERENCES ingredient (id),
    CONSTRAINT fk_recipe_ingredient_part_unknown FOREIGN KEY (ingredient_unknown_id)
        REFERENCES ingredient_unknown (id),
    CONSTRAINT ck_recipe_ingredient_part_target CHECK (ingredient_id IS NULL OR ingredient_unknown_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_sv_0900_ai_ci;

ALTER TABLE ingredient_unknown
    DROP CHECK ck_unknown_occurrences,
    ADD CONSTRAINT ck_unknown_occurrences CHECK (occurrences >= 0);
