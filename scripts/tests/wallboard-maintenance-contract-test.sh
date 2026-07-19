#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMMON="${REPOSITORY_ROOT}/scripts/lib/common.sh"
UPDATE="${REPOSITORY_ROOT}/scripts/update.sh"
DEPLOY="${REPOSITORY_ROOT}/scripts/deploy.sh"

require_text() {
  local file="$1" text="$2"
  grep -Fq -- "${text}" "${file}" || {
    printf 'Missing contract text in %s: %s\n' "${file}" "${text}" >&2
    exit 1
  }
}

line_of() {
  local file="$1" text="$2"
  grep -Fnm1 -- "${text}" "${file}" | cut -d: -f1
}

line_of_after() {
  local file="$1" text="$2" after="$3"
  awk -v needle="${text}" -v after="${after}" 'NR > after && index($0, needle) { print NR; exit }' "${file}"
}

require_text "${COMMON}" 'WALLBOARD_MAINTENANCE_NOTICE_SECONDS=6'
require_text "${COMMON}" 'WALLBOARD_MAINTENANCE_NOTICE_TTL_SECONDS=21600'
require_text "${COMMON}" 'run_cmd mv -fT -- "${temporary}" "${WALLBOARD_MAINTENANCE_NOTICE_PATH}"'
require_text "${COMMON}" '[ "${metadata}" = "0:0:644:1" ]'
require_text "${COMMON}" 'clear_wallboard_maintenance_notice'
require_text "${UPDATE}" 'announce_wallboard_maintenance update'
require_text "${DEPLOY}" 'if [ "${DIS_DEPLOYMENT_OWNER}" = "deploy" ]; then'
require_text "${DEPLOY}" 'announce_wallboard_maintenance maintenance'

update_announce="$(line_of "${UPDATE}" 'announce_wallboard_maintenance update')"
update_lock="$(line_of_after "${UPDATE}" 'enable_frontend_maintenance' "${update_announce}")"
update_stop="$(line_of_after "${UPDATE}" 'stop_dis_deployment_services' "${update_lock}")"
[[ "${update_announce}" -lt "${update_lock}" && "${update_lock}" -lt "${update_stop}" ]] || {
  printf 'Update maintenance order is unsafe.\n' >&2
  exit 1
}

complete_start="$(line_of "${COMMON}" 'complete_deployment_maintenance()')"
clear_line="$(line_of "${COMMON}" '  clear_wallboard_maintenance_notice')"
unlock_line="$(line_of "${COMMON}" '  disable_frontend_maintenance')"
[[ "${complete_start}" -lt "${clear_line}" && "${clear_line}" -lt "${unlock_line}" ]] || {
  printf 'Successful recovery does not clear the wallboard notice immediately before unlock.\n' >&2
  exit 1
}

printf 'Wallboard maintenance deployment contract passed.\n'
