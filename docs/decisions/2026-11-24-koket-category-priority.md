# Köket source category priority

The production tag report contains 31 source tags. The earlier protein override made many starter and buffet recipes dinner candidates. The source meal-type tags now control dinner eligibility before protein heuristics: `Huvudrätt` wins a conflict with another meal-type tag; without it, the seeded non-dinner meal categories exclude. Occasions and cuisines are neutral. Recipes with no source meal-type tag retain the established heuristics.

A simultaneous `Huvudrätt` and `Efterrätt` tag remains eligible unless an unambiguous dessert title or baking ingredient signal supplies independent dessert evidence. Existing egg-only and explicit breakfast title safeguards remain for other `Huvudrätt` recipes. Manual admin corrections still apply after derivation. The source category exclusion continues to use the `source_category` reason code.

Seeded cuisine tags take priority over title keywords. Specific country tags take priority over broad region tags, with stable CSV order resolving ties. Manual cuisine corrections remain final. The API lists the allowed cuisine codes; no new display or reason code is introduced, so no Flutter localisation change is needed while the app directory is absent.

The `Fest` tag now contributes one configurable bonus from `feature.fest_tag_bonus`, default 0.2. It does not also receive the older generic festive-tag weight, and the score remains capped at 1. `Picknick` is neutral. Processing reports before and after source meal-tag coverage, dinner cuisine distribution, protein gap and candidate changes so the production effect can be reviewed before further rule changes.
