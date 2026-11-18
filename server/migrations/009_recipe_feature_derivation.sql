ALTER TABLE recipe_ingredient
    MODIFY COLUMN normalise_status ENUM('matched', 'partial', 'unknown', 'unsafe_split', 'ignored') NULL;

INSERT INTO engine_config (`key`, value, type, description, version) VALUES
    ('feature.main_categories', '["protein","legume","vegetable","grain","dairy","fruit"]', 'json', 'Ingredient categories eligible for main ingredient flags', 1),
    ('feature.main_min_mass_g', '100', 'decimal', 'Minimum gram mass for an unmatched line to be considered main', 1),
    ('feature.leftover_dish_weight', '0.5', 'decimal', 'Dish type share of the leftover signal', 1),
    ('feature.leftover_method_weight', '0.3', 'decimal', 'Cooking method share of the leftover signal', 1),
    ('feature.leftover_protein_weight', '0.2', 'decimal', 'Protein form share of the leftover signal', 1),
    ('feature.leftover_baseline', '0.5', 'decimal', 'Leftover score when no signal is present', 1),
    ('feature.leftover_scale', '0.5', 'decimal', 'Scale applied to the weighted leftover signal', 1),
    ('feature.leftover_cut_up_signal', '0.5', 'decimal', 'Protein form signal for cut pieces in a batch dish', 1),
    ('feature.festive_time_full_min', '120', 'integer', 'Minutes at which the festive time component reaches one', 1),
    ('feature.festive_time_weight', '0.4', 'decimal', 'Festive score share for cooking time', 1),
    ('feature.festive_premium_weight', '0.35', 'decimal', 'Festive score share for premium ingredients', 1),
    ('feature.festive_tag_weight', '0.25', 'decimal', 'Festive score share for festive tags', 1),
    ('feature.effort_easy_time_min', '30', 'integer', 'Maximum minutes for low effort', 1),
    ('feature.effort_easy_ingredient_count', '8', 'integer', 'Maximum ingredient lines for low effort', 1),
    ('feature.effort_hard_time_min', '90', 'integer', 'Minutes at which effort is high', 1),
    ('feature.effort_hard_ingredient_count', '15', 'integer', 'Ingredient lines at which effort is high', 1),
    ('feature.report_sample_size', '20', 'integer', 'Recipes in the deterministic feature spot check', 1),
    ('feature.report_sample_seed', 'matsvep-feature-pilot', 'string', 'Stable random sample seed for feature review', 1),
    ('feature.report_leftover_buckets', '10', 'integer', 'Number of leftover score distribution buckets', 1);
