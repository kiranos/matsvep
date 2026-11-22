# Phase 2 engine foundation

Migration 017 inserts the remaining design defaults into `engine_config` with `ON DUPLICATE KEY UPDATE` preserving tuned values. Friday tag values are recipe data, so the Swedish words appear only in the seed rows. Additional defaults cover the content denominator and variety bonuses, target timing and history window. Earlier phase 1 configuration rows remain in their original migrations.

`Engine\Filters` returns English reason codes for hard exclusions. `Engine\Scorer` implements the documented score components as pure calculations over supplied recipe, family and recent-dinner data. This PR does not add household or planning persistence or a plan builder. The caller must supply recent dinners from the current plan and the preceding seven days, exclude staples from affinity features, and run filters before scoring. A missing total time is neutral in the DayFit cap.

The variety penalty is clamped to −1…1 after weekly target bonuses, allowing a negative penalty to reward a missing fish or vegetarian dinner. Those targets and weekly repeat limits use the current Sunday–Saturday plan week. The previous seven days remain available for adjacency and recent-history checks. DayFit coefficients are seeded configuration values.
