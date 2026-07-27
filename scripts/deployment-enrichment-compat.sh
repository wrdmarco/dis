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
    || {
      printf '[dis:error] Deployment-enrichment compatibility sources must be root-owned, single-link and non-writable by group/world.\n' >&2
      exit 1
    }
fi
unset -f bootstrap_root_lifecycle_source
# shellcheck source=scripts/lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

CANONICAL_SERVICE="dis-deployment-enrichment"
LEGACY_SERVICE="dis-incident-enrichment"
CANONICAL_UNIT="/etc/systemd/system/${CANONICAL_SERVICE}.service"
LEGACY_UNIT="/etc/systemd/system/${LEGACY_SERVICE}.service"
LEGACY_WANTS_LINK="/etc/systemd/system/multi-user.target.wants/${LEGACY_SERVICE}.service"
CANONICAL_SOURCE="${DIS_INSTALL_PATH}/infrastructure/systemd/dis-deployment-enrichment.service"
BRIDGE_SOURCE="${DIS_INSTALL_PATH}/infrastructure/systemd/dis-incident-enrichment-compat.service"
BRIDGE_MARKER="# DIS temporary legacy deployment-enrichment compatibility bridge"

require_exact_cutover_root() {
  [ "${DIS_INSTALL_PATH}" = "/opt/dis" ] \
    || fail "Deployment-enrichment compatibility is restricted to /opt/dis."
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

canonical_source_is_safe() {
  root_controlled_bundle_source_is_safe "${CANONICAL_SOURCE}" \
    && grep -Fq -- 'Description=DIS Deployment Location Enrichment Worker' "${CANONICAL_SOURCE}" \
    && grep -Fq -- '--queue=deployment-enrichment,incident-enrichment' "${CANONICAL_SOURCE}"
}

canonical_unit_is_safe() {
  root_owned_runtime_file_is_safe "${CANONICAL_UNIT}" 644 \
    && grep -Fq -- 'Description=DIS Deployment Location Enrichment Worker' "${CANONICAL_UNIT}" \
    && grep -Fq -- '--queue=deployment-enrichment,incident-enrichment' "${CANONICAL_UNIT}"
}

bridge_source_is_safe() {
  root_controlled_bundle_source_is_safe "${BRIDGE_SOURCE}" \
    && grep -Fxq -- "${BRIDGE_MARKER}" "${BRIDGE_SOURCE}" \
    && grep -Fxq -- 'Requires=dis-deployment-enrichment.service' "${BRIDGE_SOURCE}" \
    && grep -Fxq -- 'After=dis-deployment-enrichment.service' "${BRIDGE_SOURCE}" \
    && grep -Fxq -- 'BindsTo=dis-deployment-enrichment.service' "${BRIDGE_SOURCE}" \
    && grep -Fxq -- 'Type=oneshot' "${BRIDGE_SOURCE}" \
    && grep -Fxq -- 'ExecStart=/usr/bin/true' "${BRIDGE_SOURCE}" \
    && grep -Fxq -- 'RemainAfterExit=yes' "${BRIDGE_SOURCE}" \
    && ! grep -Fq -- 'WantedBy=' "${BRIDGE_SOURCE}"
}

bridge_unit_is_safe() {
  root_owned_runtime_file_is_safe "${LEGACY_UNIT}" 644 \
    && grep -Fxq -- "${BRIDGE_MARKER}" "${LEGACY_UNIT}" \
    && grep -Fxq -- 'Requires=dis-deployment-enrichment.service' "${LEGACY_UNIT}" \
    && grep -Fxq -- 'BindsTo=dis-deployment-enrichment.service' "${LEGACY_UNIT}" \
    && grep -Fxq -- 'ExecStart=/usr/bin/true' "${LEGACY_UNIT}"
}

original_legacy_unit_is_safe() {
  root_owned_runtime_file_is_safe "${LEGACY_UNIT}" 644 \
    && grep -Fxq -- 'Description=DIS Incident Location Enrichment Worker' "${LEGACY_UNIT}" \
    && grep -Fxq -- 'WorkingDirectory=/opt/dis/webapp/backend' "${LEGACY_UNIT}" \
    && grep -Fq -- 'artisan queue:work redis --queue=incident-enrichment ' "${LEGACY_UNIT}"
}

require_known_legacy_unit_or_absence() {
  if [ -e "${LEGACY_UNIT}" ] || [ -L "${LEGACY_UNIT}" ]; then
    original_legacy_unit_is_safe || bridge_unit_is_safe \
      || fail "Refusing to replace an unrecognized ${LEGACY_SERVICE}.service."
    return
  fi

  if systemd_service_exists "${LEGACY_SERVICE}"; then
    fail "Refusing to shadow ${LEGACY_SERVICE}.service outside the managed unit path."
  fi
}

stop_and_disable_legacy_service() {
  if systemd_service_exists "${LEGACY_SERVICE}" \
    || systemctl is-active --quiet "${LEGACY_SERVICE}.service"; then
    run_cmd systemctl stop "${LEGACY_SERVICE}"
  fi
  if systemctl is-enabled --quiet "${LEGACY_SERVICE}" 2>/dev/null; then
    run_cmd systemctl disable "${LEGACY_SERVICE}"
  fi
  if systemctl is-active --quiet "${LEGACY_SERVICE}.service"; then
    fail "Legacy service ${LEGACY_SERVICE}.service did not stop."
  fi
}

remove_legacy_service_files() {
  require_known_legacy_unit_or_absence
  stop_and_disable_legacy_service
  run_cmd rm -f -- "${LEGACY_WANTS_LINK}" "${LEGACY_UNIT}"
  run_cmd systemctl daemon-reload
  run_cmd systemctl reset-failed "${LEGACY_SERVICE}" >/dev/null 2>&1 || true
}

install_compatibility_bridge() {
  canonical_source_is_safe \
    || fail "The canonical deployment-enrichment unit source is not safe."
  canonical_unit_is_safe \
    || fail "The canonical deployment-enrichment unit is not safely installed."
  bridge_source_is_safe \
    || fail "The legacy deployment-enrichment bridge source is not safe."
  require_known_legacy_unit_or_absence
  stop_and_disable_legacy_service
  require_root_controlled_parent "${LEGACY_UNIT}"
  run_cmd install -o root -g root -m 0644 -- "${BRIDGE_SOURCE}" "${LEGACY_UNIT}"
  if [ "${DRY_RUN:-0}" != "1" ]; then
    bridge_unit_is_safe \
      || fail "The installed legacy deployment-enrichment bridge failed verification."
  fi
  run_cmd systemctl daemon-reload
  log "Installed a temporary legacy incident-enrichment service bridge for the already-running updater."
}

schedule_compatibility_finalizer() {
  local parent_pid="$1" parent_starttime unit

  parent_starttime="$(process_starttime "${parent_pid}")" \
    || fail "Could not identify the parent updater process generation."
  unit="dis-legacy-deployment-enrichment-${parent_pid}-$$"
  [ -x /usr/bin/systemd-run ] \
    || fail "systemd-run is required to finalize deployment-enrichment compatibility."
  run_cmd /usr/bin/systemd-run \
    --quiet \
    --collect \
    --unit="${unit}" \
    --property=Type=exec \
    /usr/bin/bash "${DIS_INSTALL_PATH}/scripts/deployment-enrichment-compat.sh" \
      --finalize-compat "${parent_pid}" "${parent_starttime}"
  log "Scheduled legacy incident-enrichment bridge cleanup after parent process ${parent_pid} exits."
}

finalize_compatibility_bridge() {
  local parent_pid="$1" expected_starttime="$2" current_starttime

  [[ "${parent_pid}" =~ ^[1-9][0-9]*$ ]] \
    && [[ "${expected_starttime}" =~ ^[1-9][0-9]*$ ]] \
    || fail "Invalid legacy updater process identity."
  while true; do
    current_starttime="$(process_starttime "${parent_pid}" || true)"
    [ "${current_starttime}" = "${expected_starttime}" ] || break
    sleep 1
  done

  acquire_dis_operation_lock deployment-enrichment-cutover
  canonical_unit_is_safe \
    || fail "The canonical deployment-enrichment unit changed before compatibility cleanup."
  bridge_unit_is_safe \
    || fail "Refusing to remove an unrecognized ${LEGACY_SERVICE}.service after compatibility mode."
  stop_and_disable_legacy_service
  run_cmd rm -f -- "${LEGACY_WANTS_LINK}" "${LEGACY_UNIT}"
  run_cmd systemctl daemon-reload
  run_cmd systemctl reset-failed "${LEGACY_SERVICE}" >/dev/null 2>&1 || true
  log "Removed the temporary legacy incident-enrichment service bridge."
}

retire_legacy_service() {
  canonical_source_is_safe \
    || fail "The canonical deployment-enrichment unit source is not safe."
  if [ "${DRY_RUN:-0}" != "1" ]; then
    canonical_unit_is_safe \
      || fail "The canonical deployment-enrichment unit is not safely installed."
  fi
  remove_legacy_service_files
  log "Retired the legacy incident-enrichment service name."
}

main() {
  local mode="${1:-}" parent_pid="${2:-}" expected_starttime="${3:-}"

  require_root
  require_exact_cutover_root
  case "${mode}" in
    --retire)
      [ "$#" -eq 1 ] || fail "Invalid deployment-enrichment retirement arguments."
      if [ "${DIS_DEPLOYMENT_ENRICHMENT_PARENT_OWNS_LOCK:-0}" != "1" ]; then
        acquire_dis_operation_lock deployment-enrichment-cutover
      fi
      retire_legacy_service
      ;;
    --compat-parent-pid)
      [ "$#" -eq 2 ] || fail "Invalid deployment-enrichment compatibility arguments."
      if [ "${DIS_DEPLOYMENT_ENRICHMENT_PARENT_OWNS_LOCK:-0}" != "1" ]; then
        acquire_dis_operation_lock deployment-enrichment-cutover
      fi
      install_compatibility_bridge
      schedule_compatibility_finalizer "${parent_pid}"
      ;;
    --finalize-compat)
      [ "$#" -eq 3 ] || fail "Invalid deployment-enrichment finalizer arguments."
      finalize_compatibility_bridge "${parent_pid}" "${expected_starttime}"
      ;;
    *)
      fail "Unknown deployment-enrichment compatibility mode."
      ;;
  esac
}

main "$@"
