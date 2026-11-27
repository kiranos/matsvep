# AGENTS.md – Matsvep

Read this file before every task. The full design is in `/docs/design.md`; it is the source of truth.

## What this is

Matsvep ("food swipe") is a private family app that suggests weekly dinners. A rule-based, explainable engine ranks Swedish recipes for each day; the family picks by swiping and rates each recipe once (1–5). The engine learns from those ratings. There is no AI or machine learning in the app.

## Stack and versions

- Server: Ubuntu 26.04 LTS, Apache 2.4, PHP 8.5 (PHP-FPM), MySQL 8.4. No containers. Composer, PSR-4 autoload.
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

With PHP 8.5 CLI, PDO MySQL, Composer 2 and MySQL 8.4:

- Install server dependencies: `cd server && composer install`.
- For local integration tests, copy `server/.env.testing.example` to uncommitted `server/.env.testing` and set credentials for a MySQL user that can create and drop throwaway databases. `DB_NAME` is a prefix; the test adds a random suffix.
- Run server checks from `server/`: `composer validate --strict`, `composer lint`, `composer stan`, `composer test`. CI runs these on PHP 8.5 and MySQL 8.4.
- To measure planning with generated production-sized data, run `php bin/benchmark-plan.php --candidates=16365 --rated=100` from `server/` for PHP-only timing. For the end-to-end database measurement, configure uncommitted `.env.testing` and run `php bin/benchmark-plan-database.php --candidates=16365 --rated=100`. It creates and drops a throwaway MySQL database, runs all migrations, and reports the full plan and one pick plus re-rank with query and phase timings. It never touches the pilot or production database or requests live recipe sites.
- To measure the real production recipe catalogue without writes, run `sudo -u matsvep php bin/benchmark-plan-database.php --readonly` from `/srv/matsvep/server` after deployment. With no households, it uses in-memory default household settings, day profiles and no ratings or carry-overs. Add `--household=ID` to use an existing household's settings and ratings. It reads the existing recipes in a read-only transaction, rolls back, and reports the active dinner-candidate count, full plan and one pick plus re-rank times. The shortlist stays in memory, so plan writes are excluded. See `docs/deployment.md`.
- To time the planning API against generated production-sized data in a throwaway MySQL database, run `php bin/benchmark-plan-database.php --api --candidates=16365 --rated=100` from `server/` with `.env.testing` configured. It reports the complete plan, deck fetch, choose and replace times including database queries, and drops the database afterward. CI runs it on PHP 8.5 and MySQL 8.4.
- To run migrations, copy `server/.env.example` to uncommitted `server/.env`, create an empty database, then run `php server/bin/migrate.php` from the repository root. The runner also accepts `MATS_ENV_FILE` and `DB_*` environment overrides.
- Migration 004 creates a disabled Köket source. To enable crawling after reviewing its settings, run `UPDATE source SET enabled = TRUE WHERE adapter = 'koket' AND base_url = 'https://www.koket.se';` in the intended database. Set `CRAWL_USER_AGENT` in uncommitted `server/.env` to an identifying value with a contact address. From `server/`, run `php bin/crawl-koket.php discover [category-urls...]` to queue URLs and `php bin/crawl-koket.php fetch <limit>` from cron to fetch a bounded batch. Discovery reads `/sitemap.xml`; pass category pages as arguments when needed. Never run the crawler from a user request or during development tests.
- To run the Windows pilot yourself, set `DB_HOST=127.0.0.1`, a separate `DB_NAME` ending in `_pilot`, local MySQL credentials with database-creation permission, and an identifying `CRAWL_USER_AGENT` in uncommitted `server/.env`. Save the full sitemap at `server/tests/fixtures/koket/local/sitemap.xml`. With PHP 8.5 or newer, PDO MySQL, OpenSSL and Composer dependencies installed, run `powershell -NoProfile -ExecutionPolicy Bypass -File .\bin\pilot-crawl.ps1` from `server/`. The script creates and migrates only the pilot database, samples 500 URLs once, fetches batches of 50, and writes `server/pilot-report.txt`. Re-run it to resume pending retries. Stop with Ctrl+C at any time. Do not run the pilot during development or tests.
- To rerun the pilot report without fetching, keep the pilot `DB_NAME` in uncommitted `server/.env` and run `php bin/crawl-koket.php report` from `server/`; it prints counts and URLs only. The report includes canonical duplicates and recipe completeness.
- To normalise the pilot ingredients, confirm `server/.env` names the pilot database, then run `php bin/migrate.php`, `php bin/seed-ingredients.php`, and `php bin/normalise.php --all` from `server/`. Use `php bin/normalise.php --recipe=ID` to rerun one recipe, or `php bin/normalise.php --report` for a read-only UTF-8 JSON report. Re-running the seed and normaliser is safe. The report shows matched lines, parsed quantity and unit shares, protein-candidate match share, up to 50 unknown tokens, up to 20 unparsed unit words, and lines whose multiple ingredients need review. Do not run these commands against production during development.
- To derive recipe features on the pilot database, run `php bin/migrate.php`, `php bin/seed-ingredients.php`, `php bin/seed-features.php`, `php bin/normalise.php --all`, then `php bin/derive-features.php --all` from `server/`. The last command writes the UTF-8 report to gitignored `server/var/feature-report.json`. Use `php bin/derive-features.php --recipe=ID` to rerun one recipe, or `--report` to refresh the report without changing features. The report includes main-line match share, all-recipe and dinner-only feature distributions, 10 highest and lowest leftover-score dinner candidates, and 20 reproducibly sampled recipes. Do not run these development commands against production.
- To audit protein gaps and group copies on the pilot database, run `php bin/derive-features.php --all` followed by `php bin/dedupe.php --all` from `server/`. `--report` refreshes the read-only deduplication report. Review `server/var/protein-gap-review.json`, `server/var/protein-gap-report.json`, `server/var/feature-report.json`, and `server/var/dedupe-report.json`; these local reports are gitignored. The deduplication command is repeatable and retains manual group overrides. Do not run it against production during development.
- In Windows PowerShell, save the report as UTF-8 bytes without a text pipeline: from `server/`, run `$report = Join-Path (Get-Location) 'var/ingredient-report.json'; New-Item -ItemType Directory -Force 'var' | Out-Null; Start-Process -FilePath (Get-Command php).Source -ArgumentList @('bin/normalise.php', '--report') -WorkingDirectory (Get-Location).Path -RedirectStandardOutput $report -NoNewWindow -Wait`. The `server/var/` directory is gitignored. Use the full PHP executable path for `-FilePath` if `php` is not on PATH.
- On the production Linux VPS, after installing dependencies, running migrations and enabling the Köket source, run `php bin/crawl-koket.php crawl --hours 20` from `server/`. It reads the live sitemap when the queue is empty or discovery is older than seven days, resumes queued URLs and due retries, and atomically writes the content-free report to `server/var/crawl-report.json`. Re-run it safely; SIGINT or SIGTERM stops after the current request and writes the report. The nightly systemd timer is documented in `docs/deployment.md`. Never run the production crawler during development or tests.
- For operator-run Ubuntu 26.04 deployment, clone the public repository to `/srv/matsvep` as a non-root sudo user, then run `sudo bash deploy/setup-ubuntu.sh` from the checkout. When ready for the first full crawl, run `sudo bash deploy/setup-ubuntu.sh --enable-crawl` separately; it starts the crawl timer for the next 01:00. See `docs/deployment.md` for prerequisites, updates, security upgrades, SSH key-only login, journald crawl logs, backups, restore and secret rotation. The crawler service runs for up to 20 hours from 01:00 with a 21-hour hard limit; the 22:30 backup follows it, and the crawl timer skips missed runs.
- Production processing: after applying migrations and once the full crawl is idle, run `sudo -u matsvep php bin/process-recipes.php` from `/srv/matsvep/server`. The command takes the crawler lock, is repeatable and writes ingredient, feature, deduplication and time-coverage reports to `server/var/`. Do not run production processing during development; use the pilot database for checks. See `docs/deployment.md`.
- Production feature diagnostics: from `/srv/matsvep/server`, run `sudo -u matsvep php bin/derive-features.php --protein-gap-sample=50 --seed=42`, `sudo -u matsvep php bin/derive-features.php --vegetarian-sample=50 --seed=42`, or `sudo -u matsvep php bin/derive-features.php --dinner-sample=100 --seed=42`. All three accept up to 100 recipes. Omit `--seed` for a fresh random sample; the JSON output records its seed. These sample modes only read the database and print recipe URLs, titles and main ingredient lines to standard output. The dinner sample also includes meal-type tags, main protein, cuisine and vegetarian flag; the protein-gap sample includes diagnostic hints. They do not derive features or save a report. To inspect the effect of the stricter vegetarian rule, rerun production processing after the crawler stops and compare `features_before_derivation` and `features_after_derivation` in `server/var/processing-report.json`.
- Dinner classification audit: after applying migration 021 and running `sudo -u matsvep php bin/process-recipes.php` when crawling is idle, inspect `dinner_candidate_changes` in `server/var/processing-report.json`. It gives before and after counts, 30 sampled removed and remaining titles, and all removed titles matching the seeded savory audit terms. Review these results before drawing conclusions from fresh production diagnostic samples.
- Protein and category diagnostics: after migration 022 and processing, compare `features_before_derivation` with `features_after_derivation` in `server/var/processing-report.json` for protein and dinner-exclusion distributions. `protein_stem_matches` counts seeded stem matches in titles and ingredient lines. From `/srv/matsvep/server`, run `sudo -u matsvep php bin/derive-features.php --tag-report` for a read-only JSON list of every source tag, its total and active recipe counts, and its active dinner-candidate count. This command does not modify recipes or reports.
- Category mapping review: after migration 023, run `sudo -u matsvep php bin/process-recipes.php` only when the crawler is idle and production processing has been approved. In `server/var/processing-report.json`, compare `features_before_derivation` and `features_after_derivation` for dinner candidates, recipes without a source meal-type tag, dinner cuisine distribution and protein gap; inspect `dinner_candidate_changes` for 30 removed and 30 remaining titles. The category and cuisine mappings live in `server/seeds/feature_tag_map.csv`.
- Dinner evidence review: after migration 024, rerun processing on the pilot database and compare candidate counts, the protein gap, and removed/remaining title samples in `server/var/processing-report.json`. Keep production processing for an operator-approved run after the crawler is idle. The title, pie and protein rules are in `server/seeds/feature_dinner_*.csv` and `server/seeds/feature_protein_stems.csv`.
- Affinity rebuild: from `server/`, run `php bin/rebuild-affinities.php` against the configured database to recompute every household's taste profile from current ratings and onboarding answers. It takes the crawler lock. `process-recipes.php` also runs this rebuild as its final processing step after recipe features change. No affinity timer is installed yet. Use throwaway MySQL databases for development tests; do not run the command on production during development.
- Validate the API description: `npx @redocly/cli@latest lint --extends spec api/openapi.yaml`. For response-schema contract tests, bundle it with `npx @redocly/cli@latest bundle api/openapi.yaml --ext json -o server/var/openapi.json` before `composer test` from `server/`. CI does this automatically. The API front controller is `server/public/api/index.php`; setup installs a separate API PHP-FPM pool and passes `Authorization` through Apache with `CGIPassAuth On`.
- To remove expired API retry, login and device state manually, run `sudo -u matsvep php bin/purge-api-state.php` from `/srv/matsvep/server`. The nightly `matsvep-api-purge.timer` runs it at 00:15; inspect `sudo journalctl -u matsvep-api-purge.service -n 50 --no-pager`. See `docs/deployment.md`.
- To maintain unanswered dinners and pending carry-overs, run `sudo -u matsvep php bin/maintain-plans.php` from `/srv/matsvep/server`. The nightly `matsvep-plan-maintenance.timer` runs at 00:30 and logs to journald. It is repeatable; see `docs/deployment.md`.

The Flutter app and simulator do not exist yet; add their commands when created.

- Admin cleanup: after migration, run `sudo -u matsvep php bin/create-admin.php you@example.com` from `server/` on the VPS. Setup creates `server/config/admin-allowlist.conf` once; edit it with `sudo nano /srv/matsvep/server/config/admin-allowlist.conf` to allow your public IP, then open `https://YOUR_DOMAIN/admin/`. Missing or empty allowlists deny access. For a changed IP, see the HTTPS SSH tunnel fallback in `docs/deployment.md`. Rerunning setup refreshes root-owned Apache, FPM and systemd copies in `/etc` from `deploy/`, without replacing the allowlist.

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
10. The repository is public. Recipe photos are linked by URL, never downloaded. Real scraped pages, database dumps and reports must never be committed; only synthetic fixtures belong in the repository.
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
