#!/usr/bin/env bash
set -euo pipefail
umask 077

[[ $(id -u) -eq 0 ]] || { printf 'Run the backup as root.\n' >&2; exit 1; }
backup_dir=/var/backups/matsvep
install -d -o root -g root -m 0700 "$backup_dir"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
archive="$backup_dir/matsvep-$stamp-$$.sql.gz"
temporary=$(mktemp "$backup_dir/.matsvep-XXXXXXXX.sql.gz")
trap 'rm -f "$temporary"' EXIT

mysqldump --protocol=socket -uroot --single-transaction --quick \
    --routines --triggers --events --set-gtid-purged=OFF matsvep |
    gzip -9 > "$temporary"
gzip -t "$temporary"
mv "$temporary" "$archive"
find "$backup_dir" -maxdepth 1 -type f -name 'matsvep-*.sql.gz' -mtime +13 -delete
printf 'Database backup saved: %s\n' "$archive"
