#!/usr/bin/env bash
set -euo pipefail

LIFECYCLE_SOURCE_PATH="${BASH_SOURCE[0]}"
case "${LIFECYCLE_SOURCE_PATH}" in */*) SCRIPT_DIR="${LIFECYCLE_SOURCE_PATH%/*}" ;; *) SCRIPT_DIR=. ;; esac
LIFECYCLE_SOURCE_NAME="${LIFECYCLE_SOURCE_PATH##*/}"
SCRIPT_DIR="$(cd -- "${SCRIPT_DIR}" && pwd -P)"
bootstrap_root_lifecycle_source() {
  local path="$1" parent current="" component metadata mode
  [ -f "${path}" ] && [ ! -L "${path}" ] || return 1
  metadata="$(/usr/bin/stat -c '%u:%a:%h' -- "${path}" 2>/dev/null || true)"; [[ "${metadata}" =~ ^0:([0-7]+):1$ ]] || return 1
  mode="${BASH_REMATCH[1]}"; (( (8#${mode} & 8#022) == 0 )) || return 1
  metadata="$(/usr/bin/stat -c '%u:%a' -- / 2>/dev/null || true)"; [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1; mode="${BASH_REMATCH[1]}"; (( (8#${mode} & 8#022) == 0 )) || return 1
  parent="${path%/*}"; IFS='/' read -r -a bootstrap_components <<< "${parent#/}"
  for component in "${bootstrap_components[@]}"; do [ -n "${component}" ] || continue; current="${current}/${component}"; [ -d "${current}" ] && [ ! -L "${current}" ] || return 1; metadata="$(/usr/bin/stat -c '%u:%a' -- "${current}" 2>/dev/null || true)"; [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1; mode="${BASH_REMATCH[1]}"; (( (8#${mode} & 8#022) == 0 )) || return 1; done
}
if [ "${EUID}" -eq 0 ]; then [ ! -L "${BASH_SOURCE[0]}" ] && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/${LIFECYCLE_SOURCE_NAME}" && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/lib/common.sh" || { printf '[dis:error] Lifecycle sources must be root-owned, single-link and non-writable by group/world.\n' >&2; exit 1; }; fi
unset -f bootstrap_root_lifecycle_source
APP_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd -P)"
source "${SCRIPT_DIR}/lib/common.sh"

ASSUME_YES=0
REMOVE_APP_DIR=0
REMOVE_DATABASE=0
REMOVE_DB_USER=0
REMOVE_SYSTEM_USER=0
PURGE_PACKAGES=0

usage() {
  cat <<'USAGE'
Usage: sudo ./uninstall.sh [options]
       sudo /opt/dis/scripts/uninstall.sh [options]

Options:
  --yes                  Do not prompt for confirmation.
  --remove-app-dir       Remove the DIS install directory, normally /opt/dis.
  --remove-database      Drop the local PostgreSQL database from .env.
  --remove-db-user       Drop the local PostgreSQL role from .env. Requires --remove-database.
  --remove-system-user   Remove the dis system user and group when unused.
  --purge-packages       Purge packages installed by setup.sh. Use only on a dedicated server.
  --all                  Enable all removal options except --purge-packages.
  -h, --help             Show this help.
USAGE
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --yes)
      ASSUME_YES=1
      shift
      ;;
    --remove-app-dir)
      REMOVE_APP_DIR=1
      shift
      ;;
    --remove-database)
      REMOVE_DATABASE=1
      shift
      ;;
    --remove-db-user)
      REMOVE_DB_USER=1
      REMOVE_DATABASE=1
      shift
      ;;
    --remove-system-user)
      REMOVE_SYSTEM_USER=1
      shift
      ;;
    --purge-packages)
      PURGE_PACKAGES=1
      shift
      ;;
    --all)
      REMOVE_APP_DIR=1
      REMOVE_DATABASE=1
      REMOVE_DB_USER=1
      REMOVE_SYSTEM_USER=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      fail "Unknown option: $1"
      ;;
  esac
done

require_root
if [ -f "${DIS_INSTALL_PATH}/.env" ]; then
  load_data_path_from_env "${DIS_INSTALL_PATH}/.env"
fi

if [ "${REMOVE_DB_USER}" = "1" ] && [ "${REMOVE_DATABASE}" != "1" ]; then
  fail "--remove-db-user requires --remove-database."
fi

confirm() {
  local message="$1"
  if [ "${ASSUME_YES}" = "1" ]; then
    return 0
  fi

  printf '%s [y/N] ' "${message}"
  read -r answer
  case "${answer}" in
    y|Y|yes|YES)
      return 0
      ;;
    *)
      fail "Uninstall cancelled."
      ;;
  esac
}

env_value() {
  local file="$1"
  local key="$2"
  local value
  value="$(grep -E "^${key}=" "${file}" 2>/dev/null | tail -n 1 | cut -d '=' -f 2- || true)"
  value="${value%\"}"
  value="${value#\"}"
  value="${value%\'}"
  value="${value#\'}"
  printf '%s' "${value}"
}

safe_remove_recursive() {
  local target="$1"
  local resolved

  if [ ! -e "${target}" ]; then
    return 0
  fi

  resolved="$(readlink -f "${target}")"
  case "${resolved}" in
    /opt/dis|/opt/dis/*)
      run_cmd rm -rf -- "${resolved}"
      ;;
    *)
      fail "Refusing to remove unexpected path: ${resolved}"
      ;;
  esac
}

drop_database() {
  local env_file="${DIS_INSTALL_PATH}/.env"
  if [ ! -f "${env_file}" ]; then
    log "No ${env_file} found; skipping database removal."
    return 0
  fi

  local db_host db_name db_user
  db_host="$(env_value "${env_file}" DB_HOST)"
  db_name="$(env_value "${env_file}" DB_DATABASE)"
  db_user="$(env_value "${env_file}" DB_USERNAME)"

  case "${db_host}" in
    127.0.0.1|localhost|"")
      ;;
    *)
      fail "Refusing to remove database on remote PostgreSQL host '${db_host}'."
      ;;
  esac

  if [ -z "${db_name}" ]; then
    log "DB_DATABASE is empty; skipping database removal."
    return 0
  fi

  log "Dropping local PostgreSQL database '${db_name}'"
  run_cmd systemctl start postgresql
  runuser -u postgres -- psql -v ON_ERROR_STOP=1 -v db_name="${db_name}" <<'SQL'
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE datname = :'db_name' AND pid <> pg_backend_pid();

SELECT format('DROP DATABASE IF EXISTS %I', :'db_name')\gexec
SQL

  if [ "${REMOVE_DB_USER}" = "1" ] && [ -n "${db_user}" ]; then
    log "Dropping local PostgreSQL role '${db_user}'"
    runuser -u postgres -- psql -v ON_ERROR_STOP=1 -v db_user="${db_user}" <<'SQL'
SELECT format('DROP ROLE IF EXISTS %I', :'db_user')\gexec
SQL
  fi
}

confirm "This will uninstall DIS service configuration from this server."
acquire_dis_operation_lock uninstall

log "Stopping and disabling DIS services"
# Legacy backup entries remain here so uninstall also cleans hosts upgraded from
# releases that installed the retired standalone backup helpers.
for service in dis-media dis-queue dis-scheduler dis-websocket dis-osrm dis-incident-enrichment dis-knmi dis-knmi-realtime \
  dis-osrm-admin-request.timer dis-osrm-admin-request.path dis-osrm-admin-request \
  dis-backup-request.timer dis-backup-request.path dis-backup-request \
  dis-backup-mount dis-backup.timer dis-backup; do
  if systemctl list-unit-files "${service}.service" >/dev/null 2>&1 || systemctl list-unit-files "${service}" >/dev/null 2>&1; then
    run_cmd systemctl disable --now "${service}" >/dev/null 2>&1 || true
  fi
done

log "Removing DIS systemd units"
for unit in \
  /etc/systemd/system/dis-queue.service \
  /etc/systemd/system/dis-media.service \
  /etc/systemd/system/dis-knmi.service \
  /etc/systemd/system/dis-knmi-realtime.service \
  /etc/systemd/system/dis-incident-enrichment.service \
  /etc/systemd/system/dis-scheduler.service \
  /etc/systemd/system/dis-websocket.service \
  /etc/systemd/system/dis-frontend.service \
  /etc/systemd/system/dis-osrm.service \
  /etc/systemd/system/dis-osrm-admin-request.service \
  /etc/systemd/system/dis-osrm-admin-request.path \
  /etc/systemd/system/dis-osrm-admin-request.timer \
  /etc/systemd/system/dis-backup-request.service \
  /etc/systemd/system/dis-backup-request.path \
  /etc/systemd/system/dis-backup-request.timer \
  /etc/systemd/system/dis-backup-mount.service \
  /etc/systemd/system/dis-backup.service \
  /etc/systemd/system/dis-backup.timer; do
  if [ -e "${unit}" ]; then
    run_cmd rm -f -- "${unit}"
  fi
done
if [ -d /etc/systemd/system/dis-osrm.service.d ] && [ ! -L /etc/systemd/system/dis-osrm.service.d ]; then
  secure_path_operation remove-tree /etc/systemd/system/dis-osrm.service.d
elif [ -L /etc/systemd/system/dis-osrm.service.d ]; then
  run_cmd rm -f -- /etc/systemd/system/dis-osrm.service.d
fi

if [ -f /etc/sudoers.d/dis-update ]; then
  run_cmd rm -f /etc/sudoers.d/dis-update
fi
run_cmd systemctl daemon-reload
run_cmd rm -f -- \
  /usr/local/bin/dis-backup-request-worker \
  /usr/local/bin/dis-backup-mount \
  /usr/local/bin/dis-backup-verify \
  /usr/local/bin/dis-backup-restore \
  /usr/local/bin/dis-snapshot-backup-input \
  /usr/local/bin/dis-osrm-admin-request-worker \
  /usr/local/bin/dis-update-runner
if [ -d "${OSRM_ADMIN_RUNTIME_DIR}" ] && [ ! -L "${OSRM_ADMIN_RUNTIME_DIR}" ]; then
  secure_path_operation remove-tree "${OSRM_ADMIN_RUNTIME_DIR}"
elif [ -e "${OSRM_ADMIN_RUNTIME_DIR}" ] || [ -L "${OSRM_ADMIN_RUNTIME_DIR}" ]; then
  run_cmd rm -f -- "${OSRM_ADMIN_RUNTIME_DIR}"
fi
run_cmd rmdir "${OSRM_ADMIN_RUNTIME_PARENT}" >/dev/null 2>&1 || true
run_cmd rm -f -- /var/log/dis/osrm-status.json

if command -v apt-mark >/dev/null 2>&1; then
  for held_package in podman fuse-overlayfs osrm-backend osmium-tool; do
    if apt-mark showhold 2>/dev/null | grep -Fxq "${held_package}"; then
      log "Removing the DIS-managed APT hold from ${held_package}"
      run_cmd apt-mark unhold "${held_package}" >/dev/null
    fi
  done
fi

log "Removing DIS Nginx configuration"
run_cmd rm -f -- "/etc/nginx/sites-enabled/${NGINX_SITE_NAME}" "/etc/nginx/sites-available/${NGINX_SITE_NAME}"
if command -v nginx >/dev/null 2>&1; then
  if run_cmd nginx -t; then
    run_cmd systemctl reload nginx >/dev/null 2>&1 || true
  else
    log "Nginx config test failed after removing DIS site; leaving Nginx stopped/unreloaded for manual inspection."
  fi
fi

log "Removing DIS PHP configuration"
run_cmd rm -f -- "/etc/php/${PHP_VERSION}/fpm/conf.d/99-dis-security.ini" "/etc/php/${PHP_VERSION}/fpm/conf.d/99-dis-opcache.ini"
run_cmd rm -f -- "/etc/systemd/system/${PHP_FPM_SERVICE}.service.d/dis-privileged-helpers.conf"
run_cmd rmdir "/etc/systemd/system/${PHP_FPM_SERVICE}.service.d" >/dev/null 2>&1 || true
if systemctl list-unit-files "${PHP_FPM_SERVICE}.service" >/dev/null 2>&1; then
  run_cmd systemctl reload "${PHP_FPM_SERVICE}" >/dev/null 2>&1 || true
fi

log "Removing generated runtime files"
if [ -e /usr/local/bin/update ] && grep -q "${DIS_INSTALL_PATH}/update.sh" /usr/local/bin/update 2>/dev/null; then
  run_cmd rm -f -- /usr/local/bin/update
elif [ -e /usr/local/bin/update ]; then
  log "/usr/local/bin/update exists but is not managed by DIS; leaving it in place."
fi
safe_remove_recursive "${DIS_INSTALL_PATH}/storage/generated"
run_cmd rm -f -- "${DIS_INSTALL_PATH}/webapp/frontend/.env.production" 2>/dev/null || true
run_cmd rm -f -- "${DIS_INSTALL_PATH}/webapp/backend/.env" 2>/dev/null || true

if [ "${REMOVE_DATABASE}" = "1" ]; then
  drop_database
else
  log "Database kept. Re-run with --remove-database to drop it."
fi

if [ "${REMOVE_APP_DIR}" = "1" ]; then
  confirm "Remove ${DIS_INSTALL_PATH} including application files, local .env and secrets?"
  log "Removing ${DIS_INSTALL_PATH}"
  safe_remove_recursive "${DIS_INSTALL_PATH}"
else
  log "Application directory kept at ${DIS_INSTALL_PATH}. Re-run with --remove-app-dir to remove it."
fi

if [ "${REMOVE_SYSTEM_USER}" = "1" ]; then
  log "Removing DIS system users and groups when present"
  if [ -e "${DIS_DATA_PATH}/osrm" ] || [ -L "${DIS_DATA_PATH}/osrm" ]; then
    log "Keeping OSRM service identities because generated data is retained at ${DIS_DATA_PATH}/osrm."
  else
    if [ -d "${DIS_DATA_PATH}" ] && command -v setfacl >/dev/null 2>&1; then
      run_cmd setfacl -x u:dis-osrm "${DIS_DATA_PATH}" >/dev/null 2>&1 || true
      run_cmd setfacl -x u:dis-osrm-build "${DIS_DATA_PATH}" >/dev/null 2>&1 || true
    fi
    if id dis-osrm-build >/dev/null 2>&1; then
      run_cmd userdel dis-osrm-build >/dev/null 2>&1 || true
    fi
    if id dis-osrm >/dev/null 2>&1; then
      run_cmd userdel dis-osrm >/dev/null 2>&1 || true
    fi
    if getent group dis-osrm >/dev/null 2>&1; then
      run_cmd groupdel dis-osrm >/dev/null 2>&1 || true
    fi
  fi
  if id "${DIS_USER}" >/dev/null 2>&1; then
    run_cmd userdel "${DIS_USER}" >/dev/null 2>&1 || true
  fi
  if getent group "${DIS_GROUP}" >/dev/null 2>&1; then
    run_cmd groupdel "${DIS_GROUP}" >/dev/null 2>&1 || true
  fi
else
  log "System users '${DIS_USER}', 'dis-osrm' and 'dis-osrm-build' kept. Re-run with --remove-system-user to remove them."
fi

if [ "${PURGE_PACKAGES}" = "1" ]; then
  confirm "Purge Ubuntu packages installed by DIS setup? Use only on a dedicated server."
  log "Purging DIS package dependencies"
  run_cmd apt-get purge -y \
    composer ffmpeg nginx postgresql postgresql-client redis-server redis-tools cifs-utils smbclient hdf5-tools libeccodes-tools \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-redis" \
    "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
    "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl" \
    nodejs npm
  # Podman is a host-wide container runtime and may serve workloads outside
  # DIS. Never purge it as an application-owned package.
  for osrm_package in fuse-overlayfs osrm-backend osmium-tool; do
    if dpkg-query -W -f='${db:Status-Status}' "${osrm_package}" 2>/dev/null | grep -qx installed; then
      run_cmd apt-get purge -y "${osrm_package}"
    fi
  done
  run_cmd apt-get autoremove -y
else
  log "Ubuntu packages kept. Re-run with --purge-packages only on a dedicated server."
fi

log "DIS uninstall completed."
