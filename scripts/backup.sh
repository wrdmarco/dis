#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
ENV_FILE="${APP_ROOT}/.env"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
REQUESTED_BACKUP_TARGET="${BACKUP_TARGET:-}"
REQUESTED_SAFE_LOCAL_BACKUP="${DIS_SAFE_LOCAL_BACKUP:-0}"
REQUESTED_SAFE_LOCAL_PREUPDATE_BACKUP="${DIS_SAFE_LOCAL_PREUPDATE_BACKUP:-0}"

require_root
acquire_dis_operation_lock backup

load_data_path_from_env "${ENV_FILE}"
ensure_data_links "${APP_ROOT}"
require_file "${ENV_FILE}"
set -a
source "${ENV_FILE}"
set +a
DIS_SAFE_LOCAL_BACKUP="${REQUESTED_SAFE_LOCAL_BACKUP}"
DIS_SAFE_LOCAL_PREUPDATE_BACKUP="${REQUESTED_SAFE_LOCAL_PREUPDATE_BACKUP}"
export DIS_SAFE_LOCAL_BACKUP DIS_SAFE_LOCAL_PREUPDATE_BACKUP
load_backup_runtime_config_for_operation "${APP_ROOT}/webapp/backend/storage/app/backup-config.json"
if [ "${DIS_EFFECTIVE_SAFE_LOCAL_BACKUP}" != "1" ] && [ -n "${REQUESTED_BACKUP_TARGET}" ]; then
  [[ "${REQUESTED_BACKUP_TARGET}" =~ ^(local|samba)$ ]] || fail "Invalid requested backup target."
  BACKUP_TARGET="${REQUESTED_BACKUP_TARGET}"
  export BACKUP_TARGET
fi
ensure_data_links "${APP_ROOT}"

BACKUP_ROOT="$(resolve_backup_root "${APP_ROOT}")"
TARGET="${BACKUP_ROOT}/${STAMP}"
EFFECTIVE_BACKUP_TARGET="${BACKUP_TARGET:-local}"
if [ "${BACKUP_SAMBA_ENABLED:-0}" = "1" ]; then
  EFFECTIVE_BACKUP_TARGET=samba
fi
ensure_backup_encryption_key >/dev/null
BACKUP_KEY_FILE="$(backup_encryption_key_file)"
BACKUP_WORK="$(mktemp -d "${DIS_DATA_PATH}/backup-request-work/backup.XXXXXX")"
STAGING="${BACKUP_WORK}/payload"
PUBLISH_STAGING="${BACKUP_WORK}/published"
PENDING_TARGET=""

cleanup_backup() {
  if [ -n "${PENDING_TARGET}" ] && [ -d "${PENDING_TARGET}" ]; then
    secure_path_operation remove-tree "${PENDING_TARGET}" >/dev/null 2>&1 || true
  fi
  if [ -d "${BACKUP_WORK}" ]; then
    secure_path_operation remove-tree "${BACKUP_WORK}" >/dev/null 2>&1 || true
  fi
}

trap cleanup_backup EXIT
chmod 0700 "${BACKUP_WORK}"
run_cmd install -d -m 0700 -o root -g root "${STAGING}"
run_cmd install -d -m 0700 -o root -g root "${PUBLISH_STAGING}"

publish_backup() {
  local reader_group random_suffix

  [ ! -e "${TARGET}" ] && [ ! -L "${TARGET}" ] \
    || fail "Backup destination already exists: ${TARGET}"
  repair_managed_tree "${PUBLISH_STAGING}" root "${DIS_GROUP}" 0750 0640

  if [ "${EFFECTIVE_BACKUP_TARGET}" != "samba" ]; then
    require_root_controlled_parent "${TARGET}"
    run_cmd mv -T -- "${PUBLISH_STAGING}" "${TARGET}"
    if id www-data >/dev/null 2>&1; then
      secure_path_operation acl-tree "${TARGET}" www-data r-x r--
    fi
    return
  fi

  if id www-data >/dev/null 2>&1; then
    reader_group=www-data
  else
    reader_group="${DIS_GROUP}"
  fi
  random_suffix="$(openssl rand -hex 16)"
  PENDING_TARGET="${BACKUP_ROOT}/.${STAMP}.${random_suffix}.pending"
  [ ! -e "${PENDING_TARGET}" ] && [ ! -L "${PENDING_TARGET}" ] \
    || fail "Temporary Samba publication path already exists."
  secure_path_operation ensure-dir "${PENDING_TARGET}" root "${reader_group}" 0750
  secure_path_operation copy-tree "${PUBLISH_STAGING}" "${PENDING_TARGET}"
  repair_managed_tree "${PENDING_TARGET}" root "${reader_group}" 0750 0640
  verify_backup_snapshot_identity "${PENDING_TARGET}"
  durably_sync_backup_tree "${PENDING_TARGET}"
  run_cmd mv -nT -- "${PENDING_TARGET}" "${TARGET}"
  [ ! -e "${PENDING_TARGET}" ] && [ -d "${TARGET}" ] \
    || fail "Samba backup publication lost an atomic no-clobber race."
  durably_sync_backup_tree "${TARGET}"
  PENDING_TARGET=""
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
          secure_path_operation remove-tree "${root}/${backup_id}"
        fi
      done
}

log "Creating PostgreSQL backup"
PGPASSWORD="${DB_PASSWORD}" run_cmd pg_dump \
  --host="${DB_HOST}" \
  --port="${DB_PORT}" \
  --username="${DB_USERNAME}" \
  --format=custom \
  --file="${STAGING}/database.dump" \
  "${DB_DATABASE}"

log "Archiving storage and configuration"
run_cmd tar -C "${DIS_DATA_PATH}" -czf "${STAGING}/storage.tar.gz" \
  --exclude='storage/logs' \
  --exclude='storage/releases' \
  --exclude='storage/tmp' \
  --exclude='webapp/backend/storage/logs' \
  --exclude='webapp/backend/storage/composer' \
  --exclude='webapp/backend/storage/framework/cache' \
  --exclude='webapp/backend/storage/framework/sessions' \
  --exclude='webapp/backend/storage/framework/views' \
  storage \
  webapp/backend/storage \
  secrets
log "Validating storage archive safety policy"
STORAGE_VALIDATION_ROOT="${BACKUP_WORK}/storage-validation"
run_cmd install -d -m 0700 -o root -g root "${STORAGE_VALIDATION_ROOT}"
validate_storage_backup_archive "${STAGING}/storage.tar.gz" "${STORAGE_VALIDATION_ROOT}"
secure_path_operation remove-tree "${STORAGE_VALIDATION_ROOT}"
log "Archiving software source and module manifests"
run_cmd tar -C "${APP_ROOT}" -czf "${STAGING}/source.tar.gz" \
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
run_cmd install -d -m 0700 "${STAGING}/modules"
for manifest in \
  "webapp/backend/composer.json" \
  "webapp/backend/composer.lock" \
  "webapp/frontend/package.json" \
  "webapp/frontend/package-lock.json" \
  "webapp/frontend/pnpm-lock.yaml"; do
  if [ -f "${APP_ROOT}/${manifest}" ]; then
    run_cmd install -D -m 0600 "${APP_ROOT}/${manifest}" "${STAGING}/modules/${manifest}"
  fi
done
run_cmd install -m 0600 "${ENV_FILE}" "${STAGING}/env.backup"

log "Encrypting backup payload"
tar -C "${STAGING}" -cf - . \
  | openssl enc -aes-256-cbc -salt -pbkdf2 -iter 250000 -md sha256 \
      -pass "file:${BACKUP_KEY_FILE}" \
      -out "${PUBLISH_STAGING}/backup.payload.enc"

cat > "${PUBLISH_STAGING}/manifest.json" <<EOF
{
  "format": "dis-encrypted-backup-v1",
  "encrypted": true,
  "cipher": "aes-256-cbc-pbkdf2-sha256",
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
(cd "${PUBLISH_STAGING}" && sha256sum backup.payload.enc manifest.json > SHA256SUMS)
backup_authentication_tag "${PUBLISH_STAGING}/SHA256SUMS" > "${PUBLISH_STAGING}/BACKUP.HMAC"
run_cmd chmod 0600 "${PUBLISH_STAGING}/BACKUP.HMAC"

publish_backup

if [ "${EFFECTIVE_BACKUP_TARGET}" != "samba" ]; then
  durably_sync_backup_tree "${TARGET}"
fi

prune_old_backups "${BACKUP_ROOT}"
log "Backup created at ${TARGET}"
