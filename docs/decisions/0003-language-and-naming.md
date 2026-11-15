# Language and naming

Date: 2026-11-15

All code, schemas, API fields and codes, configuration keys, comments, logs, tests, commits, pull requests and documentation use English. Swedish display text lives in the Flutter localisation files, with Swedish as the default locale. The server sends reason or event codes with structured parameters; the app renders the text. Swedish recipe content, tags, ingredient names and aliases, leftover keywords, title-reduction words and onboarding dishes are data in the database or seed CSV files.

The day profile codes are `weekday_leftovers`, `friday_treat` and `saturday_dinner`. Friday DayFit checks recipe tags against `dayfit.friday_tags`, whose documented default is `fredagsmys, taco, pizza, burgare`. All remaining `engine_config` defaults listed in `docs/design.md`, including `dayfit.friday_tags`, will be seeded together in one new migration at the start of phase 2. No applied migration is edited for this documentation change.

The current PHP, SQL and OpenAPI files have no Swedish literals. Existing schema names follow the English snake_case and singular-table conventions except `schema_migrations`, the approved sole exception because it is the standard migration-tool name. Existing `codex/` branch names stay as they are; new work uses `feature/` or `fix/` branches.
