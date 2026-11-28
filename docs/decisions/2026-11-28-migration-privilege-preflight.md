# Check migration privileges before recording pending work

Migration history is written before each SQL file is applied. A data-only application account could previously write a `started` row and then fail on the first schema change, leaving a manual repair task.

Before applying any pending migration, the runner reads `CURRENT_USER()` and checks `information_schema.USER_PRIVILEGES` and `SCHEMA_PRIVILEGES` for CREATE, ALTER, DROP, INDEX, REFERENCES and TRIGGER on the target database. It exits with setup guidance if any are missing, before writing migration history. When all files are already applied, it skips the privilege check and reports that the schema is up to date. Existing interrupted rows still require manual repair.
