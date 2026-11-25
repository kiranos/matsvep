# Planning shortlist and versioned decisions

The first planning prepares favourite and discovery pools after SQL hard filters. It scores every unrated discovery for the configured Content prefilter, retains wishlisted recipes, and stores the resulting recipe IDs in `plan_candidate`. Later picks and shuffles load that shortlist and recheck current hard filters. A valid recipe picked from search is added to the shortlist before re-ranking. This avoids a full-catalogue scan on every swipe while allowing household blocks, ratings and cooldowns to take effect immediately.

Carry-overs are placed before sampled choices on their best open day and count toward the favourite quota. The second split planning subtracts favourites already chosen in the first part from the whole-week target. The configured profile order puts constrained days first. The seeded softmax, pairwise swap pass and dish-group-unique decks are deterministic for identical inputs.

Choose, replace, autofill and shuffle lock the plan row, compare the expected version, write slots and increment the version in one transaction. A stale version raises `plan_version_conflict`. Chosen slots store reason codes, parameters and the Scorer breakdown in `explanation_json`; proposed slots do not store a permanent explanation. The API controllers remain a later task.
