#!/usr/bin/env bash
set -euo pipefail

fail() { printf 'Setup failed: %s\n' "$*" >&2; exit 1; }

[[ $(id -u) -eq 0 ]] || fail 'Run this script with sudo.'
[[ -t 0 ]] || fail 'Setup needs an interactive terminal for secrets.'
[[ -r /etc/os-release ]] || fail 'Cannot identify the operating system.'
# shellcheck source=/dev/null
source /etc/os-release
[[ ${ID:-} == ubuntu && ${VERSION_ID:-} == 24.04 ]] ||
    fail 'This setup supports Ubuntu 24.04 only.'

project_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
[[ $project_root == /srv/matsvep ]] ||
    fail 'Clone the private repository at /srv/matsvep before running setup.'
operator=$(stat -c '%U' "$project_root")
[[ $operator != root ]] || fail 'The repository must be owned by the non-root deploy operator.'
[[ $operator == "${SUDO_USER:-$operator}" ]] ||
    fail 'Run sudo as the operator who owns /srv/matsvep.'
[[ -f "$project_root/server/composer.lock" ]] || fail 'Missing server/composer.lock.'

read -r -p 'Public domain for HTTPS: ' domain
[[ $domain =~ ^[A-Za-z0-9][A-Za-z0-9.-]+[A-Za-z0-9]$ && $domain == *.* ]] ||
    fail 'Enter a valid DNS name.'
read -r -p 'Email for HTTPS renewal and crawler contact: ' email
[[ $email =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$ ]] || fail 'Enter a valid email address.'
default_ssh_port=${SSH_CONNECTION##* }
[[ $default_ssh_port =~ ^[0-9]+$ ]] || default_ssh_port=22
read -r -p "SSH port to allow in UFW [$default_ssh_port]: " ssh_port
ssh_port=${ssh_port:-$default_ssh_port}
[[ $ssh_port =~ ^[0-9]+$ && $ssh_port -ge 1 && $ssh_port -le 65535 ]] ||
    fail 'Enter a valid SSH port.'

env_file="$project_root/server/.env"
rotate_password=false
if [[ ${1:-} == --rotate-db-password ]]; then
    rotate_password=true
elif [[ $# -ne 0 ]]; then
    fail 'Usage: sudo bash deploy/setup-ubuntu.sh [--rotate-db-password]'
fi
db_password=''
if [[ -f $env_file && $rotate_password == false ]]; then
    db_password=$(sed -n 's/^DB_PASSWORD=//p' "$env_file" | head -n 1)
fi
if [[ -z $db_password ]]; then
    read -r -s -p 'Database application password (24+ safe ASCII characters): ' db_password
    printf '\n'
    read -r -s -p 'Repeat database application password: ' confirmation
    printf '\n'
    [[ $db_password == "$confirmation" ]] || fail 'Passwords do not match.'
    unset confirmation
fi
[[ ${#db_password} -ge 24 && $db_password =~ ^[A-Za-z0-9._~!@%+=/-]+$ ]] ||
    fail 'Password must be at least 24 safe ASCII characters (letters, digits, . _ ~ ! @ % + = / -).'

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y apache2 php8.3-fpm php8.3-cli php8.3-mysql php8.3-xml \
    php8.3-mbstring php8.3-curl php8.3-zip php8.3-intl mysql-server \
    composer git unzip ufw certbot python3-certbot-apache
systemctl enable --now mysql apache2 php8.3-fpm
timedatectl set-timezone Europe/Stockholm
php -r 'exit(function_exists("pcntl_async_signals") ? 0 : 1);' ||
    fail 'PHP CLI needs the pcntl extension for clean crawl shutdown.'

if ! id -u matsvep >/dev/null 2>&1; then
    useradd --system --create-home --home-dir /var/lib/matsvep \
        --shell /usr/sbin/nologin matsvep
fi
chgrp -R matsvep "$project_root/server"
chmod -R g+rX "$project_root/server"
install -d -o matsvep -g matsvep -m 0750 "$project_root/server/var"
install -d -o root -g root -m 0700 /var/backups/matsvep

mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS matsvep CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci;
CREATE USER IF NOT EXISTS 'matsvep_app'@'localhost' IDENTIFIED BY '$db_password';
ALTER USER 'matsvep_app'@'localhost' IDENTIFIED BY '$db_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON matsvep.* TO 'matsvep_app'@'localhost';
SQL

tmp_env=$(mktemp "$project_root/server/.env.XXXXXX")
trap 'rm -f "${tmp_env:-}"; unset db_password' EXIT
cat > "$tmp_env" <<ENV
DB_HOST=localhost
DB_PORT=3306
DB_NAME=matsvep
DB_USER=matsvep_app
DB_PASSWORD=$db_password
CRAWL_USER_AGENT="MatsvepCrawler/1.0 (+mailto:$email)"
ENV
chown root:matsvep "$tmp_env"
chmod 0640 "$tmp_env"
mv -f "$tmp_env" "$env_file"
unset db_password

(cd "$project_root/server" && runuser -u "$operator" -- \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader)
chgrp -R matsvep "$project_root/server/vendor"
chmod -R g+rX "$project_root/server/vendor"
DB_HOST=localhost DB_PORT=3306 DB_NAME=matsvep DB_USER=root DB_PASSWORD='' \
    php "$project_root/server/bin/migrate.php"
mysql --protocol=socket -uroot <<SQL
UPDATE matsvep.source SET enabled = TRUE
WHERE adapter = 'koket' AND base_url = 'https://www.koket.se';
SQL

install -d -o root -g root -m 0755 /var/www/matsvep
printf 'Matsvep\n' > /var/www/matsvep/index.html
cat > /etc/apache2/sites-available/matsvep.conf <<APACHE
<VirtualHost *:80>
    ServerName $domain
    DocumentRoot /var/www/matsvep
    <Directory /var/www/matsvep>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>
    ErrorLog /var/log/apache2/matsvep-error.log
    CustomLog /var/log/apache2/matsvep-access.log combined
</VirtualHost>
APACHE
a2enmod proxy_fcgi setenvif rewrite headers >/dev/null
a2enconf php8.3-fpm >/dev/null
a2ensite matsvep.conf >/dev/null
a2dissite 000-default.conf >/dev/null 2>&1 || true
apache2ctl configtest
systemctl reload apache2

ufw default deny incoming
ufw default allow outgoing
ufw allow "$ssh_port"/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
certbot --apache --non-interactive --agree-tos --email "$email" \
    --keep-until-expiring --redirect -d "$domain"

install -m 0644 "$project_root/deploy/systemd/matsvep-crawl.service" /etc/systemd/system/
install -m 0644 "$project_root/deploy/systemd/matsvep-crawl.timer" /etc/systemd/system/
install -m 0644 "$project_root/deploy/systemd/matsvep-backup.service" /etc/systemd/system/
install -m 0644 "$project_root/deploy/systemd/matsvep-backup.timer" /etc/systemd/system/
install -o root -g root -m 0750 "$project_root/deploy/backup-db.sh" /usr/local/sbin/matsvep-backup
systemctl daemon-reload
systemd-analyze verify /etc/systemd/system/matsvep-crawl.service \
    /etc/systemd/system/matsvep-crawl.timer \
    /etc/systemd/system/matsvep-backup.service \
    /etc/systemd/system/matsvep-backup.timer
systemctl enable --now matsvep-crawl.timer matsvep-backup.timer
printf 'Setup complete. Check timers with systemctl list-timers matsvep-*.timer\n'
