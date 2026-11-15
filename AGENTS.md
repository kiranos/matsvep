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

Currently available (PowerShell, with a MySQL 8 client and an existing empty database):

- Apply the numbered migrations in order: `Get-ChildItem server/migrations/*.sql | Sort-Object Name | ForEach-Object { Get-Content $_.FullName -Raw -Encoding UTF8 | mysql --database=matsvep --default-character-set=utf8mb4; if ($LASTEXITCODE -ne 0) { throw "Migration failed: $($_.Name)" } }`
- Validate the API description: `npx @redocly/cli@latest lint --extends spec api/openapi.yaml`

`composer.json`, the Flutter app, the simulator and their install/test/lint commands do not exist yet. Add those commands when the corresponding code is created.

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
