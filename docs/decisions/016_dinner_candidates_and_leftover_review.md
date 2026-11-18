# Dinner candidates and leftover review

The pilot feature report left most recipes close to the neutral leftover score. Expand the seeded dish and method vocabulary and detect slow cooking from a method and explicit duration in the same recipe step. Keep the existing weighted formula; store the duration threshold and signal in `engine_config`. Pilot calibration shifts weights from 0.5 / 0.3 / 0.2 to 0.6 / 0.3 / 0.1, giving batch dish type more influence than an individual protein-cut word.

Derive `is_dinner_candidate` separately from `meal_type`. A classified main recipe qualifies. An unclassified recipe qualifies when it has a main protein or carb base and its title and tags have no seeded non-dinner indicator. Sill titles map to buffet before generic tags, avoiding a breakfast classification from source tags. Add pasta shapes as ingredient seed data.

The report shows dinner-only distributions and ranking extremes so the larger crawl can guide later vocabulary tuning. These are heuristic derivations, not eligibility decisions made by the crawler.
