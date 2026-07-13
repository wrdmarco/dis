#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
BACKUP_PATH="${1:-}"

if [ -z "${BACKUP_PATH}" ]; then
  fail "Usage: restore.sh /opt/dis-data/backup/<timestamp>"
fi

require_directory "${BACKUP_PATH}"
load_data_path_from_env "${APP_ROOT}/.env"
ensure_data_links "${APP_ROOT}"
require_file "${APP_ROOT}/.env"
require_file "${BACKUP_PATH}/SHA256SUMS"

set -a
source "${APP_ROOT}/.env"
if [ -f "${APP_ROOT}/webapp/backend/storage/app/backup-config.env" ]; then
  source "${APP_ROOT}/webapp/backend/storage/app/backup-config.env"
fi
set +a
resolve_backup_root "${APP_ROOT}" >/dev/null

run_cmd bash "${SCRIPT_DIR}/verify-backup.sh" "${BACKUP_PATH}"

PAYLOAD_ROOT="${BACKUP_PATH}"
TEMPORARY_PAYLOAD=""
if [ -f "${BACKUP_PATH}/backup.payload.enc" ]; then
  TEMPORARY_PAYLOAD="$(mktemp -d "${TMPDIR:-/var/tmp}/dis-backup-restore.XXXXXX")"
  chmod 0700 "${TEMPORARY_PAYLOAD}"
  trap 'rm -rf -- "${TEMPORARY_PAYLOAD}"' EXIT

  log "Decrypting backup payload for restore"
  extract_encrypted_backup_payload "${BACKUP_PATH}/backup.payload.enc" "${TEMPORARY_PAYLOAD}"
  PAYLOAD_ROOT="${TEMPORARY_PAYLOAD}"
fi

require_file "${PAYLOAD_ROOT}/database.dump"

log "Restoring database from ${BACKUP_PATH}"
PGPASSWORD="${DB_PASSWORD}" run_cmd pg_restore \
  --host="${DB_HOST}" \
  --port="${DB_PORT}" \
  --username="${DB_USERNAME}" \
  --dbname="${DB_DATABASE}" \
  --clean \
  --if-exists \
  "${PAYLOAD_ROOT}/database.dump"

if [ -f "${PAYLOAD_ROOT}/storage.tar.gz" ]; then
  log "Restoring storage archive"
  if tar -tzf "${PAYLOAD_ROOT}/storage.tar.gz" | grep -q '^webapp/backend/storage/'; then
    run_cmd tar -C "${DIS_DATA_PATH}" -xzf "${PAYLOAD_ROOT}/storage.tar.gz"
  else
    run_cmd tar -C "${APP_ROOT}" -xzf "${PAYLOAD_ROOT}/storage.tar.gz"
    ensure_data_links "${APP_ROOT}"
  fi
fi

log "Restore completed"
