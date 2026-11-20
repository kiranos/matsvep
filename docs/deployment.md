# Ubuntu 26.04 deployment

Run these commands in a terminal on the Ubuntu 26.04 VPS itself; a local server console works and no SSH login to another machine is needed. The repository scripts do not deploy automatically. The current Apache site serves a small placeholder while the phase 1 crawler runs from PHP CLI; a web entry point will be deployed when the API exists.

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

The setup rerun reuses the stored application password, installs dependencies from `composer.lock`, applies only pending migrations through the local root socket, refreshes service files, reloads Apache and reschedules the backup timer. An already running crawler keeps its original deadline; restart its service after setup if you want the 20-hour window immediately. The operator still supplies the domain, email and SSH port interactively. A previously enabled crawl timer stays enabled; setup does not enable it on a fresh VPS. Check `sudo systemctl status matsvep-crawl.timer` if crawling has been enabled.

## Check crawl progress

```bash
sudo systemctl status matsvep-crawl.service
sudo journalctl -u matsvep-crawl.service -n 100 --no-pager
sudo -u matsvep cat /srv/matsvep/server/var/crawl-report.json
cd /srv/matsvep/server
sudo -u matsvep php bin/crawl-koket.php report
```

The report contains counts and URLs, never recipe content. A manual bounded run is `sudo systemctl start matsvep-crawl.service`; the same lock prevents a second concurrent crawler. Stop it with `sudo systemctl stop matsvep-crawl.service`, which sends SIGTERM and gives the crawler time to write its report.

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
