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
  mode="${BASH_REMATCH[1]}"; (( (8#${mode} & 8#022) == 0 )) || return 1
  metadata="$(/usr/bin/stat -c '%u:%a' -- / 2>/dev/null || true)"
  [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1
  mode="${BASH_REMATCH[1]}"; (( (8#${mode} & 8#022) == 0 )) || return 1
  parent="${path%/*}"; IFS='/' read -r -a bootstrap_components <<< "${parent#/}"
  for component in "${bootstrap_components[@]}"; do
    [ -n "${component}" ] || continue; current="${current}/${component}"
    [ -d "${current}" ] && [ ! -L "${current}" ] || return 1
    metadata="$(/usr/bin/stat -c '%u:%a' -- "${current}" 2>/dev/null || true)"
    [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1
    mode="${BASH_REMATCH[1]}"; (( (8#${mode} & 8#022) == 0 )) || return 1
  done
}
if [ "${EUID}" -eq 0 ]; then
  [ ! -L "${BASH_SOURCE[0]}" ] \
    && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/${LIFECYCLE_SOURCE_NAME}" \
    && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/lib/common.sh" \
    || { printf '[dis:error] Lifecycle sources must be root-owned, single-link and non-writable by group/world.\n' >&2; exit 1; }
fi
unset -f bootstrap_root_lifecycle_source
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
BACKEND_DIR="${APP_ROOT}/webapp/backend"

require_root
acquire_dis_operation_lock permission-self-heal
require_directory "${APP_ROOT}"

log "Self-healing DIS file permissions"
load_data_path_from_env "${APP_ROOT}/.env"

if ! getent group "${DIS_GROUP}" >/dev/null 2>&1; then
  run_cmd groupadd --system "${DIS_GROUP}"
fi
if ! id "${DIS_USER}" >/dev/null 2>&1; then
  run_cmd useradd --system --gid "${DIS_GROUP}" --home-dir "${APP_ROOT}" --shell /usr/sbin/nologin "${DIS_USER}"
fi
if id www-data >/dev/null 2>&1; then
  run_cmd gpasswd -d www-data "${DIS_GROUP}" >/dev/null 2>&1 || true
fi

# This establishes root-owned, non-writable parents before any recursive
# inspection. A stale runtime-created symlink therefore fails closed instead
# of being followed by install/chown/chmod.
ensure_data_links "${APP_ROOT}"

for runtime_service in "${PHP_FPM_SERVICE}" dis-push@1 dis-push@2 dis-push@3 dis-push@4 dis-queue dis-media dis-tts-engine dis-speech dis-scheduler dis-websocket dis-frontend dis-incident-enrichment dis-knmi dis-knmi-realtime dis-backup-request dis-osrm-admin-request; do
  if systemd_service_exists "${runtime_service}" && systemctl is-active --quiet "${runtime_service}"; then
    fail "Permission repair requires ${runtime_service} to be stopped under deployment maintenance."
  fi
done
if systemd_unit_exists dis-backup-request.path && systemctl is-active --quiet dis-backup-request.path; then
  fail "Permission repair requires dis-backup-request.path to be stopped under deployment maintenance."
fi
if systemd_unit_exists dis-osrm-admin-request.path && systemctl is-active --quiet dis-osrm-admin-request.path; then
  fail "Permission repair requires dis-osrm-admin-request.path to be stopped under deployment maintenance."
fi
if systemd_unit_exists dis-osrm-admin-request.timer && systemctl is-active --quiet dis-osrm-admin-request.timer; then
  fail "Permission repair requires dis-osrm-admin-request.timer to be stopped under deployment maintenance."
fi

install_osrm_admin_runtime_bundle "${APP_ROOT}"

ensure_managed_directory /mnt/dis-backup root root 0750

if [ -f "${DIS_DATA_PATH}/.env" ]; then
  run_cmd chown -h root:"${DIS_GROUP}" "${DIS_DATA_PATH}/.env"
  run_cmd chmod 0640 "${DIS_DATA_PATH}/.env"
fi

if [ -d "${APP_ROOT}/scripts" ]; then
  run_cmd find -P "${APP_ROOT}/scripts" -type f -name "*.sh" -exec chmod 0755 {} +
fi
for script in setup.sh update.sh uninstall.sh; do
  if [ -f "${APP_ROOT}/${script}" ] && [ ! -L "${APP_ROOT}/${script}" ]; then
    run_cmd chmod 0755 "${APP_ROOT}/${script}"
  fi
done

backend_runtime_leaves=(
  "${DIS_DATA_PATH}/webapp/backend/storage/app"
  "${DIS_DATA_PATH}/webapp/backend/storage/framework/cache"
  "${DIS_DATA_PATH}/webapp/backend/storage/framework/sessions"
  "${DIS_DATA_PATH}/webapp/backend/storage/framework/views"
  "${DIS_DATA_PATH}/webapp/backend/storage/logs"
  "${DIS_DATA_PATH}/webapp/backend/storage/tmp"
  "${DIS_DATA_PATH}/webapp/backend/storage/composer"
)

for runtime_leaf in "${backend_runtime_leaves[@]}"; do
  repair_managed_tree "${runtime_leaf}" "${DIS_USER}" "${DIS_GROUP}" 0770 0660
done

if [ -d "${BACKEND_DIR}" ]; then
  reconcile_backend_source_permissions "${BACKEND_DIR}"
  ensure_managed_directory "${BACKEND_DIR}/bootstrap/cache" "${DIS_USER}" "${DIS_GROUP}" 0770
  repair_managed_tree "${BACKEND_DIR}/bootstrap/cache" "${DIS_USER}" "${DIS_GROUP}" 0770 0660

  if [ -d "${BACKEND_DIR}/vendor" ]; then
    run_cmd chown -R root:root "${BACKEND_DIR}/vendor"
    run_cmd chmod -R u=rwX,go=rX "${BACKEND_DIR}/vendor"
  fi

  run_cmd ln -sfn "${APP_ROOT}/.env" "${BACKEND_DIR}/.env"
  run_cmd chown -h root:root "${BACKEND_DIR}/.env"
fi

# Top-level operational data has explicit leaf ownership as well. The parent
# itself remains root-owned and non-writable.
for runtime_leaf in \
  "${DIS_DATA_PATH}/storage/app" \
  "${DIS_DATA_PATH}/storage/logs" \
  "${DIS_DATA_PATH}/storage/tmp"; do
  repair_managed_tree "${runtime_leaf}" "${DIS_USER}" "${DIS_GROUP}" 0770 0660
done
repair_managed_tree "${DIS_DATA_PATH}/storage/generated" root root 0755 0644
repair_managed_tree "${DIS_DATA_PATH}/storage/releases" root root 0750 0640
repair_speech_data_permissions

if [ -d "${DIS_DATA_PATH}/backup" ]; then
  repair_managed_tree "${DIS_DATA_PATH}/backup" root "${DIS_GROUP}" 0750 0640
fi

run_cmd chown -h root:root "${DIS_DATA_PATH}/backup-imports" "${DIS_DATA_PATH}/backup-requests" "${DIS_DATA_PATH}/backup-request-work"
run_cmd chmod 1730 "${DIS_DATA_PATH}/backup-imports" "${DIS_DATA_PATH}/backup-requests"
run_cmd chmod 0700 "${DIS_DATA_PATH}/backup-request-work"

install_osrm_admin_layout
run_cmd chown -h root:root \
  "${DIS_DATA_PATH}/osrm-admin" \
  "${DIS_DATA_PATH}/osrm-admin/requests" \
  "${DIS_DATA_PATH}/osrm-admin/work"
run_cmd chmod 0750 "${DIS_DATA_PATH}/osrm-admin"
run_cmd chmod 1730 "${DIS_DATA_PATH}/osrm-admin/requests"
run_cmd chmod 0700 "${DIS_DATA_PATH}/osrm-admin/work"
repair_managed_tree "${DIS_DATA_PATH}/osrm-admin/results" root "${DIS_GROUP}" 0750 0640

if [ -f "${DIS_DATA_PATH}/secrets/backup-encryption.key" ] && [ ! -L "${DIS_DATA_PATH}/secrets/backup-encryption.key" ]; then
  run_cmd chown -h root:root "${DIS_DATA_PATH}/secrets/backup-encryption.key"
  run_cmd chmod 0600 "${DIS_DATA_PATH}/secrets/backup-encryption.key"
fi

if id www-data >/dev/null 2>&1; then
  run_cmd setfacl -m "u:www-data:--x" "${DIS_DATA_PATH}"
  run_cmd setfacl -m "u:www-data:--x" "${DIS_DATA_PATH}/webapp" "${DIS_DATA_PATH}/webapp/backend"
  run_cmd setfacl -m "u:www-data:r-x" \
    "${DIS_DATA_PATH}/webapp/backend/storage" \
    "${DIS_DATA_PATH}/webapp/backend/storage/framework"

  for runtime_leaf in "${backend_runtime_leaves[@]}"; do
    secure_path_operation acl-tree "${runtime_leaf}" www-data rwx rw-
  done
  # PHP-FPM uploads as www-data while isolated workers run as dis. Preserve a
  # named/default dis ACL on worker-owned app storage so newly created media can
  # cross that identity boundary without putting www-data in the dis group.
  secure_path_operation acl-tree "${DIS_DATA_PATH}/webapp/backend/storage/app" "${DIS_USER}" rwx rw-
  secure_path_operation acl-tree "${BACKEND_DIR}/bootstrap/cache" www-data r-x r--

  if [ -f "${DIS_DATA_PATH}/.env" ]; then
    run_cmd setfacl -m "u:www-data:r--" "${DIS_DATA_PATH}/.env"
  fi

  run_cmd setfacl -m "u:www-data:r-x" "${DIS_DATA_PATH}/storage"
  secure_path_operation acl-tree "${DIS_DATA_PATH}/storage/logs" www-data r-x r--

  secure_path_operation acl-tree "${DIS_DATA_PATH}/backup" www-data r-x r--
  run_cmd setfacl -m "u:www-data:r-x" /var/log/dis
  run_cmd setfacl -m "u:www-data:-wx" "${DIS_DATA_PATH}/backup-imports" "${DIS_DATA_PATH}/backup-requests"
  run_cmd setfacl -x "d:u:www-data" "${DIS_DATA_PATH}/backup-imports" "${DIS_DATA_PATH}/backup-requests" 2>/dev/null || true
  run_cmd setfacl -m "u:www-data:--x" "${DIS_DATA_PATH}/osrm-admin"
  run_cmd setfacl -m "u:www-data:-wx" "${DIS_DATA_PATH}/osrm-admin/requests"
  secure_path_operation acl-tree "${DIS_DATA_PATH}/osrm-admin/results" www-data r-x r--
  run_cmd setfacl -x "d:u:www-data" "${DIS_DATA_PATH}/osrm-admin/requests" 2>/dev/null || true
fi

run_cmd setfacl -m "u:${DIS_USER}:-wx" "${DIS_DATA_PATH}/backup-requests"
run_cmd setfacl -x "d:u:${DIS_USER}" "${DIS_DATA_PATH}/backup-requests" 2>/dev/null || true

# A previous updater process keeps its old shell functions after checking out
# a new release, but it executes this script from the new checkout after its
# legacy cache cleanup. Rebuild only when Composer has recorded that vendor
# exactly matches the current lock file, so the checkout boundary remains safe.
if [ -d "${BACKEND_DIR}" ] \
  && { [ ! -f "${BACKEND_DIR}/bootstrap/cache/packages.php" ] \
    || [ ! -f "${BACKEND_DIR}/bootstrap/cache/services.php" ]; } \
  && backend_dependency_state_is_current "${BACKEND_DIR}"; then
  log "Regenerating missing backend manifests from verified dependencies"
  regenerate_backend_package_manifest "${BACKEND_DIR}"
fi

log "Permission self-heal completed"
