# Run a resumable Köket pilot before the full crawl

Date: 2026-11-16

Use a separate local MySQL database whose name ends in `_pilot`. A fixed-seed, proportionally stratified sample of sitemap candidates includes root-level and nested paths. The `ingest_url` queue holds the sample and retry state, so restarting the Windows script resumes it without another selection. The local sitemap option avoids a redundant sitemap request.

The report contains counts, failure URLs, and retry URLs, but no recipe content. The script fetches due work in bounded batches and waits for a retry only when it is at most five minutes away. Longer pending retries remain scheduled and appear in the report for the next run. Live requests are made only when the operator runs the pilot, never from development or tests.
