# Phase 1 contract scope

Date: 2026-11-15

## Decision

Phase 1 migrations create only the recipe catalogue, ingestion and review state, dish groups and `engine_config`. Household, planning, swipe and authentication tables are deferred to phase 2.

The ingestion schema includes `ingest_url` for resumable discovery, conditional HTTP requests and retries, and `ingredient_unknown` for the normaliser's review queue. Both are part of the data model in `docs/design.md`.

The phase 1 OpenAPI contract defines shared Recipe, Source, Ingredient and DishGroup schemas and three read endpoints: `GET /recipes/{id}`, `GET /recipes/search?q=` and `GET /health`. The full `/api/v1` contract is the first task of phase 2. The recipe endpoints declare bearer authentication, which is specified now but implemented with the phase 2 auth tables. This task changes the contract only; it does not add API controllers or crawler code.

## Reason

The first crawl needs persistent URL and unknown-ingredient queues. A phase-limited contract keeps schema and API review focused on the foundation while reserving household behaviour for the engine phase.
