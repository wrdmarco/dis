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
  run_cmd usermod -aG "${DIS_GROUP}" www-data
fi

ensure_data_links "${APP_ROOT}"

ensure_directory "${APP_ROOT}/storage" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${APP_ROOT}/storage/app" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${APP_ROOT}/storage/logs" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${APP_ROOT}/storage/tmp" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${APP_ROOT}/backup" root "${DIS_GROUP}" 0770
ensure_directory "${APP_ROOT}/secrets" root "${DIS_GROUP}" 0750
ensure_directory "${DIS_DATA_PATH}/backup" root "${DIS_GROUP}" 0770
ensure_directory "${DIS_DATA_PATH}/backup-requests" root "${DIS_GROUP}" 0770
ensure_directory "${DIS_DATA_PATH}/secrets" root "${DIS_GROUP}" 0750

if [ -f "${APP_ROOT}/.env" ]; then
  run_cmd chown root:"${DIS_GROUP}" "${APP_ROOT}/.env"
  run_cmd chmod 0640 "${APP_ROOT}/.env"
fi

if [ -d "${APP_ROOT}/scripts" ]; then
  run_cmd find "${APP_ROOT}/scripts" -type f -name "*.sh" -exec chmod 0755 {} +
fi
for script in setup.sh update.sh uninstall.sh; do
  if [ -f "${APP_ROOT}/${script}" ]; then
    run_cmd chmod 0755 "${APP_ROOT}/${script}"
  fi
done

if [ -d "${BACKEND_DIR}" ]; then
  ensure_directory "${BACKEND_DIR}/storage/app" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${BACKEND_DIR}/storage/framework/cache" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${BACKEND_DIR}/storage/framework/sessions" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${BACKEND_DIR}/storage/framework/views" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${BACKEND_DIR}/storage/logs" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${BACKEND_DIR}/storage/composer" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${BACKEND_DIR}/bootstrap/cache" "${DIS_USER}" "${DIS_GROUP}" 0750

  run_cmd chown -R "${DIS_USER}:${DIS_GROUP}" "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache"
  run_cmd find "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache" -type d -exec chmod 0770 {} +
  run_cmd find "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache" -type f -exec chmod 0660 {} +

  if [ -d "${BACKEND_DIR}/vendor" ]; then
    run_cmd chown -R "${DIS_USER}:${DIS_GROUP}" "${BACKEND_DIR}/vendor"
    run_cmd find "${BACKEND_DIR}/vendor" -type d -exec chmod 0750 {} +
    run_cmd find "${BACKEND_DIR}/vendor" -type f -exec chmod 0640 {} +
  fi

  run_cmd ln -sfn "${APP_ROOT}/.env" "${BACKEND_DIR}/.env"
  run_cmd chown -h "${DIS_USER}:${DIS_GROUP}" "${BACKEND_DIR}/.env"

  if id www-data >/dev/null 2>&1; then
    run_cmd chown -R "www-data:${DIS_GROUP}" "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache" || true
    run_cmd find "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache" -type d -exec chmod 0770 {} + || true
    run_cmd find "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache" -type f -exec chmod 0660 {} + || true
    if [ -f "${APP_ROOT}/.env" ]; then
      run_cmd setfacl -m "u:www-data:r--" "${APP_ROOT}/.env" || true
    fi
    run_cmd setfacl -R -m "u:www-data:rwx" "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache" || true
    run_cmd setfacl -R -d -m "u:www-data:rwx" "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache" || true
    if [ -d "${BACKEND_DIR}/vendor" ]; then
      run_cmd setfacl -R -m "u:www-data:rx" "${BACKEND_DIR}/vendor" || true
    fi
  fi
fi

if [ -d "${APP_ROOT}/backup" ]; then
  run_cmd chgrp -R "${DIS_GROUP}" "${APP_ROOT}/backup" || true
  run_cmd chmod 0770 "${APP_ROOT}/backup" || true
  run_cmd find "${APP_ROOT}/backup" -mindepth 1 -type d -exec chmod 0750 {} + || true
  run_cmd find "${APP_ROOT}/backup" -type f -exec chmod 0640 {} + || true
fi

if [ -d "${DIS_DATA_PATH}/backup" ]; then
  run_cmd chgrp -R "${DIS_GROUP}" "${DIS_DATA_PATH}/backup" || true
  run_cmd chmod 0770 "${DIS_DATA_PATH}/backup" || true
  run_cmd find "${DIS_DATA_PATH}/backup" -mindepth 1 -type d -exec chmod 0750 {} + || true
  run_cmd find "${DIS_DATA_PATH}/backup" -type f -exec chmod 0640 {} + || true
fi

if [ -d "${DIS_DATA_PATH}/backup-requests" ]; then
  run_cmd chgrp -R "${DIS_GROUP}" "${DIS_DATA_PATH}/backup-requests" || true
  run_cmd chmod 0770 "${DIS_DATA_PATH}/backup-requests" || true
  run_cmd find "${DIS_DATA_PATH}/backup-requests" -type f -exec chmod 0640 {} + || true
fi

if [ -d "/mnt/dis-backup" ] && mountpoint -q /mnt/dis-backup; then
  run_cmd find /mnt/dis-backup -maxdepth 2 -type d -exec chmod 0750 {} + || true
  run_cmd find /mnt/dis-backup -maxdepth 2 -type f -exec chmod 0640 {} + || true
fi

log "Permission self-heal completed"
