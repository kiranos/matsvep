# Audit cooked meal cooldowns

The simulator previously counted every non-proposed plan slot against a fixed 21-day window. Proposed, chosen, replaced and not-cooked slots do not start a cooldown. The report now reads cooked and unconfirmed meals from `meal_log` and applies the chosen recipe's rating-dependent cooldown. A placed carry-over is exempt from a violation when placed, but starts cooldown if subsequently cooked.

The report separately counts accepted API choices that would have failed the cooldown hard filter at choice time. This selection-time audit reads meal history independently of the planning shortlist. Each policy also reports the number of distinct recipes and dish groups cooked.
