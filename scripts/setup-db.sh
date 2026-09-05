#!/bin/bash
# Database bootstrap helper for the World Graph Studio Docker environment.
# This script is intended to run after the MariaDB service is available.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_ARCHIVE="$ROOT_DIR/scripts/backup.sql.gz"
COMPOSE_FILE="$ROOT_DIR/compose.yaml"

if [[ ! -f "$BACKUP_ARCHIVE" ]]; then
  echo "Backup file not found: $BACKUP_ARCHIVE"
  exit 1
fi

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose is required to import the database backup."
  exit 1
fi

if [[ ! -f "$COMPOSE_FILE" ]]; then
  echo "Compose file not found: $COMPOSE_FILE"
  exit 1
fi

echo "=== Bootstrapping World Graph Studio database ==="

(
  cd "$ROOT_DIR"
  gzip -dc "$BACKUP_ARCHIVE" \
    | docker compose -f "$COMPOSE_FILE" exec -T database \
      sh -lc 'exec mariadb --user="$MARIADB_USER" --password="$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
)

echo "=== Database bootstrap complete ==="
