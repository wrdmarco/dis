#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

UPDATE_SYSTEM=1
UPDATE_APP=1
UPDATE_SOURCE=1
RUN_HEALTHCHECK="${RUN_HEALTHCHECK:-1}"
SYSTEM_UPDATES_AVAILABLE=0
APP_UPDATES_AVAILABLE=0

usage() {
  cat <<'USAGE'
Usage: sudo update [options]
       sudo /opt/dis/update.sh [options]
       sudo /opt/dis/scripts/update.sh [options]

Options:
  --skip-system          Do not run apt update/upgrade.
  --skip-app             Do not deploy the DIS application.
  --skip-source          Do not run git pull before deploying.
  --skip-healthcheck     Skip final local health check.
  -h, --help             Show this help.
USAGE
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --skip-system)
      UPDATE_SYSTEM=0
      shift
      ;;
    --skip-app)
      UPDATE_APP=0
      shift
      ;;
    --skip-source)
      UPDATE_SOURCE=0
      shift
      ;;
    --skip-healthcheck)
      RUN_HEALTHCHECK=0
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
require_ubuntu_2604
require_directory "${DIS_INSTALL_PATH}"
require_file "${DIS_INSTALL_PATH}/.env"

if [ "${APP_ROOT}" != "${DIS_INSTALL_PATH}" ]; then
  fail "DIS update script must run from ${DIS_INSTALL_PATH}. Current script path is ${APP_ROOT}."
fi

env_value() {
  local key="$1"
  local value
  value="$(grep -E "^${key}=" "${DIS_INSTALL_PATH}/.env" | tail -n 1 | cut -d '=' -f 2- || true)"
  value="${value%\"}"
  value="${value#\"}"
  value="${value%\'}"
  value="${value#\'}"
  printf '%s' "${value}"
}

write_frontend_env() {
  local app_url host
  app_url="$(env_value APP_URL)"
  host="${app_url#http://}"
  host="${host#https://}"
  host="${host%%/*}"

  if [ -z "${host}" ]; then
    fail "APP_URL must be set in ${DIS_INSTALL_PATH}/.env before updating."
  fi

  log "Refreshing frontend production environment"
  cat > "${DIS_INSTALL_PATH}/webapp/frontend/.env.production" <<EOF
VITE_API_BASE_URL=/api
VITE_REVERB_APP_KEY=$(env_value REVERB_APP_KEY)
VITE_WEBSOCKET_HOST=${host}
VITE_WEBSOCKET_PORT=80
VITE_WEBSOCKET_SCHEME=ws
EOF
  run_cmd chown "${DIS_USER}:${DIS_GROUP}" "${DIS_INSTALL_PATH}/webapp/frontend/.env.production"
  run_cmd chmod 0640 "${DIS_INSTALL_PATH}/webapp/frontend/.env.production"
}

refresh_generated_nginx() {
  local app_url host generated_dir generated_conf
  app_url="$(env_value APP_URL)"
  host="${app_url#http://}"
  host="${host#https://}"
  host="${host%%/*}"

  if [ -z "${host}" ]; then
    fail "APP_URL must be set in ${DIS_INSTALL_PATH}/.env before updating."
  fi

  generated_dir="${DIS_INSTALL_PATH}/storage/generated/nginx"
  generated_conf="${generated_dir}/dis.conf"
  ensure_directory "${generated_dir}" root root 0755
  run_cmd cp "${DIS_INSTALL_PATH}/infrastructure/nginx/dis.conf" "${generated_conf}"
  run_cmd sed -i "s/server_name _;/server_name ${host} _;/" "${generated_conf}"
  run_cmd sed -i "s#unix:/run/php/php[0-9.]*-fpm.sock#unix:/run/php/php${PHP_VERSION}-fpm.sock#" "${generated_conf}"
  printf '%s' "${generated_conf}"
}

install_update_command() {
  log "Installing global update command"
  if [ -e /usr/local/bin/update ] && ! grep -q "${DIS_INSTALL_PATH}/update.sh" /usr/local/bin/update 2>/dev/null; then
    fail "/usr/local/bin/update already exists and is not managed by DIS."
  fi
  cat > /usr/local/bin/update <<EOF
#!/usr/bin/env bash
exec bash "${DIS_INSTALL_PATH}/update.sh" "\$@"
EOF
  run_cmd chmod 0755 /usr/local/bin/update
}

clear_application_caches() {
  local backend_dir frontend_dir
  backend_dir="${DIS_INSTALL_PATH}/webapp/backend"
  frontend_dir="${DIS_INSTALL_PATH}/webapp/frontend"

  log "Clearing backend and frontend caches"
  if [ -f "${backend_dir}/artisan" ]; then
    run_cmd runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" optimize:clear
  fi

  run_cmd rm -rf \
    "${backend_dir}/bootstrap/cache/"*.php \
    "${backend_dir}/storage/framework/cache/data/"* \
    "${backend_dir}/storage/framework/views/"* \
    "${frontend_dir}/.vite" \
    "${frontend_dir}/.cache" \
    "${frontend_dir}/node_modules/.vite" \
    "${frontend_dir}/node_modules/.cache" \
    "${frontend_dir}/tsconfig.tsbuildinfo" \
    "${frontend_dir}/tsconfig.node.tsbuildinfo" \
    "${frontend_dir}/vite.config.js" \
    "${frontend_dir}/vite.config.d.ts" \
    2>/dev/null || true

  if command -v npm >/dev/null 2>&1; then
    run_cmd npm cache clean --force >/dev/null 2>&1 || true
    run_cmd npm cache verify >/dev/null 2>&1 || true
  fi
}

stash_local_git_changes() {
  local status stash_name

  if [ ! -d "${DIS_INSTALL_PATH}/.git" ]; then
    return
  fi

  status="$(git -C "${DIS_INSTALL_PATH}" status --porcelain)"
  if [ -z "${status}" ]; then
    return
  fi

  stash_name="server-local-before-update-$(date -u +%Y%m%dT%H%M%SZ)"
  log "Local Git changes detected. Stashing them as ${stash_name}."
  run_cmd git -C "${DIS_INSTALL_PATH}" stash push -u -m "${stash_name}"
  log "Local changes were stashed and not reapplied automatically."
}

check_system_updates() {
  local update_count

  log "Checking Ubuntu package updates"
  run_cmd apt-get update
  update_count="$(apt list --upgradable 2>/dev/null | sed '1d' | wc -l | tr -d ' ')"

  if [ "${update_count}" -gt 0 ]; then
    SYSTEM_UPDATES_AVAILABLE=1
    log "Ubuntu package updates available: ${update_count}"
  else
    log "No Ubuntu package updates available."
  fi
}

check_app_updates() {
  local upstream counts behind

  if [ "${UPDATE_SOURCE}" != "1" ]; then
    APP_UPDATES_AVAILABLE=1
    log "Source update check skipped; application deploy requested."
    return
  fi

  if [ ! -d "${DIS_INSTALL_PATH}/.git" ]; then
    APP_UPDATES_AVAILABLE=1
    log "No Git checkout found at ${DIS_INSTALL_PATH}; application deploy requested."
    return
  fi

  log "Checking DIS application updates"
  run_cmd git -C "${DIS_INSTALL_PATH}" fetch --prune

  upstream="$(git -C "${DIS_INSTALL_PATH}" rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || true)"
  if [ -z "${upstream}" ]; then
    fail "Git branch at ${DIS_INSTALL_PATH} has no upstream branch configured."
  fi

  counts="$(git -C "${DIS_INSTALL_PATH}" rev-list --left-right --count "HEAD...${upstream}")"
  behind="$(printf '%s' "${counts}" | awk '{print $2}')"

  if [ "${behind}" -gt 0 ]; then
    APP_UPDATES_AVAILABLE=1
    log "DIS application updates available: ${behind} commit(s)."
  else
    log "No DIS application updates available."
  fi
}

if [ "${UPDATE_SYSTEM}" = "1" ]; then
  check_system_updates
fi

if [ "${UPDATE_APP}" = "1" ]; then
  check_app_updates
fi

if [ "${SYSTEM_UPDATES_AVAILABLE}" = "0" ] && [ "${APP_UPDATES_AVAILABLE}" = "0" ]; then
  log "No updates available. Nothing to do."
  exit 0
fi

if [ "${UPDATE_SYSTEM}" = "1" ]; then
  if [ "${SYSTEM_UPDATES_AVAILABLE}" = "1" ]; then
    log "Updating Ubuntu packages"
    run_cmd apt-get upgrade -y
    run_cmd apt-get autoremove -y
  else
    log "Skipping Ubuntu package update."
  fi
fi

if [ "${UPDATE_APP}" = "1" ]; then
  if [ "${APP_UPDATES_AVAILABLE}" = "1" ]; then
    if [ "${UPDATE_SOURCE}" = "1" ]; then
      if [ -d "${DIS_INSTALL_PATH}/.git" ]; then
        log "Pulling latest DIS source"
        stash_local_git_changes
        run_cmd git -C "${DIS_INSTALL_PATH}" pull --ff-only
      else
        log "No Git checkout found at ${DIS_INSTALL_PATH}; skipping source update."
      fi
    fi

    write_frontend_env
    nginx_source="$(refresh_generated_nginx)"

    log "Deploying updated DIS application"
    APP_ROOT="${DIS_INSTALL_PATH}" NGINX_SOURCE="${nginx_source}" SKIP_DEPLOY_CACHE_CLEAR=1 bash "${SCRIPT_DIR}/deploy.sh"
    install_update_command
  else
    log "Skipping DIS application deploy."
  fi
fi

if [ "${RUN_HEALTHCHECK}" = "1" ]; then
  log "Running final local health check"
  HEALTH_URL="http://127.0.0.1/health" bash "${SCRIPT_DIR}/healthcheck.sh"
fi

clear_application_caches

log "DIS system and application update completed."
