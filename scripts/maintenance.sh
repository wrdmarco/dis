#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
BACKEND_DIR="${APP_ROOT}/webapp/backend"
ACTION="${1:-}"

require_root
acquire_dis_operation_lock maintenance
load_data_path_from_env "${APP_ROOT}/.env"
ensure_data_links "${APP_ROOT}"

case "${ACTION}" in
  enable)
    require_file "${BACKEND_DIR}/artisan"
    announce_wallboard_maintenance maintenance
    enable_deployment_maintenance "${BACKEND_DIR}"
    ;;
  disable)
    require_file "${BACKEND_DIR}/artisan"
    complete_deployment_maintenance "${BACKEND_DIR}"
    ;;
  *)
    fail "Usage: maintenance.sh enable|disable"
    ;;
esac
