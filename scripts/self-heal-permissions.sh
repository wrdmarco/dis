#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
BACKEND_DIR="${APP_ROOT}/webapp/backend"

require_root
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

for runtime_service in "${PHP_FPM_SERVICE}" dis-queue dis-scheduler dis-websocket dis-frontend dis-backup-request; do
  if systemd_service_exists "${runtime_service}" && systemctl is-active --quiet "${runtime_service}"; then
    fail "Permission repair requires ${runtime_service} to be stopped under deployment maintenance."
  fi
done
if systemd_unit_exists dis-backup-request.path && systemctl is-active --quiet dis-backup-request.path; then
  fail "Permission repair requires dis-backup-request.path to be stopped under deployment maintenance."
fi

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

if [ -d "${DIS_DATA_PATH}/backup" ]; then
  repair_managed_tree "${DIS_DATA_PATH}/backup" root "${DIS_GROUP}" 0750 0640
fi

run_cmd chown -h root:root "${DIS_DATA_PATH}/backup-imports" "${DIS_DATA_PATH}/backup-requests" "${DIS_DATA_PATH}/backup-request-work"
run_cmd chmod 1730 "${DIS_DATA_PATH}/backup-imports" "${DIS_DATA_PATH}/backup-requests"
run_cmd chmod 0700 "${DIS_DATA_PATH}/backup-request-work"

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

  for runtime_leaf in "${backend_runtime_leaves[@]}" "${BACKEND_DIR}/bootstrap/cache"; do
    secure_path_operation acl-tree "${runtime_leaf}" www-data rwx rw-
  done

  if [ -f "${DIS_DATA_PATH}/.env" ]; then
    run_cmd setfacl -m "u:www-data:r--" "${DIS_DATA_PATH}/.env"
  fi

  run_cmd setfacl -m "u:www-data:r-x" "${DIS_DATA_PATH}/storage"
  secure_path_operation acl-tree "${DIS_DATA_PATH}/storage/logs" www-data r-x r--

  secure_path_operation acl-tree "${DIS_DATA_PATH}/backup" www-data r-x r--
  run_cmd setfacl -m "u:www-data:-wx" "${DIS_DATA_PATH}/backup-imports" "${DIS_DATA_PATH}/backup-requests"
  run_cmd setfacl -x "d:u:www-data" "${DIS_DATA_PATH}/backup-imports" "${DIS_DATA_PATH}/backup-requests" 2>/dev/null || true
fi

run_cmd setfacl -m "u:${DIS_USER}:-wx" "${DIS_DATA_PATH}/backup-requests"
run_cmd setfacl -x "d:u:${DIS_USER}" "${DIS_DATA_PATH}/backup-requests" 2>/dev/null || true

log "Permission self-heal completed"
