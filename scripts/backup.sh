#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
ENV_FILE="${APP_ROOT}/.env"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

load_data_path_from_env "${ENV_FILE}"
ensure_data_links "${APP_ROOT}"
require_file "${ENV_FILE}"
set -a
source "${ENV_FILE}"
if [ -f "${APP_ROOT}/webapp/backend/storage/app/backup-config.env" ]; then
  source "${APP_ROOT}/webapp/backend/storage/app/backup-config.env"
fi
set +a
ensure_data_links "${APP_ROOT}"

BACKUP_ROOT="$(resolve_backup_root "${APP_ROOT}")"
TARGET="${BACKUP_ROOT}/${STAMP}"
if [ "${EUID}" -eq 0 ]; then
  run_cmd install -d -m 0750 -o root -g "${DIS_GROUP}" "${TARGET}"
else
  run_cmd install -d -m 0750 "${TARGET}"
  run_cmd chgrp "${DIS_GROUP}" "${TARGET}" 2>/dev/null || true
fi

allow_app_backup_read() {
  local path="$1"

  if getent group "${DIS_GROUP}" >/dev/null 2>&1; then
    chgrp -R "${DIS_GROUP}" "${path}" 2>/dev/null || true
  fi
  chmod 0750 "${path}" 2>/dev/null || true
  find "${path}" -type d -exec chmod 0750 {} + 2>/dev/null || true
  find "${path}" -type f -exec chmod 0640 {} + 2>/dev/null || true
}

prune_old_backups() {
  local root="$1"
  local keep="${BACKUP_RETENTION_COUNT:-0}"

  if ! [[ "${keep}" =~ ^[0-9]+$ ]] || [ "${keep}" -lt 1 ]; then
    return 0
  fi

  log "Pruning old backups, keeping latest ${keep}"
  find "${root}" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended -regex '.*/[0-9]{8}T[0-9]{6}Z$' -printf '%f\n' \
    | sort -r \
    | awk -v keep="${keep}" 'NR > keep { print }' \
    | while IFS= read -r backup_id; do
        if [[ "${backup_id}" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; then
          run_cmd rm -rf -- "${root}/${backup_id}"
        fi
      done
}

log "Creating PostgreSQL backup"
PGPASSWORD="${DB_PASSWORD}" run_cmd pg_dump \
  --host="${DB_HOST}" \
  --port="${DB_PORT}" \
  --username="${DB_USERNAME}" \
  --format=custom \
  --file="${TARGET}/database.dump" \
  "${DB_DATABASE}"

log "Archiving storage and configuration"
run_cmd tar --warning=no-file-changed --ignore-failed-read -C "${DIS_DATA_PATH}" -czf "${TARGET}/storage.tar.gz" \
  storage \
  webapp/backend/storage \
  secrets
log "Archiving software source and module manifests"
run_cmd tar --warning=no-file-changed --ignore-failed-read -C "${APP_ROOT}" -czf "${TARGET}/source.tar.gz" \
  --exclude='./.git' \
  --exclude='./backup' \
  --exclude='./storage' \
  --exclude='./webapp/backend/vendor' \
  --exclude='./webapp/backend/storage' \
  --exclude='./webapp/frontend/node_modules' \
  --exclude='./webapp/frontend/.next' \
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
  "data_root": "${DIS_DATA_PATH}",
  "database": "${DB_DATABASE}",
  "host": "$(hostname -f 2>/dev/null || hostname)",
  "version": "$(cat "${APP_ROOT}/VERSION" 2>/dev/null || printf unknown)",
  "git_commit": "$(git -C "${APP_ROOT}" rev-parse HEAD 2>/dev/null || printf unknown)",
  "target": "${BACKUP_TARGET:-local}",
  "includes": ["database", "storage", "env", "source", "module_manifests"]
}
EOF
allow_app_backup_read "${TARGET}"

prune_old_backups "${BACKUP_ROOT}"
log "Backup created at ${TARGET}"
