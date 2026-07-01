#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
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

log "Stopping and disabling DIS services"
for service in dis-queue dis-scheduler dis-websocket dis-backup.timer dis-backup; do
  if systemctl list-unit-files "${service}.service" >/dev/null 2>&1 || systemctl list-unit-files "${service}" >/dev/null 2>&1; then
    run_cmd systemctl disable --now "${service}" >/dev/null 2>&1 || true
  fi
done

log "Removing DIS systemd units"
for unit in \
  /etc/systemd/system/dis-queue.service \
  /etc/systemd/system/dis-scheduler.service \
  /etc/systemd/system/dis-websocket.service \
  /etc/systemd/system/dis-backup.service \
  /etc/systemd/system/dis-backup.timer; do
  if [ -e "${unit}" ]; then
    run_cmd rm -f -- "${unit}"
  fi
done

if [ -f /etc/sudoers.d/dis-update ]; then
  run_cmd rm -f /etc/sudoers.d/dis-update
fi
run_cmd systemctl daemon-reload

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
  log "Removing DIS system user and group when present"
  if id "${DIS_USER}" >/dev/null 2>&1; then
    run_cmd userdel "${DIS_USER}" >/dev/null 2>&1 || true
  fi
  if getent group "${DIS_GROUP}" >/dev/null 2>&1; then
    run_cmd groupdel "${DIS_GROUP}" >/dev/null 2>&1 || true
  fi
else
  log "System user '${DIS_USER}' kept. Re-run with --remove-system-user to remove it."
fi

if [ "${PURGE_PACKAGES}" = "1" ]; then
  confirm "Purge Ubuntu packages installed by DIS setup? Use only on a dedicated server."
  log "Purging DIS package dependencies"
  run_cmd apt-get purge -y \
    composer nginx postgresql postgresql-client redis-server redis-tools cifs-utils smbclient \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-redis" \
    "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
    "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl" \
    nodejs npm
  run_cmd apt-get autoremove -y
else
  log "Ubuntu packages kept. Re-run with --purge-packages only on a dedicated server."
fi

log "DIS uninstall completed."
