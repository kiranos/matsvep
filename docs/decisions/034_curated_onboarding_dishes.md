# Curated onboarding dishes

Onboarding uses an admin-managed ordered list of recipe references stored in the database. The list is shared across households. Admins can search, add, reorder and deactivate entries; each change is audited. The API includes only active dinner candidates. If none remain, the existing title-keyword seed supplies options so an empty or stale list does not block onboarding.

The admin page shows each recipe's title, source and current eligibility. It warns when fewer than `onboarding.dish_count_min` or more than `onboarding.dish_count_max` eligible entries are active. The defaults are 30 and 40. Recipe titles and URLs are never copied into repository seeds or tests.
