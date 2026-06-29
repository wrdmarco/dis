#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
ENV_FILE="${APP_ROOT}/.env"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

require_file "${ENV_FILE}"
set -a
source "${ENV_FILE}"
if [ -f "${APP_ROOT}/webapp/backend/storage/app/backup-config.env" ]; then
  source "${APP_ROOT}/webapp/backend/storage/app/backup-config.env"
fi
set +a

BACKUP_ROOT="$(resolve_backup_root "${APP_ROOT}")"
TARGET="${BACKUP_ROOT}/${STAMP}"
run_cmd install -d -m 0750 "${TARGET}"

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
log "Archiving software source and module manifests"
run_cmd tar -C "${APP_ROOT}" -czf "${TARGET}/source.tar.gz" \
  --exclude='./.git' \
  --exclude='./storage' \
  --exclude='./webapp/backend/vendor' \
  --exclude='./webapp/backend/storage' \
  --exclude='./webapp/frontend/node_modules' \
  --exclude='./webapp/frontend/dist' \
  --exclude='./webapp/frontend/.vite' \
  --exclude='./webapp/frontend/.cache' \
  .
run_cmd install -d -m 0750 "${TARGET}/modules"
for manifest in \
  "webapp/backend/composer.json" \
  "webapp/backend/composer.lock" \
  "webapp/frontend/package.json" \
  "webapp/frontend/package-lock.json" \
  "webapp/frontend/pnpm-lock.yaml"; do
  if [ -f "${APP_ROOT}/${manifest}" ]; then
    run_cmd install -D -m 0640 "${APP_ROOT}/${manifest}" "${TARGET}/modules/${manifest}"
  fi
done
run_cmd cp "${ENV_FILE}" "${TARGET}/env.backup"
run_cmd sha256sum "${TARGET}/database.dump" "${TARGET}/storage.tar.gz" "${TARGET}/source.tar.gz" "${TARGET}/env.backup" > "${TARGET}/SHA256SUMS"
cat > "${TARGET}/manifest.json" <<EOF
{
  "created_at": "${STAMP}",
  "app_root": "${APP_ROOT}",
  "database": "${DB_DATABASE}",
  "host": "$(hostname -f 2>/dev/null || hostname)",
  "version": "$(cat "${APP_ROOT}/VERSION" 2>/dev/null || printf unknown)",
  "git_commit": "$(git -C "${APP_ROOT}" rev-parse HEAD 2>/dev/null || printf unknown)",
  "includes": ["database", "storage", "env", "source", "module_manifests"]
}
EOF

log "Backup created at ${TARGET}"
