# Household API and search state

Household routes derive their scope from the authenticated member's `household_id`. Members in the same household share invites, settings, profiles, onboarding, ratings and wishlist state; users without a household receive `household_required`. Retryable writes require an idempotency key, and `Learner` joins the idempotency transaction through `WriteTransaction`.

Search and wishlist pages include the household's current rating and wishlist flag. Search excludes inactive and blocked recipes, while global recipe content remains shared. Onboarding options contain identifiers and English codes; Swedish dish and ingredient terms used to select options live in seed files. Device push tokens are stored without sending notifications in this PR.

The crawler, migration runner and pilot setup set every PDO session to UTC, including the pilot's database-creation connection. The `plan_days` codes remain `all_seven` and `sunday_thursday` in settings, configuration and the API contract.
