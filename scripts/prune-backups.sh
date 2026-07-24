#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"
source "${SCRIPT_DIR}/lib/backup-retention.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
ENV_FILE="${APP_ROOT}/.env"
REQUESTED_BACKUP_TARGET="${BACKUP_TARGET:-}"
REQUESTED_SAFE_LOCAL_BACKUP="${DIS_SAFE_LOCAL_BACKUP:-0}"
REQUESTED_RUNTIME_CONFIG_SHA256="${EXPECTED_BACKUP_RUNTIME_CONFIG_SHA256:-}"

require_root
acquire_dis_operation_lock backup

[[ "${REQUESTED_BACKUP_TARGET}" =~ ^(local|samba)$ ]] \
  || fail "An explicit local or samba backup target is required for retention."

load_data_path_from_env "${ENV_FILE}"
ensure_data_links "${APP_ROOT}"
require_file "${ENV_FILE}"
set -a
source "${ENV_FILE}"
set +a
DIS_SAFE_LOCAL_BACKUP="${REQUESTED_SAFE_LOCAL_BACKUP}"
DIS_SAFE_LOCAL_PREUPDATE_BACKUP=0
export DIS_SAFE_LOCAL_BACKUP DIS_SAFE_LOCAL_PREUPDATE_BACKUP
load_backup_runtime_config_for_operation "${APP_ROOT}/webapp/backend/storage/app/backup-config.json"
require_backup_runtime_config_binding \
  "${REQUESTED_RUNTIME_CONFIG_SHA256}" \
  "${REQUESTED_BACKUP_TARGET}"
if [ "${BACKUP_RETENTION_COUNT:-0}" = "0" ]; then
  log "Backup retention is unlimited; no backups were removed."
  exit 0
fi
ensure_data_links "${APP_ROOT}"

BACKUP_ROOT="$(resolve_backup_root "${APP_ROOT}")"
prune_old_backups "${BACKUP_ROOT}"
log "Backup retention applied to ${REQUESTED_BACKUP_TARGET} target."
