# Planning API opens plans on demand

Plans are prepared only through an authenticated household request. A whole week or the first split half opens on Sunday at 00:00; the second split half opens on Wednesday at 00:00 in the household's time zone. Local calendar dates define the half across daylight-saving changes. Earlier requests return `slot_not_open`.

Plan writes join the idempotency transaction, and a repeated key replays its saved response before the expected version is checked. Choose, replace, autofill and shuffle still use the plan row lock and increment its version. Every plan and slot lookup is scoped to the caller's household.

An unanswered `not_cooked_action` defaults to a carry-over. A cooked answer records a meal and requests a rating on the first cook, including when an onboarding rating exists. Subsequent ratings can be changed through the rating endpoint. Scheduled planning at 07:00 and day-after push delivery remain later work.
