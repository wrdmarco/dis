#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${ROOT_DIR}/scripts/lib/common.sh"

UPDATE_SYSTEM=1
UPDATE_APP=1
UPDATE_SOURCE=1
RUN_HEALTHCHECK="${RUN_HEALTHCHECK:-1}"

usage() {
  cat <<'USAGE'
Usage: sudo update [options]
       sudo /opt/dis/update.sh [options]

Options:
  --skip-system          Do not run apt update/upgrade.
  --skip-app             Do not deploy the DIS application.
  --skip-source          Do not run git pull before deploying.
  --skip-healthcheck     Skip final local health check.
  -h, --help             Show this help.

Behavior:
  Updates Ubuntu packages, pulls the latest DIS source when /opt/dis is a Git
  checkout, rebuilds backend/frontend, runs migrations/seeders, refreshes
  systemd/Nginx/PHP configuration, restarts services and runs a health check.
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

if [ "${ROOT_DIR}" != "${DIS_INSTALL_PATH}" ]; then
  fail "DIS update script must run from ${DIS_INSTALL_PATH}. Current script path is ${ROOT_DIR}."
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
  local app_url host scheme port
  app_url="$(env_value APP_URL)"
  host="${app_url#http://}"
  host="${host#https://}"
  host="${host%%/*}"
  scheme="ws"
  port="80"

  if [ -z "${host}" ]; then
    fail "APP_URL must be set in ${DIS_INSTALL_PATH}/.env before updating."
  fi

  log "Refreshing frontend production environment"
  cat > "${DIS_INSTALL_PATH}/webapp/frontend/.env.production" <<EOF
VITE_API_BASE_URL=/api
VITE_REVERB_APP_KEY=$(env_value REVERB_APP_KEY)
VITE_WEBSOCKET_HOST=${host}
VITE_WEBSOCKET_PORT=${port}
VITE_WEBSOCKET_SCHEME=${scheme}
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
  run_cmd sed -i "s/server_name dis.example.nl;/server_name ${host};/" "${generated_conf}"
  printf '%s' "${generated_conf}"
}

install_update_command() {
  log "Installing global update command"
  if [ -e /usr/local/bin/update ] && ! grep -q "${DIS_INSTALL_PATH}/update.sh" /usr/local/bin/update 2>/dev/null; then
    fail "/usr/local/bin/update already exists and is not managed by DIS."
  fi
  cat > /usr/local/bin/update <<EOF
#!/usr/bin/env bash
exec "${DIS_INSTALL_PATH}/update.sh" "\$@"
EOF
  run_cmd chmod 0755 /usr/local/bin/update
}

if [ "${UPDATE_SYSTEM}" = "1" ]; then
  log "Updating Ubuntu packages"
  run_cmd apt-get update
  run_cmd apt-get upgrade -y
  run_cmd apt-get autoremove -y
fi

if [ "${UPDATE_APP}" = "1" ]; then
  if [ "${UPDATE_SOURCE}" = "1" ]; then
    if [ -d "${DIS_INSTALL_PATH}/.git" ]; then
      log "Pulling latest DIS source"
      run_cmd git -C "${DIS_INSTALL_PATH}" fetch --prune
      run_cmd git -C "${DIS_INSTALL_PATH}" pull --ff-only
    else
      log "No Git checkout found at ${DIS_INSTALL_PATH}; skipping source update."
    fi
  fi

  write_frontend_env
  nginx_source="$(refresh_generated_nginx)"

  log "Deploying updated DIS application"
  APP_ROOT="${DIS_INSTALL_PATH}" NGINX_SOURCE="${nginx_source}" bash "${DIS_INSTALL_PATH}/scripts/deploy.sh"
  install_update_command
fi

if [ "${RUN_HEALTHCHECK}" = "1" ]; then
  log "Running final local health check"
  HEALTH_URL="http://127.0.0.1/health" bash "${DIS_INSTALL_PATH}/scripts/healthcheck.sh"
fi

log "DIS system and application update completed."
