#!/usr/bin/env bash
set -euo pipefail

LIFECYCLE_SOURCE_PATH="${BASH_SOURCE[0]}"
case "${LIFECYCLE_SOURCE_PATH}" in */*) SCRIPT_DIR="${LIFECYCLE_SOURCE_PATH%/*}" ;; *) SCRIPT_DIR=. ;; esac
LIFECYCLE_SOURCE_NAME="${LIFECYCLE_SOURCE_PATH##*/}"
SCRIPT_DIR="$(cd -- "${SCRIPT_DIR}" && pwd -P)"
bootstrap_root_lifecycle_source() {
  local path="$1" parent current="" component metadata mode

  [ -f "${path}" ] && [ ! -L "${path}" ] || return 1
  metadata="$(/usr/bin/stat -c '%u:%a:%h' -- "${path}" 2>/dev/null || true)"
  [[ "${metadata}" =~ ^0:([0-7]+):1$ ]] || return 1
  mode="${BASH_REMATCH[1]}"
  (( (8#${mode} & 8#022) == 0 )) || return 1
  metadata="$(/usr/bin/stat -c '%u:%a' -- / 2>/dev/null || true)"
  [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1
  mode="${BASH_REMATCH[1]}"
  (( (8#${mode} & 8#022) == 0 )) || return 1
  parent="${path%/*}"
  IFS='/' read -r -a bootstrap_components <<< "${parent#/}"
  for component in "${bootstrap_components[@]}"; do
    [ -n "${component}" ] || continue
    current="${current}/${component}"
    [ -d "${current}" ] && [ ! -L "${current}" ] || return 1
    metadata="$(/usr/bin/stat -c '%u:%a' -- "${current}" 2>/dev/null || true)"
    [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1
    mode="${BASH_REMATCH[1]}"
    (( (8#${mode} & 8#022) == 0 )) || return 1
  done
}
if [ "${EUID}" -eq 0 ]; then
  [ ! -L "${BASH_SOURCE[0]}" ] \
    && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/${LIFECYCLE_SOURCE_NAME}" \
    && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/lib/common.sh" \
    && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/lib/secure-path.py" \
    && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/../infrastructure/systemd/dis-weather-snapshot-compat.service" \
    || {
      printf '[dis:error] Weather-snapshot retirement sources must be root-owned, single-link and non-writable by group/world.\n' >&2
      exit 1
    }
fi
unset -f bootstrap_root_lifecycle_source
# shellcheck source=scripts/lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

LEGACY_FORECAST_SERVICE="dis-knmi"
LEGACY_REALTIME_SERVICE="dis-knmi-realtime"
LEGACY_FORECAST_UNIT="/etc/systemd/system/dis-knmi.service"
LEGACY_REALTIME_UNIT="/etc/systemd/system/dis-knmi-realtime.service"
LEGACY_FORECAST_WANTS_LINK="/etc/systemd/system/multi-user.target.wants/dis-knmi.service"
LEGACY_REALTIME_WANTS_LINK="/etc/systemd/system/multi-user.target.wants/dis-knmi-realtime.service"
LEGACY_WEATHER_STORAGE_PARENT="/opt/dis-data/webapp/backend/storage/app"
LEGACY_KNMI_STORAGE="${LEGACY_WEATHER_STORAGE_PARENT}/knmi-forecast"
LEGACY_EUMETSAT_STORAGE="${LEGACY_WEATHER_STORAGE_PARENT}/eumetsat-lightning"
LEGACY_ENV_FILE="/opt/dis-data/.env"
BRIDGE_SOURCE="/opt/dis/infrastructure/systemd/dis-weather-snapshot-compat.service"
BRIDGE_MARKER="# DIS temporary legacy weather snapshot compatibility bridge"

require_exact_retirement_roots() {
  [ "${DIS_INSTALL_PATH}" = "/opt/dis" ] \
    || fail "Weather-snapshot retirement is restricted to /opt/dis."
  [ "${DIS_DATA_PATH}" = "/opt/dis-data" ] \
    || fail "Weather-snapshot retirement is restricted to /opt/dis-data."
}

process_starttime() {
  local pid="$1"

  [[ "${pid}" =~ ^[1-9][0-9]*$ ]] || return 1
  /usr/bin/python3 -I -S -c '
import pathlib
import sys

pid = sys.argv[1]
record = pathlib.Path(f"/proc/{pid}/stat").read_text(encoding="ascii")
closing = record.rfind(")")
if closing < 0:
    raise SystemExit(1)
fields = record[closing + 2:].split()
if len(fields) < 20:
    raise SystemExit(1)
print(fields[19])
' "${pid}" 2>/dev/null
}

bridge_source_is_safe() {
  root_controlled_bundle_source_is_safe "${BRIDGE_SOURCE}" \
    && grep -Fxq -- "${BRIDGE_MARKER}" "${BRIDGE_SOURCE}" \
    && grep -Fxq -- 'Type=oneshot' "${BRIDGE_SOURCE}" \
    && grep -Fxq -- 'ExecStart=/usr/bin/true' "${BRIDGE_SOURCE}" \
    && grep -Fxq -- 'RemainAfterExit=yes' "${BRIDGE_SOURCE}" \
    && ! grep -Fq -- 'WantedBy=' "${BRIDGE_SOURCE}" \
    && ! grep -Fq -- 'artisan' "${BRIDGE_SOURCE}"
}

bridge_unit_is_safe() {
  local unit="$1"

  root_owned_runtime_file_is_safe "${unit}" 644 \
    && grep -Fxq -- "${BRIDGE_MARKER}" "${unit}" \
    && grep -Fxq -- 'ExecStart=/usr/bin/true' "${unit}" \
    && grep -Fxq -- 'RemainAfterExit=yes' "${unit}"
}

original_unit_is_safe() {
  local service="$1" unit="$2"

  root_owned_runtime_file_is_safe "${unit}" 644 || return 1
  case "${service}" in
    "${LEGACY_FORECAST_SERVICE}")
      grep -Fxq -- 'Description=DIS KNMI Forecast Import Worker' "${unit}" \
        && grep -Fq -- 'artisan queue:work knmi --queue=knmi ' "${unit}"
      ;;
    "${LEGACY_REALTIME_SERVICE}")
      grep -Fxq -- 'Description=DIS KNMI Realtime Precipitation Import Worker' "${unit}" \
        && grep -Fq -- 'artisan queue:work knmi_realtime --queue=knmi-realtime ' "${unit}"
      ;;
    *)
      return 1
      ;;
  esac
}

require_known_unit_or_absence() {
  local service="$1" unit="$2"

  if [ -e "${unit}" ] || [ -L "${unit}" ]; then
    original_unit_is_safe "${service}" "${unit}" \
      || bridge_unit_is_safe "${unit}" \
      || fail "Refusing to replace an unrecognized ${service}.service."
    return
  fi
  if systemd_service_exists "${service}"; then
    fail "Refusing to shadow ${service}.service outside the managed unit path."
  fi
}

stop_and_disable_legacy_services() {
  local service

  for service in "${LEGACY_FORECAST_SERVICE}" "${LEGACY_REALTIME_SERVICE}"; do
    if systemd_service_exists "${service}" \
      || systemctl is-active --quiet "${service}.service"; then
      run_cmd systemctl stop "${service}"
    fi
  done
  for service in "${LEGACY_FORECAST_SERVICE}" "${LEGACY_REALTIME_SERVICE}"; do
    if systemctl is-enabled --quiet "${service}" 2>/dev/null; then
      run_cmd systemctl disable "${service}"
    fi
    if systemctl is-active --quiet "${service}.service"; then
      fail "Legacy weather service ${service}.service did not stop."
    fi
  done
}

legacy_queue_clear_is_available() {
  local backend="/opt/dis/webapp/backend"

  [ -f "${backend}/artisan" ] && [ ! -L "${backend}/artisan" ] \
    && [ -d "${backend}/vendor" ] && [ ! -L "${backend}/vendor" ]
}

clear_legacy_weather_queues() {
  local backend="/opt/dis/webapp/backend"
  local required="${1:-0}"

  if ! legacy_queue_clear_is_available; then
    [ "${required}" = "0" ] \
      || fail "The backend runtime is unavailable; refusing to continue without clearing legacy weather queues."
    return 0
  fi
  log "Removing pending KNMI snapshot jobs without touching other Redis queues"
  run_cmd runuser -u "${DIS_USER}" -- \
    php "${backend}/artisan" queue:clear redis --queue=knmi --force
  run_cmd runuser -u "${DIS_USER}" -- \
    php "${backend}/artisan" queue:clear redis --queue=knmi-realtime --force
  run_cmd runuser -u "${DIS_USER}" -- php "${backend}/artisan" config:clear
}

strip_legacy_weather_environment_keys() (
  set -euo pipefail

  local temporary="" acl_snapshot="" owner group mode
  local pattern='^[[:space:]]*(export[[:space:]]+)?(KNMI_OPEN_DATA_API_KEY|KNMI_FORECAST_[A-Za-z0-9_]*|KNMI_PRECIPITATION_[A-Za-z0-9_]*|KNMI_QUEUE_RETRY_AFTER|KNMI_REALTIME_QUEUE_RETRY_AFTER|EUMETSAT_LIGHTNING_STORAGE_ROOT|WEATHER_DATASET_OPERATION_RETENTION_DAYS)[[:space:]]*='

  [ -e "${LEGACY_ENV_FILE}" ] || return 0
  [ -f "${LEGACY_ENV_FILE}" ] && [ ! -L "${LEGACY_ENV_FILE}" ] \
    && [ "$(stat -c '%h' -- "${LEGACY_ENV_FILE}" 2>/dev/null || true)" = "1" ] \
    || fail "The managed environment file is not a safe regular file."
  require_root_controlled_parent "${LEGACY_ENV_FILE}"
  grep -qE "${pattern}" "${LEGACY_ENV_FILE}" || return 0
  [ -x /usr/bin/getfacl ] && [ -x /usr/bin/setfacl ] \
    || fail "ACL tools are required to preserve the managed environment file."

  owner="$(stat -c '%u' -- "${LEGACY_ENV_FILE}")"
  group="$(stat -c '%g' -- "${LEGACY_ENV_FILE}")"
  mode="$(stat -c '%a' -- "${LEGACY_ENV_FILE}")"
  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would atomically remove retired weather snapshot settings from ${LEGACY_ENV_FILE}."
    return 0
  fi

  temporary="$(mktemp "${LEGACY_ENV_FILE}.without-weather-snapshots.XXXXXX")"
  acl_snapshot="$(mktemp "${LEGACY_ENV_FILE}.acl.XXXXXX")"
  cleanup_environment_rewrite() {
    local status="$?"
    trap - EXIT INT TERM
    rm -f -- "${temporary:-}" "${acl_snapshot:-}" 2>/dev/null || true
    exit "${status}"
  }
  trap cleanup_environment_rewrite EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM

  /usr/bin/getfacl -cp -- "${LEGACY_ENV_FILE}" > "${acl_snapshot}"
  /usr/bin/awk -v retired_pattern="${pattern}" \
    '$0 !~ retired_pattern' "${LEGACY_ENV_FILE}" > "${temporary}"
  chown "${owner}:${group}" "${temporary}"
  chmod "${mode}" "${temporary}"
  /usr/bin/setfacl --set-file="${acl_snapshot}" -- "${temporary}"
  sync -f "${temporary}"
  mv -fT -- "${temporary}" "${LEGACY_ENV_FILE}"
  temporary=""
  sync -f "${LEGACY_ENV_FILE}"
  sync -f "$(dirname -- "${LEGACY_ENV_FILE}")"
  rm -f -- "${acl_snapshot}"
  acl_snapshot=""
  trap - EXIT INT TERM
  log "Removed retired weather snapshot settings from the managed environment."
)

remove_exact_managed_tree() {
  local path="$1"

  if [ -L "${path}" ]; then
    fail "Refusing to follow an unexpected symbolic link at ${path}."
  fi
  if [ -e "${path}" ]; then
    [ -d "${path}" ] || fail "Refusing to remove an unexpected non-directory object at ${path}."
    secure_path_operation remove-tree "${path}"
  fi
}

remove_legacy_weather_storage() (
  set -euo pipefail

  local acl_snapshot="" owner group mode restored=0 writer_service

  if [ ! -e "${LEGACY_KNMI_STORAGE}" ] \
    && [ ! -L "${LEGACY_KNMI_STORAGE}" ] \
    && [ ! -e "${LEGACY_EUMETSAT_STORAGE}" ] \
    && [ ! -L "${LEGACY_EUMETSAT_STORAGE}" ]; then
    return 0
  fi
  for writer_service in \
    "${PHP_FPM_SERVICE}" \
    dis-queue \
    dis-media \
    dis-scheduler \
    dis-deployment-enrichment \
    dis-knmi \
    dis-knmi-realtime; do
    if systemctl is-active --quiet "${writer_service}.service" \
      && [ "${DRY_RUN:-0}" != "1" ]; then
      fail "Weather snapshot storage can only be retired while ${writer_service}.service is stopped."
    fi
  done
  [ -d "${LEGACY_WEATHER_STORAGE_PARENT}" ] && [ ! -L "${LEGACY_WEATHER_STORAGE_PARENT}" ] \
    || fail "The application storage parent is not a safe directory."
  [ -x /usr/bin/getfacl ] && [ -x /usr/bin/setfacl ] \
    || fail "ACL tools are required to preserve application storage permissions."

  owner="$(stat -c '%u' -- "${LEGACY_WEATHER_STORAGE_PARENT}")"
  group="$(stat -c '%g' -- "${LEGACY_WEATHER_STORAGE_PARENT}")"
  mode="$(stat -c '%a' -- "${LEGACY_WEATHER_STORAGE_PARENT}")"
  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would freeze ${LEGACY_WEATHER_STORAGE_PARENT}, remove only retired weather snapshots, and restore its metadata."
    return 0
  fi

  acl_snapshot="$(mktemp /var/tmp/dis-weather-snapshot-app-acl.XXXXXX)"
  /usr/bin/getfacl -cp -- "${LEGACY_WEATHER_STORAGE_PARENT}" > "${acl_snapshot}"
  restore_application_storage_parent() {
    local status="$?" restore_status=0

    trap - EXIT INT TERM
    if [ "${restored}" = "0" ]; then
      chown "${owner}:${group}" "${LEGACY_WEATHER_STORAGE_PARENT}" || restore_status=$?
      chmod "${mode}" "${LEGACY_WEATHER_STORAGE_PARENT}" || restore_status=$?
      /usr/bin/setfacl --set-file="${acl_snapshot}" -- "${LEGACY_WEATHER_STORAGE_PARENT}" || restore_status=$?
    fi
    rm -f -- "${acl_snapshot:-}" 2>/dev/null || true
    if [ "${status}" -eq 0 ] && [ "${restore_status}" -ne 0 ]; then
      status="${restore_status}"
    fi
    exit "${status}"
  }
  trap restore_application_storage_parent EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM

  ensure_managed_directory "${LEGACY_WEATHER_STORAGE_PARENT}" root root 0750
  remove_exact_managed_tree "${LEGACY_KNMI_STORAGE}"
  remove_exact_managed_tree "${LEGACY_EUMETSAT_STORAGE}"

  chown "${owner}:${group}" "${LEGACY_WEATHER_STORAGE_PARENT}"
  chmod "${mode}" "${LEGACY_WEATHER_STORAGE_PARENT}"
  /usr/bin/setfacl --set-file="${acl_snapshot}" -- "${LEGACY_WEATHER_STORAGE_PARENT}"
  restored=1
  rm -f -- "${acl_snapshot}"
  acl_snapshot=""
  trap - EXIT INT TERM
  log "Removed the retired KNMI and EUMETSAT weather snapshot directories."
)

remove_legacy_unit_files() {
  require_known_unit_or_absence "${LEGACY_FORECAST_SERVICE}" "${LEGACY_FORECAST_UNIT}"
  require_known_unit_or_absence "${LEGACY_REALTIME_SERVICE}" "${LEGACY_REALTIME_UNIT}"
  stop_and_disable_legacy_services
  run_cmd rm -f -- \
    "${LEGACY_FORECAST_WANTS_LINK}" \
    "${LEGACY_REALTIME_WANTS_LINK}" \
    "${LEGACY_FORECAST_UNIT}" \
    "${LEGACY_REALTIME_UNIT}"
  run_cmd systemctl daemon-reload
  run_cmd systemctl reset-failed \
    "${LEGACY_FORECAST_SERVICE}" "${LEGACY_REALTIME_SERVICE}" >/dev/null 2>&1 || true
}

install_compatibility_bridges() {
  bridge_source_is_safe || fail "The weather snapshot compatibility source is not safe."
  require_known_unit_or_absence "${LEGACY_FORECAST_SERVICE}" "${LEGACY_FORECAST_UNIT}"
  require_known_unit_or_absence "${LEGACY_REALTIME_SERVICE}" "${LEGACY_REALTIME_UNIT}"
  stop_and_disable_legacy_services
  run_cmd rm -f -- "${LEGACY_FORECAST_WANTS_LINK}" "${LEGACY_REALTIME_WANTS_LINK}"
  require_root_controlled_parent "${LEGACY_FORECAST_UNIT}"
  require_root_controlled_parent "${LEGACY_REALTIME_UNIT}"
  run_cmd install -o root -g root -m 0644 -- "${BRIDGE_SOURCE}" "${LEGACY_FORECAST_UNIT}"
  run_cmd install -o root -g root -m 0644 -- "${BRIDGE_SOURCE}" "${LEGACY_REALTIME_UNIT}"
  if [ "${DRY_RUN:-0}" != "1" ]; then
    bridge_unit_is_safe "${LEGACY_FORECAST_UNIT}" \
      && bridge_unit_is_safe "${LEGACY_REALTIME_UNIT}" \
      || fail "The installed weather snapshot compatibility bridges failed verification."
  fi
  run_cmd systemctl daemon-reload
  log "Installed inert weather-worker bridges for the already-running legacy updater."
}

schedule_compatibility_finalizer() {
  local parent_pid="$1" parent_starttime unit

  parent_starttime="$(process_starttime "${parent_pid}")" \
    || fail "Could not identify the parent updater process generation."
  unit="dis-legacy-weather-snapshot-${parent_pid}-$$"
  [ -x /usr/bin/systemd-run ] \
    || fail "systemd-run is required to finalize weather snapshot retirement."
  run_cmd /usr/bin/systemd-run \
    --quiet \
    --collect \
    --unit="${unit}" \
    --property=Type=exec \
    /usr/bin/bash "${DIS_INSTALL_PATH}/scripts/retire-weather-snapshots.sh" \
      --finalize-compat "${parent_pid}" "${parent_starttime}"
  log "Scheduled weather-worker bridge cleanup after parent process ${parent_pid} exits."
}

finalize_compatibility_bridges() {
  local parent_pid="$1" expected_starttime="$2" current_starttime

  [[ "${parent_pid}" =~ ^[1-9][0-9]*$ ]] \
    && [[ "${expected_starttime}" =~ ^[1-9][0-9]*$ ]] \
    || fail "Invalid legacy updater process identity."
  while true; do
    current_starttime="$(process_starttime "${parent_pid}" || true)"
    [ "${current_starttime}" = "${expected_starttime}" ] || break
    sleep 1
  done

  acquire_dis_operation_lock weather-snapshot-cutover
  bridge_unit_is_safe "${LEGACY_FORECAST_UNIT}" \
    && bridge_unit_is_safe "${LEGACY_REALTIME_UNIT}" \
    || fail "Refusing to remove unrecognized weather-worker units after compatibility mode."
  remove_legacy_unit_files
  log "Removed the temporary weather-worker compatibility bridges."
}

retire_weather_snapshots() {
  local compatibility_parent_pid="${1:-}"

  stop_and_disable_legacy_services
  clear_legacy_weather_queues
  remove_legacy_weather_storage
  strip_legacy_weather_environment_keys
  if [ -n "${compatibility_parent_pid}" ]; then
    install_compatibility_bridges
    schedule_compatibility_finalizer "${compatibility_parent_pid}"
  else
    remove_legacy_unit_files
  fi
}

main() {
  local mode="${1:-}" parent_pid="${2:-}" expected_starttime="${3:-}"

  require_root
  require_exact_retirement_roots
  case "${mode}" in
    "")
      [ "$#" -eq 0 ] || fail "Invalid weather snapshot retirement arguments."
      if [ "${DIS_RETIRE_WEATHER_PARENT_OWNS_LOCK:-0}" != "1" ]; then
        acquire_dis_operation_lock weather-snapshot-cutover
      fi
      retire_weather_snapshots
      ;;
    --compat-parent-pid)
      [ "$#" -eq 2 ] || fail "Invalid weather snapshot compatibility arguments."
      if [ "${DIS_RETIRE_WEATHER_PARENT_OWNS_LOCK:-0}" != "1" ]; then
        acquire_dis_operation_lock weather-snapshot-cutover
      fi
      retire_weather_snapshots "${parent_pid}"
      ;;
    --clear-queues-only)
      [ "$#" -eq 1 ] || fail "Invalid weather snapshot queue cleanup arguments."
      if [ "${DIS_RETIRE_WEATHER_PARENT_OWNS_LOCK:-0}" != "1" ]; then
        acquire_dis_operation_lock weather-snapshot-cutover
      fi
      clear_legacy_weather_queues 1
      ;;
    --finalize-compat)
      [ "$#" -eq 3 ] || fail "Invalid weather snapshot finalizer arguments."
      finalize_compatibility_bridges "${parent_pid}" "${expected_starttime}"
      ;;
    *)
      fail "Unknown weather snapshot retirement mode."
      ;;
  esac
}

main "$@"
