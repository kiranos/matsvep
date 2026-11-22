# Ubuntu 26.04 deployment

Run these commands in a terminal on the Ubuntu 26.04 VPS itself; a local server console works and no SSH login to another machine is needed. The repository scripts do not deploy automatically. The current Apache site serves a small placeholder from `server/public/` while the phase 1 crawler runs from PHP CLI; an API entry point will be deployed when it exists.

## First setup

1. Create a non-root SSH account with sudo access on the VPS. Point the intended domain's DNS record at the VPS. Set up an SSH deploy key with read access to the private GitHub repository.
2. Clone the merged `main` branch as that account at `/srv/matsvep`:

   ```bash
   sudo install -d -o "$USER" -g "$USER" -m 0755 /srv/matsvep
   git clone git@github.com:kiranos/matsvep.git /srv/matsvep
   cd /srv/matsvep
   ```

3. Run `sudo bash deploy/setup-ubuntu.sh` from the checkout. It prompts for the public domain, email, active SSH port and a new database application password. Keep the password private. The script requires Ubuntu 26.04 and an interactive terminal, installs Apache 2.4, PHP 8.5-FPM and CLI, MySQL 8.4, Composer and Certbot, and can be rerun. It creates the non-root `matsvep` service account, a local `matsvep` database, and a `matsvep_app` user with only SELECT, INSERT, UPDATE and DELETE on that database. Schema migrations run separately through the local MySQL root socket; the application password cannot change the schema. The script writes `server/.env` as `root:matsvep` with mode 0640. It installs and enables `unattended-upgrades` for security updates.
4. The script verifies that the default CLI is PHP 8.5, CLI `pcntl` is available, and the local server is MySQL 8.4. It enables UFW after allowing the SSH port you entered plus TCP 80 and 443. On a local console, the SSH port prompt defaults to 22; enter the server's actual SSH port if it differs. The script does not connect over SSH. It obtains a Let's Encrypt certificate with Certbot's Apache plugin and configures HTTPS redirection. Check that DNS points to this VPS before running it. The VPS timezone is set to `Europe/Stockholm`.
5. Check the services and backup timer before enabling crawling:

   ```bash
   sudo systemctl status apache2 php8.5-fpm mysql
   sudo systemctl list-timers 'matsvep-*.timer'
   sudo systemctl status matsvep-backup.timer apt-daily-upgrade.timer
   ```

6. When ready to start collecting recipes, run `sudo bash deploy/setup-ubuntu.sh --enable-crawl` from `/srv/matsvep`. This separate step enables the Köket source and starts the crawl timer. **The full crawl starts at 01:00 on the first night after you enable it.** Setup alone does not enable the source or crawl timer.

The crawl timer starts at 01:00 local time. `Persistent=false` skips a missed run. The service executes `php bin/crawl-koket.php crawl --hours 20` as `matsvep` and has `RuntimeMaxSec=21h` as a hard stop, leaving time for clean shutdown. It uses `Type=exec` because systemd does not apply `RuntimeMaxSec` to `Type=oneshot`. The crawler's database lock prevents overlapping runs. The backup timer runs at 22:30, after the crawl window and hard stop, and retains fourteen days of archives.

## SSH key-only login

After setup, you may switch SSH to key-only login manually. **First open a second SSH session and confirm your key can log in and use `sudo`; keep the original session open.** Then edit an SSH server drop-in to set `PasswordAuthentication no` and `KbdInteractiveAuthentication no`. Check the effective settings with `sudo sshd -T`, validate with `sudo sshd -t`, and reload SSH. Test a new key-based session before closing the original one. The setup script does not change SSH authentication, so it cannot lock you out during provisioning.

## Deploy updates

From `/srv/matsvep`, as the checkout owner:

```bash
git fetch origin
git pull --ff-only origin main
sudo bash deploy/setup-ubuntu.sh
```

The setup rerun reuses the stored application password, installs dependencies from `composer.lock`, applies only pending migrations through the local root socket, refreshes the root-owned Apache, PHP-FPM and systemd configuration copies from `deploy/`, reloads Apache and reschedules the backup timer. An already running crawler keeps its original deadline; restart its service after setup if you want the 20-hour window immediately. It preserves `server/config/admin-allowlist.conf`. The operator still supplies the domain, email and SSH port interactively. A previously enabled crawl timer stays enabled; setup does not enable it on a fresh VPS. Check `sudo systemctl status matsvep-crawl.timer` if crawling has been enabled.

## Check crawl progress

```bash
sudo systemctl status matsvep-crawl.service
sudo journalctl -u matsvep-crawl.service -n 100 --no-pager
sudo -u matsvep cat /srv/matsvep/server/var/crawl-report.json
cd /srv/matsvep/server
sudo -u matsvep php bin/crawl-koket.php report
```

The report contains counts and URLs, never recipe content. A manual bounded run is `sudo systemctl start matsvep-crawl.service`; the same lock prevents a second concurrent crawler. Stop it with `sudo systemctl stop matsvep-crawl.service`, which sends SIGTERM and gives the crawler time to write its report.

## Process collected recipes

After the full crawl has finished, apply pending migrations with `sudo bash deploy/setup-ubuntu.sh` if this version has not yet been deployed. Then, from the VPS checkout, run the processing command as the service account:

```bash
cd /srv/matsvep/server
sudo -u matsvep php bin/process-recipes.php
```

It seeds ingredient and feature vocabularies, normalises ingredient lines, derives recipe features and missing time estimates, then deduplicates and groups recipes. It writes `server/var/ingredient-report.json`, `feature-report.json`, `dedupe-report.json`, and `processing-report.json`. The last report includes time coverage before and after estimation. The command is repeatable: mapped or ignored unknown ingredients, recipe feature overrides, duplicate review decisions and manually edited dish groups persist. It takes the same MySQL advisory lock as the Köket crawler for its entire run, so it fails before changing data if crawling is active. Running it can take a while on the full catalogue; use a persistent terminal session and rerun it if interrupted. No processing timer is enabled automatically.

After deploying the parser fix, previously exhausted HTTP 200 parse failures can be queued for one more fetch. Run this only after the new parser is installed; the crawler will fetch them on its next run. Inspect the affected count first:

```bash
sudo mysql --protocol=socket -uroot matsvep -e "SELECT COUNT(*) AS failed_http_200 FROM ingest_url u JOIN source s ON s.id = u.source_id WHERE s.adapter = 'koket' AND u.status = 'failed' AND u.http_status = 200"
sudo mysql --protocol=socket -uroot matsvep -e "UPDATE ingest_url u JOIN source s ON s.id = u.source_id SET u.status = 'queued', u.attempts = 0, u.next_attempt_at = NULL WHERE s.adapter = 'koket' AND u.status = 'failed' AND u.http_status = 200"
```

The saved diagnostic pages showed three pages that parse with the current parser and one genuine JSON-LD Recipe with an empty instruction list. The parser now stores that incomplete recipe with zero steps so the quality report can flag it. This command requeues only failed HTTP 200 pages, not terminal non-recipes or gone URLs.

Check `sudo systemctl status matsvep-crawl.service` before starting. The systemd crawler may start at 01:00; the shared lock prevents it from overlapping processing. Process after the crawl window or stop the crawler cleanly first if you intentionally need to run it earlier. The database connection stays open while the command holds the lock.

## Backups and off-server copies

At 22:30, `matsvep-backup.service` uses the local MySQL root socket to run `mysqldump --single-transaction --quick` with routines, triggers and events, compresses the result, verifies gzip integrity, then atomically saves it under `/var/backups/matsvep/`. The directory is root-only. Successful runs remove archives older than fourteen days. The dump contains collected recipe data and must stay private.

```bash
sudo systemctl start matsvep-backup.service
sudo journalctl -u matsvep-backup.service -n 30 --no-pager
sudo ls -lh /var/backups/matsvep/
```

To copy one archive off the VPS, stage a private copy for your SSH account and use `scp` from your own computer. Remove the staged VPS copy after verifying the local copy:

```bash
sudo install -o "$USER" -g "$USER" -m 0600 \
  /var/backups/matsvep/ARCHIVE.sql.gz "$HOME/ARCHIVE.sql.gz"
# On your own computer:
scp your-user@your-vps:~/ARCHIVE.sql.gz ./ARCHIVE.sql.gz
# Back on the VPS:
rm "$HOME/ARCHIVE.sql.gz"
```

Store off-server copies in private, encrypted storage. The local fourteen-day retention is not a substitute for an off-server copy.

## Restore a backup

Restoring replaces the current database. First verify the chosen file and make a fresh backup if the current database is still usable. Stop the crawl timer and any running crawl:

```bash
sudo systemctl stop matsvep-crawl.timer matsvep-crawl.service
sudo gzip -t /path/to/ARCHIVE.sql.gz
sudo systemctl start matsvep-backup.service
sudo mysql --protocol=socket -uroot -e \
  "DROP DATABASE IF EXISTS matsvep; CREATE DATABASE matsvep CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci;"
set -o pipefail
sudo gzip -dc /path/to/ARCHIVE.sql.gz | sudo mysql --protocol=socket -uroot matsvep
cd /srv/matsvep
sudo env DB_HOST=localhost DB_PORT=3306 DB_NAME=matsvep DB_USER=root DB_PASSWORD='' \
  php server/bin/migrate.php
sudo systemctl start matsvep-crawl.timer
```

Inspect the report and table counts before resuming scheduled crawling. Database grants survive recreation because MySQL stores user grants separately from the database contents.

## Rotate secrets

Run `sudo bash deploy/setup-ubuntu.sh --rotate-db-password` from `/srv/matsvep`. It prompts for a new password, updates the local MySQL account and rewrites the restricted `server/.env`; it does not print the secret. Stop an active crawl before rotation and start it again afterward. Rotate the private repository deploy key in GitHub and on the VPS separately. Certbot manages certificate renewal; check `sudo systemctl list-timers '*certbot*'` and `sudo certbot renew --dry-run`.

The setup uses Ubuntu 26.04 PHP 8.5 packages and its MySQL 8.4 server and Certbot Apache integration. The timers use systemd calendar scheduling, and the backup uses MySQL's consistent InnoDB dump mode. See [Ubuntu 26.04 release notes](https://documentation.ubuntu.com/release-notes/26.04/), [systemd service](https://www.freedesktop.org/software/systemd/man/latest/systemd.service.html), [systemd timer](https://www.freedesktop.org/software/systemd/man/latest/systemd.timer.html), and [MySQL mysqldump](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html).

## Application and system configuration

The repository at `/srv/matsvep` contains deployment templates under `deploy/`. Application code, the web root (`server/public/`), `server/.env`, the admin IP allowlist (`server/config/admin-allowlist.conf`), and runtime data (`server/var/`, including sessions and reports) all stay under `server/`.

The setup script installs **root-owned copies**, mode 0644, of the Apache site in `/etc/apache2/sites-available/matsvep.conf`, the dedicated PHP 8.5-FPM pool in `/etc/php/8.5/fpm/pool.d/matsvep-admin.conf`, and the crawler and backup units in `/etc/systemd/system/`. It enables the Apache site with `a2ensite`. These are normal system registrations, not symlinks into the repository. Rerunning setup refreshes them from the templates in `deploy/` and validates/reloads the services. The private database backup archives stay in `/var/backups/matsvep/`.

## Admin cleanup pages

The admin site is available at `https://YOUR_DOMAIN/admin/`. Apache redirects HTTP to HTTPS. Before starting a session, the PHP front controller checks `REMOTE_ADDR` against `server/config/admin-allowlist.conf` on **every request** and denies access with 403 if the file is missing, empty, unreadable, or has no matching address. Invalid lines are logged and ignored. Login lockout, a Secure session cookie, CSRF protection and an audit log provide further protection. A dedicated PHP-FPM pool runs as `matsvep` so it can read the private `.env` and use `server/var/sessions/`, which is owned by `matsvep` with mode 0700.

Setup copies `server/config/admin-allowlist.conf.example` to the live allowlist **only if the live file does not exist**. It leaves later edits intact and sets the live file to `root:matsvep`, mode 0640. The example grants only loopback access; add your current public IP or CIDR to reach `/admin/` directly. Each line accepts one IPv4/IPv6 address or CIDR range; blank lines and `#` comments are allowed. Edit the file on the VPS:

```bash
sudo nano /srv/matsvep/server/config/admin-allowlist.conf
```

The next request sees the change without restarting Apache or PHP-FPM. Use your browser's public IP as seen by the VPS; proxies or VPNs can change `REMOTE_ADDR`. After deploying and running migrations, create or reset an admin account with `cd /srv/matsvep/server && sudo -u matsvep php bin/create-admin.php you@example.com`. The command prompts for a password without echoing it. Setup does not create an admin account.

If your public IP changes before you can edit the file, use a temporary **HTTPS** SSH tunnel from your computer. Keep the loopback entries in the allowlist. Map `YOUR_DOMAIN` to `127.0.0.1` temporarily in your computer's hosts file, then run:

```sh
ssh -L 8443:127.0.0.1:443 user@vps
```

While the tunnel is open, visit `https://YOUR_DOMAIN:8443/admin/`. The domain still matches the HTTPS certificate; the VPS sees the tunnel connection from loopback. Remove the temporary hosts-file entry when finished. This tunnel does not replace the admin login.
