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
MOCK_UID='33'
MOCK_MODE='600'
REQUEST_PUBLISHER_UID='33'
CALLBACK_FAIL=0
CALLBACK_LOG=''
CALLBACK_LOG_FILE="${TEST_ROOT}/callbacks.log"
: > "${CALLBACK_LOG_FILE}"
RECOVERY_PAYLOAD_COORDINATE='5.1214,52.0907'
RECOVERY_PAYLOAD_MANIFEST="$(jq -cn '
  {
    source_set_sha256:"ec4174cfe1cba6c41db2475fdbe9f61c4bb22f4255653c0fb212341eeee7c072",
    snapshot_date:"2026-07-15",
    source_timestamp:"2026-07-15T02:43:01Z",
    sources:[
      {id:"netherlands",filename:"netherlands-260715.osm.pbf",version_url:"https://download.geofabrik.de/europe/netherlands-260715.osm.pbf",md5:"eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee",size_bytes:1000000000},
      {id:"belgium",filename:"belgium-260715.osm.pbf",version_url:"https://download.geofabrik.de/europe/belgium-260715.osm.pbf",md5:"dddddddddddddddddddddddddddddddd",size_bytes:500000000}
    ]
  }
')"

run_cmd() {
  local destination mode=0640 argument

  case "${1:-}" in
    chown|setfacl) return 0 ;;
    chmod) command chmod "${@:2}" ;;
    install)
      destination="${!#}"
      for argument in "$@"; do
        case "${argument}" in
          0[0-7][0-7][0-7]) mode="${argument}" ;;
        esac
      done
      command touch "${destination}"
      command chmod "${mode}" "${destination}"
      ;;
    *) command "$@" ;;
  esac
}
logger() { return 0; }
# Git for Windows sed parses the production apostrophe-containing redaction
# expression differently from GNU sed on the Ubuntu target. Keep this behavior
# test focused on request/recovery contracts; static tests still pin the real
# production redaction function.
safe_line() { printf '[redacted]'; }
artisan_callback() {
  local command="${1:-}" longitude latitude

  CALLBACK_LOG="${CALLBACK_LOG}${CALLBACK_LOG:+$'\n'}$*"
  printf '%s\n' "$*" >> "${CALLBACK_LOG_FILE}"
  [ "${CALLBACK_FAIL}" = '0' ] || return 1
  if [ "${command}" = 'dis:osrm-operation:payload' ]; then
    longitude="${RECOVERY_PAYLOAD_COORDINATE%,*}"
    latitude="${RECOVERY_PAYLOAD_COORDINATE#*,}"
    jq -cn \
      --arg operation_id "${OPERATION_ID}" \
      --arg action "${ACTION}" \
      --arg actor_id "${ACTOR_ID}" \
      --arg netherlands_url "${NETHERLANDS_LATEST_URL}" \
      --arg belgium_url "${BELGIUM_LATEST_URL}" \
      --argjson longitude "${longitude}" \
      --argjson latitude "${latitude}" \
      '{
        version:2,
        operation_id:$operation_id,
        action:$action,
        actor_id:$actor_id,
        sources:[
          {id:"netherlands",latest_url:$netherlands_url},
          {id:"belgium",latest_url:$belgium_url}
        ],
        health_coordinate:{longitude:$longitude,latitude:$latitude}
      }'
  fi
}
stat() {
  if [ "${1:-}" = '-c' ]; then
    case "${2:-}" in
      '%U') printf '%s\n' "${MOCK_OWNER}"; return 0 ;;
      '%u') printf '%s\n' "${MOCK_UID}"; return 0 ;;
      '%a') printf '%s\n' "${MOCK_MODE}"; return 0 ;;
      '%u:%a:%h') printf '0:%s:1\n' "${MOCK_MODE}"; return 0 ;;
      '%u:%g:%a:%h') printf '0:0:%s:1\n' "${MOCK_MODE}"; return 0 ;;
    esac
  fi
  command stat "$@"
}

source_manifest_json_is_valid "${RECOVERY_PAYLOAD_MANIFEST}"
if source_manifest_json_is_valid "$(jq -c '.source_set_sha256 = ("f" * 64)' <<< "${RECOVERY_PAYLOAD_MANIFEST}")"; then
  printf 'A composite manifest with the wrong fixed source-set hash was accepted.\n' >&2
  exit 1
fi
if source_manifest_json_is_valid "$(jq -c '.source_timestamp = "2026-07-14T23:59:59Z"' <<< "${RECOVERY_PAYLOAD_MANIFEST}")"; then
  printf 'A composite manifest whose timestamp date differs from its snapshot was accepted.\n' >&2
  exit 1
fi
if source_manifest_json_is_valid "$(jq -c '.sources[1].filename = "belgium-260714.osm.pbf" | .sources[1].version_url = "https://download.geofabrik.de/europe/belgium-260714.osm.pbf"' <<< "${RECOVERY_PAYLOAD_MANIFEST}")"; then
  printf 'A composite manifest whose Belgian filename differs from its snapshot was accepted.\n' >&2
  exit 1
fi

make_valid_request() {
  local path="$1" version="${2:-2}"
  jq -cn \
    --argjson version "${version}" \
    --arg operation_id '01ARZ3NDEKTSV4RRFFQ69G5FAV' \
    --arg actor_id '01ARZ3NDEKTSV4RRFFQ69G5FAW' \
    --arg created_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{version:$version,operation_id:$operation_id,action:"install_activate",actor_id:$actor_id,created_at:$created_at}' \
    > "${path}"
  chmod 0600 "${path}"
}

# Malformed and wrong-mode requests release the backend active key by their
# protected filename id instead of being silently deleted.
request_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
printf '{broken\n' > "${REQUEST_DIR}/${request_id}.pending"
chmod 0600 "${REQUEST_DIR}/${request_id}.pending"
reset_operation_context
if claim_request "${REQUEST_DIR}/${request_id}.pending" >/dev/null 2>&1; then
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

request_id='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'
make_valid_request "${REQUEST_DIR}/${request_id}.pending"
MOCK_MODE='600'
MOCK_UID='34'
CALLBACK_LOG=''
reset_operation_context
if claim_request "${REQUEST_DIR}/${request_id}.pending"; then
  printf 'Request from the wrong publisher uid was unexpectedly accepted.\n' >&2
  exit 1
fi
[[ "${CALLBACK_LOG}" == *"dis:osrm-operation:fail-request ${request_id} rejected"* ]]
MOCK_UID='33'

# Production PostgreSQL identifiers are stored as lowercase ULIDs. They are
# case-insensitive by specification and must remain bound to their exact value.
request_id='ffffffffffffffffffffffffffffffff'
make_valid_request "${REQUEST_DIR}/${request_id}.pending"
lowercase_request="${TEST_ROOT}/lowercase-request.json"
jq '.operation_id |= ascii_downcase | .actor_id |= ascii_downcase' \
  "${REQUEST_DIR}/${request_id}.pending" > "${lowercase_request}"
mv -f -- "${lowercase_request}" "${REQUEST_DIR}/${request_id}.pending"
chmod 0600 "${REQUEST_DIR}/${request_id}.pending"
CALLBACK_LOG=''
reset_operation_context
claim_request "${REQUEST_DIR}/${request_id}.pending"
[ "${OPERATION_ID}" = '01arz3ndektsv4rrffq69g5fav' ]
[ "${ACTOR_ID}" = '01arz3ndektsv4rrffq69g5faw' ]
rm -f -- "${RUNNING_FILE}"
reset_operation_context

# A valid-shaped legacy v1 request is rejected rather than entering the v2
# supplier-MD5 workflow with a mismatched backend contract.
request_id='22222222222222222222222222222222'
make_valid_request "${REQUEST_DIR}/${request_id}.pending" 1
MOCK_MODE='600'
CALLBACK_LOG=''
reset_operation_context
if claim_request "${REQUEST_DIR}/${request_id}.pending"; then
  printf 'Legacy v1 request was unexpectedly accepted.\n' >&2
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
record_resolved_source_manifest "${RECOVERY_PAYLOAD_MANIFEST}"
active_source_manifest() { printf '%s\n' "${RECOVERY_PAYLOAD_MANIFEST}"; }
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
  '    jq -cn --argjson manifest "${RECOVERY_STATUS_MANIFEST}" --arg coordinate "${RECOVERY_STATUS_COORDINATE}" --argjson healthy "${RECOVERY_STATUS_HEALTHY}" '\''{version:2,installed:true,state:(if $healthy then "ready" else "degraded" end),healthy:$healthy,dataset:{source_manifest:$manifest,legacy_sha256:null,health_coordinate:$coordinate}}'\'' ;;' \
  '  reconcile) exit 0 ;;' \
  '  verify|health) exit "${RECOVERY_RUNTIME_CHECK_EXIT:-0}" ;;' \
  '  *) exit 1 ;;' \
  'esac' > "${fake_osrm}"
chmod 0700 "${fake_osrm}"
OSRM_SCRIPT="${fake_osrm}"
export RECOVERY_STATUS_MANIFEST="${RECOVERY_PAYLOAD_MANIFEST}"
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
  record_resolved_source_manifest "${RECOVERY_PAYLOAD_MANIFEST}"
  write_operation_status running verifying 'Onderbroken testbewerking.' null null
  reset_operation_context
}

# A loaded immutable payload with a definitive composite-manifest mismatch is
# failed closed.
request_id='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'
prepare_running_recovery "${request_id}"
export RECOVERY_STATUS_MANIFEST="$(jq -c '.sources[1].md5 = "ffffffffffffffffffffffffffffffff"' <<< "${RECOVERY_PAYLOAD_MANIFEST}")"
export RECOVERY_STATUS_HEALTHY=true
CALLBACK_LOG=''
recover_abandoned_operation "${WORK_DIR}/${request_id}.json"
[[ "${CALLBACK_LOG}" == *'dis:osrm-operation:finish 01ARZ3NDEKTSV4RRFFQ69G5FAV 124'* ]]
[ ! -e "${WORK_DIR}/${request_id}.json" ]
jq -e '.state == "failed" and .exit_code == 124' "$(operation_status_path '01ARZ3NDEKTSV4RRFFQ69G5FAV')" >/dev/null

# An exact source manifest/probe that is not healthy is equally definitive.
request_id='ffffffffffffffffffffffffffffffff'
prepare_running_recovery "${request_id}"
export RECOVERY_STATUS_MANIFEST="${RECOVERY_PAYLOAD_MANIFEST}"
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
: > "${CALLBACK_LOG_FILE}"
recover_abandoned_operation "${WORK_DIR}/${request_id}.json"
[ -f "${WORK_DIR}/${request_id}.json" ]
[ "${status_before}" = "$(sha256sum "$(operation_status_path '01ARZ3NDEKTSV4RRFFQ69G5FAV')" | awk '{print $1}')" ]
grep -F 'dis:osrm-operation:payload 01ARZ3NDEKTSV4RRFFQ69G5FAV' "${CALLBACK_LOG_FILE}" >/dev/null
if grep -F 'dis:osrm-operation:finish' "${CALLBACK_LOG_FILE}" >/dev/null; then
  printf 'A temporary payload outage unexpectedly issued a finish callback.\n' >&2
  exit 1
fi
[ "${RECOVERY_RETRY_PENDING}" = '1' ]
CALLBACK_FAIL=0
rm -f -- "${WORK_DIR}/${request_id}.json"

# The official sidecar parser accepts exactly one md5sum-formatted line for
# each resolved immutable country filename, lowercases the digest and rejects
# filenames that do not match the pinned version.
supplier_md5_file="${TEST_ROOT}/supplier.md5"
supplier_filename='netherlands-260715.osm.pbf'
MOCK_MODE='400'
printf 'ABCDEFABCDEFABCDEFABCDEFABCDEFAB  %s\n' "${supplier_filename}" > "${supplier_md5_file}"
chmod 0400 "${supplier_md5_file}"
[ "$(parse_supplier_md5_file "${supplier_md5_file}" "${supplier_filename}")" = 'abcdefabcdefabcdefabcdefabcdefab' ]
chmod 0600 "${supplier_md5_file}"
printf 'abcdefabcdefabcdefabcdefabcdefab  other.osm.pbf\n' > "${supplier_md5_file}"
chmod 0400 "${supplier_md5_file}"
if parse_supplier_md5_file "${supplier_md5_file}" "${supplier_filename}" >/dev/null 2>&1; then
  printf 'Supplier MD5 with the wrong filename was unexpectedly accepted.\n' >&2
  exit 1
fi
chmod 0600 "${supplier_md5_file}"
printf 'abcdefabcdefabcdefabcdefabcdefab  %s\nextra\n' "${supplier_filename}" > "${supplier_md5_file}"
chmod 0400 "${supplier_md5_file}"
if parse_supplier_md5_file "${supplier_md5_file}" "${supplier_filename}" >/dev/null 2>&1; then
  printf 'Supplier MD5 with extra content was unexpectedly accepted.\n' >&2
  exit 1
fi
MOCK_MODE='600'

# Public logs redact URLs and filesystem paths. The immutable payload accepts
# only the exact ordered NL+BE source set and no browser-controlled URL.
redacted="$(safe_line 'download https://example.test/file from /opt/dis/private')"
[[ "${redacted}" != *'example.test'* ]]
[[ "${redacted}" != *'/opt/dis'* ]]
valid_payload="$(artisan_callback dis:osrm-operation:payload "${OPERATION_ID}")"
operation_payload_contract_is_valid "${valid_payload}"
if operation_payload_contract_is_valid "$(jq -c '.sources[1].latest_url = "https://example.test/belgium.osm.pbf"' <<< "${valid_payload}")"; then
  printf 'A browser-controlled OSRM source URL was unexpectedly accepted.\n' >&2
  exit 1
fi

printf 'OSRM admin worker request, security and recovery test passed.\n'
