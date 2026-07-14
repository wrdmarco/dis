#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ ! -f "${SCRIPT_DIR}/lib/common.sh" ] && [ -f "${DIS_INSTALL_PATH:-/opt/dis}/scripts/lib/common.sh" ]; then
  SCRIPT_DIR="${DIS_INSTALL_PATH:-/opt/dis}/scripts"
fi
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
ENV_FILE="${APP_ROOT}/.env"

require_root
load_data_path_from_env "${ENV_FILE}"
ensure_data_links "${APP_ROOT}"
require_file "${ENV_FILE}"

set -a
source "${ENV_FILE}"
set +a
load_backup_runtime_config "${APP_ROOT}/webapp/backend/storage/app/backup-config.json"

if [ "${BACKUP_TARGET:-local}" != "samba" ]; then
  exit 0
fi

resolve_backup_root "${APP_ROOT}" >/dev/null
log "Configured Samba backup storage is mounted."
