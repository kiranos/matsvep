# Migration runner

Date: 2026-11-15

The PHP CLI runner applies numbered SQL files in filename order to an existing database. Migration `000_schema_migrations.sql` creates the tracking table. Rows store the filename, a SHA-256 checksum, `started` or `applied` status, and UTC timestamps. The runner uses a database advisory lock to prevent concurrent runs.

Files already marked applied are skipped when checksums match. A changed or missing file, or a row left `started`, stops the run for manual repair. This matters because MySQL DDL commits independently of the tracking row; the runner cannot automatically roll back a partially applied file. The runner does not infer a baseline for databases populated outside it.

Connection settings come from uncommitted `server/.env` or environment variables. The runner does not deploy or create the database.
