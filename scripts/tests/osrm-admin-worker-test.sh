#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
if ! command -v jq >/dev/null 2>&1; then
  printf 'SKIP: OSRM admin worker behavior test requires jq (installed on the Ubuntu target).\n'
  exit 0
fi

TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-osrm-admin-worker-test.XXXXXX")"
cleanup() {
  case "${TEST_ROOT}" in
    "${TMPDIR:-/tmp}"/dis-osrm-admin-worker-test.*) rm -rf -- "${TEST_ROOT}" ;;
    *) printf 'Refusing to clean unexpected test path: %s\n' "${TEST_ROOT}" >&2 ;;
  esac
}
trap cleanup EXIT

# shellcheck source=scripts/osrm-admin-request-worker.sh
source "${APP_ROOT}/scripts/osrm-admin-request-worker.sh"

DIS_DATA_PATH="${TEST_ROOT}/data"
ADMIN_ROOT="${DIS_DATA_PATH}/osrm-admin"
REQUEST_DIR="${ADMIN_ROOT}/requests"
WORK_DIR="${ADMIN_ROOT}/work"
RESULT_DIR="${ADMIN_ROOT}/results"
mkdir -p "${REQUEST_DIR}" "${WORK_DIR}" "${RESULT_DIR}"

MOCK_OWNER='www-data'
MOCK_MODE='600'
CALLBACK_FAIL=0
CALLBACK_LOG=''
RECOVERY_PAYLOAD_SHA='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'
RECOVERY_PAYLOAD_COORDINATE='5.1214,52.0907'

run_cmd() {
  case "${1:-}" in
    chown|setfacl) return 0 ;;
    chmod) command chmod "${@:2}" ;;
    *) command "$@" ;;
  esac
}
logger() { return 0; }
artisan_callback() {
  local command="${1:-}" longitude latitude

  CALLBACK_LOG="${CALLBACK_LOG}${CALLBACK_LOG:+$'\n'}$*"
  [ "${CALLBACK_FAIL}" = '0' ] || return 1
  if [ "${command}" = 'dis:osrm-operation:payload' ]; then
    longitude="${RECOVERY_PAYLOAD_COORDINATE%,*}"
    latitude="${RECOVERY_PAYLOAD_COORDINATE#*,}"
    jq -cn \
      --arg operation_id "${OPERATION_ID}" \
      --arg action "${ACTION}" \
      --arg actor_id "${ACTOR_ID}" \
      --arg source_url "${SOURCE_URL_DEFAULT}" \
      --arg source_sha256 "${RECOVERY_PAYLOAD_SHA}" \
      --argjson longitude "${longitude}" \
      --argjson latitude "${latitude}" \
      '{
        version:1,
        operation_id:$operation_id,
        action:$action,
        actor_id:$actor_id,
        source_url:$source_url,
        source_sha256:$source_sha256,
        health_coordinate:{longitude:$longitude,latitude:$latitude}
      }'
  fi
}
stat() {
  if [ "${1:-}" = '-c' ]; then
    case "${2:-}" in
      '%U') printf '%s\n' "${MOCK_OWNER}"; return 0 ;;
      '%a') printf '%s\n' "${MOCK_MODE}"; return 0 ;;
      '%u:%a:%h') printf '0:%s:1\n' "${MOCK_MODE}"; return 0 ;;
    esac
  fi
  command stat "$@"
}

make_valid_request() {
  local path="$1"
  jq -cn \
    --arg operation_id '01ARZ3NDEKTSV4RRFFQ69G5FAV' \
    --arg actor_id '01ARZ3NDEKTSV4RRFFQ69G5FAW' \
    --arg created_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{version:1,operation_id:$operation_id,action:"install_activate",actor_id:$actor_id,created_at:$created_at}' \
    > "${path}"
  chmod 0600 "${path}"
}

# Malformed and wrong-mode requests release the backend active key by their
# protected filename id instead of being silently deleted.
request_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
printf '{broken\n' > "${REQUEST_DIR}/${request_id}.pending"
chmod 0600 "${REQUEST_DIR}/${request_id}.pending"
reset_operation_context
if claim_request "${REQUEST_DIR}/${request_id}.pending"; then
  printf 'Malformed request was unexpectedly accepted.\n' >&2
  exit 1
fi
[[ "${CALLBACK_LOG}" == *"dis:osrm-operation:fail-request ${request_id} rejected"* ]]
[ ! -e "${WORK_DIR}/${request_id}.json" ]

request_id='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
make_valid_request "${REQUEST_DIR}/${request_id}.pending"
MOCK_MODE='640'
CALLBACK_LOG=''
reset_operation_context
if claim_request "${REQUEST_DIR}/${request_id}.pending"; then
  printf 'Mode-0640 request was unexpectedly accepted.\n' >&2
  exit 1
fi
[[ "${CALLBACK_LOG}" == *"dis:osrm-operation:fail-request ${request_id} rejected"* ]]

# A temporary callback outage retains the root-owned work marker. Recovery
# retries by request id and removes it only after the backend confirms failure.
request_id='cccccccccccccccccccccccccccccccc'
printf '{broken\n' > "${REQUEST_DIR}/${request_id}.pending"
chmod 0600 "${REQUEST_DIR}/${request_id}.pending"
MOCK_MODE='600'
CALLBACK_FAIL=1
CALLBACK_LOG=''
reset_operation_context
claim_request "${REQUEST_DIR}/${request_id}.pending" >/dev/null 2>&1 || true
[ -f "${WORK_DIR}/${request_id}.json" ]
CALLBACK_FAIL=0
reset_operation_context
recover_abandoned_operation "${WORK_DIR}/${request_id}.json"
[[ "${CALLBACK_LOG}" == *"dis:osrm-operation:fail-request ${request_id} abandoned"* ]]
[ ! -e "${WORK_DIR}/${request_id}.json" ]

# The exact valid contract is claimable and bound to the expected operation,
# action and actor before any privileged work starts.
request_id='dddddddddddddddddddddddddddddddd'
make_valid_request "${REQUEST_DIR}/${request_id}.pending"
CALLBACK_LOG=''
reset_operation_context
claim_request "${REQUEST_DIR}/${request_id}.pending"
[ "${OPERATION_ID}" = '01ARZ3NDEKTSV4RRFFQ69G5FAV' ]
[ "${ACTION}" = 'install_activate' ]
[ "${ACTOR_ID}" = '01ARZ3NDEKTSV4RRFFQ69G5FAW' ]
[ -f "${WORK_DIR}/${request_id}.json" ]

# A temporary terminal callback outage preserves the truthful succeeded root
# snapshot and work marker. The later recovery retries finish(0) and only then
# removes the marker.
STATUS_FILE="$(operation_status_path "${OPERATION_ID}")"
LOG_FILE="$(operation_log_path "${OPERATION_ID}")"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
ensure_result_file "${STATUS_FILE}"
ensure_result_file "${LOG_FILE}"
active_source_sha() { printf 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee\n'; }
publish_global_status() { :; }
sync_backend_event() { :; }
CALLBACK_FAIL=1
if finish_operation 0 'OSRM testoperation succeeded.'; then
  printf 'Failed backend callback unexpectedly completed the operation.\n' >&2
  exit 1
fi
jq -e '.state == "succeeded" and .stage == "completed" and .exit_code == 0' "${STATUS_FILE}" >/dev/null
[ -f "${WORK_DIR}/${request_id}.json" ]

fake_osrm="${TEST_ROOT}/osrm-test.sh"
printf '%s\n' \
  '#!/usr/bin/env bash' \
  'set -euo pipefail' \
  'case "${1:-}" in' \
  '  status)' \
  '    jq -cn --arg sha "${RECOVERY_STATUS_SHA}" --arg coordinate "${RECOVERY_STATUS_COORDINATE}" --argjson healthy "${RECOVERY_STATUS_HEALTHY}" '\''{installed:true,state:(if $healthy then "ready" else "degraded" end),healthy:$healthy,dataset:{sha256:$sha,health_coordinate:$coordinate}}'\'' ;;' \
  '  reconcile) exit 0 ;;' \
  '  verify|health) exit "${RECOVERY_RUNTIME_CHECK_EXIT:-0}" ;;' \
  '  *) exit 1 ;;' \
  'esac' > "${fake_osrm}"
chmod 0700 "${fake_osrm}"
OSRM_SCRIPT="${fake_osrm}"
export RECOVERY_STATUS_SHA="${RECOVERY_PAYLOAD_SHA}"
export RECOVERY_STATUS_COORDINATE="${RECOVERY_PAYLOAD_COORDINATE}"
export RECOVERY_STATUS_HEALTHY=true
export RECOVERY_RUNTIME_CHECK_EXIT=0
CALLBACK_FAIL=0
CALLBACK_LOG=''
reset_operation_context
recover_abandoned_operation "${WORK_DIR}/${request_id}.json"
[[ "${CALLBACK_LOG}" == *'dis:osrm-operation:finish 01ARZ3NDEKTSV4RRFFQ69G5FAV 0'* ]]
[ ! -e "${WORK_DIR}/${request_id}.json" ]

prepare_running_recovery() {
  local marker_id="$1"

  make_valid_request "${WORK_DIR}/${marker_id}.json"
  OPERATION_ID='01ARZ3NDEKTSV4RRFFQ69G5FAV'
  ACTION='install_activate'
  ACTOR_ID='01ARZ3NDEKTSV4RRFFQ69G5FAW'
  RUNNING_FILE="${WORK_DIR}/${marker_id}.json"
  STATUS_FILE="$(operation_status_path "${OPERATION_ID}")"
  LOG_FILE="$(operation_log_path "${OPERATION_ID}")"
  STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  ensure_result_file "${STATUS_FILE}"
  ensure_result_file "${LOG_FILE}"
  write_operation_status running verifying 'Onderbroken testbewerking.' null null
  reset_operation_context
}

# A loaded immutable payload with a definitive SHA mismatch is failed closed.
request_id='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'
prepare_running_recovery "${request_id}"
export RECOVERY_STATUS_SHA='ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'
export RECOVERY_STATUS_HEALTHY=true
CALLBACK_LOG=''
recover_abandoned_operation "${WORK_DIR}/${request_id}.json"
[[ "${CALLBACK_LOG}" == *'dis:osrm-operation:finish 01ARZ3NDEKTSV4RRFFQ69G5FAV 124'* ]]
[ ! -e "${WORK_DIR}/${request_id}.json" ]
jq -e '.state == "failed" and .exit_code == 124' "$(operation_status_path '01ARZ3NDEKTSV4RRFFQ69G5FAV')" >/dev/null

# An exact SHA/probe that is not healthy is equally definitive and fails.
request_id='ffffffffffffffffffffffffffffffff'
prepare_running_recovery "${request_id}"
export RECOVERY_STATUS_SHA="${RECOVERY_PAYLOAD_SHA}"
export RECOVERY_STATUS_HEALTHY=false
CALLBACK_LOG=''
recover_abandoned_operation "${WORK_DIR}/${request_id}.json"
[[ "${CALLBACK_LOG}" == *'dis:osrm-operation:finish 01ARZ3NDEKTSV4RRFFQ69G5FAV 124'* ]]
[ ! -e "${WORK_DIR}/${request_id}.json" ]

# A temporary payload/database outage leaves both snapshot and marker exactly
# retryable; no finish callback is issued.
request_id='11111111111111111111111111111111'
prepare_running_recovery "${request_id}"
status_before="$(sha256sum "$(operation_status_path '01ARZ3NDEKTSV4RRFFQ69G5FAV')" | awk '{print $1}')"
CALLBACK_FAIL=1
CALLBACK_LOG=''
recover_abandoned_operation "${WORK_DIR}/${request_id}.json"
[ -f "${WORK_DIR}/${request_id}.json" ]
[ "${status_before}" = "$(sha256sum "$(operation_status_path '01ARZ3NDEKTSV4RRFFQ69G5FAV')" | awk '{print $1}')" ]
[[ "${CALLBACK_LOG}" == *'dis:osrm-operation:payload 01ARZ3NDEKTSV4RRFFQ69G5FAV'* ]]
[[ "${CALLBACK_LOG}" != *'dis:osrm-operation:finish'* ]]
[ "${RECOVERY_RETRY_PENDING}" = '1' ]
CALLBACK_FAIL=0
rm -f -- "${WORK_DIR}/${request_id}.json"

# Public logs redact URLs and filesystem paths, and the root source policy
# rejects even a syntactically valid but unapproved HTTPS URL.
redacted="$(safe_line 'download https://example.test/file from /opt/dis/private')"
[[ "${redacted}" != *'example.test'* ]]
[[ "${redacted}" != *'/opt/dis'* ]]
printf 'OSRM_ADMIN_PBF_URL=%s\n' "${SOURCE_URL_DEFAULT}" > "${DIS_DATA_PATH}/.env"
[ "$(configured_source_url)" = "${SOURCE_URL_DEFAULT}" ]
printf 'OSRM_ADMIN_PBF_URL=https://example.test/netherlands.osm.pbf\n' > "${DIS_DATA_PATH}/.env"
if (configured_source_url >/dev/null 2>&1); then
  printf 'Unapproved OSRM source URL was unexpectedly accepted.\n' >&2
  exit 1
fi

printf 'OSRM admin worker request, security and recovery test passed.\n'
