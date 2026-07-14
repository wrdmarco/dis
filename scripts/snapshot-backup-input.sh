#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ ! -f "${SCRIPT_DIR}/lib/common.sh" ] && [ -f "${DIS_INSTALL_PATH:-/opt/dis}/scripts/lib/common.sh" ]; then
  SCRIPT_DIR="${DIS_INSTALL_PATH:-/opt/dis}/scripts"
fi
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
SOURCE_PATH="${1:-}"
DESTINATION_PATH="${2:-}"
PAYLOAD_LIMIT="${3:-0}"

require_root
[ -n "${SOURCE_PATH}" ] && [ -n "${DESTINATION_PATH}" ] \
  || fail "Usage: snapshot-backup-input.sh SOURCE DESTINATION [PAYLOAD_LIMIT]"
load_data_path_from_env "${APP_ROOT}/.env"
snapshot_authenticated_backup_input "${SOURCE_PATH}" "${DESTINATION_PATH}" "${PAYLOAD_LIMIT}"
verify_backup_snapshot_identity "${DESTINATION_PATH}"
