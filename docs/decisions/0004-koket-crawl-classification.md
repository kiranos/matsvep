# Classify Köket pages after fetching

Date: 2026-11-15

Real saved Köket pages show recipe URLs at root-level slugs and non-recipe pages in the sitemap. Discovery therefore queues same-host URLs except known non-recipe sections, and the fetch stage classifies a page by schema.org `Recipe` JSON-LD or the recipe `page-type` meta tag. A fetched page without either marker becomes `not_recipe`, a terminal `ingest_url` status. Migration 004 adds that status and a disabled Köket source row; an operator must enable the source before crawling.

The crawler retries failures with `min(crawl.retry_max_s, crawl.retry_base_s × 2^(attempts − 1))`. A longer `Retry-After` response takes precedence for politeness. After `crawl.max_attempts`, a failed row has no next attempt. A successful 200 or 304 response resets attempts to zero. Same-host redirects queue the target and mark the old URL gone; off-host redirects use the failure policy. `crawl.timeout_s` bounds each HTTP request.

Source-provided JSON-LD cuisine and category are tags, while `recipe.cuisine` and `recipe.meal_type` remain derived feature columns for the later normalisation step. Ingredient and instruction section names are retained in their `group_name` columns when present. Migration 004 seeds only the phase 1 crawler settings needed here; the other documented `engine_config` defaults remain scheduled for one migration at the start of phase 2.
