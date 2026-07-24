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
      printf '[dis:error] TTS retirement sources must be root-owned, single-link and non-writable by group/world.\n' >&2
      exit 1
    }
fi
unset -f bootstrap_root_lifecycle_source
# shellcheck source=scripts/lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

LEGACY_ENGINE_UNIT="/etc/systemd/system/dis-tts-engine.service"
LEGACY_WORKER_UNIT="/etc/systemd/system/dis-speech.service"
LEGACY_ENGINE_WANTS_LINK="/etc/systemd/system/multi-user.target.wants/dis-tts-engine.service"
LEGACY_WORKER_WANTS_LINK="/etc/systemd/system/multi-user.target.wants/dis-speech.service"
LEGACY_SOCKET_ROOT="/run/dis-tts"
LEGACY_TTS_ROOT="/opt/dis-data/tts"
LEGACY_SPEECH_STORAGE="/opt/dis-data/webapp/backend/storage/app/speech"
LEGACY_SPEECH_SOURCE="/opt/dis/speech-engine"
LEGACY_ENV_FILE="/opt/dis-data/.env"
BRIDGE_MARKER="# DIS temporary legacy TTS compatibility bridge"

require_exact_retirement_roots() {
  [ "${DIS_INSTALL_PATH}" = "/opt/dis" ] \
    || fail "Server-side TTS retirement is restricted to /opt/dis."
  [ "${DIS_DATA_PATH}" = "/opt/dis-data" ] \
    || fail "Server-side TTS retirement is restricted to /opt/dis-data."
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

legacy_server_tts_present() {
  [ -e "${LEGACY_ENGINE_UNIT}" ] \
    || [ -e "${LEGACY_WORKER_UNIT}" ] \
    || [ -e "${LEGACY_ENGINE_WANTS_LINK}" ] \
    || [ -L "${LEGACY_ENGINE_WANTS_LINK}" ] \
    || [ -e "${LEGACY_WORKER_WANTS_LINK}" ] \
    || [ -L "${LEGACY_WORKER_WANTS_LINK}" ] \
    || [ -e "${LEGACY_SOCKET_ROOT}" ] \
    || [ -L "${LEGACY_SOCKET_ROOT}" ] \
    || [ -e "${LEGACY_TTS_ROOT}" ] \
    || [ -L "${LEGACY_TTS_ROOT}" ] \
    || [ -e "${LEGACY_SPEECH_STORAGE}" ] \
    || [ -L "${LEGACY_SPEECH_STORAGE}" ] \
    || [ -e "${LEGACY_SPEECH_SOURCE}" ] \
    || [ -L "${LEGACY_SPEECH_SOURCE}" ] \
    || { [ -f "${LEGACY_ENV_FILE}" ] \
      && grep -qE '^[[:space:]]*(export[[:space:]]+)?SPEECH_[A-Za-z0-9_]*[[:space:]]*=' "${LEGACY_ENV_FILE}"; }
}

stop_and_disable_legacy_services() {
  local service

  # Stop the queue consumer before its engine. This ordering prevents another
  # synthesis job from being claimed while the runtime is being retired.
  for service in dis-speech dis-tts-engine; do
    if systemd_service_exists "${service}" \
      || systemctl is-active --quiet "${service}.service"; then
      run_cmd systemctl stop "${service}"
    fi
  done
  for service in dis-speech dis-tts-engine; do
    if systemctl is-enabled --quiet "${service}" 2>/dev/null; then
      run_cmd systemctl disable "${service}"
    fi
  done
  for service in dis-speech dis-tts-engine; do
    if systemctl is-active --quiet "${service}.service"; then
      fail "Legacy service ${service}.service did not stop."
    fi
  done
}

legacy_queue_clear_is_available() {
  local backend="/opt/dis/webapp/backend"

  [ -f "${backend}/artisan" ] && [ ! -L "${backend}/artisan" ] \
    && [ -d "${backend}/vendor" ] && [ ! -L "${backend}/vendor" ]
}

clear_legacy_speech_queue() {
  local backend="/opt/dis/webapp/backend"

  log "Removing pending server-side speech jobs without touching other Redis queues"
  run_cmd runuser -u "${DIS_USER}" -- \
    php "${backend}/artisan" queue:clear redis --queue=speech --force
}

clear_legacy_speech_configuration_cache() {
  local backend="/opt/dis/webapp/backend"

  legacy_queue_clear_is_available || return 0
  log "Removing compiled Laravel configuration that could still contain retired speech settings"
  run_cmd runuser -u "${DIS_USER}" -- php "${backend}/artisan" config:clear
}

strip_speech_environment_keys() (
  set -euo pipefail

  local temporary="" acl_snapshot="" owner group mode

  [ -e "${LEGACY_ENV_FILE}" ] || return 0
  [ -f "${LEGACY_ENV_FILE}" ] && [ ! -L "${LEGACY_ENV_FILE}" ] \
    && [ "$(stat -c '%h' -- "${LEGACY_ENV_FILE}" 2>/dev/null || true)" = "1" ] \
    || fail "The managed environment file is not a safe regular file."
  require_root_controlled_parent "${LEGACY_ENV_FILE}"
  grep -qE '^[[:space:]]*(export[[:space:]]+)?SPEECH_[A-Za-z0-9_]*[[:space:]]*=' "${LEGACY_ENV_FILE}" \
    || return 0
  [ -x /usr/bin/getfacl ] && [ -x /usr/bin/setfacl ] \
    || fail "ACL tools are required to preserve the managed environment file."

  owner="$(stat -c '%u' -- "${LEGACY_ENV_FILE}")"
  group="$(stat -c '%g' -- "${LEGACY_ENV_FILE}")"
  mode="$(stat -c '%a' -- "${LEGACY_ENV_FILE}")"

  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would atomically remove all SPEECH_* settings from ${LEGACY_ENV_FILE}."
    return 0
  fi

  temporary="$(mktemp "${LEGACY_ENV_FILE}.without-speech.XXXXXX")"
  acl_snapshot="$(mktemp "${LEGACY_ENV_FILE}.acl.XXXXXX")"
  cleanup_speech_environment_rewrite() {
    local status="$?"
    trap - EXIT INT TERM
    rm -f -- "${temporary:-}" "${acl_snapshot:-}" 2>/dev/null || true
    exit "${status}"
  }
  trap cleanup_speech_environment_rewrite EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM

  /usr/bin/getfacl -cp -- "${LEGACY_ENV_FILE}" > "${acl_snapshot}"
  /usr/bin/awk \
    '!($0 ~ /^[[:space:]]*(export[[:space:]]+)?SPEECH_[A-Za-z0-9_]*[[:space:]]*=/)' \
    "${LEGACY_ENV_FILE}" > "${temporary}"
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
  log "Removed all legacy server-side speech settings from the managed environment."
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

remove_encrypted_speech_storage() (
  set -euo pipefail

  local app_parent="/opt/dis-data/webapp/backend/storage/app"
  local acl_snapshot="" owner group mode restored=0 writer_service

  if [ ! -e "${LEGACY_SPEECH_STORAGE}" ] && [ ! -L "${LEGACY_SPEECH_STORAGE}" ]; then
    return 0
  fi
  for writer_service in \
    "${PHP_FPM_SERVICE}" \
    dis-queue \
    dis-media \
    dis-push@1 \
    dis-push@2 \
    dis-push@3 \
    dis-push@4 \
    dis-scheduler \
    dis-incident-enrichment \
    dis-knmi \
    dis-knmi-realtime; do
    if systemctl is-active --quiet "${writer_service}.service" \
      && [ "${DRY_RUN:-0}" != "1" ]; then
      fail "Encrypted speech storage can only be retired while ${writer_service}.service is stopped."
    fi
  done
  [ -d "${app_parent}" ] && [ ! -L "${app_parent}" ] \
    || fail "The application storage parent is not a safe directory."
  [ -x /usr/bin/getfacl ] && [ -x /usr/bin/setfacl ] \
    || fail "ACL tools are required to preserve application storage permissions."

  owner="$(stat -c '%u' -- "${app_parent}")"
  group="$(stat -c '%g' -- "${app_parent}")"
  mode="$(stat -c '%a' -- "${app_parent}")"

  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would freeze ${app_parent}, remove encrypted speech storage, and restore its metadata."
    return 0
  fi

  acl_snapshot="$(mktemp /var/tmp/dis-speech-app-acl.XXXXXX)"
  /usr/bin/getfacl -cp -- "${app_parent}" > "${acl_snapshot}"
  restore_application_storage_parent() {
    local status="$?"
    local restore_status=0

    trap - EXIT INT TERM
    if [ "${restored}" = "0" ]; then
      chown "${owner}:${group}" "${app_parent}" || restore_status=$?
      chmod "${mode}" "${app_parent}" || restore_status=$?
      /usr/bin/setfacl --set-file="${acl_snapshot}" -- "${app_parent}" || restore_status=$?
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

  # The leaf is normally below a dis-writable directory. Freeze that exact
  # parent through the descriptor-based helper before removing the exact tree.
  ensure_managed_directory "${app_parent}" root root 0750
  remove_exact_managed_tree "${LEGACY_SPEECH_STORAGE}"

  chown "${owner}:${group}" "${app_parent}"
  chmod "${mode}" "${app_parent}"
  /usr/bin/setfacl --set-file="${acl_snapshot}" -- "${app_parent}"
  restored=1
  rm -f -- "${acl_snapshot}"
  acl_snapshot=""
  trap - EXIT INT TERM
)

remove_legacy_runtime_trees() {
  remove_exact_managed_tree "${LEGACY_SOCKET_ROOT}"
  remove_exact_managed_tree "${LEGACY_TTS_ROOT}"
  remove_exact_managed_tree "${LEGACY_SPEECH_SOURCE}"
}

remove_legacy_unit_files() {
  run_cmd rm -f -- \
    "${LEGACY_WORKER_WANTS_LINK}" \
    "${LEGACY_ENGINE_WANTS_LINK}" \
    "${LEGACY_WORKER_UNIT}" \
    "${LEGACY_ENGINE_UNIT}"
  run_cmd systemctl daemon-reload
  run_cmd systemctl reset-failed dis-speech dis-tts-engine >/dev/null 2>&1 || true
}

write_bridge_file() {
  local path="$1" owner="$2" group="$3" mode="$4"

  secure_path_operation write-file "${path}" "${owner}" "${group}" "${mode}"
}

install_legacy_compatibility_bridge() {
  ensure_managed_directory "${LEGACY_TTS_ROOT}" root "${DIS_GROUP}" 0750
  ensure_managed_directory "${LEGACY_TTS_ROOT}/runtime" root root 0755
  ensure_managed_directory "${LEGACY_TTS_ROOT}/runtime/bin" root root 0755
  ensure_managed_directory "${LEGACY_TTS_ROOT}/legacy-bridge" root root 0755
  ensure_managed_directory "${LEGACY_SPEECH_SOURCE}" root root 0755
  ensure_managed_directory "${LEGACY_SPEECH_SOURCE}/dis_tts_engine" root root 0755

  write_bridge_file "${LEGACY_TTS_ROOT}/runtime/bin/python" root root 0755 <<'EOF'
#!/usr/bin/env bash
export PYTHONDONTWRITEBYTECODE=1
exec /usr/bin/python3 "$@"
EOF

  write_bridge_file "${LEGACY_TTS_ROOT}/legacy-bridge/server.py" root root 0644 <<'EOF'
import os
import signal
import socket
import stat

SOCKET_PATH = "/run/dis-tts/engine.sock"
running = True


def stop(_signum, _frame):
    global running
    running = False


signal.signal(signal.SIGTERM, stop)
signal.signal(signal.SIGINT, stop)
try:
    metadata = os.lstat(SOCKET_PATH)
except FileNotFoundError:
    pass
else:
    if not stat.S_ISSOCK(metadata.st_mode):
        raise RuntimeError("legacy bridge socket path contains an unexpected object")
    os.unlink(SOCKET_PATH)

server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
server.bind(SOCKET_PATH)
os.chmod(SOCKET_PATH, 0o600)
server.listen(4)
server.settimeout(1.0)
try:
    while running:
        try:
            connection, _ = server.accept()
        except TimeoutError:
            continue
        connection.close()
finally:
    server.close()
    try:
        metadata = os.lstat(SOCKET_PATH)
    except FileNotFoundError:
        pass
    else:
        if stat.S_ISSOCK(metadata.st_mode):
            os.unlink(SOCKET_PATH)
EOF

  write_bridge_file "${LEGACY_SPEECH_SOURCE}/dis_tts_engine/__init__.py" root root 0644 <<'EOF'
"""Temporary compatibility namespace for one in-progress legacy updater."""
EOF

  write_bridge_file "${LEGACY_SPEECH_SOURCE}/dis_tts_engine/healthcheck.py" root root 0644 <<'EOF'
import socket

client = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
client.settimeout(3.0)
try:
    client.connect("/run/dis-tts/engine.sock")
finally:
    client.close()
EOF

  write_bridge_file "${LEGACY_ENGINE_UNIT}" root root 0644 <<'EOF'
# DIS temporary legacy TTS compatibility bridge
[Unit]
Description=DIS temporary legacy updater compatibility bridge
Before=dis-speech.service

[Service]
Type=simple
User=dis
Group=dis
RuntimeDirectory=dis-tts
RuntimeDirectoryMode=0750
ExecStart=/usr/bin/python3 -I -S /opt/dis-data/tts/legacy-bridge/server.py
Restart=on-failure
RestartSec=1s
NoNewPrivileges=true
PrivateDevices=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=/run/dis-tts
RestrictAddressFamilies=AF_UNIX
RestrictSUIDSGID=true

[Install]
WantedBy=multi-user.target
EOF

  write_bridge_file "${LEGACY_WORKER_UNIT}" root root 0644 <<'EOF'
# DIS temporary legacy TTS compatibility bridge
[Unit]
Description=DIS temporary legacy updater worker bridge
Requires=dis-tts-engine.service
After=dis-tts-engine.service

[Service]
Type=oneshot
User=dis
Group=dis
ExecStart=/usr/bin/true
RemainAfterExit=yes
NoNewPrivileges=true
PrivateDevices=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
RestrictSUIDSGID=true

[Install]
WantedBy=multi-user.target
EOF

  run_cmd systemctl daemon-reload
  log "Installed an inert compatibility bridge for the already-running legacy updater."
}

schedule_compatibility_finalizer() {
  local parent_pid="$1" parent_starttime unit

  parent_starttime="$(process_starttime "${parent_pid}")" \
    || fail "Could not identify the parent updater process generation."
  unit="dis-legacy-tts-retirement-${parent_pid}-$$"
  [ -x /usr/bin/systemd-run ] \
    || fail "systemd-run is required to finalize the legacy TTS retirement."
  run_cmd /usr/bin/systemd-run \
    --quiet \
    --collect \
    --unit="${unit}" \
    --property=Type=exec \
    /usr/bin/bash "${DIS_INSTALL_PATH}/scripts/retire-server-tts.sh" \
      --finalize-compat "${parent_pid}" "${parent_starttime}"
  log "Scheduled legacy updater compatibility cleanup after parent process ${parent_pid} exits."
}

bridge_unit_is_safe() {
  local path="$1"

  [ -f "${path}" ] && [ ! -L "${path}" ] \
    && [ "$(stat -c '%u:%g:%a:%h' -- "${path}" 2>/dev/null || true)" = "0:0:644:1" ] \
    && grep -Fxq "${BRIDGE_MARKER}" "${path}"
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

  acquire_dis_operation_lock server-tts-retirement
  bridge_unit_is_safe "${LEGACY_WORKER_UNIT}" \
    || fail "Refusing to remove an unrecognized dis-speech.service after compatibility mode."
  bridge_unit_is_safe "${LEGACY_ENGINE_UNIT}" \
    || fail "Refusing to remove an unrecognized dis-tts-engine.service after compatibility mode."

  for service in dis-speech dis-tts-engine; do
    if systemd_service_exists "${service}" \
      || systemctl is-active --quiet "${service}.service"; then
      run_cmd systemctl stop "${service}"
    fi
  done
  remove_legacy_unit_files
  remove_legacy_runtime_trees
  log "Removed the temporary legacy updater compatibility bridge."
}

retire_server_tts() {
  local compatibility_parent_pid="${1:-}"
  local had_legacy=0

  legacy_server_tts_present && had_legacy=1
  stop_and_disable_legacy_services
  # An earlier partial retirement may have removed every filesystem marker
  # while Redis still contains delayed speech jobs. Clear the targeted queue on
  # every deployment with an initialized backend, independent of those markers.
  if legacy_queue_clear_is_available; then
    clear_legacy_speech_queue
  else
    if [ "${DIS_RETIRE_TTS_ALLOW_DEFERRED_QUEUE_CLEAR:-0}" = "1" ]; then
      log "Deferring the targeted speech queue clear until backend dependencies are installed."
    else
      [ "${had_legacy}" = "0" ] \
        || fail "Legacy server-side TTS data exists but Laravel is unavailable to clear its queue."
      log "Skipping the absent speech queue on an uninitialized fresh installation."
    fi
  fi
  strip_speech_environment_keys
  clear_legacy_speech_configuration_cache
  remove_encrypted_speech_storage
  remove_legacy_runtime_trees
  remove_legacy_unit_files

  if [ -n "${compatibility_parent_pid}" ]; then
    install_legacy_compatibility_bridge
    schedule_compatibility_finalizer "${compatibility_parent_pid}"
  fi
  log "Server-side TTS runtime, models, cache, recordings and configuration have been retired."
}

main() {
  local mode="${1:-}" parent_pid="${2:-}" expected_starttime="${3:-}"

  require_root
  require_exact_retirement_roots
  case "${mode}" in
    --clear-queue-only)
      [ "$#" -eq 1 ] || fail "Invalid queue retirement arguments."
      if [ "${DIS_RETIRE_TTS_PARENT_OWNS_LOCK:-0}" != "1" ]; then
        acquire_dis_operation_lock server-tts-retirement
      fi
      legacy_queue_clear_is_available \
        || fail "Laravel is unavailable to clear the retired speech queue."
      clear_legacy_speech_queue
      clear_legacy_speech_configuration_cache
      ;;
    --finalize-compat)
      [ "$#" -eq 3 ] || fail "Invalid finalizer arguments."
      finalize_compatibility_bridge "${parent_pid}" "${expected_starttime}"
      ;;
    --compat-parent-pid)
      [ "$#" -eq 2 ] || fail "Invalid compatibility arguments."
      if [ "${DIS_RETIRE_TTS_PARENT_OWNS_LOCK:-0}" != "1" ]; then
        acquire_dis_operation_lock server-tts-retirement
      fi
      retire_server_tts "${parent_pid}"
      ;;
    "")
      [ "$#" -eq 0 ] || fail "Invalid retirement arguments."
      if [ "${DIS_RETIRE_TTS_PARENT_OWNS_LOCK:-0}" != "1" ]; then
        acquire_dis_operation_lock server-tts-retirement
      fi
      retire_server_tts
      ;;
    *)
      fail "Unknown server-side TTS retirement mode."
      ;;
  esac
}

main "$@"
