#!/usr/bin/env bash
set -euo pipefail

WORKER_SOURCE_PATH="${BASH_SOURCE[0]}"
case "${WORKER_SOURCE_PATH}" in
  */*) SCRIPT_DIR="${WORKER_SOURCE_PATH%/*}" ;;
  *) SCRIPT_DIR=. ;;
esac
SCRIPT_DIR="$(cd -- "${SCRIPT_DIR}" && pwd -P)"
OSRM_ADMIN_RUNTIME_DIR_FIXED="/usr/local/lib/dis/osrm-admin"

bootstrap_fail() {
  printf '[dis:error] OSRM admin worker bootstrap rejected an unsafe runtime chain: %s\n' "$*" >&2
  exit 1
}

bootstrap_require_parent_directory() {
  local path="$1" metadata mode

  [ -d "${path}" ] && [ ! -L "${path}" ] \
    || bootstrap_fail "${path}"
  metadata="$(/usr/bin/stat -c '%u:%g:%a' -- "${path}" 2>/dev/null || true)"
  [[ "${metadata}" =~ ^0:0:([0-7]+)$ ]] \
    || bootstrap_fail "${path}"
  mode="${BASH_REMATCH[1]}"
  (( (8#${mode} & 8#022) == 0 )) \
    || bootstrap_fail "${path}"
}

bootstrap_require_bundle_directory() {
  local path="$1"

  [ -d "${path}" ] && [ ! -L "${path}" ] \
    && [ "$(/usr/bin/stat -c '%u:%g:%a' -- "${path}" 2>/dev/null || true)" = '0:0:755' ] \
    || bootstrap_fail "${path}"
}

bootstrap_require_file() {
  local path="$1" expected_mode="$2"

  [ -f "${path}" ] && [ ! -L "${path}" ] \
    && [ "$(/usr/bin/stat -c '%u:%g:%a:%h' -- "${path}" 2>/dev/null || true)" = "0:0:${expected_mode}:1" ] \
    || bootstrap_fail "${path}"
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  [ "${EUID}" -eq 0 ] || bootstrap_fail "worker is not running as root"
  for bootstrap_parent in / /usr /usr/local /usr/local/lib /usr/local/bin; do
    bootstrap_require_parent_directory "${bootstrap_parent}"
  done
  bootstrap_require_bundle_directory /usr/local/lib/dis
  bootstrap_require_bundle_directory "${OSRM_ADMIN_RUNTIME_DIR_FIXED}"
  bootstrap_require_file "$0" 755
  for bootstrap_file in common.sh osrm.sh secure-path.py dis-osrm.service; do
    bootstrap_require_file "${OSRM_ADMIN_RUNTIME_DIR_FIXED}/${bootstrap_file}" 644
  done
  RUNTIME_COMMON="${OSRM_ADMIN_RUNTIME_DIR_FIXED}/common.sh"
  OSRM_SCRIPT="${OSRM_ADMIN_RUNTIME_DIR_FIXED}/osrm.sh"
else
  # Unit tests source the repository copy without ever running it as the
  # privileged persistent broker. Production execution always takes the
  # immutable branch above before a single external file is sourced.
  RUNTIME_COMMON="${SCRIPT_DIR}/lib/common.sh"
  OSRM_SCRIPT="${SCRIPT_DIR}/osrm.sh"
fi
# shellcheck source=scripts/lib/common.sh
source "${RUNTIME_COMMON}"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
BACKEND_DIR="${APP_ROOT}/webapp/backend"
ADMIN_ROOT=""
REQUEST_DIR=""
WORK_DIR=""
RESULT_DIR=""
LOCK_DIR="/run/dis-osrm-admin-request"
WORKER_LOCK="${LOCK_DIR}/worker.lock"
SOURCE_URL_DEFAULT="https://download.geofabrik.de/europe/netherlands-latest.osm.pbf"
SOURCE_HOST="download.geofabrik.de"
SOURCE_PATH="/europe/netherlands-latest.osm.pbf"
MAX_PBF_BYTES="${OSRM_ADMIN_MAX_PBF_BYTES:-3221225472}"
MIN_PBF_BYTES="${OSRM_ADMIN_MIN_PBF_BYTES:-104857600}"
DOWNLOAD_TIMEOUT_SECONDS="${OSRM_ADMIN_DOWNLOAD_TIMEOUT_SECONDS:-14400}"
DOWNLOAD_CONNECT_TIMEOUT_SECONDS="${OSRM_ADMIN_CONNECT_TIMEOUT_SECONDS:-15}"
LOG_MAX_BYTES="${OSRM_ADMIN_LOG_MAX_BYTES:-8388608}"
LOG_RETAIN_LINES="${OSRM_ADMIN_LOG_RETAIN_LINES:-2000}"
OPERATION_ID=""
ACTION=""
ACTOR_ID=""
RUNNING_FILE=""
STATUS_FILE=""
LOG_FILE=""
STARTED_AT=""
LOG_SEQUENCE=0
LAST_STAGE="validating"
OPERATION_FINISHED=0
DOWNLOAD_DIRECTORY=""
DOWNLOADED_PBF_FILE=""
PREVIOUS_ROUTING_HEALTHY=0
RECOVERY_RETRY_PENDING=0

reset_operation_context() {
  OPERATION_ID=""
  ACTION=""
  ACTOR_ID=""
  RUNNING_FILE=""
  STATUS_FILE=""
  LOG_FILE=""
  STARTED_AT=""
  LOG_SEQUENCE=0
  LAST_STAGE="validating"
  OPERATION_FINISHED=0
  DOWNLOAD_DIRECTORY=""
  DOWNLOADED_PBF_FILE=""
  PREVIOUS_ROUTING_HEALTHY=0
}

initialize_worker() {
  local config_file="${DIS_DATA_PATH}/.env" config_gid config_metadata artisan_mode

  require_root
  require_directory "${APP_ROOT}"
  [[ "${APP_ROOT}" =~ ^/[A-Za-z0-9._/-]+$ ]] \
    && [[ "/${APP_ROOT}/" != *"/../"* ]] \
    && [[ "/${APP_ROOT}/" != *"/./"* ]] \
    && [[ "${APP_ROOT}" != *"//"* ]] \
    || fail "APP_ROOT is not a safe absolute application path."
  require_file "${config_file}"
  require_file "${BACKEND_DIR}/artisan"
  require_root_controlled_parent "${config_file}"
  config_gid="$(getent group "${DIS_GROUP}" | cut -d: -f3)"
  [[ "${config_gid}" =~ ^[0-9]+$ ]] || fail "The DIS configuration group is unavailable."
  config_metadata="$(stat -c '%u:%g:%a:%h' -- "${config_file}" 2>/dev/null || true)"
  [ -f "${config_file}" ] && [ ! -L "${config_file}" ] \
    && [ "${config_metadata}" = "0:${config_gid}:640:1" ] \
    || fail "The DIS data configuration is not an immutable root-owned mode-0640 file."
  [ -f "${BACKEND_DIR}/artisan" ] && [ ! -L "${BACKEND_DIR}/artisan" ] \
    && [ "$(stat -c '%h' -- "${BACKEND_DIR}/artisan")" = '1' ] \
    || fail "The non-root Artisan entrypoint is unsafe."
  artisan_mode="$(stat -c '%a' -- "${BACKEND_DIR}/artisan")"
  (( (8#${artisan_mode} & 8#022) == 0 )) \
    || fail "The non-root Artisan entrypoint may not be group- or world-writable."
  ADMIN_ROOT="${DIS_DATA_PATH}/osrm-admin"
  REQUEST_DIR="${ADMIN_ROOT}/requests"
  WORK_DIR="${ADMIN_ROOT}/work"
  RESULT_DIR="${ADMIN_ROOT}/results"
}

safe_line() {
  local line="$1"

  line="$(printf '%s' "${line}" \
    | tr '\000-\010\013\014\016-\037\177' ' ' \
    | sed -E \
      -e 's#https?://[^[:space:]"'"']+#[url]#g' \
      -e 's#(^|[[:space:]])/(opt|var|etc|usr|root|home|tmp|run)(/[^[:space:]"'"']*)?#\1[path]#g' \
      -e 's/[[:space:]]+/ /g')"
  line="${line:0:800}"
  [ -n "${line}" ] || line="OSRM-uitvoer afgeschermd."
  printf '%s' "${line}"
}

operation_log_path() {
  printf '%s/%s.log.jsonl' "${RESULT_DIR}" "$1"
}

operation_status_path() {
  printf '%s/%s.status.json' "${RESULT_DIR}" "$1"
}

ensure_result_file() {
  local path="$1"

  if [ -e "${path}" ] || [ -L "${path}" ]; then
    [ -f "${path}" ] && [ ! -L "${path}" ] \
      && [ "$(stat -c '%h' -- "${path}")" = "1" ] \
      && [ "$(stat -c '%u' -- "${path}")" = "0" ] \
      || fail "Unsafe OSRM operation result path."
    run_cmd chown root:"${DIS_GROUP}" "${path}"
    run_cmd chmod 0640 "${path}"
    run_cmd setfacl -m "u:www-data:r--" "${path}"
    return
  fi
  run_cmd install -m 0640 -o root -g "${DIS_GROUP}" /dev/null "${path}"
  run_cmd setfacl -m "u:www-data:r--" "${path}"
}

initialize_log_sequence() {
  local sequence

  sequence="$(tail -n 1 -- "${LOG_FILE}" 2>/dev/null \
    | jq -er '.seq | select(type == "number" and . >= 0 and floor == .)' 2>/dev/null || true)"
  if [[ "${sequence}" =~ ^[0-9]+$ ]]; then
    LOG_SEQUENCE="${sequence}"
  else
    LOG_SEQUENCE=0
  fi
}

trim_log_if_needed() {
  local size temporary

  size="$(stat -c '%s' -- "${LOG_FILE}" 2>/dev/null || printf 0)"
  [[ "${size}" =~ ^[0-9]+$ ]] || return 1
  [ "${size}" -le "${LOG_MAX_BYTES}" ] && return 0
  temporary="$(mktemp "${WORK_DIR}/.log.XXXXXX")"
  tail -n "${LOG_RETAIN_LINES}" -- "${LOG_FILE}" > "${temporary}"
  run_cmd chown root:"${DIS_GROUP}" "${temporary}"
  run_cmd chmod 0640 "${temporary}"
  run_cmd setfacl -m "u:www-data:r--" "${temporary}"
  run_cmd mv -fT -- "${temporary}" "${LOG_FILE}"
}

append_log() {
  local stage="$1" level="$2" message="$3" progress="${4:-null}" line

  [[ "${progress}" = "null" || "${progress}" =~ ^([0-9]|[1-9][0-9]|100)$ ]] \
    || progress=null
  LOG_SEQUENCE=$((LOG_SEQUENCE + 1))
  message="$(safe_line "${message}")"
  line="$(jq -cn \
    --argjson version 1 \
    --argjson seq "${LOG_SEQUENCE}" \
    --arg timestamp "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --arg stage "${stage}" \
    --arg level "${level}" \
    --arg message "${message}" \
    --argjson progress_percent "${progress}" \
    '{version:$version,seq:$seq,timestamp:$timestamp,stage:$stage,level:$level,message:$message,progress_percent:$progress_percent}')"
  printf '%s\n' "${line}" >> "${LOG_FILE}"
  trim_log_if_needed
}

active_source_sha() {
  DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" status 2>/dev/null \
    | jq -er '.dataset.sha256 | select(type == "string" and test("^[a-f0-9]{64}$"))' 2>/dev/null \
    || true
}

write_operation_status() {
  local state="$1" stage="$2" message="$3" progress="${4:-null}" exit_code="${5:-null}"
  local active_sha finished_at=null temporary updated_at

  [[ "${progress}" = "null" || "${progress}" =~ ^([0-9]|[1-9][0-9]|100)$ ]] \
    || progress=null
  [[ "${exit_code}" = "null" || "${exit_code}" =~ ^[0-9]+$ ]] || exit_code=1
  updated_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  if [ "${state}" = "succeeded" ] || [ "${state}" = "failed" ]; then
    finished_at="\"${updated_at}\""
  fi
  active_sha="$(active_source_sha)"
  temporary="$(mktemp "${WORK_DIR}/.status.XXXXXX")"
  jq -n \
    --argjson version 1 \
    --arg operation_id "${OPERATION_ID}" \
    --arg action "${ACTION}" \
    --arg state "${state}" \
    --arg stage "${stage}" \
    --arg message "$(safe_line "${message}")" \
    --arg started_at "${STARTED_AT}" \
    --arg updated_at "${updated_at}" \
    --arg active_source_sha256 "${active_sha}" \
    --argjson progress_percent "${progress}" \
    --argjson finished_at "${finished_at}" \
    --argjson exit_code "${exit_code}" \
    '{
      version:$version,
      operation_id:$operation_id,
      action:$action,
      state:$state,
      stage:$stage,
      message:$message,
      progress_percent:$progress_percent,
      started_at:$started_at,
      updated_at:$updated_at,
      finished_at:$finished_at,
      exit_code:$exit_code,
      active_source_sha256:(if $active_source_sha256 == "" then null else $active_source_sha256 end)
    }' > "${temporary}"
  run_cmd chown root:"${DIS_GROUP}" "${temporary}"
  run_cmd chmod 0640 "${temporary}"
  run_cmd setfacl -m "u:www-data:r--" "${temporary}"
  run_cmd sync -f "${temporary}"
  run_cmd mv -fT -- "${temporary}" "${STATUS_FILE}"
  run_cmd sync -f "${RESULT_DIR}"
  LAST_STAGE="${stage}"
}

update_stage() {
  local stage="$1" message="$2" progress="${3:-null}"

  write_operation_status running "${stage}" "${message}" "${progress}" null
  append_log "${stage}" info "${message}" "${progress}"
}

artisan_callback() {
  local command="$1"
  shift

  runuser -u "${DIS_USER}" -- env -i \
    HOME="${BACKEND_DIR}" PATH=/usr/local/bin:/usr/bin:/bin \
    APP_ROOT="${APP_ROOT}" DIS_DATA_PATH="${DIS_DATA_PATH}" \
    /usr/bin/php "${BACKEND_DIR}/artisan" "${command}" "$@"
}

publish_global_status() {
  DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" publish-status
}

sync_backend_event() {
  artisan_callback dis:osrm-operation:sync "${OPERATION_ID}" >/dev/null 2>&1 || true
}

finish_operation() {
  local exit_code="$1" message="$2" callback_code
  local final_stage="${LAST_STAGE}" state="failed" progress=null

  [ "${exit_code}" -ne 0 ] || {
    state="succeeded"
    progress=100
    final_stage=completed
  }
  write_operation_status "${state}" "${final_stage}" "${message}" "${progress}" "${exit_code}"
  append_log "${final_stage}" "$([ "${exit_code}" -eq 0 ] && printf info || printf error)" "${message}" "${progress}"
  publish_global_status >/dev/null 2>&1 || true
  sync_backend_event

  set +e
  artisan_callback dis:osrm-operation:finish "${OPERATION_ID}" "${exit_code}" >/dev/null 2>&1
  callback_code=$?
  set -e
  if [ "${callback_code}" -ne 0 ]; then
    append_log completed warning "De backend kon de OSRM-operatie nog niet bevestigen; automatisch herstel volgt." "${progress}"
    # Keep the claimed work marker. The timer retries the callback and thereby
    # releases the database active-key after PHP/database recovery.
    return 1
  fi

  OPERATION_FINISHED=1
  run_cmd rm -f -- "${RUNNING_FILE}"
  return 0
}

operation_exit_handler() {
  local exit_code="$?"

  trap - EXIT INT TERM
  if [ -n "${DOWNLOAD_DIRECTORY}" ] && [ -d "${DOWNLOAD_DIRECTORY}" ] \
    && [ ! -L "${DOWNLOAD_DIRECTORY}" ]; then
    safe_cleanup_admin_download "${DOWNLOAD_DIRECTORY}" || true
  fi
  if [ -n "${OPERATION_ID}" ] && [ "${OPERATION_FINISHED}" = "0" ]; then
    [ "${exit_code}" -ne 0 ] || exit_code=1
    if [ "${PREVIOUS_ROUTING_HEALTHY}" = "1" ]; then
      finish_operation "${exit_code}" "OSRM-operatie mislukt; de vorige gezonde routering is behouden." || true
    else
      finish_operation "${exit_code}" "OSRM-operatie mislukt; bestaande kaartdata is niet vervangen." || true
    fi
  fi
  exit "${exit_code}"
}

safe_cleanup_admin_download() {
  local directory="$1" resolved_parent

  [[ "${directory}" == "${DIS_DATA_PATH}/osrm"/.admin-download.* ]] || return 1
  [ -d "${directory}" ] && [ ! -L "${directory}" ] || return 1
  resolved_parent="$(readlink -f -- "$(dirname "${directory}")")"
  [ "${resolved_parent}" = "$(readlink -f -- "${DIS_DATA_PATH}/osrm")" ] || return 1
  secure_path_operation remove-tree "${directory}"
}

validate_ulid() {
  [[ "$1" =~ ^[0-9A-HJKMNP-TV-Z]{26}$ ]]
}

validate_coordinate_pair() {
  local longitude="$1" latitude="$2"

  [[ "${longitude}" =~ ^-?[0-9]+([.][0-9]+)?$ ]] \
    && [[ "${latitude}" =~ ^-?[0-9]+([.][0-9]+)?$ ]] \
    && awk -v lon="${longitude}" -v lat="${latitude}" \
      'BEGIN { exit !(lon >= -180 && lon <= 180 && lat >= -90 && lat <= 90) }'
}

operation_payload_contract_is_valid() {
  local payload="$1"

  jq -e \
    --arg operation_id "${OPERATION_ID}" \
    --arg action "${ACTION}" \
    --arg actor_id "${ACTOR_ID}" '
      type == "object"
      and .version == 1
      and .operation_id == $operation_id
      and .action == $action
      and .actor_id == $actor_id
      and (.source_url | type == "string" and length <= 512)
      and (.source_sha256 | type == "string" and test("^[A-Fa-f0-9]{64}$"))
      and (.health_coordinate | type == "object")
      and (.health_coordinate.longitude | type == "number")
      and (.health_coordinate.latitude | type == "number")
      and ((keys_unsorted - ["version","operation_id","action","actor_id","source_url","source_sha256","health_coordinate"]) | length == 0)
      and ((.health_coordinate | keys_unsorted) - ["longitude","latitude"] | length == 0)
    ' <<< "${payload}" >/dev/null
}

load_validated_operation_payload() {
  local payload

  payload="$(artisan_callback dis:osrm-operation:payload "${OPERATION_ID}")" \
    || return 1
  operation_payload_contract_is_valid "${payload}" || return 1
  printf '%s\n' "${payload}"
}

configured_source_url() {
  local value

  value="$(grep -E '^OSRM_ADMIN_PBF_URL=' "${DIS_DATA_PATH}/.env" | tail -n 1 | cut -d '=' -f 2- || true)"
  value="${value%\"}"
  value="${value#\"}"
  value="${value%\'}"
  value="${value#\'}"
  [ -n "${value}" ] || value="${SOURCE_URL_DEFAULT}"
  [ "${value}" = "https://${SOURCE_HOST}${SOURCE_PATH}" ] \
    || fail "OSRM_ADMIN_PBF_URL must be the approved Netherlands HTTPS source."
  printf '%s' "${value}"
}

is_public_ip() {
  python3 -I -S -c '
import ipaddress, sys
try:
    address = ipaddress.ip_address(sys.argv[1])
except ValueError:
    raise SystemExit(1)
raise SystemExit(0 if address.is_global else 1)
' "$1"
}

resolve_and_pin_source() {
  local address selected=""
  local -a addresses=()

  mapfile -t addresses < <(
    { getent ahostsv4 "${SOURCE_HOST}" 2>/dev/null || true; getent ahostsv6 "${SOURCE_HOST}" 2>/dev/null || true; } \
      | awk '$2 == "STREAM" { print $1 }' | sort -u
  )
  [ "${#addresses[@]}" -gt 0 ] || fail "De vaste OSRM-downloadhost kon niet veilig worden omgezet."
  for address in "${addresses[@]}"; do
    is_public_ip "${address}" || fail "De vaste OSRM-downloadhost resolveert naar een niet-publiek adres."
    if [ -z "${selected}" ] || { [[ "${selected}" == *:* ]] && [[ "${address}" != *:* ]]; }; then
      selected="${address}"
    fi
  done
  [ -n "${selected}" ] || fail "Geen publiek adres voor de vaste OSRM-downloadhost beschikbaar."
  if [[ "${selected}" == *:* ]]; then
    printf '%s:443:[%s]' "${SOURCE_HOST}" "${selected}"
  else
    printf '%s:443:%s' "${SOURCE_HOST}" "${selected}"
  fi
}

validate_download_limits() {
  [[ "${MAX_PBF_BYTES}" =~ ^[1-9][0-9]*$ ]] \
    && [ "${MAX_PBF_BYTES}" -le 10737418240 ] \
    || fail "OSRM_ADMIN_MAX_PBF_BYTES is invalid."
  [[ "${MIN_PBF_BYTES}" =~ ^[1-9][0-9]*$ ]] \
    && [ "${MIN_PBF_BYTES}" -lt "${MAX_PBF_BYTES}" ] \
    || fail "OSRM_ADMIN_MIN_PBF_BYTES is invalid."
  [[ "${DOWNLOAD_TIMEOUT_SECONDS}" =~ ^[1-9][0-9]*$ ]] \
    && [ "${DOWNLOAD_TIMEOUT_SECONDS}" -le 21600 ] \
    || fail "OSRM_ADMIN_DOWNLOAD_TIMEOUT_SECONDS is invalid."
  [[ "${DOWNLOAD_CONNECT_TIMEOUT_SECONDS}" =~ ^[1-9][0-9]*$ ]] \
    && [ "${DOWNLOAD_CONNECT_TIMEOUT_SECONDS}" -le 60 ] \
    || fail "OSRM_ADMIN_CONNECT_TIMEOUT_SECONDS is invalid."
  [[ "${LOG_MAX_BYTES}" =~ ^[1-9][0-9]*$ ]] \
    && [ "${LOG_MAX_BYTES}" -le 33554432 ] \
    || fail "OSRM_ADMIN_LOG_MAX_BYTES is invalid."
  [[ "${LOG_RETAIN_LINES}" =~ ^[1-9][0-9]*$ ]] \
    && [ "${LOG_RETAIN_LINES}" -le 10000 ] \
    || fail "OSRM_ADMIN_LOG_RETAIN_LINES is invalid."
}

read_content_length() {
  local header_file="$1" values value

  values="$(tr -d '\r' < "${header_file}" \
    | awk 'tolower($1) == "content-length:" && $2 ~ /^[0-9]+$/ { print $2 }' \
    | sort -u)"
  [ "$(printf '%s\n' "${values}" | sed '/^$/d' | wc -l | tr -d ' ')" = "1" ] \
    || fail "De OSRM-bron gaf geen eenduidige bestandsgrootte."
  value="$(printf '%s' "${values}" | tr -d '\r\n')"
  [[ "${value}" =~ ^[0-9]+$ ]] \
    && [ "${value}" -ge "${MIN_PBF_BYTES}" ] \
    && [ "${value}" -le "${MAX_PBF_BYTES}" ] \
    || fail "De OSRM-bron valt buiten de toegestane bestandsgrootte."
  printf '%s' "${value}"
}

check_import_disk_space() {
  local source_size="$1" available_bytes filesystem_bytes factor reserve required

  factor="${OSRM_IMPORT_DISK_FACTOR:-8}"
  reserve="${OSRM_IMPORT_DISK_RESERVE_BYTES:-2147483648}"
  [[ "${factor}" =~ ^[2-9]$|^1[0-6]$ ]] || fail "OSRM_IMPORT_DISK_FACTOR is invalid."
  [[ "${reserve}" =~ ^[1-9][0-9]*$ ]] || fail "OSRM_IMPORT_DISK_RESERVE_BYTES is invalid."
  read -r filesystem_bytes available_bytes < <(df -PB1 "${DIS_DATA_PATH}/osrm" | awk 'NR == 2 { print $2, $4 }')
  [[ "${filesystem_bytes}" =~ ^[0-9]+$ ]] && [[ "${available_bytes}" =~ ^[0-9]+$ ]] \
    || fail "Vrije ruimte voor OSRM kon niet worden bepaald."
  required=$((source_size * (factor + 1) + reserve))
  [ "${available_bytes}" -ge "${required}" ] \
    || fail "Onvoldoende vrije ruimte voor download en begrensde OSRM-verwerking."
}

prepare_download_control_file() {
  local path="$1" expected_parent="$2"

  [ "$(dirname -- "${path}")" = "${expected_parent}" ] \
    || fail "Ongeldig OSRM-downloadcontrolepad."
  [ -d "${expected_parent}" ] && [ ! -L "${expected_parent}" ] \
    && [ "$(stat -c '%u:%g:%a' -- "${expected_parent}" 2>/dev/null || true)" = \
      "0:$(id -g dis-osrm):750" ] \
    || fail "De OSRM-downloadcontrolemap is niet veilig."
  [ ! -e "${path}" ] && [ ! -L "${path}" ] \
    || fail "Een OSRM-downloadcontrolebestand bestaat al."
  run_cmd install -m 0600 -o dis-osrm-build -g dis-osrm /dev/null "${path}"
  [ -f "${path}" ] && [ ! -L "${path}" ] \
    && [ "$(stat -c '%U:%G:%a:%h' -- "${path}" 2>/dev/null || true)" = \
      'dis-osrm-build:dis-osrm:600:1' ] \
    || fail "Een OSRM-downloadcontrolebestand kon niet veilig worden aangemaakt."
}

seal_download_control_file() {
  local path="$1" expected_parent="$2"

  [ "$(dirname -- "${path}")" = "${expected_parent}" ] \
    && [ -f "${path}" ] && [ ! -L "${path}" ] \
    && [ "$(stat -c '%U:%G:%a:%h' -- "${path}" 2>/dev/null || true)" = \
      'dis-osrm-build:dis-osrm:600:1' ] \
    || fail "Een OSRM-downloadcontrolebestand is tijdens gebruik vervangen."
  run_cmd chown root:root "${path}"
  run_cmd chmod 0400 "${path}"
  [ "$(stat -c '%u:%g:%a:%h' -- "${path}" 2>/dev/null || true)" = '0:0:400:1' ] \
    || fail "Een OSRM-downloadcontrolebestand kon niet worden verzegeld."
}

download_source() {
  local source_url="$1" expected_sha="$2" pin http_code content_length
  local control_directory header_file head_error_file code_file error_file pbf_file
  local curl_pid size percent actual_sha exit_code

  validate_download_limits
  update_stage downloading "Oude tijdelijke verwerking en kaartreleases veilig opruimen." 0
  run_logged_command downloading env DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" sweep-scratch \
    || fail "Oude OSRM-werkmappen konden niet veilig worden opgeschoond."
  run_logged_command downloading env DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" prune \
    || fail "Oude OSRM-releases konden niet veilig worden opgeschoond."
  pin="$(resolve_and_pin_source)"
  DOWNLOAD_DIRECTORY="$(mktemp -d "${DIS_DATA_PATH}/osrm/.admin-download.XXXXXX")"
  run_cmd chown root:dis-osrm "${DOWNLOAD_DIRECTORY}"
  run_cmd chmod 0750 "${DOWNLOAD_DIRECTORY}"
  control_directory="${DOWNLOAD_DIRECTORY}/control"
  run_cmd install -d -m 0750 -o root -g dis-osrm "${control_directory}"
  header_file="${control_directory}/head-headers"
  head_error_file="${control_directory}/head-error"
  code_file="${control_directory}/download-http-code"
  error_file="${control_directory}/download-error"
  pbf_file="${DOWNLOAD_DIRECTORY}/netherlands.osm.pbf"
  prepare_download_control_file "${header_file}" "${control_directory}"
  prepare_download_control_file "${head_error_file}" "${control_directory}"
  prepare_download_control_file "${code_file}" "${control_directory}"
  prepare_download_control_file "${error_file}" "${control_directory}"
  prepare_download_control_file "${pbf_file}" "${DOWNLOAD_DIRECTORY}"

  update_stage downloading "Downloadbron en beschikbare ruimte controleren." 0
  set +e
  http_code="$(runuser -u dis-osrm-build -- /usr/bin/curl \
    --silent --show-error --head \
    --proto '=https' --proto-redir '=https' --max-redirs 0 \
    --connect-timeout "${DOWNLOAD_CONNECT_TIMEOUT_SECONDS}" --max-time 60 \
    --resolve "${pin}" \
    --dump-header "${header_file}" --output /dev/null \
    --write-out '%{http_code}' "${source_url}" 2>"${head_error_file}")"
  exit_code=$?
  set -e
  seal_download_control_file "${header_file}" "${control_directory}"
  seal_download_control_file "${head_error_file}" "${control_directory}"
  [ "${exit_code}" -eq 0 ] && [ "${http_code}" = "200" ] \
    || fail "De vaste OSRM-downloadbron is niet veilig bereikbaar."
  content_length="$(read_content_length "${header_file}")"
  check_import_disk_space "${content_length}"

  update_stage downloading "Nederlandse kaartdata downloaden." 0
  set +e
  runuser -u dis-osrm-build -- /usr/bin/curl \
    --silent --show-error \
    --proto '=https' --proto-redir '=https' --max-redirs 0 \
    --connect-timeout "${DOWNLOAD_CONNECT_TIMEOUT_SECONDS}" \
    --max-time "${DOWNLOAD_TIMEOUT_SECONDS}" \
    --retry 2 --retry-delay 5 --retry-connrefused \
    --max-filesize "${MAX_PBF_BYTES}" \
    --resolve "${pin}" \
    --output "${pbf_file}" \
    --write-out '%{http_code}' "${source_url}" >"${code_file}" 2>"${error_file}" &
  curl_pid=$!
  set -e
  while kill -0 "${curl_pid}" 2>/dev/null; do
    size="$(stat -c '%s' -- "${pbf_file}" 2>/dev/null || printf 0)"
    if [[ "${size}" =~ ^[0-9]+$ ]]; then
      percent=$((size * 100 / content_length))
      [ "${percent}" -le 99 ] || percent=99
      write_operation_status running downloading "Nederlandse kaartdata downloaden." "${percent}" null
    fi
    sleep 5
  done
  set +e
  wait "${curl_pid}"
  exit_code=$?
  set -e
  seal_download_control_file "${code_file}" "${control_directory}"
  seal_download_control_file "${error_file}" "${control_directory}"
  http_code="$(tr -d '\r\n' < "${code_file}" 2>/dev/null || true)"
  if [ "${exit_code}" -ne 0 ] || [ "${http_code}" != "200" ]; then
    append_log downloading error "Download van Nederlandse kaartdata is mislukt." null
    fail "Download van Nederlandse kaartdata is mislukt."
  fi
  size="$(stat -c '%s' -- "${pbf_file}")"
  [ -f "${pbf_file}" ] && [ ! -L "${pbf_file}" ] \
    && [ "$(stat -c '%h' -- "${pbf_file}")" = "1" ] \
    && [ "$(stat -c '%U' -- "${pbf_file}")" = "dis-osrm-build" ] \
    && [ "$(stat -c '%a' -- "${pbf_file}")" = "600" ] \
    || fail "De gedownloade kaartdata is geen veilig, exclusief downloadbestand."
  [ "${size}" = "${content_length}" ] \
    || fail "De gedownloade kaartdata heeft niet de aangekondigde grootte."

  update_stage verifying "SHA-256 van de Nederlandse kaartdata controleren." 100
  actual_sha="$(sha256sum -- "${pbf_file}" | awk '{ print $1 }')"
  [ "${actual_sha}" = "${expected_sha}" ] \
    || fail "De gedownloade kaartdata voldoet niet aan de vastgelegde SHA-256."
  run_cmd chown root:dis-osrm "${pbf_file}"
  run_cmd chmod 0440 "${pbf_file}"
  DOWNLOADED_PBF_FILE="${pbf_file}"
}

stage_from_output() {
  local line="$1"

  case "${line}" in
    *"Running OSRM extract stage"*) printf extracting ;;
    *"Running OSRM partition stage"*) printf partitioning ;;
    *"Running OSRM customize stage"*) printf customizing ;;
    *"Starting the newly prepared OSRM dataset"*) printf activating ;;
    *"active and healthy"*|*"readiness check passed"*) printf verifying ;;
    *) printf '%s' "${LAST_STAGE}" ;;
  esac
}

run_logged_command() {
  local initial_stage="$1"
  shift
  local command_pid exit_code fifo line stage

  fifo="$(mktemp "${WORK_DIR}/.output.XXXXXX")"
  rm -f -- "${fifo}"
  mkfifo -m 0600 "${fifo}"
  "$@" >"${fifo}" 2>&1 &
  command_pid=$!
  while IFS= read -r line; do
    stage="$(stage_from_output "${line}")"
    if [ "${stage}" != "${LAST_STAGE}" ]; then
      write_operation_status running "${stage}" "OSRM-verwerking: ${stage}." null null
    fi
    append_log "${stage:-${initial_stage}}" info "${line}" null
  done < "${fifo}"
  set +e
  wait "${command_pid}"
  exit_code=$?
  set -e
  rm -f -- "${fifo}"
  return "${exit_code}"
}

read_active_probe() {
  DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" status \
    | jq -er '.dataset.health_coordinate | select(type == "string")'
}

process_operation() {
  local payload source_url expected_sha longitude latitude coordinate initial_status active_sha pbf_file

  trap operation_exit_handler EXIT INT TERM
  ensure_result_file "${LOG_FILE}"
  ensure_result_file "${STATUS_FILE}"
  initialize_log_sequence
  STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  update_stage validating "OSRM-verzoek en onveranderlijke configuratie controleren." 0

  payload="$(load_validated_operation_payload)" \
    || fail "De OSRM-operatieconfiguratie kon niet veilig worden geladen."
  source_url="$(jq -r '.source_url' <<< "${payload}")"
  [ "${source_url}" = "$(configured_source_url)" ] \
    || fail "De OSRM-bron wijkt af van de root-geconfigureerde Nederlandse bron."
  expected_sha="$(jq -r '.source_sha256 | ascii_downcase' <<< "${payload}")"
  longitude="$(jq -r '.health_coordinate.longitude | tostring' <<< "${payload}")"
  latitude="$(jq -r '.health_coordinate.latitude | tostring' <<< "${payload}")"
  validate_coordinate_pair "${longitude}" "${latitude}" \
    || fail "De OSRM-probecoördinaat is ongeldig."
  coordinate="${longitude},${latitude}"

  artisan_callback dis:osrm-operation:mark-running "${OPERATION_ID}" >/dev/null \
    || fail "De OSRM-operatie kon niet als actief worden gemarkeerd."

  initial_status="$(DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" status)"
  if jq -e '.state == "ready" and .healthy == true' <<< "${initial_status}" >/dev/null; then
    PREVIOUS_ROUTING_HEALTHY=1
  fi
  if [ "${ACTION}" = "update" ]; then
    jq -e '.installed == true and (.dataset.sha256 | type == "string" and test("^[a-f0-9]{64}$"))' \
      <<< "${initial_status}" >/dev/null \
      || fail "OSRM moet beheerd geïnstalleerd zijn en actieve kaartdata hebben voordat deze kan worden bijgewerkt."
    [ "${coordinate}" = "$(jq -r '.dataset.health_coordinate // ""' <<< "${initial_status}")" ] \
      || fail "De update-probe wijkt af van de actieve, opgeslagen OSRM-probe."
  fi

  if [ "${ACTION}" = "install_activate" ] \
    && ! jq -e '.installed == true' <<< "${initial_status}" >/dev/null; then
    update_stage installing_package "Geverifieerd Ubuntu OSRM-pakket installeren en controleren." null
    run_logged_command installing_package \
      env DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" install-package \
      || fail "Het geverifieerde Ubuntu OSRM-pakket kon niet worden geïnstalleerd."
    DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" status \
      | jq -e '.installed == true and .package.version != null' >/dev/null \
      || fail "Het OSRM-pakket heeft geen geldige provenance-receipt."
  else
    # A map update deliberately retains the exact healthy binary verified in
    # initial_status. Upgrading it here would make dataset-only rollback unsafe
    # if a later download or import fails.
    append_log validating info "Bestaand geverifieerd OSRM-pakket blijft ongewijzigd tijdens de kaartupdate." null
  fi

  update_stage provisioning "Geïsoleerde OSRM-service en datamappen controleren." null
  run_logged_command provisioning env DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" provision \
    || fail "De geïsoleerde OSRM-service kon niet worden ingericht."

  active_sha="$(active_source_sha)"
  if [ -n "${active_sha}" ] && [ "${active_sha}" = "${expected_sha}" ] \
    && [ "${coordinate}" = "$(jq -r '.dataset.health_coordinate // ""' <<< "${initial_status}")" ] \
    && jq -e '.state == "ready" and .healthy == true' <<< "${initial_status}" >/dev/null; then
    update_stage verifying "De vastgelegde kaartdata is al actief; gezondheid opnieuw controleren." 100
    run_logged_command verifying env DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" reconcile \
      || fail "De bestaande OSRM-dataset kon niet gezond worden geactiveerd."
  else
    download_source "${source_url}" "${expected_sha}"
    pbf_file="${DOWNLOADED_PBF_FILE}"
    [ -n "${pbf_file}" ] || fail "De geverifieerde OSRM-download ontbreekt."
    update_stage extracting "Nederlandse kaartdata voorbereiden voor navigatieroutes." null
    run_logged_command extracting \
      env DIS_DATA_PATH="${DIS_DATA_PATH}" \
        OSRM_ACTIVE_SCRATCH_PATH="${DOWNLOAD_DIRECTORY}" \
        OSRM_IMPORT_PARENT_UNIT=dis-osrm-admin-request.service \
      bash "${OSRM_SCRIPT}" import \
        --pbf "${pbf_file}" \
        --sha256 "${expected_sha}" \
        --health-coordinate "${coordinate}" \
      || fail "De nieuwe OSRM-dataset kon niet veilig worden voorbereid of geactiveerd."
  fi

  update_stage verifying "Actieve OSRM-dataset en lokale route-endpoint verifiëren." 100
  run_logged_command verifying env DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" verify \
    || fail "De actieve OSRM-artifactcontrole is mislukt."
  run_logged_command verifying env DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" health \
    || fail "De actieve OSRM-readinesscontrole is mislukt."
  publish_global_status
  safe_cleanup_admin_download "${DOWNLOAD_DIRECTORY}" >/dev/null 2>&1 || true
  DOWNLOAD_DIRECTORY=""

  update_stage configuring "OSRM-routering in DIS activeren en configuratie afronden." 100
  write_operation_status succeeded completed "OSRM is geïnstalleerd, geactiveerd en gezond." 100 0
  append_log completed info "OSRM is geïnstalleerd, geactiveerd en gezond." 100
  sync_backend_event
  if ! artisan_callback dis:osrm-operation:finish "${OPERATION_ID}" 0 >/dev/null; then
    # The privileged work has genuinely succeeded. Preserve that terminal root
    # snapshot and work marker so the timer can retry the idempotent backend
    # completion instead of rewriting a healthy result as failed.
    append_log completed warning "De backendbevestiging is tijdelijk niet bereikbaar; automatisch herstel volgt." 100
    trap - EXIT INT TERM
    return 1
  fi
  OPERATION_FINISHED=1
  run_cmd rm -f -- "${RUNNING_FILE}"
  publish_global_status
  trap - EXIT INT TERM
}

discard_invalid_request() {
  local request_file="$1" quarantine

  quarantine="${WORK_DIR}/rejected.$$.$(date +%s%N).${RANDOM}"
  if mv -T -- "${request_file}" "${quarantine}" 2>/dev/null; then
    rm -f -- "${quarantine}"
  fi
  logger -p authpriv.warning -t dis-security \
    "osrm_admin_request_rejected reason=invalid_request" 2>/dev/null || true
}

fail_request_by_id() {
  local request_id="$1" reason="$2" request_file="$3" callback_code

  [[ "${request_id}" =~ ^[a-f0-9]{32}$ ]] || return 1
  [[ "${reason}" =~ ^(rejected|expired|abandoned)$ ]] || return 1
  logger -p authpriv.warning -t dis-security \
    "osrm_admin_request_rejected reason=${reason}" 2>/dev/null || true

  set +e
  artisan_callback dis:osrm-operation:fail-request "${request_id}" "${reason}" >/dev/null 2>&1
  callback_code=$?
  set -e
  if [ "${callback_code}" -ne 0 ]; then
    # Keep the broker marker so the periodic sweep can retry after a temporary
    # PHP or database outage. The backend also expires stale queued rows.
    return 1
  fi
  rm -f -- "${request_file}"
  return 0
}

claim_request() {
  local request_file="$1" request_id request_owner request_mode created_at created_epoch age

  request_id="$(basename "${request_file}" .pending)"
  if [[ ! "${request_id}" =~ ^[a-f0-9]{32}$ ]]; then
    discard_invalid_request "${request_file}"
    return 1
  fi
  RUNNING_FILE="${WORK_DIR}/${request_id}.json"
  [ ! -e "${RUNNING_FILE}" ] && [ ! -L "${RUNNING_FILE}" ] \
    || { discard_invalid_request "${request_file}"; return 1; }
  mv -T -- "${request_file}" "${RUNNING_FILE}" 2>/dev/null || return 1
  if [ -L "${RUNNING_FILE}" ] || [ ! -f "${RUNNING_FILE}" ] \
    || [ "$(stat -c '%h' -- "${RUNNING_FILE}" 2>/dev/null || printf 0)" != "1" ] \
    || [ "$(stat -c '%s' -- "${RUNNING_FILE}" 2>/dev/null || printf 4097)" -gt 4096 ]; then
    fail_request_by_id "${request_id}" rejected "${RUNNING_FILE}" || true
    return 1
  fi
  request_owner="$(stat -c '%U' -- "${RUNNING_FILE}")"
  request_mode="$(stat -c '%a' -- "${RUNNING_FILE}")"
  if [ "${request_owner}" != "www-data" ] || [ "${request_mode}" != "600" ]; then
    fail_request_by_id "${request_id}" rejected "${RUNNING_FILE}" || true
    return 1
  fi
  run_cmd chown root:root "${RUNNING_FILE}"
  run_cmd chmod 0600 "${RUNNING_FILE}"
  jq -e '
    type == "object"
    and .version == 1
    and (.operation_id | type == "string" and test("^[0-9A-HJKMNP-TV-Z]{26}$"))
    and (.action | type == "string" and test("^(install_activate|update)$"))
    and (.actor_id | type == "string" and test("^[0-9A-HJKMNP-TV-Z]{26}$"))
    and (.created_at | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
    and ((keys_unsorted - ["version","operation_id","action","actor_id","created_at"]) | length == 0)
  ' "${RUNNING_FILE}" >/dev/null \
    || { fail_request_by_id "${request_id}" rejected "${RUNNING_FILE}" || true; return 1; }

  OPERATION_ID="$(jq -r '.operation_id' "${RUNNING_FILE}")"
  ACTION="$(jq -r '.action' "${RUNNING_FILE}")"
  ACTOR_ID="$(jq -r '.actor_id' "${RUNNING_FILE}")"
  validate_ulid "${OPERATION_ID}" && validate_ulid "${ACTOR_ID}" \
    || { fail_request_by_id "${request_id}" rejected "${RUNNING_FILE}" || true; return 1; }
  created_at="$(jq -r '.created_at' "${RUNNING_FILE}")"
  created_epoch="$(date -u -d "${created_at}" +%s 2>/dev/null || true)"
  [[ "${created_epoch}" =~ ^[0-9]+$ ]] \
    || { OPERATION_ID=""; ACTION=""; ACTOR_ID=""; fail_request_by_id "${request_id}" rejected "${RUNNING_FILE}" || true; return 1; }
  age=$(( $(date +%s) - created_epoch ))
  if [ "${age}" -lt -60 ] || [ "${age}" -gt 86400 ]; then
    STATUS_FILE="$(operation_status_path "${OPERATION_ID}")"
    LOG_FILE="$(operation_log_path "${OPERATION_ID}")"
    STARTED_AT="${created_at}"
    ensure_result_file "${LOG_FILE}"
    ensure_result_file "${STATUS_FILE}"
    finish_operation 124 "OSRM-verzoek is verlopen voordat de root-worker het kon claimen." || true
    return 1
  fi
  STATUS_FILE="$(operation_status_path "${OPERATION_ID}")"
  LOG_FILE="$(operation_log_path "${OPERATION_ID}")"
  return 0
}

recover_committed_operation() {
  local payload runtime_status expected_sha longitude latitude coordinate source_url

  # Payload/database unavailability is retryable: without the immutable
  # database contract we cannot truthfully classify the interrupted work.
  payload="$(artisan_callback dis:osrm-operation:payload "${OPERATION_ID}")" \
    || return 2
  operation_payload_contract_is_valid "${payload}" || return 2
  source_url="$(jq -r '.source_url' <<< "${payload}")"
  [ "${source_url}" = "${SOURCE_URL_DEFAULT}" ] || return 1
  expected_sha="$(jq -r '.source_sha256 | ascii_downcase' <<< "${payload}")"
  longitude="$(jq -r '.health_coordinate.longitude | tostring' <<< "${payload}")"
  latitude="$(jq -r '.health_coordinate.latitude | tostring' <<< "${payload}")"
  validate_coordinate_pair "${longitude}" "${latitude}" || return 1
  coordinate="${longitude},${latitude}"

  runtime_status="$(DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" status 2>/dev/null)" \
    || return 1
  jq -e \
    --arg expected_sha "${expected_sha}" \
    --arg coordinate "${coordinate}" '
      .installed == true
      and .state == "ready"
      and .healthy == true
      and (.dataset | type == "object")
      and .dataset.sha256 == $expected_sha
      and .dataset.health_coordinate == $coordinate
    ' <<< "${runtime_status}" >/dev/null \
    || return 1
  DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" verify >/dev/null 2>&1 \
    || return 1
  DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" health >/dev/null 2>&1 \
    || return 1
  return 0
}

recover_abandoned_operation() {
  local running_file="$1" request_id state exit_code recovery_result

  request_id="$(basename "${running_file}" .json)"
  [[ "${request_id}" =~ ^[a-f0-9]{32}$ ]] \
    || { rm -f -- "${running_file}" 2>/dev/null || true; return; }
  if [ ! -f "${running_file}" ] || [ -L "${running_file}" ] \
    || [ "$(stat -c '%u:%a:%h' -- "${running_file}" 2>/dev/null || true)" != "0:600:1" ]; then
    fail_request_by_id "${request_id}" abandoned "${running_file}" || true
    return
  fi
  OPERATION_ID="$(jq -r '.operation_id // ""' "${running_file}" 2>/dev/null || true)"
  ACTION="$(jq -r '.action // ""' "${running_file}" 2>/dev/null || true)"
  ACTOR_ID="$(jq -r '.actor_id // ""' "${running_file}" 2>/dev/null || true)"
  validate_ulid "${OPERATION_ID}" && validate_ulid "${ACTOR_ID}" \
    && [[ "${ACTION}" =~ ^(install_activate|update)$ ]] \
    || { OPERATION_ID=""; ACTION=""; ACTOR_ID=""; fail_request_by_id "${request_id}" abandoned "${running_file}" || true; return; }
  RUNNING_FILE="${running_file}"
  STATUS_FILE="$(operation_status_path "${OPERATION_ID}")"
  LOG_FILE="$(operation_log_path "${OPERATION_ID}")"
  ensure_result_file "${LOG_FILE}"
  ensure_result_file "${STATUS_FILE}"
  initialize_log_sequence
  STARTED_AT="$(jq -r '.started_at // empty' "${STATUS_FILE}" 2>/dev/null || true)"
  [ -n "${STARTED_AT}" ] || STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

  DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" reconcile >/dev/null 2>&1 || true
  state="$(jq -r '.state // "running"' "${STATUS_FILE}" 2>/dev/null || printf running)"
  exit_code="$(jq -r '.exit_code // 124' "${STATUS_FILE}" 2>/dev/null || printf 124)"
  if [ "${state}" = "running" ] \
    || { [ "${state}" = "succeeded" ] && [ "${exit_code}" = "0" ]; }; then
    recovery_result=0
    recover_committed_operation || recovery_result=$?
    if [ "${recovery_result}" = "0" ]; then
      finish_operation 0 "OSRM-operatie is na onderbreking exact tegen de actieve, gezonde routering geverifieerd." \
        || RECOVERY_RETRY_PENDING=1
    elif [ "${recovery_result}" = "2" ]; then
      # Preserve both the root snapshot and work marker. A later timer run can
      # retry after PHP/database recovery without fabricating a failure.
      RECOVERY_RETRY_PENDING=1
      return 0
    else
      finish_operation 124 "Een onderbroken OSRM-operatie is veilig hersteld; bestaande kaartdata is niet vervangen." \
        || RECOVERY_RETRY_PENDING=1
    fi
  else
    finish_operation 124 "Een onderbroken OSRM-operatie is veilig hersteld; bestaande kaartdata is niet vervangen." \
      || RECOVERY_RETRY_PENDING=1
  fi
}

main() {
  local request_file running_file

  initialize_worker
  install_osrm_admin_layout
  ensure_directory "${LOCK_DIR}" root root 0700
  exec 9>"${WORKER_LOCK}"
  flock -n 9 || exit 0

  run_cmd install -d -m 0755 -o root -g root /run/lock
  exec {DIS_OPERATION_LOCK_FD}>/run/lock/dis-exclusive-operation.lock
  run_cmd chmod 0600 /run/lock/dis-exclusive-operation.lock
  # A deploy, backup, restore, manual import or another privileged mutation has
  # priority. Leave pending requests untouched; the timer retries them.
  flock -n "${DIS_OPERATION_LOCK_FD}" || exit 0
  DIS_OPERATION_LOCK_HELD=1
  export DIS_OPERATION_LOCK_HELD DIS_OPERATION_LOCK_FD

  if [ -e "${DIS_DATA_PATH}/osrm" ] || [ -L "${DIS_DATA_PATH}/osrm" ]; then
    [ -d "${DIS_DATA_PATH}/osrm" ] && [ ! -L "${DIS_DATA_PATH}/osrm" ] \
      || fail "De OSRM-datamap is onveilig; crashherstel is gestopt."
    DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}" sweep-scratch
  fi

  shopt -s nullglob
  for running_file in "${WORK_DIR}"/*.json; do
    reset_operation_context
    recover_abandoned_operation "${running_file}"
    [ "${RECOVERY_RETRY_PENDING}" = "0" ] || break
  done
  if [ "${RECOVERY_RETRY_PENDING}" != "0" ]; then
    publish_global_status >/dev/null 2>&1 || true
    return 0
  fi
  for request_file in "${REQUEST_DIR}"/*.pending; do
    reset_operation_context
    if claim_request "${request_file}"; then
      process_operation
    fi
    # One request per invocation; path/timer starts the next operation later.
    break
  done
  publish_global_status >/dev/null 2>&1 || true
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
