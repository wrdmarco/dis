#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
RELEASE_PATH="${1:-}"

if [ -z "${RELEASE_PATH}" ]; then
  fail "Usage: rollback.sh /opt/dis/releases/<release>"
fi

require_directory "${RELEASE_PATH}"
require_file "${RELEASE_PATH}/VERSION"

log "Rolling back DIS to ${RELEASE_PATH}"
run_cmd bash "${SCRIPT_DIR}/maintenance.sh" enable
run_cmd rsync -a --delete \
  --exclude ".env" \
  --exclude "storage/" \
  "${RELEASE_PATH}/" "${APP_ROOT}/"

run_cmd bash "${SCRIPT_DIR}/deploy.sh"
run_cmd bash "${SCRIPT_DIR}/maintenance.sh" disable
log "Rollback completed"
