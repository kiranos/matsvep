# Longer nightly Köket crawl

The sitemap has about 41,500 possible recipe URLs. With at least five seconds between requests, the first pass takes more than 58 hours. Increase the production systemd run from six to twenty hours, beginning at 01:00. Keep `Type=exec`, a 21-hour `RuntimeMaxSec` safety limit, `Persistent=false`, and the crawler's database lock and resumable queue. The fetch delay and polite retry rules do not change.

Move the consistent gzipped MySQL backup from 08:30 to 22:30, after the crawler's normal window and hard stop. Rerunning setup installs the updated root-owned unit copies, reloads systemd and reschedules the backup timer. A crawler already running when the service file changes retains its original command and deadline until restarted or until the next scheduled run.
