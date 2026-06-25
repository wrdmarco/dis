#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
ENV_FILE="${APP_ROOT}/.env"
BACKUP_ROOT="${BACKUP_ROOT:-${APP_ROOT}/storage/backups}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="${BACKUP_ROOT}/${STAMP}"

require_file "${ENV_FILE}"
run_cmd install -d -m 0750 "${TARGET}"

set -a
source "${ENV_FILE}"
set +a

log "Creating PostgreSQL backup"
PGPASSWORD="${DB_PASSWORD}" run_cmd pg_dump \
  --host="${DB_HOST}" \
  --port="${DB_PORT}" \
  --username="${DB_USERNAME}" \
  --format=custom \
  --file="${TARGET}/database.dump" \
  "${DB_DATABASE}"

log "Archiving storage and configuration"
run_cmd tar -C "${APP_ROOT}" -czf "${TARGET}/storage.tar.gz" storage
run_cmd cp "${ENV_FILE}" "${TARGET}/env.backup"
run_cmd sha256sum "${TARGET}/database.dump" "${TARGET}/storage.tar.gz" "${TARGET}/env.backup" > "${TARGET}/SHA256SUMS"
cat > "${TARGET}/manifest.json" <<EOF
{
  "created_at": "${STAMP}",
  "app_root": "${APP_ROOT}",
  "database": "${DB_DATABASE}",
  "host": "$(hostname -f 2>/dev/null || hostname)",
  "version": "$(cat "${APP_ROOT}/VERSION" 2>/dev/null || printf unknown)"
}
EOF

log "Backup created at ${TARGET}"
