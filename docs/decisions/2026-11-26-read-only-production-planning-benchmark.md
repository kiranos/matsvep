# Read-only production planning benchmark

The generated planning benchmark uses a throwaway MySQL database. The production application account cannot create databases, and operators need a measurement against the real recipe catalogue without inserting test rows.

`benchmark-plan-database.php --readonly` reads the existing catalogue inside a MySQL read-only transaction and rolls it back. With no household ID, it uses default settings and day profiles in memory. `--household=ID` uses that household's settings and ratings. The candidate shortlist and simulated pick remain in memory, so no plans, slots, or recipes are written. The report includes the active dinner-candidate count and query-inclusive timings for a full build and a pick plus re-rank. It explicitly excludes plan writes and does not replace the throwaway database benchmark for measuring the complete persistence path.
