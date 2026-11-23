# Production feature diagnostics

The full catalogue has a larger dinner-candidate protein gap than the pilot. We will inspect a reproducible, read-only sample from production before adding broad protein rules. Each sample prints main ingredient lines and a diagnostic reason; these reasons are hints for review, not automatic classifications. An optional seed makes a sample reproducible without storing recipe content in the repository.

Vegetarian is now true only when at least one main ingredient line is present, every main line is matched, and no known meat or fish is present. A known meat or fish makes it false; uncertain evidence leaves it unknown. Processing reports the three vegetarian counts and the dinner-candidate protein gap before and after derivation. The ingredient report is generated after derivation so its main-line counts reflect the current flags.

The first seed correction adds common descriptors, units and exact non-food equipment lines. A conjunction following a hyphenated shared ending does not split the ingredient line. No production data is changed by the sample commands; the operator runs processing separately after reviewing the change.
