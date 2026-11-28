# Audit cooked meal cooldowns

The simulator previously counted every non-proposed plan slot against a fixed 21-day window. Proposed, chosen, replaced and not-cooked slots do not start a cooldown. The report now reads cooked and unconfirmed meals from `meal_log` and applies the chosen recipe's rating-dependent cooldown. A placed carry-over is exempt from a violation when placed, but starts cooldown if subsequently cooked.

The report separately counts accepted API choices that would have failed the cooldown hard filter at choice time. This selection-time audit reads meal history independently of the planning shortlist. Each policy also reports the number of distinct recipes and dish groups cooked.

The synthetic audit exposed a hard-filter breach: recipes loaded solely to provide history or fixed-slot context could also appear in decks and be chosen. Candidate eligibility is now carried separately from contextual inclusion. Context recipes remain available for variety scoring and explicit carry-over placement, but cannot enter a normal deck or choice while a hard filter applies.
