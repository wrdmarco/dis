#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
BACKUP_PATH="${1:-}"

if [ -z "${BACKUP_PATH}" ]; then
  fail "Usage: restore.sh /opt/dis/backup/<timestamp>"
fi

require_directory "${BACKUP_PATH}"
require_file "${APP_ROOT}/.env"
require_file "${BACKUP_PATH}/database.dump"
require_file "${BACKUP_PATH}/SHA256SUMS"

run_cmd bash "${SCRIPT_DIR}/verify-backup.sh" "${BACKUP_PATH}"

set -a
source "${APP_ROOT}/.env"
set +a

log "Restoring database from ${BACKUP_PATH}"
PGPASSWORD="${DB_PASSWORD}" run_cmd pg_restore \
  --host="${DB_HOST}" \
  --port="${DB_PORT}" \
  --username="${DB_USERNAME}" \
  --dbname="${DB_DATABASE}" \
  --clean \
  --if-exists \
  "${BACKUP_PATH}/database.dump"

if [ -f "${BACKUP_PATH}/storage.tar.gz" ]; then
  log "Restoring storage archive"
  run_cmd tar -C "${APP_ROOT}" -xzf "${BACKUP_PATH}/storage.tar.gz"
fi

log "Restore completed"
