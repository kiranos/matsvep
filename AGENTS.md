# AGENTS.md – Matsvep

Read this file before every task. The full design is in `/docs/design.md`; it is the source of truth.

## What this is

Matsvep ("food swipe") is a private family app that suggests weekly dinners. A rule-based, explainable engine ranks Swedish recipes for each day; the family picks by swiping and rates each recipe once (1–5). The engine learns from those ratings. There is no AI or machine learning in the app.

## Stack and versions

- Server: Linux (Ubuntu 24.04 LTS or Debian 12), Apache 2.4, PHP 8.3 (PHP-FPM), MySQL 8. No containers. Composer, PSR-4 autoload.
- Clients: one Flutter (stable) codebase for Windows desktop and Android.
- API: JSON under `/api/v1`, described in `/api/openapi.yaml`.

## Repository layout

```
/docs/design.md        design document (source of truth)
/docs/decisions/       one short note per design change
/server/src/Engine     Filters, Scorer, PlanBuilder, Learner, Explainer, Config
/server/src/Ingest     Adapter/*, Normaliser, Dedupe
/server/src/Api        thin JSON controllers
/server/migrations     numbered SQL migrations
/server/tests          PHPUnit tests, fixtures, golden plans
/server/simulator      personas and the headless simulator
/app/                  Flutter app
/api/openapi.yaml      API contract
```

## Commands

With PHP 8.3 CLI, PDO MySQL, Composer 2 and MySQL 8:

- Install server dependencies: `cd server && composer install`.
- For local integration tests, copy `server/.env.testing.example` to uncommitted `server/.env.testing` and set credentials for a MySQL user that can create and drop throwaway databases. `DB_NAME` is a prefix; the test adds a random suffix.
- Run server checks from `server/`: `composer lint`, `composer stan`, `composer test`.
- To run migrations, copy `server/.env.example` to uncommitted `server/.env`, create an empty database, then run `php server/bin/migrate.php` from the repository root. The runner also accepts `MATS_ENV_FILE` and `DB_*` environment overrides.
- Validate the API description: `npx @redocly/cli@latest lint --extends spec api/openapi.yaml`.

The Flutter app and simulator do not exist yet; add their commands when created.

## Hard rules

1. No AI, ML or LLM libraries or API calls in the app or server.
2. Every tunable number comes from the `engine_config` table, never hard-coded.
3. No business logic in API controllers. Logic lives in `Engine` and `Ingest`.
4. Database access only through prepared statements.
5. Schema changes only through new numbered migrations. Never edit an applied migration.
6. Every engine change comes with tests. Expected numbers come from the formulas in `/docs/design.md`.
7. All scoring runs on the server. The Flutter app holds no scoring logic.
8. Crawlers obey robots.txt, identify themselves, wait at least `crawl.delay_s` between requests per site, and never run from a user request.
9. Parser tests use saved HTML or JSON-LD fixtures. Never hit live recipe sites from tests or during development tasks.
10. Recipe photos are linked by URL, never downloaded. Scraped data and database snapshots never go into a public repository.
11. Never read, print or commit secrets. Configuration comes from an uncommitted `.env`. You have no production credentials and do not deploy.
12. Everything in the code base is in English (see Language and naming). Swedish appears only in the Flutter app's localisation files and in data (recipe content, word lists, seed files), never in code.

## Language and naming

- Use English for identifiers, tables, columns, enum values, config keys, API paths and fields, codes, comments, log messages, tests, commits, pull requests and docs.
- Keep user-facing text in Flutter localisation files: `app/lib/l10n/app_sv.arb` is the default Swedish locale and `app/lib/l10n/app_en.arb` is the English locale. Do not hard-code user-facing text in Dart. The server sends event and reason codes with parameters, never display text; for example, reason `rated_stars` with `{"stars": 5}` or event `planning_ready`.
- Keep Swedish recipe content, tags, ingredient names and aliases, leftover keywords, title-reduction words and onboarding dishes in the database or seed files (`server/seeds/*.csv`), never as literals in code.
- PHP: PSR-12 via PHP-CS-Fixer; PascalCase classes, camelCase methods and variables, UPPER_SNAKE constants, and the `Matsvep\` namespace. Dart: Effective Dart.
- SQL: snake_case identifiers, singular tables, `fk_`/`ix_`/`uq_`/`ck_`/`ft_` index and constraint prefixes, and `NNN_snake_case.sql` migrations. `schema_migrations` is the sole singular-table exception because it is the standard migration-tool name.
- API: snake_case fields and lower snake_case enum values. Config: dot-separated lower snake_case keys. Event and reason codes: lower snake_case English.
- Git: use `feature/…` or `fix/…` branches and English imperative commit messages.

## How to work

- Start each task by reading the relevant section of `/docs/design.md`.
- Write a short plan (files to touch, approach, tests) and wait for approval before coding.
- Keep tasks small: one feature, one branch, one pull request.
- Contract first: update migrations and `openapi.yaml` before feature code that depends on them.
- If the design is unclear or seems wrong, ask instead of guessing. If a design change is agreed, add a note in `/docs/decisions/` and update `/docs/design.md`.

## Definition of done

- All tests pass, including golden plan tests and simulator checks when engine behaviour is touched.
- Linters and static analysis are clean.
- `openapi.yaml` is updated if the API changed.
- `/docs/design.md` is updated if behaviour changed.
- The pull request description says what changed, why, and how it was tested.
