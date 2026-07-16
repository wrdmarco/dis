#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-osrm-recovery-test.XXXXXX")"
DIS_DATA_PATH="${TEST_ROOT}/data"
export APP_ROOT DIS_DATA_PATH

cleanup() {
  rm -rf -- "${TEST_ROOT}"
}
trap cleanup EXIT

# shellcheck source=scripts/osrm.sh
source "${APP_ROOT}/scripts/osrm.sh"

old_target='releases/20260101T000000Z-aaaaaaaaaaaa'
previous_target='releases/20251231T000000Z-bbbbbbbbbbbb'
candidate_target='releases/20260102T000000Z-cccccccccccc'
mkdir -p \
  "${OSRM_DATA_ROOT}/${old_target}" \
  "${OSRM_DATA_ROOT}/${previous_target}" \
  "${OSRM_DATA_ROOT}/${candidate_target}"

owner_alive=1
restore_count=0
restored_current=''
restored_previous=''

current_boot_id() { printf '11111111-2222-3333-4444-555555555555\n'; }
process_start_ticks() {
  [ "${owner_alive}" = '1' ] || return 1
  printf '123456\n'
}
run_cmd() {
  if [ "${1:-}" = 'chown' ] || [ "${1:-}" = 'sync' ]; then
    return 0
  fi
  "$@"
}
stat() {
  if [ "${1:-}" = '-c' ] \
    && [ "${2:-}" = '%u' ] \
    && [ "${4:-}" = "${OSRM_ACTIVATION_PENDING_FILE}" ]; then
    printf '0\n'
    return 0
  fi
  command stat "$@"
}
restore_dataset_pointers() {
  restored_current="$1"
  restored_previous="$2"
  restore_count=$((restore_count + 1))
}

write_pending_activation "${old_target}" "${previous_target}" "${candidate_target}"
[ -f "${OSRM_ACTIVATION_PENDING_FILE}" ]
read_pending_activation
[ "${OSRM_PENDING_CURRENT_TARGET}" = "${old_target}" ]
[ "${OSRM_PENDING_PREVIOUS_TARGET}" = "${previous_target}" ]
[ "${OSRM_PENDING_CANDIDATE_TARGET}" = "${candidate_target}" ]
pending_activation_owner_is_alive

# The service restart used for the intentional readiness probe must not undo
# an activation while its exact PID/start-time/boot owner is still alive.
recover_pending_activation serve
[ "${restore_count}" = '0' ]
[ -f "${OSRM_ACTIVATION_PENDING_FILE}" ]
[ -z "${OSRM_SERVE_RELEASE_OVERRIDE}" ]

# Simulate SIGKILL (same boot, owner process gone). Startup recovery restores
# both exact prior targets and removes the durable marker before normal serve.
owner_alive=0
recover_pending_activation strict
[ "${restore_count}" = '1' ]
[ "${restored_current}" = "${old_target}" ]
[ "${restored_previous}" = "${previous_target}" ]
[ ! -e "${OSRM_ACTIVATION_PENDING_FILE}" ]

# Failed readiness records only the restored active manifest SHA. When no
# release remains, the status SHA must be null/empty rather than the rejected
# candidate's SHA.
restored_sha='dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd'
captured_state=''
captured_sha=''
health_result=0
active_dataset_sha() { [ -n "${restored_sha}" ] && printf '%s\n' "${restored_sha}"; }
wait_for_health() { return "${health_result}"; }
write_status() {
  captured_state="$1"
  captured_sha="${3:-}"
}
record_failed_activation_status "${old_target}"
[ "${captured_state}" = 'ready' ]
[ "${captured_sha}" = "${restored_sha}" ]

restored_sha=''
health_result=1
record_failed_activation_status ''
[ "${captured_state}" = 'degraded' ]
[ -z "${captured_sha}" ]

# A stale activation marker means the unprivileged service falls back to the
# last committed target, even if the live pointer still names the candidate.
fallback_sha='ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'
dataset_sha_for_release() {
  [ "$1" = "${OSRM_DATA_ROOT}/${old_target}" ] || return 1
  printf '%s\n' "${fallback_sha}"
}
owner_alive=1
write_pending_activation "${old_target}" "${previous_target}" "${candidate_target}"
owner_alive=0
[ "$(effective_dataset_sha)" = "${fallback_sha}" ]
clear_pending_activation

# Simulate a crash after the activation marker was durably cleared but before
# status.json was refreshed. Public status must override its stale stored SHA
# with the SHA derived from the release that is now effectively active.
old_status_sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
new_active_sha='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'
printf '{"state":"ready","dataset_sha256":"%s"}\n' \
  "${old_status_sha}" > "${OSRM_STATUS_FILE}"
restored_sha="${new_active_sha}"
systemd_unit_exists() { return 1; }
jq() {
  local dataset_sha='' name value

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --arg)
        name="${2:-}"
        value="${3:-}"
        [ "${name}" != 'dataset_sha256' ] || dataset_sha="${value}"
        shift 3
        ;;
      *)
        shift
        ;;
    esac
  done
  printf '{"dataset_sha256":"%s"}\n' "${dataset_sha}"
}
status_output="$(status)"
[[ "${status_output}" == *"${new_active_sha}"* ]]
[[ "${status_output}" != *"${old_status_sha}"* ]]

printf 'OSRM durable crash recovery and status SHA test passed.\n'
