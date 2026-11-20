#!/usr/bin/env bash
set -euo pipefail

fail() { printf 'Setup failed: %s\n' "$*" >&2; exit 1; }

[[ $(id -u) -eq 0 ]] || fail 'Run this script with sudo.'
[[ -t 0 ]] || fail 'Setup needs an interactive terminal for secrets.'
[[ -r /etc/os-release ]] || fail 'Cannot identify the operating system.'
# shellcheck source=/dev/null
source /etc/os-release
[[ ${ID:-} == ubuntu && ${VERSION_ID:-} == 26.04 ]] ||
    fail 'This setup supports Ubuntu 26.04 only.'

project_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
[[ $project_root == /srv/matsvep ]] ||
    fail 'Clone the private repository at /srv/matsvep before running setup.'
if [[ ${1:-} == --enable-crawl ]]; then
    [[ $# -eq 1 ]] || fail 'Usage: sudo bash deploy/setup-ubuntu.sh --enable-crawl'
    [[ -f $project_root/server/.env && -f $project_root/server/vendor/autoload.php &&
        -f /etc/systemd/system/matsvep-crawl.timer ]] ||
        fail 'Run the initial setup successfully before enabling the crawl.'
    source_count=$(mysql --protocol=socket -uroot --batch --skip-column-names \
        -e "SELECT COUNT(*) FROM matsvep.source WHERE adapter = 'koket' AND base_url = 'https://www.koket.se'")
    [[ $source_count == 1 ]] || fail 'The Köket source is missing or duplicated.'
    mysql --protocol=socket -uroot \
        -e "UPDATE matsvep.source SET enabled = TRUE WHERE adapter = 'koket' AND base_url = 'https://www.koket.se'"
    systemctl enable --now matsvep-crawl.timer
    printf 'Koket crawl enabled; the first scheduled run is the next 01:00.\n'
    exit 0
fi
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
ssh_session=${SSH_CONNECTION:-${SSH_CLIENT:-}}
default_ssh_port=${ssh_session##* }
[[ $default_ssh_port =~ ^[0-9]+$ && $default_ssh_port -ge 1 && $default_ssh_port -le 65535 ]] ||
    default_ssh_port=22
read -r -p "SSH port to allow in UFW [$default_ssh_port]: " ssh_port
ssh_port=${ssh_port:-$default_ssh_port}
[[ $ssh_port =~ ^[0-9]+$ && $ssh_port -ge 1 && $ssh_port -le 65535 ]] ||
    fail 'Enter a valid SSH port.'

env_file="$project_root/server/.env"
rotate_password=false
if [[ ${1:-} == --rotate-db-password ]]; then
    rotate_password=true
elif [[ $# -ne 0 ]]; then
    fail 'Usage: sudo bash deploy/setup-ubuntu.sh [--rotate-db-password|--enable-crawl]'
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
apt-get install -y apache2 php8.5-fpm php8.5-cli php8.5-mysql php8.5-xml \
    php8.5-mbstring php8.5-curl php8.5-zip php8.5-intl mysql-server \
    composer git unzip ufw certbot python3-certbot-apache unattended-upgrades
systemctl enable --now mysql apache2 php8.5-fpm
php -r 'exit(PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80600 ? 0 : 1);' ||
    fail 'The default PHP CLI must be PHP 8.5.'
[[ $(mysql --protocol=socket -uroot --batch --skip-column-names -e 'SELECT VERSION()') == 8.4.* ]] ||
    fail 'The local MySQL server must be MySQL 8.4.'
timedatectl set-timezone Europe/Stockholm
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'APT'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT
systemctl enable --now apt-daily.timer apt-daily-upgrade.timer
php8.5 -r 'exit(extension_loaded("pcntl") && function_exists("pcntl_async_signals") ? 0 : 1);' ||
    fail 'PHP 8.5 CLI needs the pcntl extension for clean crawl shutdown.'

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
allowlist="$project_root/server/config/admin-allowlist.conf"
[[ ! -L $allowlist ]] || fail 'Admin allowlist must be a regular file, not a symlink.'
if [[ ! -e $allowlist ]]; then
    install -o root -g matsvep -m 0640 \
        "$project_root/server/config/admin-allowlist.conf.example" "$allowlist"
fi
[[ -f $allowlist ]] || fail 'Admin allowlist must be a regular file.'
chown root:matsvep "$allowlist"
chmod 0640 "$allowlist"
install -d -o matsvep -g matsvep -m 0700 "$project_root/server/var/sessions"
chmod -R go-rwx "$project_root/server/var/sessions"

render_install() {
    local template=$1 destination=$2 rendered
    rendered=$(mktemp)
    sed -e "s|@DOMAIN@|$domain|g" -e "s|@PROJECT_ROOT@|$project_root|g" \
        "$template" > "$rendered"
    install -o root -g root -m 0644 "$rendered" "$destination"
    rm -f "$rendered"
}

render_install "$project_root/deploy/php-fpm/matsvep-admin.conf.template" \
    /etc/php/8.5/fpm/pool.d/matsvep-admin.conf
php-fpm8.5 -t
systemctl restart php8.5-fpm
a2enmod ssl proxy_fcgi setenvif rewrite headers >/dev/null
a2enconf php8.5-fpm >/dev/null
a2dissite 000-default.conf >/dev/null 2>&1 || true
a2dissite matsvep-admin.conf >/dev/null 2>&1 || true
a2disconf matsvep-admin-listen >/dev/null 2>&1 || true
rm -f /etc/apache2/sites-available/matsvep-admin.conf \
    /etc/apache2/conf-available/matsvep-admin-listen.conf

ufw default deny incoming
ufw default allow outgoing
ufw allow "$ssh_port"/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

if [[ ! -f /etc/letsencrypt/live/$domain/fullchain.pem ||
    ! -f /etc/letsencrypt/live/$domain/privkey.pem ]]; then
    render_install "$project_root/deploy/apache/matsvep-bootstrap.conf.template" \
        /etc/apache2/sites-available/matsvep-bootstrap.conf
    a2dissite matsvep.conf >/dev/null 2>&1 || true
    a2ensite matsvep-bootstrap.conf >/dev/null
    apache2ctl configtest
    systemctl reload apache2
    certbot --apache --non-interactive --agree-tos --email "$email" \
        --keep-until-expiring --redirect -d "$domain"
fi
[[ -f /etc/letsencrypt/live/$domain/fullchain.pem &&
    -f /etc/letsencrypt/live/$domain/privkey.pem &&
    -f /etc/letsencrypt/options-ssl-apache.conf ]] ||
    fail 'HTTPS certificate or Apache TLS options are missing.'
render_install "$project_root/deploy/apache/matsvep.conf.template" \
    /etc/apache2/sites-available/matsvep.conf
a2dissite matsvep-bootstrap.conf >/dev/null 2>&1 || true
a2dissite matsvep-bootstrap-le-ssl.conf >/dev/null 2>&1 || true
a2dissite matsvep-le-ssl.conf >/dev/null 2>&1 || true
rm -f /etc/apache2/sites-available/matsvep-bootstrap.conf \
    /etc/apache2/sites-available/matsvep-bootstrap-le-ssl.conf \
    /etc/apache2/sites-available/matsvep-le-ssl.conf
a2ensite matsvep.conf >/dev/null
apache2ctl configtest
systemctl reload apache2

install -o root -g root -m 0644 "$project_root/deploy/systemd/matsvep-crawl.service" /etc/systemd/system/
install -o root -g root -m 0644 "$project_root/deploy/systemd/matsvep-crawl.timer" /etc/systemd/system/
install -o root -g root -m 0644 "$project_root/deploy/systemd/matsvep-backup.service" /etc/systemd/system/
install -o root -g root -m 0644 "$project_root/deploy/systemd/matsvep-backup.timer" /etc/systemd/system/
install -o root -g root -m 0750 "$project_root/deploy/backup-db.sh" /usr/local/sbin/matsvep-backup
systemctl daemon-reload
systemd-analyze verify /etc/systemd/system/matsvep-crawl.service \
    /etc/systemd/system/matsvep-crawl.timer \
    /etc/systemd/system/matsvep-backup.service \
    /etc/systemd/system/matsvep-backup.timer
systemctl enable --now matsvep-backup.timer
printf 'Setup complete. Run sudo bash deploy/setup-ubuntu.sh --enable-crawl when ready.\n'
