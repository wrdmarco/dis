#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

DOMAIN="${DIS_DOMAIN:-}"
RUN_HEALTHCHECK="${RUN_HEALTHCHECK:-1}"

usage() {
  cat <<'USAGE'
Usage: sudo ./setup.sh --domain dis.example.nl [options]

Options:
  --domain <host>        Public hostname for DIS. Required unless DIS_DOMAIN is set.
  --skip-healthcheck     Skip final local health check.
  -h, --help             Show this help.

Environment:
  DIS_INSTALL_PATH       Install path. Default: /opt/dis
  DIS_DOMAIN             Same as --domain
  RUN_HEALTHCHECK        0 disables final health check
USAGE
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --domain)
      DOMAIN="${2:-}"
      shift 2
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

if [ -z "${DOMAIN}" ]; then
  fail "Domain is required. Run: sudo ./setup.sh --domain dis.example.nl"
fi

if [ "${APP_ROOT}" != "${DIS_INSTALL_PATH}" ]; then
  fail "DIS must be cloned to ${DIS_INSTALL_PATH}. Current path is ${APP_ROOT}. Run: sudo git clone <repo-url> ${DIS_INSTALL_PATH}"
fi

log "Starting full DIS setup for ${DOMAIN} at ${DIS_INSTALL_PATH}"

bash "${SCRIPT_DIR}/install.sh"

ENV_FILE="${APP_ROOT}/.env"
if [ ! -f "${ENV_FILE}" ]; then
  log "Creating ${ENV_FILE} from .env.example"
  run_cmd cp "${APP_ROOT}/.env.example" "${ENV_FILE}"
fi

set_env() {
  local key="$1"
  local value="$2"
  local escaped
  escaped="$(printf '%s' "${value}" | sed 's/[&/\\]/\\&/g')"

  if grep -qE "^${key}=" "${ENV_FILE}"; then
    run_cmd sed -i "s/^${key}=.*/${key}=${escaped}/" "${ENV_FILE}"
  else
    printf '%s=%s\n' "${key}" "${value}" >> "${ENV_FILE}"
  fi
}

env_value() {
  local key="$1"
  local value
  value="$(grep -E "^${key}=" "${ENV_FILE}" | tail -n 1 | cut -d '=' -f 2- || true)"
  value="${value%\"}"
  value="${value#\"}"
  value="${value%\'}"
  value="${value#\'}"
  printf '%s' "${value}"
}

random_hex() {
  openssl rand -hex "$1"
}

random_base64() {
  openssl rand -base64 "$1" | tr -d '\n'
}

if [ -z "$(env_value APP_KEY)" ]; then
  set_env APP_KEY "base64:$(random_base64 32)"
fi

if [ "$(env_value DB_PASSWORD)" = "change-this-database-password" ] || [ -z "$(env_value DB_PASSWORD)" ]; then
  set_env DB_PASSWORD "$(random_hex 24)"
fi

if [ "$(env_value REVERB_APP_KEY)" = "change-this-reverb-key" ] || [ -z "$(env_value REVERB_APP_KEY)" ]; then
  set_env REVERB_APP_KEY "$(random_hex 16)"
fi

if [ "$(env_value VITE_REVERB_APP_KEY)" = "change-this-reverb-key" ] || [ -z "$(env_value VITE_REVERB_APP_KEY)" ]; then
  set_env VITE_REVERB_APP_KEY "$(env_value REVERB_APP_KEY)"
fi

if [ "$(env_value REVERB_APP_SECRET)" = "change-this-reverb-secret" ] || [ -z "$(env_value REVERB_APP_SECRET)" ]; then
  set_env REVERB_APP_SECRET "$(random_hex 32)"
fi

set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://${DOMAIN}"
set_env DIS_INSTALL_PATH "${DIS_INSTALL_PATH}"
set_env DB_HOST 127.0.0.1
set_env REDIS_HOST 127.0.0.1
set_env SESSION_SECURE_COOKIE false
set_env REVERB_SCHEME http

run_cmd chown root:"${DIS_GROUP}" "${ENV_FILE}"
run_cmd chmod 0640 "${ENV_FILE}"

log "Creating backend .env symlink"
run_cmd ln -sfn "${ENV_FILE}" "${APP_ROOT}/webapp/backend/.env"
run_cmd chown -h "${DIS_USER}:${DIS_GROUP}" "${APP_ROOT}/webapp/backend/.env"

log "Creating frontend production environment"
cat > "${APP_ROOT}/webapp/frontend/.env.production" <<EOF
VITE_API_BASE_URL=/api
VITE_REVERB_APP_KEY=$(env_value REVERB_APP_KEY)
VITE_WEBSOCKET_HOST=${DOMAIN}
VITE_WEBSOCKET_PORT=80
VITE_WEBSOCKET_SCHEME=ws
VITE_APP_TIMEZONE=$(env_value APP_TIMEZONE)
EOF
run_cmd chown "${DIS_USER}:${DIS_GROUP}" "${APP_ROOT}/webapp/frontend/.env.production"
run_cmd chmod 0640 "${APP_ROOT}/webapp/frontend/.env.production"

log "Generating Nginx site configuration"
GENERATED_NGINX_DIR="${APP_ROOT}/storage/generated/nginx"
ensure_directory "${GENERATED_NGINX_DIR}" root root 0755
GENERATED_NGINX_CONF="${GENERATED_NGINX_DIR}/dis.conf"
run_cmd cp "${APP_ROOT}/infrastructure/nginx/dis.conf" "${GENERATED_NGINX_CONF}"
run_cmd sed -i "s/server_name _;/server_name ${DOMAIN} _;/" "${GENERATED_NGINX_CONF}"
run_cmd sed -i "s#unix:/run/php/php[0-9.]*-fpm.sock#unix:/run/php/php${PHP_VERSION}-fpm.sock#" "${GENERATED_NGINX_CONF}"

log "Provisioning database"
APP_ROOT="${APP_ROOT}" ENV_FILE="${ENV_FILE}" bash "${SCRIPT_DIR}/provision-database.sh"

log "Running deployment"
APP_ROOT="${APP_ROOT}" NGINX_SOURCE="${GENERATED_NGINX_CONF}" RUN_SEEDERS=1 bash "${SCRIPT_DIR}/deploy.sh"

if [ "${RUN_HEALTHCHECK}" = "1" ]; then
  log "Running final local health check"
  HEALTH_URL="http://127.0.0.1/health" bash "${SCRIPT_DIR}/healthcheck.sh"
fi

log "DIS setup completed for https://${DOMAIN}"
log "Open https://${DOMAIN}/setup to finish the first web configuration."
log "Future updates can be run with: sudo update"
