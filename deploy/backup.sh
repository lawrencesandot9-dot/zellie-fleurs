#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/zellie-fleurs}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/zellie-fleurs}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
STAMP="$(date +%Y%m%d-%H%M%S)"
DB="$APP_DIR/data/shop.db"

umask 077
mkdir -p "$BACKUP_DIR"

if [ ! -f "$DB" ]; then
    echo "Database not found: $DB" >&2
    exit 1
fi

TMP="$(mktemp -d "$BACKUP_DIR/.tmp.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

sqlite3 "$DB" ".backup '$TMP/shop.db'"
CHECK="$(sqlite3 "$TMP/shop.db" 'PRAGMA integrity_check;')"
if [ "$CHECK" != "ok" ]; then
    echo "Backup integrity check failed: $CHECK" >&2
    exit 1
fi

ARCHIVE="$BACKUP_DIR/zellie-fleurs-$STAMP.tar.gz"
tar -czf "$ARCHIVE" -C "$TMP" shop.db -C "$APP_DIR" public/uploads
chmod 600 "$ARCHIVE"
find "$BACKUP_DIR" -maxdepth 1 -type f -name 'zellie-fleurs-*.tar.gz' -mtime +"$RETENTION_DAYS" -delete

echo "$ARCHIVE"
