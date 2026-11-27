# Planning API opens plans on demand

Plans are prepared only through an authenticated household request. A whole week or the first split half opens on Sunday at 00:00; the second split half opens on Wednesday at 00:00 in the household's time zone. Local calendar dates define the half across daylight-saving changes. Earlier requests return `slot_not_open`.

Plan writes join the idempotency transaction, and a repeated key replays its saved response before the expected version is checked. Choose, replace, autofill and shuffle still use the plan row lock and increment its version. Every plan and slot lookup is scoped to the caller's household.

An unanswered `not_cooked_action` defaults to a carry-over. A cooked answer records a meal and requests a rating on the first cook, including when an onboarding rating exists. Subsequent ratings can be changed through the rating endpoint. Scheduled planning at 07:00 and day-after push delivery remain later work.

Nightly maintenance records unanswered chosen meals as eaten after `after_dinner.unconfirmed_days` in the household's local calendar, and expires pending carry-overs after `carryover.expire_weeks` from their target week. The new unanswered-dinner key inherits any configured value from the older `confirm.auto_days` key during migration. Already prepared plans expose later carry-overs as pending until a family choice places them. An undone left swipe does not contribute to the rejection threshold.
