#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
BACKEND_DIR="${APP_ROOT}/webapp/backend"
ACTION="${1:-}"

case "${ACTION}" in
  enable)
    require_file "${BACKEND_DIR}/artisan"
    run_cmd php "${BACKEND_DIR}/artisan" down --render="errors::503"
    ;;
  disable)
    require_file "${BACKEND_DIR}/artisan"
    run_cmd php "${BACKEND_DIR}/artisan" up
    ;;
  *)
    fail "Usage: maintenance.sh enable|disable"
    ;;
esac

