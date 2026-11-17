# Operator-run Ubuntu deployment

The production crawler runs on a single Ubuntu 24.04 VPS through a systemd timer. The service uses `Type=exec` so `RuntimeMaxSec=7h` limits a six-hour crawl. The 01:00 timer has `Persistent=false`: a missed run waits until the next night. The crawler's database lock also prevents overlapping invocations.

The operator runs an idempotent setup script from the private checkout at `/srv/matsvep`. It installs Apache, PHP 8.3-FPM and CLI, MySQL 8 and Composer, configures UFW and Let's Encrypt, and prompts for secrets. The service runs as the non-root `matsvep` account. Its database account has data privileges only; migrations use the local root socket. Raw data and `.env` remain outside the Apache document root.

Initial setup also enables unattended security updates. It installs the crawl timer but leaves both that timer and the Köket source disabled. The operator uses `setup-ubuntu.sh --enable-crawl` as a separate activation step, so the first full crawl begins at the next scheduled 01:00. SSH password authentication is unchanged during setup; the operator may switch to key-only login after proving a second key-based session works.

A separate nightly timer creates a compressed, consistent MySQL dump after the crawler's hard-stop window. Archives stay root-only, are kept for fourteen days locally, and must also be copied to private off-server storage. No production command is run by the repository automation; the operator performs setup, updates, backups and restores.
