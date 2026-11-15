# Phase 1 contract scope

Date: 2026-11-15

## Decision

Phase 1 migrations create only the recipe catalogue, ingestion and review state, dish groups and `engine_config`. Household, planning, swipe and authentication tables are deferred to phase 2.

The ingestion schema includes `ingest_url` for resumable discovery, conditional HTTP requests and retries, and `ingredient_unknown` for the normaliser's review queue. Both are part of the data model in `docs/design.md`.

Recipe copies merged during ingestion are marked inactive, not deleted. `ON DELETE CASCADE` on recipe ingredients, steps, tags, raw snapshots and source links applies only when an administrator deliberately deletes a recipe during clean-up.
The unique primary source-link marker is a virtual generated column so MySQL permits `ON DELETE CASCADE` on `recipe_source_link.recipe_id`.
All phase 1 tables use the Swedish `utf8mb4_sv_0900_ai_ci` collation; `ingredient_unknown.normalised_token` retains its binary collation for exact token uniqueness. The leftover keyword column is `signal_type` to avoid MySQL's reserved `SIGNAL` keyword.

The phase 1 OpenAPI contract defines shared Recipe, Source, Ingredient and DishGroup schemas and three read endpoints: `GET /recipes/{id}`, `GET /recipes/search?q=` and `GET /health`. The full `/api/v1` contract is the first task of phase 2. The recipe endpoints declare bearer authentication, which is specified now but implemented with the phase 2 auth tables. This task changes the contract only; it does not add API controllers or crawler code.

## Reason

The first crawl needs persistent URL and unknown-ingredient queues. A phase-limited contract keeps schema and API review focused on the foundation while reserving household behaviour for the engine phase.
