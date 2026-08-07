#!/usr/bin/env bash
set -euo pipefail
set +x

# Root-only transaction helper for a claimed Admin-portal configuration request.
# The caller owns the global DIS operation lock for the complete invocation.
readonly MAX_REQUEST_BYTES=16384
readonly MAX_ENV_BYTES=2097152
readonly LOCK_PATH=/run/lock/dis-exclusive-operation.lock
readonly REFRESH_PATH=/usr/local/sbin/dis-wallboard-live-refresh
readonly ACTIVATION_OFFSET=50
readonly SUCCESS_OFFSET=75
readonly ROLLBACK_OFFSET=95
readonly TERMINAL_OFFSET=100

REQUEST_DIR=""
WORK_DIR=""
ENV_FILE=""
REQUEST_ID=""
RUNNING_FILE=""
REQUEST_FD=""
PREVIOUS_ENV=""
COMMIT_MARKER=""
RECOVERY_MARKER=""
RESULT_FILE=""

security_log() {
  /usr/bin/logger -p "authpriv.$1" -t dis-security -- "$2" 2>/dev/null || true
}

stop_live_services() {
  /usr/bin/timeout --signal=TERM --kill-after=2s 5s \
    /usr/bin/systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service \
    >/dev/null 2>&1 || true
}

root_controlled_file() {
  local path="$1" metadata mode current
  [ -f "${path}" ] && [ ! -L "${path}" ] || return 1
  metadata="$(/usr/bin/stat -c '%u:%a:%h' -- "${path}" 2>/dev/null || true)"
  [[ "${metadata}" =~ ^0:([0-7]+):1$ ]] || return 1
  mode="${BASH_REMATCH[1]}"
  (( (8#${mode} & 8#022) == 0 )) || return 1
  current="${path%/*}"
  while [ -n "${current}" ]; do
    metadata="$(/usr/bin/stat -c '%u:%a' -- "${current}" 2>/dev/null || true)"
    [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1
    mode="${BASH_REMATCH[1]}"
    (( (8#${mode} & 8#022) == 0 )) || return 1
    [ "${current}" = / ] && break
    current="${current%/*}"
    [ -n "${current}" ] || current=/
  done
}

layout_is_safe() {
  local request_metadata work_metadata request_device work_device acl
  request_metadata="$(/usr/bin/stat -c '%u:%g:%a' -- "${REQUEST_DIR}" 2>/dev/null || true)"
  work_metadata="$(/usr/bin/stat -c '%u:%g:%a' -- "${WORK_DIR}" 2>/dev/null || true)"
  [ "${request_metadata}" = 0:0:1730 ] && [ "${work_metadata}" = 0:0:700 ] || return 1
  request_device="$(/usr/bin/stat -c '%d' -- "${REQUEST_DIR}" 2>/dev/null || true)"
  work_device="$(/usr/bin/stat -c '%d' -- "${WORK_DIR}" 2>/dev/null || true)"
  [ -n "${request_device}" ] && [ "${request_device}" = "${work_device}" ] || return 1
  acl="$(/usr/bin/getfacl -cp -- "${REQUEST_DIR}" 2>/dev/null || true)"
  /usr/bin/grep -Fxq 'user:www-data:-wx' <<< "${acl}" \
    && /usr/bin/grep -Fxq 'user::rwx' <<< "${acl}" \
    && /usr/bin/grep -Fxq 'group::-wx' <<< "${acl}" \
    && /usr/bin/grep -Fxq 'mask::-wx' <<< "${acl}" \
    && /usr/bin/grep -Fxq 'other::---' <<< "${acl}" \
    && [ "$(/usr/bin/grep '^user:[^:]' <<< "${acl}" || true)" = 'user:www-data:-wx' ] \
    && [ -z "$(/usr/bin/grep '^group:[^:]' <<< "${acl}" || true)" ] \
    && ! /usr/bin/grep -q '^default:' <<< "${acl}"
}

claim_is_same_inode() {
  local path_identity descriptor_identity
  path_identity="$(/usr/bin/stat -Lc '%d:%i:%h' -- "${RUNNING_FILE}" 2>/dev/null || true)"
  descriptor_identity="$(/usr/bin/stat -Lc '%d:%i:%h' -- "/proc/$$/fd/${REQUEST_FD}" 2>/dev/null || true)"
  [ -n "${path_identity}" ] && [ "${path_identity}" = "${descriptor_identity}" ] \
    && [[ "${path_identity}" == *:1 ]]
}

schema_is_valid() {
  /usr/bin/jq -e '
    type == "object" and .operation == "configure"
    and (.enabled | type == "boolean")
    and (.public_host | type == "string" and length <= 253 and test("^[ -~]*$"))
    and (.bind_address | type == "string" and length <= 15 and test("^[ -~]*$"))
    and (.rtmps_port | type == "number" and floor == .)
    and (.tls_certificate_path | type == "string" and length <= 4096 and test("^[ -~]*$"))
    and (.tls_private_key_path | type == "string" and length <= 4096 and test("^[ -~]*$"))
    and (.expected_config_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
    and (.actor_id | type == "string" and test("^[0-9A-HJKMNP-TV-Z]{26}$"; "i"))
    and (.created_at | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
    and (.expires_at | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
    and ((keys_unsorted - ["operation","enabled","public_host","bind_address","rtmps_port","tls_certificate_path","tls_private_key_path","expected_config_sha256","actor_id","created_at","expires_at"]) | length == 0)
    and (keys_unsorted | length == 11)
  ' "/proc/$$/fd/${REQUEST_FD}" >/dev/null
}

valid_stream_key() {
  local key="$1" first
  [[ "${key}" =~ ^[A-Za-z0-9._~-]{32,79}$ ]] || return 1
  first="${key:0:1}"
  [ -n "${key//${first}/}" ]
}

valid_host() {
  local host="$1" part
  local -a parts
  [ -n "${host}" ] && [ "${#host}" -le 253 ] && [[ "${host}" != *..* ]] \
    && [[ "${host}" != .* ]] && [[ "${host}" != *. ]] || return 1
  if [[ "${host}" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
    IFS=. read -r -a parts <<< "${host}"
    for part in "${parts[@]}"; do
      [[ "${part}" =~ ^[0-9]{1,3}$ ]] && { [ "${#part}" = 1 ] || [ "${part:0:1}" != 0 ]; } \
        && (( 10#${part} <= 255 )) || return 1
    done
    (( 10#${parts[0]} > 0 && 10#${parts[0]} < 224 )) && [ "${host}" != 255.255.255.255 ]
    return
  fi
  IFS=. read -r -a parts <<< "${host}"
  for part in "${parts[@]}"; do
    [ "${#part}" -ge 1 ] && [ "${#part}" -le 63 ] \
      && [[ "${part}" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$ ]] || return 1
  done
}

valid_bind() {
  local address="$1" part
  local -a parts
  [[ "${address}" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  IFS=. read -r -a parts <<< "${address}"
  [ "${#parts[@]}" = 4 ] || return 1
  for part in "${parts[@]}"; do
    [[ "${part}" =~ ^[0-9]{1,3}$ ]] && { [ "${#part}" = 1 ] || [ "${part:0:1}" != 0 ]; } \
      && (( 10#${part} <= 255 )) || return 1
  done
  [ "${address}" = 0.0.0.0 ] \
    || { (( 10#${parts[0]} > 0 && 10#${parts[0]} < 224 )) && [ "${address}" != 255.255.255.255 ]; }
}

valid_tls_path() {
  local path="$1"
  [ -n "${path}" ] && [[ "${path}" =~ ^/[A-Za-z0-9._/-]+$ ]] \
    && [[ "${path}" != *//* ]] && [[ "${path}" != */./* ]] && [[ "${path}" != */../* ]] \
    && [[ "${path}" != */. ]] && [[ "${path}" != */.. ]] && [[ "${path}" != */ ]] \
    && { [[ "${path}" == /etc/letsencrypt/live/* ]] || [[ "${path}" == /etc/ssl/* ]]; }
}

env_contents_are_safe() {
  local path="$1" size
  [ -f "${path}" ] && [ ! -L "${path}" ] || return 1
  size="$(/usr/bin/stat -c '%s' -- "${path}" 2>/dev/null || true)"
  [[ "${size}" =~ ^[0-9]+$ ]] && [ "${size}" -ge 1 ] && [ "${size}" -le "${MAX_ENV_BYTES}" ] \
    && /usr/bin/tr -d '\000' < "${path}" | /usr/bin/cmp -s - "${path}"
}

managed_env_path() {
  local data_root resolved_env metadata mode
  data_root="$(/usr/bin/readlink -f -- "${DIS_DATA_PATH}" 2>/dev/null || true)"
  resolved_env="$(/usr/bin/readlink -e -- "${APP_ROOT}/.env" 2>/dev/null || true)"
  [ -n "${data_root}" ] && [ "${resolved_env}" = "${data_root}/.env" ] \
    && root_controlled_file "${resolved_env}" && env_contents_are_safe "${resolved_env}" || return 1
  metadata="$(/usr/bin/stat -c '%u:%g:%a:%h' -- "${resolved_env}" 2>/dev/null || true)"
  [[ "${metadata}" =~ ^0:[0-9]+:([0-7]+):1$ ]] || return 1
  mode="${BASH_REMATCH[1]}"
  (( (8#${mode} & 8#022) == 0 )) || return 1
  [ "$(/usr/bin/stat -c '%d' -- "${resolved_env%/*}")" = "$(/usr/bin/stat -c '%d' -- "${WORK_DIR}")" ] \
    || return 1
  printf '%s\n' "${resolved_env}"
}

read_env_value() {
  local path="$1" key="$2" fallback="$3" output_name="$4" line value="" count=0
  while IFS= read -r line || [ -n "${line}" ]; do
    line="${line%$'\r'}"
    case "${line}" in
      "${key}"=*) count=$((count + 1)); value="${line#*=}" ;;
    esac
  done < "${path}"
  [ "${count}" -le 1 ] || return 1
  [ "${count}" = 1 ] || value="${fallback}"
  [[ "${value}" != *$'\n'* ]] && [[ "${value}" != *$'\r'* ]] \
    && [[ "${value}" != \"* ]] && [[ "${value}" != \'* ]] || return 1
  printf -v "${output_name}" '%s' "${value}"
}

read_configuration() {
  local path="$1" enabled_name="$2" host_name="$3" bind_name="$4" port_name="$5"
  local certificate_name="$6" private_key_name="$7" stream_key_name="$8" configured_name="$9"
  local raw_enabled enabled host bind port certificate private_key stream_key configured=false
  env_contents_are_safe "${path}" || return 1
  read_env_value "${path}" WALLBOARD_LIVE_STREAM_ENABLED false raw_enabled || return 1
  case "${raw_enabled,,}" in true) enabled=true ;; false) enabled=false ;; *) return 1 ;; esac
  read_env_value "${path}" WALLBOARD_LIVE_STREAM_PUBLIC_HOST '' host || return 1
  read_env_value "${path}" WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS 0.0.0.0 bind || return 1
  read_env_value "${path}" WALLBOARD_LIVE_STREAM_RTMPS_PORT 1936 port || return 1
  read_env_value "${path}" WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH '' certificate || return 1
  read_env_value "${path}" WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH '' private_key || return 1
  read_env_value "${path}" WALLBOARD_LIVE_STREAM_STREAM_KEY '' stream_key || return 1
  host="${host,,}"
  [ -z "${host}" ] || valid_host "${host}" || return 1
  valid_bind "${bind}" || return 1
  [[ "${port}" =~ ^[0-9]{1,10}$ ]] || return 1
  port="$((10#${port}))"
  (( port >= 1024 && port <= 65535 )) && [ "${port}" != 19350 ] && [ "${port}" != 19351 ] \
    || return 1
  for value in "${certificate}" "${private_key}"; do
    [ -z "${value}" ] || { [ "${#value}" -le 4096 ] && [[ "${value}" == /* ]] \
      && [[ "${value}" =~ ^[\ -~]+$ ]]; } || return 1
  done
  if [ -n "${stream_key}" ]; then valid_stream_key "${stream_key}" || return 1; configured=true; fi
  if [ "${enabled}" = true ]; then
    [ -n "${host}" ] && [ -n "${certificate}" ] && [ -n "${private_key}" ] || return 1
  fi
  printf -v "${enabled_name}" '%s' "${enabled}"
  printf -v "${host_name}" '%s' "${host}"
  printf -v "${bind_name}" '%s' "${bind}"
  printf -v "${port_name}" '%s' "${port}"
  printf -v "${certificate_name}" '%s' "${certificate}"
  printf -v "${private_key_name}" '%s' "${private_key}"
  printf -v "${stream_key_name}" '%s' "${stream_key}"
  printf -v "${configured_name}" '%s' "${configured}"
}

configuration_digest() {
  /usr/bin/jq -cjn --argjson enabled "$1" --arg public_host "$2" --arg bind_address "$3" \
    --argjson rtmps_port "$4" --arg tls_certificate_path "$5" --arg tls_private_key_path "$6" \
    --argjson stream_key_configured "$7" \
    '{enabled:$enabled,public_host:$public_host,bind_address:$bind_address,rtmps_port:$rtmps_port,tls_certificate_path:$tls_certificate_path,tls_private_key_path:$tls_private_key_path,stream_key_configured:$stream_key_configured}' \
    | /usr/bin/sha256sum | /usr/bin/cut -d' ' -f1
}

safe_previous_env() {
  [ -f "${PREVIOUS_ENV}" ] && [ ! -L "${PREVIOUS_ENV}" ] \
    && [ "$(/usr/bin/stat -c '%u:%g:%a:%h' -- "${PREVIOUS_ENV}" 2>/dev/null || true)" = 0:0:600:1 ] \
    && env_contents_are_safe "${PREVIOUS_ENV}"
}

create_previous_env() (
  set -euo pipefail
  local temporary=""
  [ ! -e "${PREVIOUS_ENV}" ] && [ ! -L "${PREVIOUS_ENV}" ] || return 1
  temporary="$(/usr/bin/mktemp "${WORK_DIR}/.previous-env.XXXXXX")" || return 1
  trap '/usr/bin/rm -f -- "${temporary:-}" 2>/dev/null || true' EXIT
  /usr/bin/cp --reflink=never -- "${ENV_FILE}" "${temporary}"
  /usr/bin/chown root:root "${temporary}"
  /usr/bin/chmod 0600 "${temporary}"
  /usr/bin/sync -f "${temporary}"
  /usr/bin/mv -T -- "${temporary}" "${PREVIOUS_ENV}"
  temporary=""
  /usr/bin/sync -f "${WORK_DIR}"
  safe_previous_env
  trap - EXIT
)

publish_env_file() {
  local temporary="$1" source_metadata target_metadata source_acl target_acl
  env_contents_are_safe "${temporary}" || return 1
  source_metadata="$(/usr/bin/stat -c '%u:%g:%a:%d:%i:%h' -- "${ENV_FILE}" 2>/dev/null || true)"
  [[ "${source_metadata}" =~ ^0:[0-9]+:[0-7]+:[0-9]+:[0-9]+:1$ ]] || return 1
  /usr/bin/chown --reference="${ENV_FILE}" "${temporary}" \
    && /usr/bin/chmod --reference="${ENV_FILE}" "${temporary}" || return 1
  /usr/bin/getfacl -cp -- "${ENV_FILE}" | /usr/bin/setfacl --set-file=- -- "${temporary}" || return 1
  target_metadata="$(/usr/bin/stat -c '%u:%g:%a:%h' -- "${temporary}" 2>/dev/null || true)"
  [ "${target_metadata}" = "$(/usr/bin/stat -c '%u:%g:%a:%h' -- "${ENV_FILE}")" ] || return 1
  source_acl="$(/usr/bin/getfacl -cp -- "${ENV_FILE}")"
  target_acl="$(/usr/bin/getfacl -cp -- "${temporary}")"
  [ "${source_acl}" = "${target_acl}" ] || return 1
  /usr/bin/sync -f "${temporary}"
  [ "$(/usr/bin/stat -c '%u:%g:%a:%d:%i:%h' -- "${ENV_FILE}")" = "${source_metadata}" ] \
    && [ ! -L "${ENV_FILE}" ] || return 1
  /usr/bin/mv -fT -- "${temporary}" "${ENV_FILE}"
  /usr/bin/sync -f "${ENV_FILE}"
  /usr/bin/sync -f "${ENV_FILE%/*}"
  [ "$(managed_env_path)" = "${ENV_FILE}" ]
}

rewrite_configuration() (
  set -euo pipefail
  local enabled="$1" host="$2" bind="$3" port="$4" certificate="$5" private_key="$6" stream_key="$7"
  local temporary="" line
  temporary="$(/usr/bin/mktemp "${ENV_FILE}.rewrite.XXXXXX")" || return 1
  trap '/usr/bin/rm -f -- "${temporary:-}" 2>/dev/null || true' EXIT
  while IFS= read -r line || [ -n "${line}" ]; do
    line="${line%$'\r'}"
    case "${line}" in
      WALLBOARD_LIVE_STREAM_ENABLED=*|WALLBOARD_LIVE_STREAM_PUBLIC_HOST=*|WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS=*|WALLBOARD_LIVE_STREAM_RTMPS_PORT=*|WALLBOARD_LIVE_STREAM_STREAM_KEY=*|WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH=*|WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH=*) ;;
      *) printf '%s\n' "${line}" >> "${temporary}" ;;
    esac
  done < "${ENV_FILE}"
  printf '%s\n' "WALLBOARD_LIVE_STREAM_ENABLED=${enabled}" "WALLBOARD_LIVE_STREAM_PUBLIC_HOST=${host}" \
    "WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS=${bind}" "WALLBOARD_LIVE_STREAM_RTMPS_PORT=${port}" \
    "WALLBOARD_LIVE_STREAM_STREAM_KEY=${stream_key}" "WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH=${certificate}" \
    "WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH=${private_key}" >> "${temporary}"
  publish_env_file "${temporary}"
  temporary=""
  trap - EXIT
)

restore_previous_env() (
  set -euo pipefail
  local temporary=""
  safe_previous_env || return 1
  temporary="$(/usr/bin/mktemp "${ENV_FILE}.rewrite.XXXXXX")" || return 1
  trap '/usr/bin/rm -f -- "${temporary:-}" 2>/dev/null || true' EXIT
  /usr/bin/cp --reflink=never -- "${PREVIOUS_ENV}" "${temporary}"
  publish_env_file "${temporary}"
  temporary=""
  trap - EXIT
)

remove_safe_temporary() {
  local path="$1" owners="$2" limit="$3" metadata owner mode links size
  [ -f "${path}" ] && [ ! -L "${path}" ] || return 1
  metadata="$(/usr/bin/stat -c '%U:%a:%h:%s' -- "${path}" 2>/dev/null || true)"
  IFS=: read -r owner mode links size <<< "${metadata}"
  [[ "${owner}" =~ ${owners} ]] && [[ "${mode}" =~ ^[0-7]+$ ]] \
    && (( (8#${mode} & 8#022) == 0 )) && [ "${links}" = 1 ] \
    && [[ "${size}" =~ ^[0-9]+$ ]] && [ "${size}" -le "${limit}" ] || return 1
  /usr/bin/rm -f -- "${path}"
}

sweep_interrupted_temporaries() {
  local path
  for path in "${WORK_DIR}"/.previous-env.?????? "${WORK_DIR}"/.configuration-result.?????? \
    "${WORK_DIR}"/.configuration-commit.?????? "${WORK_DIR}"/.recovery-required.??????; do
    [ -e "${path}" ] || [ -L "${path}" ] || continue
    case "${path}" in
      */.configuration-result.*) remove_safe_temporary "${path}" '^(root|www-data)$' 65536 || return 1 ;;
      */.previous-env.*) remove_safe_temporary "${path}" '^root$' "${MAX_ENV_BYTES}" || return 1 ;;
      *) remove_safe_temporary "${path}" '^root$' 4096 || return 1 ;;
    esac
  done
  for path in "${ENV_FILE}".rewrite.??????; do
    [ -e "${path}" ] || [ -L "${path}" ] || continue
    remove_safe_temporary "${path}" '^root$' "${MAX_ENV_BYTES}" || return 1
  done
  /usr/bin/sync -f "${WORK_DIR}"; /usr/bin/sync -f "${ENV_FILE%/*}"
}

current_digest() {
  local digest_enabled digest_host digest_bind digest_port digest_certificate digest_private_key
  local digest_stream_key digest_configured
  read_configuration "${ENV_FILE}" digest_enabled digest_host digest_bind digest_port \
    digest_certificate digest_private_key digest_stream_key digest_configured || return 1
  configuration_digest "${digest_enabled}" "${digest_host}" "${digest_bind}" "${digest_port}" \
    "${digest_certificate}" "${digest_private_key}" "${digest_configured}"
  unset digest_stream_key
}

safe_commit_marker() {
  [ -f "${COMMIT_MARKER}" ] && [ ! -L "${COMMIT_MARKER}" ] \
    && [ "$(/usr/bin/stat -c '%u:%g:%a:%h' -- "${COMMIT_MARKER}" 2>/dev/null || true)" = 0:0:600:1 ] \
    && /usr/bin/jq -e 'type == "object" and (.key_created|type == "boolean")
      and (.config_sha256|type == "string" and test("^[a-f0-9]{64}$"))
      and (keys_unsorted|sort == ["config_sha256","key_created"])' "${COMMIT_MARKER}" >/dev/null
}

write_commit_marker() (
  set -euo pipefail
  local key_created="$1" config_sha="$2" deadline="${3:-0}" temporary=""
  if [ -e "${COMMIT_MARKER}" ] || [ -L "${COMMIT_MARKER}" ]; then safe_commit_marker; return; fi
  deadline_is_open "${deadline}" || return 124
  temporary="$(/usr/bin/mktemp "${WORK_DIR}/.configuration-commit.XXXXXX")"
  trap '/usr/bin/rm -f -- "${temporary:-}" 2>/dev/null || true' EXIT
  /usr/bin/jq -cn --argjson key_created "${key_created}" --arg config_sha256 "${config_sha}" \
    '{key_created:$key_created,config_sha256:$config_sha256}' > "${temporary}"
  /usr/bin/chown root:root "${temporary}"; /usr/bin/chmod 0600 "${temporary}"
  run_by_deadline "${deadline}" /usr/bin/sync -f "${temporary}"
  run_by_deadline "${deadline}" /usr/bin/mv -T -- "${temporary}" "${COMMIT_MARKER}"
  temporary=""
  run_by_deadline "${deadline}" /usr/bin/sync -f "${WORK_DIR}"
  safe_commit_marker; trap - EXIT
)

safe_recovery_marker() {
  [ -f "${RECOVERY_MARKER}" ] && [ ! -L "${RECOVERY_MARKER}" ] \
    && [ "$(/usr/bin/stat -c '%u:%g:%a:%h:%s' -- "${RECOVERY_MARKER}" 2>/dev/null || true)" = 0:0:600:1:25 ] \
    && [ "$(< "${RECOVERY_MARKER}")" = manual-recovery-required ]
}

write_recovery_marker() (
  set -euo pipefail
  local temporary=""
  if [ -e "${RECOVERY_MARKER}" ] || [ -L "${RECOVERY_MARKER}" ]; then safe_recovery_marker; return; fi
  temporary="$(/usr/bin/mktemp "${WORK_DIR}/.recovery-required.XXXXXX")"
  trap '/usr/bin/rm -f -- "${temporary:-}" 2>/dev/null || true' EXIT
  printf 'manual-recovery-required\n' > "${temporary}"
  /usr/bin/chown root:root "${temporary}"; /usr/bin/chmod 0600 "${temporary}"
  /usr/bin/sync -f "${temporary}"; /usr/bin/mv -T -- "${temporary}" "${RECOVERY_MARKER}"
  temporary=""; /usr/bin/sync -f "${WORK_DIR}"; safe_recovery_marker; trap - EXIT
)

result_matches() {
  local state="$1" key_created="${2:-}" config_sha="${3:-}" metadata
  [ -f "${RESULT_FILE}" ] && [ ! -L "${RESULT_FILE}" ] || return 1
  metadata="$(/usr/bin/stat -c '%U:%G:%a:%h:%s' -- "${RESULT_FILE}" 2>/dev/null || true)"
  [[ "${metadata}" =~ ^www-data:root:600:1:([0-9]+)$ ]] \
    && [ "${BASH_REMATCH[1]}" -ge 2 ] && [ "${BASH_REMATCH[1]}" -le 65536 ] || return 1
  if [ "${state}" = succeeded ]; then
    /usr/bin/jq -e --argjson key_created "${key_created}" --arg config_sha256 "${config_sha}" '
      type == "object" and .state == "succeeded" and .exit_code == 0
      and (.output|type == "string") and (.finished_at|type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
      and .key_created == $key_created and .config_sha256 == $config_sha256
      and (keys_unsorted|sort == ["config_sha256","exit_code","finished_at","key_created","output","state"])' \
      "${RESULT_FILE}" >/dev/null
  else
    /usr/bin/jq -e 'type == "object" and .state == "failed" and (.exit_code|type == "number" and floor == . and . != 0)
      and (.output|type == "string") and (.finished_at|type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
      and (keys_unsorted|sort == ["exit_code","finished_at","output","state"])' "${RESULT_FILE}" >/dev/null
  fi
}

remove_claim() {
  claim_is_same_inode || return 1
  /usr/bin/rm -f -- "${RUNNING_FILE}"
  /usr/bin/sync -f "${WORK_DIR}"
}

cleanup_after_result() {
  local mode="$1"
  case "${mode}" in
    success|failure)
      [ "${mode}" = failure ] || safe_commit_marker || return 1
      if [ -e "${PREVIOUS_ENV}" ] || [ -L "${PREVIOUS_ENV}" ]; then
        safe_previous_env || return 1
        /usr/bin/rm -f -- "${PREVIOUS_ENV}"; /usr/bin/sync -f "${WORK_DIR}"
      fi
      remove_claim || return 1
      if [ "${mode}" = success ]; then
        /usr/bin/rm -f -- "${COMMIT_MARKER}"; /usr/bin/sync -f "${WORK_DIR}"
      fi
      ;;
    # Keep the claimed request as a root-only recovery barrier. The worker
    # always processes claimed requests before new pending requests, so a
    # request-specific recovery marker cannot be bypassed by a later request.
    fail-closed) : ;;
    *) return 1 ;;
  esac
}

publish_result() (
  set -euo pipefail
  local state="$1" exit_code="$2" output="$3" cleanup_mode="$4"
  local key_created="${5:-}" config_sha="${6:-}" temporary="" result_fd="" published=0
  temporary="$(/usr/bin/mktemp "${WORK_DIR}/.configuration-result.XXXXXX")"
  trap '[ "${published:-0}" = 1 ] && /usr/bin/rm -f -- "${RESULT_FILE}" 2>/dev/null || true; /usr/bin/rm -f -- "${temporary:-}" 2>/dev/null || true' EXIT
  if [ "${state}" = succeeded ]; then
    /usr/bin/jq -n --arg state succeeded --argjson exit_code 0 --arg output "${output}" \
      --arg finished_at "$(/usr/bin/date -u +%Y-%m-%dT%H:%M:%SZ)" --argjson key_created "${key_created}" \
      --arg config_sha256 "${config_sha}" '{state:$state,exit_code:$exit_code,output:$output,finished_at:$finished_at,key_created:$key_created,config_sha256:$config_sha256}' > "${temporary}"
  else
    /usr/bin/jq -n --arg state failed --argjson exit_code "${exit_code}" --arg output "${output}" \
      --arg finished_at "$(/usr/bin/date -u +%Y-%m-%dT%H:%M:%SZ)" \
      '{state:$state,exit_code:$exit_code,output:$output,finished_at:$finished_at}' > "${temporary}"
  fi
  /usr/bin/chown www-data:root "${temporary}"; /usr/bin/chmod 0600 "${temporary}"
  exec {result_fd}<>"${temporary}"; /usr/bin/flock -x "${result_fd}"; /usr/bin/sync -f "${temporary}"
  [ ! -d "${RESULT_FILE}" ] || return 1
  /usr/bin/mv -fT -- "${temporary}" "${RESULT_FILE}"; temporary=""; published=1
  /usr/bin/sync -f "${REQUEST_DIR}"
  cleanup_after_result "${cleanup_mode}"
  published=0
  /usr/bin/flock -u "${result_fd}"; exec {result_fd}>&-
  trap - EXIT
)

run_before() {
  local deadline="$1" now budget
  shift
  now="$(/usr/bin/date +%s)"; budget="$((deadline - now))"
  [ "${budget}" -ge 2 ] || return 124
  /usr/bin/timeout --signal=TERM --kill-after=1s "$((budget - 1))s" "$@"
}

deadline_is_open() {
  local deadline="$1"
  [ "${deadline}" -eq 0 ] || [ "$(/usr/bin/date +%s)" -lt "${deadline}" ]
}

run_by_deadline() {
  local deadline="$1"
  shift
  if [ "${deadline}" -eq 0 ]; then
    "$@"
  else
    run_before "${deadline}" "$@"
  fi
}

activate_before() {
  local deadline="$1"
  run_before "${deadline}" /usr/bin/env DIS_OPERATION_LOCK_HELD=1 \
    DIS_OPERATION_LOCK_FD="${DIS_OPERATION_LOCK_FD}" "${REFRESH_PATH}" >/dev/null 2>&1 \
    && run_before "${deadline}" /usr/bin/systemctl reload "${PHP_FPM_SERVICE}.service" >/dev/null 2>&1 \
    && run_before "${deadline}" /usr/bin/systemctl is-active --quiet "${PHP_FPM_SERVICE}.service"
}

fail_closed() {
  local reason="$1"
  if ! write_recovery_marker; then
    stop_live_services
    security_log crit "wallboard_live_configuration_recovery_marker_failed request_id=${REQUEST_ID} reason=${reason} services=stopped"
    return 1
  fi
  stop_live_services
  security_log crit "wallboard_live_configuration_recovery_required request_id=${REQUEST_ID} reason=${reason} services=stopped"
  publish_result failed 1 'Live-stream configuration requires protected server recovery.' fail-closed || true
  return 1
}

rollback_failure() {
  local expected_sha="$1" rollback_deadline="$2" result_deadline="$3" output="$4" reason="$5"
  restore_previous_env \
    && [ "$(current_digest)" = "${expected_sha}" ] \
    && activate_before "${rollback_deadline}" \
    || { fail_closed "${reason}_rollback_failed"; return 1; }
  [ "$(/usr/bin/date +%s)" -lt "${result_deadline}" ] \
    || { fail_closed "${reason}_terminal_deadline"; return 1; }
  publish_result failed 1 "${output}" failure \
    || { security_log err "wallboard_live_configuration_result_failed request_id=${REQUEST_ID}"; return 1; }
  security_log err "wallboard_live_configuration_completed request_id=${REQUEST_ID} state=failed reason=${reason} rollback=restored"
}

main() {
  local data_root expected_path metadata inherited_path size
  local enabled host bind port certificate private_key expected_sha actor created_at expires_at
  local created_epoch expires_epoch now activation_deadline success_deadline rollback_deadline terminal_deadline
  local current_enabled current_host current_bind current_port current_certificate current_private_key
  local current_key current_configured current_sha prior_enabled prior_host prior_bind prior_port
  local prior_certificate prior_private_key prior_key prior_configured prior_sha desired_configured desired_sha
  local desired_key key_created=false marker_key_created marker_sha

  [ "${EUID}" -eq 0 ] || { printf 'The configuration request helper requires root.\n' >&2; exit 1; }
  [ "$#" -eq 1 ] || { printf 'The configuration request helper takes one claimed request.\n' >&2; exit 1; }
  umask 0077
  APP_ROOT="${APP_ROOT:-/opt/dis}"
  DIS_DATA_PATH="${DIS_DATA_PATH:-/opt/dis-data}"
  [[ "${APP_ROOT}" == /* ]] && [[ "${DIS_DATA_PATH}" == /* ]] || exit 1
  [[ "${PHP_FPM_SERVICE:-}" =~ ^php[0-9]+\.[0-9]+-fpm$ ]] || exit 1
  [ "${DIS_OPERATION_LOCK_HELD:-0}" = 1 ] \
    && [[ "${DIS_OPERATION_LOCK_FD:-}" =~ ^([3-9]|[1-9][0-9]+)$ ]] || exit 1
  inherited_path="$(/usr/bin/readlink -f "/proc/$$/fd/${DIS_OPERATION_LOCK_FD}" 2>/dev/null || true)"
  [ "${inherited_path}" = "${LOCK_PATH}" ] && /usr/bin/flock -n "${DIS_OPERATION_LOCK_FD}" || exit 1
  root_controlled_file "${REFRESH_PATH}" \
    && [ "$(/usr/bin/stat -c '%u:%g:%a:%h' -- "${REFRESH_PATH}")" = 0:0:700:1 ] || exit 1

  data_root="$(/usr/bin/readlink -f -- "${DIS_DATA_PATH}" 2>/dev/null || true)"
  [ -n "${data_root}" ] || exit 1
  REQUEST_DIR="${data_root}/wallboard-live-key-requests"
  WORK_DIR="${data_root}/wallboard-live-key-request-work"
  layout_is_safe || exit 1
  RUNNING_FILE="$1"
  [[ "${RUNNING_FILE}" == /* ]] || exit 1
  REQUEST_ID="$(/usr/bin/basename "${RUNNING_FILE}" .json)"
  [[ "${REQUEST_ID}" =~ ^[a-f0-9]{32}$ ]] || exit 1
  expected_path="${WORK_DIR}/${REQUEST_ID}.json"
  [ "$(/usr/bin/readlink -e -- "${RUNNING_FILE}" 2>/dev/null || true)" = "${expected_path}" ] || exit 1
  metadata="$(/usr/bin/stat -c '%u:%g:%a:%h:%s' -- "${RUNNING_FILE}" 2>/dev/null || true)"
  [[ "${metadata}" =~ ^0:0:600:1:([0-9]+)$ ]] || exit 1
  size="${BASH_REMATCH[1]}"; [ "${size}" -ge 1 ] && [ "${size}" -le "${MAX_REQUEST_BYTES}" ] || exit 1
  exec {REQUEST_FD}<"${RUNNING_FILE}"; claim_is_same_inode || exit 1
  PREVIOUS_ENV="${WORK_DIR}/${REQUEST_ID}.previous-env"
  COMMIT_MARKER="${WORK_DIR}/${REQUEST_ID}.configuration-commit"
  RECOVERY_MARKER="${WORK_DIR}/${REQUEST_ID}.recovery-required"
  RESULT_FILE="${REQUEST_DIR}/${REQUEST_ID}.result"

  if ! schema_is_valid; then
    publish_result failed 2 'Invalid live-stream configuration request.' failure || exit 1
    exit 0
  fi
  enabled="$(/usr/bin/jq -r .enabled "/proc/$$/fd/${REQUEST_FD}")"
  host="$(/usr/bin/jq -r .public_host "/proc/$$/fd/${REQUEST_FD}")"; host="${host,,}"
  bind="$(/usr/bin/jq -r .bind_address "/proc/$$/fd/${REQUEST_FD}")"
  port="$(/usr/bin/jq -r .rtmps_port "/proc/$$/fd/${REQUEST_FD}")"
  certificate="$(/usr/bin/jq -r .tls_certificate_path "/proc/$$/fd/${REQUEST_FD}")"
  private_key="$(/usr/bin/jq -r .tls_private_key_path "/proc/$$/fd/${REQUEST_FD}")"
  expected_sha="$(/usr/bin/jq -r .expected_config_sha256 "/proc/$$/fd/${REQUEST_FD}")"
  actor="$(/usr/bin/jq -r .actor_id "/proc/$$/fd/${REQUEST_FD}")"
  created_at="$(/usr/bin/jq -r .created_at "/proc/$$/fd/${REQUEST_FD}")"
  expires_at="$(/usr/bin/jq -r .expires_at "/proc/$$/fd/${REQUEST_FD}")"
  valid_bind "${bind}" && [[ "${port}" =~ ^[0-9]{1,5}$ ]] \
    && (( port >= 1024 && port <= 65535 && port != 19350 && port != 19351 )) \
    && { [ -z "${host}" ] || valid_host "${host}"; } \
    && { [ -z "${certificate}" ] || valid_tls_path "${certificate}"; } \
    && { [ -z "${private_key}" ] || valid_tls_path "${private_key}"; } \
    && { [ "${enabled}" = false ] || { valid_host "${host}" && valid_tls_path "${certificate}" && valid_tls_path "${private_key}"; }; } \
    || { publish_result failed 2 'Invalid live-stream configuration values.' failure || exit 1; exit 0; }

  created_epoch="$(/usr/bin/date -u -d "${created_at}" +%s 2>/dev/null || true)"
  expires_epoch="$(/usr/bin/date -u -d "${expires_at}" +%s 2>/dev/null || true)"
  [[ "${created_epoch}" =~ ^[0-9]+$ ]] && [[ "${expires_epoch}" =~ ^[0-9]+$ ]] \
    && [ "$(/usr/bin/date -u -d "@${created_epoch}" +%Y-%m-%dT%H:%M:%SZ)" = "${created_at}" ] \
    && [ "$(/usr/bin/date -u -d "@${expires_epoch}" +%Y-%m-%dT%H:%M:%SZ)" = "${expires_at}" ] \
    && [ "$((expires_epoch - created_epoch))" = 120 ] || { publish_result failed 2 'Invalid configuration request lifetime.' failure || exit 1; exit 0; }
  activation_deadline="$((created_epoch + ACTIVATION_OFFSET))"; success_deadline="$((created_epoch + SUCCESS_OFFSET))"
  rollback_deadline="$((created_epoch + ROLLBACK_OFFSET))"; terminal_deadline="$((created_epoch + TERMINAL_OFFSET))"
  now="$(/usr/bin/date +%s)"
  [ "$((now - created_epoch))" -ge -60 ] || { publish_result failed 124 'Configuration request is not active yet.' failure || exit 1; exit 0; }

  ENV_FILE="$(managed_env_path)" || { fail_closed unsafe_environment; exit 1; }
  sweep_interrupted_temporaries || { fail_closed unsafe_temporary_state; exit 1; }
  read_configuration "${ENV_FILE}" current_enabled current_host current_bind current_port current_certificate \
    current_private_key current_key current_configured || { fail_closed invalid_environment; exit 1; }
  current_sha="$(configuration_digest "${current_enabled}" "${current_host}" "${current_bind}" "${current_port}" \
    "${current_certificate}" "${current_private_key}" "${current_configured}")"
  prior_configured="${current_configured}"; prior_key="${current_key}"
  if [ -e "${PREVIOUS_ENV}" ] || [ -L "${PREVIOUS_ENV}" ]; then
    safe_previous_env && read_configuration "${PREVIOUS_ENV}" prior_enabled prior_host prior_bind prior_port \
      prior_certificate prior_private_key prior_key prior_configured || { fail_closed unsafe_previous_environment; exit 1; }
    prior_sha="$(configuration_digest "${prior_enabled}" "${prior_host}" "${prior_bind}" "${prior_port}" \
      "${prior_certificate}" "${prior_private_key}" "${prior_configured}")"
    [ "${prior_sha}" = "${expected_sha}" ] || { fail_closed previous_environment_mismatch; exit 1; }
  fi
  desired_configured="${prior_configured}"; [ "${enabled}" = false ] || desired_configured=true
  desired_sha="$(configuration_digest "${enabled}" "${host}" "${bind}" "${port}" "${certificate}" "${private_key}" "${desired_configured}")"
  [ "${prior_configured}" = true ] || [ "${desired_configured}" = false ] || key_created=true

  if [ -e "${RECOVERY_MARKER}" ] || [ -L "${RECOVERY_MARKER}" ]; then
    safe_recovery_marker || true; fail_closed prior_recovery_required; exit 1
  fi
  if [ -e "${COMMIT_MARKER}" ] || [ -L "${COMMIT_MARKER}" ]; then
    safe_commit_marker || { fail_closed unsafe_commit_marker; exit 1; }
    marker_key_created="$(/usr/bin/jq -r .key_created "${COMMIT_MARKER}")"
    marker_sha="$(/usr/bin/jq -r .config_sha256 "${COMMIT_MARKER}")"
    if [ -e "${PREVIOUS_ENV}" ] || [ -L "${PREVIOUS_ENV}" ]; then
      [ "${marker_key_created}" = "${key_created}" ] || { fail_closed committed_key_state_mismatch; exit 1; }
    else
      key_created="${marker_key_created}"
    fi
    [ "${marker_sha}" = "${desired_sha}" ] && [ "${current_sha}" = "${desired_sha}" ] \
      || { fail_closed committed_state_mismatch; exit 1; }
    if [ -e "${RESULT_FILE}" ] || [ -L "${RESULT_FILE}" ]; then
      if result_matches succeeded "${key_created}" "${desired_sha}"; then
        cleanup_after_result success || exit 1
      elif [ ! -e "${RESULT_FILE}" ] && [ ! -L "${RESULT_FILE}" ]; then
        publish_result succeeded 0 'The live-stream configuration is active.' success "${key_created}" "${desired_sha}" || exit 1
      else
        fail_closed unsafe_committed_result; exit 1
      fi
    else
      publish_result succeeded 0 'The live-stream configuration is active.' success "${key_created}" "${desired_sha}" || exit 1
    fi
    exit 0
  fi

  if [ -e "${RESULT_FILE}" ] || [ -L "${RESULT_FILE}" ]; then
    if result_matches failed && [ "${current_sha}" = "${expected_sha}" ]; then
      cleanup_after_result failure && exit 0
    elif [ -e "${RESULT_FILE}" ] || [ -L "${RESULT_FILE}" ]; then
      fail_closed unsafe_uncommitted_result; exit 1
    fi
  fi
  if [ -e "${PREVIOUS_ENV}" ] || [ -L "${PREVIOUS_ENV}" ]; then
    { [ "${current_sha}" = "${expected_sha}" ] || [ "${current_sha}" = "${desired_sha}" ]; } \
      || { fail_closed uncommitted_state_mismatch; exit 1; }
    rollback_failure "${expected_sha}" "${rollback_deadline}" "${terminal_deadline}" \
      'Interrupted configuration was rolled back.' interrupted || exit 1
    exit 0
  fi
  [ "${current_sha}" = "${expected_sha}" ] \
    || { publish_result failed 3 'The active live-stream configuration changed.' failure || exit 1; exit 0; }
  [ "$(/usr/bin/date +%s)" -lt "${activation_deadline}" ] \
    || { publish_result failed 124 'The live-stream configuration request expired before execution.' failure || exit 1; exit 0; }

  if [ "${desired_sha}" = "${current_sha}" ]; then
    [ "$(/usr/bin/date +%s)" -lt "${success_deadline}" ] \
      || { publish_result failed 124 'The live-stream configuration request expired before commit.' failure || exit 1; exit 0; }
    write_commit_marker false "${desired_sha}" "${success_deadline}" || exit 1
    publish_result succeeded 0 'The live-stream configuration was already active.' success false "${desired_sha}" || exit 1
    exit 0
  fi
  create_previous_env || { fail_closed recovery_snapshot_failed; exit 1; }
  desired_key="${current_key}"
  if [ "${key_created}" = true ]; then
    desired_key="$(/usr/bin/openssl rand -base64 48 | /usr/bin/tr '+/' '_-')"
    valid_stream_key "${desired_key}" && [ "${#desired_key}" = 64 ] \
      || { rollback_failure "${expected_sha}" "${rollback_deadline}" "${terminal_deadline}" 'Stream-key generation failed; previous configuration restored.' key_generation; exit 1; }
  fi
  if ! rewrite_configuration "${enabled}" "${host}" "${bind}" "${port}" "${certificate}" "${private_key}" "${desired_key}" \
    || [ "$(current_digest 2>/dev/null || true)" != "${desired_sha}" ]; then
    rollback_failure "${expected_sha}" "${rollback_deadline}" "${terminal_deadline}" 'Configuration update failed; previous configuration restored.' environment_update
    exit $?
  fi
  if ! activate_before "${activation_deadline}"; then
    rollback_failure "${expected_sha}" "${rollback_deadline}" "${terminal_deadline}" 'Configuration activation failed; previous configuration restored.' activation
    exit $?
  fi
  if [ "$(/usr/bin/date +%s)" -ge "${success_deadline}" ]; then
    rollback_failure "${expected_sha}" "${rollback_deadline}" "${terminal_deadline}" 'Configuration missed its commit deadline; previous configuration restored.' success_deadline
    exit $?
  fi
  if ! write_commit_marker "${key_created}" "${desired_sha}" "${success_deadline}"; then
    [ ! -e "${COMMIT_MARKER}" ] && [ ! -L "${COMMIT_MARKER}" ] \
      && rollback_failure "${expected_sha}" "${rollback_deadline}" "${terminal_deadline}" 'Configuration commit failed; previous configuration restored.' commit_publication
    fail_closed ambiguous_commit; exit 1
  fi
  publish_result succeeded 0 'The live-stream configuration is active.' success "${key_created}" "${desired_sha}" || exit 1
  security_log notice "wallboard_live_configuration_completed request_id=${REQUEST_ID} actor_id=${actor} state=succeeded key_created=${key_created}"
  unset current_key prior_key desired_key
}

main "$@"
