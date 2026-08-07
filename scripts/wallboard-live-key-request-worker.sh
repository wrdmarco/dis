#!/usr/bin/env bash
set -euo pipefail
set +x

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ ! -f "${SCRIPT_DIR}/lib/common.sh" ] && [ -f "${APP_ROOT:-/opt/dis}/scripts/lib/common.sh" ]; then
  SCRIPT_DIR="${APP_ROOT:-/opt/dis}/scripts"
fi
# shellcheck source=scripts/lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
REQUEST_DIR="${DIS_DATA_PATH}/wallboard-live-key-requests"
WORK_DIR="${DIS_DATA_PATH}/wallboard-live-key-request-work"
LOCK_DIR="/run/dis-wallboard-live-key-request"
LOCK_FILE="${LOCK_DIR}/worker.lock"
REFRESH_PATH="/usr/local/sbin/dis-wallboard-live-refresh"
MAX_REQUEST_BYTES=4096
MAX_RESULT_BYTES=65536
MAX_MANAGED_ENV_BYTES=2097152
MAX_REQUEST_LIFETIME_SECONDS=120
MINIMUM_REMAINING_SECONDS=35
ACTIVATION_DEADLINE_SECONDS=55
SUCCESS_RESULT_DEADLINE_SECONDS=70
ROLLBACK_REFRESH_TIMEOUT_SECONDS=75

security_log() {
  local priority="$1" message="$2"
  /usr/bin/logger -p "authpriv.${priority}" -t dis-security -- "${message}" 2>/dev/null || true
}

request_layout_is_safe() {
  local request_metadata work_metadata acl named_users named_groups

  request_metadata="$(/usr/bin/stat -c '%u:%g:%a' -- "${REQUEST_DIR}" 2>/dev/null || true)"
  work_metadata="$(/usr/bin/stat -c '%u:%g:%a' -- "${WORK_DIR}" 2>/dev/null || true)"
  [ "${request_metadata}" = "0:0:1730" ] && [ "${work_metadata}" = "0:0:700" ] || return 1
  acl="$(/usr/bin/getfacl -cp -- "${REQUEST_DIR}" 2>/dev/null || true)"
  /usr/bin/grep -Fxq 'user:www-data:-wx' <<< "${acl}" || return 1
  /usr/bin/grep -Fxq 'user::rwx' <<< "${acl}" || return 1
  /usr/bin/grep -Fxq 'group::-wx' <<< "${acl}" || return 1
  /usr/bin/grep -Fxq 'mask::-wx' <<< "${acl}" || return 1
  /usr/bin/grep -Fxq 'other::---' <<< "${acl}" || return 1
  named_users="$(/usr/bin/grep '^user:[^:]' <<< "${acl}" || true)"
  named_groups="$(/usr/bin/grep '^group:[^:]' <<< "${acl}" || true)"
  [ "${named_users}" = 'user:www-data:-wx' ] && [ -z "${named_groups}" ] \
    && ! /usr/bin/grep -q '^default:' <<< "${acl}"
}

managed_env_path() {
  local resolved_data_path resolved_env

  resolved_data_path="$(canonical_dis_data_path)" || return 1
  resolved_env="$(/usr/bin/readlink -e -- "${APP_ROOT}/.env" 2>/dev/null || true)"
  [ "${resolved_env}" = "${resolved_data_path}/.env" ] || return 1
  [ -f "${resolved_env}" ] && [ ! -L "${resolved_env}" ] \
    && [ "$(/usr/bin/stat -c '%u:%h' -- "${resolved_env}" 2>/dev/null || true)" = "0:1" ] \
    || return 1
  require_root_controlled_parent "${resolved_env}" || return 1
  printf '%s\n' "${resolved_env}"
}

read_managed_stream_key() {
  local env_file="$1" line value="" matches=0 size

  size="$(/usr/bin/stat -c '%s' -- "${env_file}" 2>/dev/null || true)"
  [[ "${size}" =~ ^[0-9]+$ ]] && [ "${size}" -ge 1 ] && [ "${size}" -le "${MAX_MANAGED_ENV_BYTES}" ] \
    || return 1
  /usr/bin/tr -d '\000' < "${env_file}" | /usr/bin/cmp -s - "${env_file}" \
    || return 1

  while IFS= read -r line || [ -n "${line}" ]; do
    line="${line%$'\r'}"
    case "${line}" in
      WALLBOARD_LIVE_STREAM_STREAM_KEY=*)
        matches=$((matches + 1))
        value="${line#*=}"
        ;;
    esac
  done < "${env_file}"
  [ "${matches}" -eq 1 ] && valid_stream_key "${value}" || return 1
  printf '%s' "${value}"
}

valid_stream_key() {
  local key="$1" first

  [[ "${key}" =~ ^[A-Za-z0-9._~-]{32,79}$ ]] || return 1
  first="${key:0:1}"
  [ -n "${key//${first}/}" ]
}

stream_key_sha256() {
  printf '%s' "$1" | /usr/bin/sha256sum | /usr/bin/cut -d' ' -f1
}

safe_previous_key_file() {
  local path="$1"

  [ -f "${path}" ] && [ ! -L "${path}" ] \
    && [ "$(/usr/bin/stat -c '%u:%g:%a:%h' -- "${path}" 2>/dev/null || true)" = "0:0:600:1" ]
}

safe_commit_marker() {
  local path="$1"

  [ -f "${path}" ] && [ ! -L "${path}" ] \
    && [ "$(/usr/bin/stat -c '%u:%g:%a:%h:%s' -- "${path}" 2>/dev/null || true)" = "0:0:600:1:10" ] \
    && [ "$(< "${path}")" = 'committed' ]
}

write_commit_marker() (
  set -euo pipefail
  local path="$1" temporary=""

  if [ -e "${path}" ] || [ -L "${path}" ]; then
    safe_commit_marker "${path}"
    return
  fi
  temporary="$(/usr/bin/mktemp "${WORK_DIR}/.commit.XXXXXX")" || return 1
  trap '/usr/bin/rm -f -- "${temporary:-}" 2>/dev/null || true' EXIT
  /usr/bin/chown root:root "${temporary}" || return 1
  /usr/bin/chmod 0600 "${temporary}" || return 1
  printf 'committed\n' > "${temporary}" || return 1
  /usr/bin/sync -f "${temporary}" || return 1
  /usr/bin/mv -T -- "${temporary}" "${path}" || return 1
  temporary=""
  /usr/bin/sync -f "${WORK_DIR}" || return 1
  safe_commit_marker "${path}" || return 1
  trap - EXIT
)

cleanup_committed_request() {
  local running_file="$1" previous_key_file="$2" commit_marker="$3"

  safe_commit_marker "${commit_marker}" || return 1
  if [ -e "${previous_key_file}" ] || [ -L "${previous_key_file}" ]; then
    safe_previous_key_file "${previous_key_file}" || return 1
    /usr/bin/rm -f -- "${previous_key_file}" || return 1
    /usr/bin/sync -f "${WORK_DIR}" || return 1
  fi
  if [ -e "${running_file}" ] || [ -L "${running_file}" ]; then
    [ -f "${running_file}" ] && [ ! -L "${running_file}" ] \
      && [ "$(/usr/bin/stat -c '%u:%g:%a:%h' -- "${running_file}" 2>/dev/null || true)" = "0:0:600:1" ] \
      || return 1
    /usr/bin/rm -f -- "${running_file}" || return 1
    /usr/bin/sync -f "${WORK_DIR}" || return 1
  fi
  /usr/bin/rm -f -- "${commit_marker}" || return 1
  /usr/bin/sync -f "${WORK_DIR}"
}

remove_committed_result_for_retry() (
  set -euo pipefail
  local result_file="$1" result_fd="" path_metadata descriptor_metadata
  local result_process_id="${BASHPID}"

  if [ ! -e "${result_file}" ] && [ ! -L "${result_file}" ]; then
    return 0
  fi
  [ -f "${result_file}" ] && [ ! -L "${result_file}" ] \
    && [[ "$(/usr/bin/stat -c '%U:%a:%h:%s' -- "${result_file}" 2>/dev/null || true)" =~ ^www-data:600:1:([0-9]+)$ ]] \
    && [ "${BASH_REMATCH[1]}" -ge 2 ] && [ "${BASH_REMATCH[1]}" -le "${MAX_RESULT_BYTES}" ] \
    || return 1
  exec {result_fd}<>"${result_file}" || return 1
  /usr/bin/flock -x "${result_fd}" || return 1
  if [ ! -e "${result_file}" ] && [ ! -L "${result_file}" ]; then
    return 0
  fi
  path_metadata="$(/usr/bin/stat -Lc '%d:%i:%h' -- "${result_file}" 2>/dev/null || true)"
  descriptor_metadata="$(/usr/bin/stat -Lc '%d:%i:%h' -- \
    "/proc/${result_process_id}/fd/${result_fd}" 2>/dev/null || true)"
  [ -n "${path_metadata}" ] && [ "${path_metadata}" = "${descriptor_metadata}" ] \
    && [[ "${path_metadata}" == *:1 ]] || return 1
  /usr/bin/rm -f -- "${result_file}" || return 1
  /usr/bin/sync -f "${REQUEST_DIR}" || return 1
)

protect_committed_claim_on_error() {
  local request_id="$1" reason="$2" commit_marker="${WORK_DIR}/${request_id}.committed"

  if [ -e "${commit_marker}" ] || [ -L "${commit_marker}" ]; then
    /usr/bin/systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service >/dev/null 2>&1 || true
    security_log crit \
      "wallboard_live_key_committed_recovery_blocked request_id=${request_id} reason=${reason} services=stopped"
    return 0
  fi
  return 1
}

write_previous_key() (
  set -euo pipefail
  local path="$1" key="$2" temporary

  [ ! -e "${path}" ] && [ ! -L "${path}" ] || return 1
  temporary="$(/usr/bin/mktemp "${WORK_DIR}/.previous-key.XXXXXX")" || return 1
  trap '/usr/bin/rm -f -- "${temporary:-}" 2>/dev/null || true' EXIT
  /usr/bin/chown root:root "${temporary}" || return 1
  /usr/bin/chmod 0600 "${temporary}" || return 1
  printf '%s' "${key}" > "${temporary}" || return 1
  /usr/bin/sync -f "${temporary}" || return 1
  /usr/bin/mv -T -- "${temporary}" "${path}" || return 1
  temporary=""
  /usr/bin/sync -f "${WORK_DIR}" || return 1
  safe_previous_key_file "${path}" || return 1
  trap - EXIT
)

write_result() (
  set -euo pipefail
  local request_id="$1" state="$2" exit_code="$3" output="$4"
  local result_deadline="${5:-0}" result_file="${REQUEST_DIR}/${request_id}.result"
  local commit_marker="${WORK_DIR}/${request_id}.committed"
  local temporary="" result_fd="" published=0 completed=0 result_process_id="${BASHPID}"
  local initial_state="${state}" initial_exit_code="${exit_code}" initial_output="${output}"

  if [ "${state}" = "succeeded" ]; then
    initial_state=failed
    initial_exit_code=1
    initial_output='Stream-key rotation commit is being finalized.'
  fi

  write_locked_result() {
    local locked_state="$1" locked_exit_code="$2" locked_output="$3"
    local descriptor_path="/proc/${result_process_id}/fd/${result_fd}"

    : > "${descriptor_path}" || return 1
    /usr/bin/jq -n \
      --arg state "${locked_state}" \
      --argjson exit_code "${locked_exit_code}" \
      --arg output "${locked_output}" \
      --arg finished_at "$(/usr/bin/date -u +%Y-%m-%dT%H:%M:%SZ)" \
      '{state: $state, exit_code: $exit_code, output: $output, finished_at: $finished_at}' \
      > "${descriptor_path}" || return 1
    /usr/bin/sync -f "${descriptor_path}" || return 1
  }

  invalidate_published_result() {
    local descriptor_path

    [ "${published}" = "1" ] && [[ "${result_fd}" =~ ^[0-9]+$ ]] || return 0
    descriptor_path="/proc/${result_process_id}/fd/${result_fd}"
    # Readers take a shared lock before inspecting this inode. Replace any
    # uncommitted content while the exclusive lock is still held.
    : > "${descriptor_path}" || true
    /usr/bin/jq -n \
      --arg state failed \
      --argjson exit_code 124 \
      --arg output 'Stream-key rotation missed its result publication deadline.' \
      --arg finished_at "$(/usr/bin/date -u +%Y-%m-%dT%H:%M:%SZ)" \
      '{state: $state, exit_code: $exit_code, output: $output, finished_at: $finished_at}' \
      > "${descriptor_path}" 2>/dev/null || : > "${descriptor_path}"
    /usr/bin/sync -f "${descriptor_path}" >/dev/null 2>&1 || true
    if /usr/bin/rm -f -- "${result_file}" 2>/dev/null; then
      /usr/bin/sync -f "${REQUEST_DIR}" >/dev/null 2>&1 || true
      published=0
    fi
  }

  cleanup_result_publication() {
    local status="$?"

    trap - EXIT INT TERM
    set +e
    if [ "${completed}" != "1" ] && ! safe_commit_marker "${commit_marker}"; then
      invalidate_published_result
    fi
    if [[ "${result_fd}" =~ ^[0-9]+$ ]]; then
      /usr/bin/flock -u "${result_fd}" >/dev/null 2>&1 || true
      exec {result_fd}>&-
    fi
    /usr/bin/rm -f -- "${temporary:-}" 2>/dev/null || true
    exit "${status}"
  }

  trap cleanup_result_publication EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM

  if [ "${result_deadline}" -gt 0 ] \
    && [ "$(/usr/bin/date +%s)" -ge "${result_deadline}" ]; then
    return 1
  fi

  temporary="$(/usr/bin/mktemp "${WORK_DIR}/.result.XXXXXX")" || return 1
  if ! /usr/bin/jq -n \
    --arg state "${initial_state}" \
    --argjson exit_code "${initial_exit_code}" \
    --arg output "${initial_output}" \
    --arg finished_at "$(/usr/bin/date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{state: $state, exit_code: $exit_code, output: $output, finished_at: $finished_at}' \
    > "${temporary}"; then
    /usr/bin/rm -f -- "${temporary}"
    return 1
  fi
  /usr/bin/chown www-data:root "${temporary}" || return 1
  /usr/bin/chmod 0600 "${temporary}" || return 1
  exec {result_fd}<>"${temporary}" || return 1
  /usr/bin/flock -x "${result_fd}" || return 1
  /usr/bin/sync -f "${temporary}" || return 1
  if [ "${result_deadline}" -gt 0 ] \
    && [ "$(/usr/bin/date +%s)" -ge "${result_deadline}" ]; then
    return 1
  fi
  if [ -e "${result_file}" ] || [ -L "${result_file}" ]; then
    [ ! -d "${result_file}" ] || { /usr/bin/rm -f -- "${temporary}"; return 1; }
    /usr/bin/rm -f -- "${result_file}" || return 1
  fi
  /usr/bin/mv -T -- "${temporary}" "${result_file}" || return 1
  temporary=""
  published=1
  /usr/bin/sync -f "${REQUEST_DIR}" || return 1
  if [ "${result_deadline}" -gt 0 ] \
    && [ "$(/usr/bin/date +%s)" -ge "${result_deadline}" ]; then
    return 1
  fi
  if [ "${state}" = "succeeded" ]; then
    # From this durable marker onward the new key is irrevocably committed.
    # Recovery may finish publication/cleanup, but must never roll it back.
    write_commit_marker "${commit_marker}" || return 1
    write_locked_result succeeded 0 "${output}" || return 2
    if [ "${result_deadline}" -gt 0 ] \
      && [ "$(/usr/bin/date +%s)" -ge "${result_deadline}" ]; then
      write_locked_result failed 1 \
        'The new stream key is committed, but its result missed the response deadline.' || return 2
      return 2
    fi
  fi
  completed=1
  /usr/bin/flock -u "${result_fd}" >/dev/null 2>&1 || true
  exec {result_fd}>&-
  result_fd=""
  trap - EXIT INT TERM
)

finish_request() {
  local running_file="$1" previous_key_file="$2" request_id="$3"
  local state="$4" exit_code="$5" output="$6" retain_previous="${7:-0}"
  local result_deadline="${8:-0}" result_status=0
  local commit_marker="${WORK_DIR}/${request_id}.committed"

  write_result "${request_id}" "${state}" "${exit_code}" "${output}" "${result_deadline}" \
    || result_status="$?"
  if [ "${state}" = "succeeded" ] && safe_commit_marker "${commit_marker}"; then
    if [ "${result_status}" = "0" ]; then
      cleanup_committed_request "${running_file}" "${previous_key_file}" "${commit_marker}" \
        || security_log crit \
          "wallboard_live_key_request_cleanup_failed request_id=${request_id} state=committed"
      return 0
    fi
    # The durable marker makes rollback forbidden even if success publication
    # was interrupted. A later worker will reconcile this committed request.
    return 2
  fi
  [ "${result_status}" = "0" ] || return 1
  /usr/bin/rm -f -- "${running_file}" \
    || security_log crit "wallboard_live_key_request_cleanup_failed request_id=${request_id} target=request"
  if [ "${retain_previous}" != "1" ]; then
    /usr/bin/rm -f -- "${previous_key_file}" \
      || security_log crit "wallboard_live_key_request_cleanup_failed request_id=${request_id} target=recovery"
  fi
  return 0
}

reject_invalid_claim() {
  local running_file="$1" request_id="$2" reason="$3"
  local previous_key_file="${WORK_DIR}/${request_id}.previous-key"

  if protect_committed_claim_on_error "${request_id}" "${reason}"; then
    return
  fi
  security_log warning \
    "wallboard_live_key_request_rejected request_id=${request_id} reason=${reason}"
  if [ -e "${previous_key_file}" ] || [ -L "${previous_key_file}" ]; then
    /usr/bin/systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service >/dev/null 2>&1 || true
    security_log crit \
      "wallboard_live_key_rotation_recovery_required request_id=${request_id} reason=${reason} services=stopped"
    finish_request "${running_file}" "${previous_key_file}" "${request_id}" \
      failed 1 'The invalid request has protected recovery state and requires server recovery.' 1
  else
    finish_request "${running_file}" "${previous_key_file}" "${request_id}" \
      failed 2 'Invalid stream-key rotation request.'
  fi
}

refresh_with_inherited_lock() {
  /usr/bin/timeout --signal=TERM --kill-after=10s "${ROLLBACK_REFRESH_TIMEOUT_SECONDS}s" \
    /usr/bin/env DIS_OPERATION_LOCK_HELD=1 DIS_OPERATION_LOCK_FD="${DIS_OPERATION_LOCK_FD}" \
      "${REFRESH_PATH}" >/dev/null 2>&1
}

refresh_before_deadline() {
  local deadline_epoch="$1" now_epoch budget

  now_epoch="$(/usr/bin/date +%s)"
  budget="$((deadline_epoch - now_epoch))"
  [ "${budget}" -ge 1 ] || return 124
  /usr/bin/timeout --signal=TERM --kill-after=10s "${budget}s" \
    /usr/bin/env DIS_OPERATION_LOCK_HELD=1 DIS_OPERATION_LOCK_FD="${DIS_OPERATION_LOCK_FD}" \
      "${REFRESH_PATH}" >/dev/null 2>&1
}

restore_previous_managed_key() {
  local env_file="$1" previous_key="$2" expected_key_sha256="$3" restored_key

  set_managed_env_secret "${env_file}" WALLBOARD_LIVE_STREAM_STREAM_KEY "${previous_key}" \
    || return 1
  restored_key="$(read_managed_stream_key "${env_file}")" || return 1
  [ "$(stream_key_sha256 "${restored_key}")" = "${expected_key_sha256}" ]
}

restore_previous_key_and_runtime() {
  local env_file="$1" previous_key="$2" expected_key_sha256="$3"

  restore_previous_managed_key "${env_file}" "${previous_key}" "${expected_key_sha256}" \
    && refresh_with_inherited_lock
}

process_claimed_request() {
  local running_file="$1" request_id metadata operation stream_key expected_key_sha256 actor_id
  local created_at expires_at created_epoch expires_epoch now_epoch lifetime remaining env_file
  local activation_deadline success_result_deadline activation_remaining time_invalid=0
  local current_key current_key_sha256 new_key_sha256 previous_key_file previous_key=""
  local commit_marker result_file previous_key_available=0 rollback_ok failure_reason result_status

  request_id="$(/usr/bin/basename "${running_file}" .json)"
  [[ "${request_id}" =~ ^[a-f0-9]{32}$ ]] || { /usr/bin/rm -f -- "${running_file}"; return; }
  metadata="$(/usr/bin/stat -c '%u:%g:%a:%h:%s' -- "${running_file}" 2>/dev/null || true)"
  if [[ ! "${metadata}" =~ ^0:0:600:1:([0-9]+)$ ]] \
    || [ "${BASH_REMATCH[1]:-0}" -lt 1 ] || [ "${BASH_REMATCH[1]:-0}" -gt "${MAX_REQUEST_BYTES}" ]; then
    protect_committed_claim_on_error "${request_id}" unsafe_request_metadata \
      || /usr/bin/rm -f -- "${running_file}"
    return
  fi

  if ! /usr/bin/jq -e '
    type == "object"
    and .operation == "rotate"
    and (.stream_key | type == "string" and test("^[A-Za-z0-9_-]{64}$"))
    and (.expected_key_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
    and (.actor_id | type == "string" and test("^[0-9A-HJKMNP-TV-Z]{26}$"; "i"))
    and (.created_at | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
    and (.expires_at | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
    and ((keys_unsorted - ["operation", "stream_key", "expected_key_sha256", "actor_id", "created_at", "expires_at"]) | length == 0)
    and (keys_unsorted | length == 6)
  ' "${running_file}" >/dev/null; then
    reject_invalid_claim "${running_file}" "${request_id}" invalid_schema
    return
  fi

  operation="$(/usr/bin/jq -r '.operation' "${running_file}")"
  stream_key="$(/usr/bin/jq -r '.stream_key' "${running_file}")"
  expected_key_sha256="$(/usr/bin/jq -r '.expected_key_sha256' "${running_file}")"
  actor_id="$(/usr/bin/jq -r '.actor_id' "${running_file}")"
  created_at="$(/usr/bin/jq -r '.created_at' "${running_file}")"
  expires_at="$(/usr/bin/jq -r '.expires_at' "${running_file}")"
  [ "${operation}" = "rotate" ] && [[ "${stream_key}" =~ ^[A-Za-z0-9_-]{64}$ ]] \
    && valid_stream_key "${stream_key}" \
    && [[ "${expected_key_sha256}" =~ ^[a-f0-9]{64}$ ]] \
    && [[ "${actor_id^^}" =~ ^[0-9A-HJKMNP-TV-Z]{26}$ ]] \
    || { reject_invalid_claim "${running_file}" "${request_id}" invalid_values; return; }

  created_epoch="$(/usr/bin/date -u -d "${created_at}" +%s 2>/dev/null || true)"
  expires_epoch="$(/usr/bin/date -u -d "${expires_at}" +%s 2>/dev/null || true)"
  [[ "${created_epoch}" =~ ^[0-9]+$ ]] && [[ "${expires_epoch}" =~ ^[0-9]+$ ]] \
    || { reject_invalid_claim "${running_file}" "${request_id}" invalid_time; return; }
  [ "$(/usr/bin/date -u -d "@${created_epoch}" +%Y-%m-%dT%H:%M:%SZ)" = "${created_at}" ] \
    && [ "$(/usr/bin/date -u -d "@${expires_epoch}" +%Y-%m-%dT%H:%M:%SZ)" = "${expires_at}" ] \
    || { reject_invalid_claim "${running_file}" "${request_id}" invalid_time; return; }
  now_epoch="$(/usr/bin/date +%s)"
  lifetime="$((expires_epoch - created_epoch))"
  remaining="$((expires_epoch - now_epoch))"
  activation_deadline="$((created_epoch + ACTIVATION_DEADLINE_SECONDS))"
  success_result_deadline="$((created_epoch + SUCCESS_RESULT_DEADLINE_SECONDS))"
  activation_remaining="$((activation_deadline - now_epoch))"
  if [ "$((now_epoch - created_epoch))" -lt -60 ] \
    || [ "${lifetime}" -lt 1 ] || [ "${lifetime}" -gt "${MAX_REQUEST_LIFETIME_SECONDS}" ] \
    || [ "${remaining}" -lt "${MINIMUM_REMAINING_SECONDS}" ] \
    || [ "${activation_remaining}" -lt "${MINIMUM_REMAINING_SECONDS}" ]; then
    time_invalid=1
  fi

  env_file="$(managed_env_path)" \
    || { reject_invalid_claim "${running_file}" "${request_id}" unsafe_environment; return; }
  current_key="$(read_managed_stream_key "${env_file}")" \
    || { reject_invalid_claim "${running_file}" "${request_id}" invalid_current_key; return; }
  current_key_sha256="$(stream_key_sha256 "${current_key}")"
  new_key_sha256="$(stream_key_sha256 "${stream_key}")"
  [ "${new_key_sha256}" != "${expected_key_sha256}" ] \
    || { reject_invalid_claim "${running_file}" "${request_id}" unchanged_key; return; }
  previous_key_file="${WORK_DIR}/${request_id}.previous-key"
  commit_marker="${WORK_DIR}/${request_id}.committed"
  result_file="${REQUEST_DIR}/${request_id}.result"

  if [ -e "${previous_key_file}" ] || [ -L "${previous_key_file}" ]; then
    safe_previous_key_file "${previous_key_file}" \
      || { reject_invalid_claim "${running_file}" "${request_id}" unsafe_recovery_key; return; }
    previous_key="$(< "${previous_key_file}")"
    valid_stream_key "${previous_key}" \
      && [ "$(stream_key_sha256 "${previous_key}")" = "${expected_key_sha256}" ] \
      || { reject_invalid_claim "${running_file}" "${request_id}" invalid_recovery_key; return; }
    previous_key_available=1
  fi

  if [ -e "${commit_marker}" ] || [ -L "${commit_marker}" ]; then
    if ! safe_commit_marker "${commit_marker}" \
      || [ "${current_key_sha256}" != "${new_key_sha256}" ]; then
      /usr/bin/systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service >/dev/null 2>&1 || true
      security_log crit \
        "wallboard_live_key_committed_recovery_blocked request_id=${request_id} actor_id=${actor_id} reason=state_mismatch services=stopped"
      unset stream_key current_key previous_key
      return
    fi

    # The marker is the irrevocable commit point. Never revisit timeout/CAS or
    # rollback branches after it exists. Give an in-flight reader until request
    # expiry; afterwards retire any stale result before durable cleanup.
    if [ -e "${result_file}" ] || [ -L "${result_file}" ]; then
      if [ "${now_epoch}" -lt "${expires_epoch}" ]; then
        security_log notice \
          "wallboard_live_key_committed_recovery_deferred request_id=${request_id} actor_id=${actor_id} reason=result_in_flight"
        unset stream_key current_key previous_key
        return
      fi
      if remove_committed_result_for_retry "${result_file}"; then
        security_log warning \
          "wallboard_live_key_committed_recovery_progress request_id=${request_id} actor_id=${actor_id} state=stale_result_retired"
      else
        /usr/bin/systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service >/dev/null 2>&1 || true
        security_log crit \
          "wallboard_live_key_committed_recovery_blocked request_id=${request_id} actor_id=${actor_id} reason=unsafe_result services=stopped"
        unset stream_key current_key previous_key
        return
      fi
    fi

    if cleanup_committed_request "${running_file}" "${previous_key_file}" "${commit_marker}"; then
      security_log notice \
        "wallboard_live_key_committed_recovery_completed request_id=${request_id} actor_id=${actor_id} state=committed"
    else
      security_log crit \
        "wallboard_live_key_request_cleanup_failed request_id=${request_id} state=committed_recovery"
    fi
    unset stream_key current_key previous_key
    return
  fi

  if [ "${time_invalid}" = "1" ]; then
    security_log warning \
      "wallboard_live_key_request_rejected request_id=${request_id} reason=expired actor_id=${actor_id}"
    if [ "${current_key_sha256}" = "${new_key_sha256}" ] \
      && [ "${previous_key_available}" = "1" ]; then
      rollback_ok=0
      restore_previous_key_and_runtime "${env_file}" "${previous_key}" "${expected_key_sha256}" \
        && rollback_ok=1
      if [ "${rollback_ok}" = "1" ]; then
        finish_request "${running_file}" "${previous_key_file}" "${request_id}" failed 124 \
          'Stream-key rotation expired and the previous key was restored.'
        security_log warning \
          "wallboard_live_key_rotation_completed request_id=${request_id} actor_id=${actor_id} state=failed reason=expired rollback=restored"
      else
        /usr/bin/systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service >/dev/null 2>&1 || true
        finish_request "${running_file}" "${previous_key_file}" "${request_id}" failed 1 \
          'Expired stream-key rotation requires protected server recovery.' 1
        security_log crit \
          "wallboard_live_key_rotation_completed request_id=${request_id} actor_id=${actor_id} state=failed reason=expired rollback=manual_recovery_required services=stopped"
      fi
    elif [ "${current_key_sha256}" = "${expected_key_sha256}" ]; then
      if [ "${previous_key_available}" = "1" ]; then
        # A prior process may have restored .env and died before restoring the
        # generated MediaMTX credentials/runtime. Verify that second half before
        # removing the only durable recovery copy of the old key.
        rollback_ok=0
        refresh_with_inherited_lock && rollback_ok=1
        if [ "${rollback_ok}" = "1" ]; then
          finish_request "${running_file}" "${previous_key_file}" "${request_id}" failed 124 \
            'Stream-key rotation expired and the previous runtime was verified.'
          security_log warning \
            "wallboard_live_key_rotation_completed request_id=${request_id} actor_id=${actor_id} state=failed reason=expired rollback=verified"
        else
          /usr/bin/systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service >/dev/null 2>&1 || true
          finish_request "${running_file}" "${previous_key_file}" "${request_id}" failed 1 \
            'Expired stream-key rotation requires protected server recovery.' 1
          security_log crit \
            "wallboard_live_key_rotation_completed request_id=${request_id} actor_id=${actor_id} state=failed reason=expired rollback=manual_recovery_required services=stopped"
        fi
      else
        finish_request "${running_file}" "${previous_key_file}" "${request_id}" \
          failed 124 'Stream-key rotation request expired before execution.'
      fi
    else
      security_log warning \
        "wallboard_live_key_rotation_conflict request_id=${request_id} actor_id=${actor_id}"
      finish_request "${running_file}" "${previous_key_file}" "${request_id}" failed 3 \
        'The active stream key changed before this request was processed.'
    fi
    unset stream_key current_key previous_key
    return
  fi

  if [ "${current_key_sha256}" = "${expected_key_sha256}" ]; then
    if [ "${previous_key_available}" != "1" ]; then
      write_previous_key "${previous_key_file}" "${current_key}" \
        || { reject_invalid_claim "${running_file}" "${request_id}" recovery_key_unavailable; return; }
      previous_key="${current_key}"
      previous_key_available=1
    fi
    if ! set_managed_env_secret "${env_file}" WALLBOARD_LIVE_STREAM_STREAM_KEY "${stream_key}"; then
      rollback_ok=0
      if current_key="$(read_managed_stream_key "${env_file}")"; then
        current_key_sha256="$(stream_key_sha256 "${current_key}")"
        if [ "${current_key_sha256}" = "${expected_key_sha256}" ]; then
          # The recovery file may predate a crash after .env rollback but
          # before the runtime credentials were restored. Verify runtime too.
          refresh_with_inherited_lock && rollback_ok=1
        elif [ "${current_key_sha256}" = "${new_key_sha256}" ] \
          && restore_previous_key_and_runtime "${env_file}" "${previous_key}" "${expected_key_sha256}"; then
          rollback_ok=1
        fi
      fi
      if [ "${rollback_ok}" = "1" ]; then
        finish_request "${running_file}" "${previous_key_file}" "${request_id}" failed 1 \
          'The managed stream-key update failed and the previous key remains active.'
      else
        /usr/bin/systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service >/dev/null 2>&1 || true
        finish_request "${running_file}" "${previous_key_file}" "${request_id}" failed 1 \
          'The managed stream-key update requires protected server recovery.' 1
      fi
      security_log err \
        "wallboard_live_key_rotation_completed request_id=${request_id} actor_id=${actor_id} state=failed reason=environment_update rollback=${rollback_ok}"
      unset stream_key current_key previous_key
      return
    fi
  elif [ "${current_key_sha256}" = "${new_key_sha256}" ] \
    && [ "${previous_key_available}" = "1" ]; then
    : # Recover a claimed request only while it still has the full activation budget.
  else
    security_log warning \
      "wallboard_live_key_rotation_conflict request_id=${request_id} actor_id=${actor_id}"
    finish_request "${running_file}" "${previous_key_file}" "${request_id}" failed 3 \
      'The active stream key changed before this request was processed.'
    return
  fi

  if ! current_key="$(read_managed_stream_key "${env_file}")" \
    || [ "$(stream_key_sha256 "${current_key}")" != "${new_key_sha256}" ]; then
    rollback_ok=0
    restore_previous_key_and_runtime "${env_file}" "${previous_key}" "${expected_key_sha256}" \
      && rollback_ok=1
    if [ "${rollback_ok}" = "1" ]; then
      finish_request "${running_file}" "${previous_key_file}" "${request_id}" failed 1 \
        'The managed stream-key update could not be verified and the previous key was restored.'
    else
      /usr/bin/systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service >/dev/null 2>&1 || true
      finish_request "${running_file}" "${previous_key_file}" "${request_id}" failed 1 \
        'The managed stream-key update requires protected server recovery.' 1
    fi
    security_log err \
      "wallboard_live_key_rotation_completed request_id=${request_id} actor_id=${actor_id} state=failed reason=environment_verification rollback=${rollback_ok}"
    return
  fi

  security_log notice \
    "wallboard_live_key_rotation_started request_id=${request_id} actor_id=${actor_id}"
  if refresh_before_deadline "${activation_deadline}"; then
    result_status=0
    finish_request "${running_file}" "${previous_key_file}" "${request_id}" succeeded 0 \
      'The new stream key is active.' 0 "${success_result_deadline}" || result_status="$?"
    if [ "${result_status}" = "0" ]; then
      security_log notice \
        "wallboard_live_key_rotation_completed request_id=${request_id} actor_id=${actor_id} state=succeeded"
      unset stream_key current_key previous_key
      return
    fi
    if [ "${result_status}" = "2" ] && safe_commit_marker "${commit_marker}"; then
      security_log crit \
        "wallboard_live_key_rotation_committed request_id=${request_id} actor_id=${actor_id} state=committed reason=result_publication_incomplete"
      unset stream_key current_key previous_key
      return
    fi
    failure_reason=result_publication_or_deadline
  else
    failure_reason=activation
  fi

  # Only pre-commit activation/publication failures reach this branch. Once the
  # durable marker exists, the result_status=2 branch above returns without a
  # rollback and the key remains retrievable through the portal.
  rollback_ok=0
  restore_previous_key_and_runtime "${env_file}" "${previous_key}" "${expected_key_sha256}" \
    && rollback_ok=1
  if [ "${rollback_ok}" = "1" ]; then
    finish_request "${running_file}" "${previous_key_file}" "${request_id}" failed 1 \
      'Stream-key activation failed; the previous managed key was restored.'
    security_log err \
      "wallboard_live_key_rotation_completed request_id=${request_id} actor_id=${actor_id} state=failed reason=${failure_reason} rollback=restored"
  else
    /usr/bin/systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service >/dev/null 2>&1 || true
    finish_request "${running_file}" "${previous_key_file}" "${request_id}" failed 1 \
      'Stream-key activation failed and requires protected server recovery.' 1
    security_log crit \
      "wallboard_live_key_rotation_completed request_id=${request_id} actor_id=${actor_id} state=failed reason=${failure_reason} rollback=manual_recovery_required services=stopped"
  fi
  unset stream_key current_key previous_key
}

claim_pending_request() {
  local pending="$1" request_id running metadata

  request_id="$(/usr/bin/basename "${pending}" .pending)"
  [[ "${request_id}" =~ ^[a-f0-9]{32}$ ]] || { /usr/bin/rm -f -- "${pending}"; return 1; }
  running="${WORK_DIR}/${request_id}.json"
  [ ! -e "${running}" ] && [ ! -L "${running}" ] || return 1
  /usr/bin/mv -T -- "${pending}" "${running}" 2>/dev/null || return 1
  if [ -L "${running}" ] || [ ! -f "${running}" ]; then
    /usr/bin/rm -f -- "${running}"
    return 1
  fi
  metadata="$(/usr/bin/stat -c '%U:%a:%h:%s' -- "${running}" 2>/dev/null || true)"
  if [[ ! "${metadata}" =~ ^www-data:600:1:([0-9]+)$ ]] \
    || [ "${BASH_REMATCH[1]:-0}" -lt 1 ] || [ "${BASH_REMATCH[1]:-0}" -gt "${MAX_REQUEST_BYTES}" ]; then
    /usr/bin/rm -f -- "${running}"
    security_log warning \
      "wallboard_live_key_request_rejected request_id=${request_id} reason=unsafe_metadata"
    return 1
  fi
  /usr/bin/chown root:root "${running}"
  /usr/bin/chmod 0600 "${running}"
  process_claimed_request "${running}"
}

main() {
  local running pending inherited_fd_path

  require_root
  [ "$#" -eq 0 ] || fail 'The wallboard live key request worker takes no arguments.'
  root_controlled_bundle_source_is_safe "${SCRIPT_DIR}/lib/common.sh" \
    || fail 'The wallboard live key request worker library is unsafe.'
  root_owned_runtime_file_is_safe "${REFRESH_PATH}" 700 \
    || fail 'The wallboard live refresh helper is unsafe.'
  command -v /usr/bin/jq >/dev/null 2>&1 || fail 'jq is required for stream-key rotation requests.'
  request_layout_is_safe || fail 'The wallboard live key request layout is unsafe.'
  /usr/bin/install -d -m 0700 -o root -g root "${LOCK_DIR}"
  exec 9>"${LOCK_FILE}"
  /usr/bin/chown root:root "${LOCK_FILE}"
  /usr/bin/chmod 0600 "${LOCK_FILE}"
  /usr/bin/flock -n 9 || exit 0

  /usr/bin/install -d -m 0755 -o root -g root /run/lock
  exec {DIS_OPERATION_LOCK_FD}>/run/lock/dis-exclusive-operation.lock
  /usr/bin/chown root:root /run/lock/dis-exclusive-operation.lock
  /usr/bin/chmod 0600 /run/lock/dis-exclusive-operation.lock
  /usr/bin/flock -n "${DIS_OPERATION_LOCK_FD}" || exit 0
  inherited_fd_path="$(/usr/bin/readlink -f "/proc/$$/fd/${DIS_OPERATION_LOCK_FD}" 2>/dev/null || true)"
  [ "${inherited_fd_path}" = '/run/lock/dis-exclusive-operation.lock' ] \
    || fail 'The inherited DIS operation lock descriptor is unsafe.'
  DIS_OPERATION_LOCK_HELD=1
  export DIS_OPERATION_LOCK_HELD DIS_OPERATION_LOCK_FD

  shopt -s nullglob
  for running in "${WORK_DIR}"/*.json; do
    process_claimed_request "${running}"
    return
  done
  for pending in "${REQUEST_DIR}"/*.pending; do
    claim_pending_request "${pending}" || true
    return
  done
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
