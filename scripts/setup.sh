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
  DIS_DATA_PATH          Custom data path. Default: /opt/dis-data
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
ensure_data_links "${APP_ROOT}"

ENV_FILE="${DIS_DATA_PATH}/.env"
if [ ! -f "${ENV_FILE}" ]; then
  log "Creating ${ENV_FILE} from .env.example"
  run_cmd cp "${APP_ROOT}/.env.example" "${ENV_FILE}"
fi
run_cmd ln -sfn "${ENV_FILE}" "${APP_ROOT}/.env"

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

if [ "$(env_value REVERB_APP_SECRET)" = "change-this-reverb-secret" ] || [ -z "$(env_value REVERB_APP_SECRET)" ]; then
  set_env REVERB_APP_SECRET "$(random_hex 32)"
fi

if [ -z "$(env_value SPEECH_CACHE_HMAC_KEY)" ]; then
  set_managed_env_secret "${ENV_FILE}" SPEECH_CACHE_HMAC_KEY "base64:$(random_base64 48)"
fi

set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://${DOMAIN}"
set_env DIS_INSTALL_PATH "${DIS_INSTALL_PATH}"
set_env DIS_DATA_PATH "${DIS_DATA_PATH}"
set_env BACKUP_DISK_PATH "${DIS_DATA_PATH}/backup"
set_env BACKUP_ENCRYPTION_KEY_FILE "${DIS_DATA_PATH}/secrets/backup-encryption.key"
set_env DB_HOST 127.0.0.1
set_env REDIS_HOST 127.0.0.1
set_env TRUSTED_PROXIES "127.0.0.1,::1"
set_env CORS_ALLOWED_ORIGINS "https://${DOMAIN}"
set_env SESSION_DRIVER database
set_env SESSION_LIFETIME 120
set_env SESSION_ABSOLUTE_LIFETIME 720
set_env SESSION_COOKIE __Host-dis_session
set_env SESSION_DOMAIN ""
set_env SESSION_SECURE_COOKIE true
set_env SESSION_SAME_SITE lax
set_env SESSION_TRUSTED_ORIGINS "https://${DOMAIN}"
set_env SANCTUM_STATEFUL_DOMAINS "${DOMAIN}"
set_env REVERB_SCHEME http

run_cmd chown root:"${DIS_GROUP}" "${ENV_FILE}"
run_cmd chmod 0640 "${ENV_FILE}"

DIS_BACKUP_KEY_CUTOVER_ALLOWED=1 ensure_backup_encryption_key >/dev/null

log "Creating backend .env symlink"
run_cmd ln -sfn "${ENV_FILE}" "${APP_ROOT}/webapp/backend/.env"
run_cmd chown -h "${DIS_USER}:${DIS_GROUP}" "${APP_ROOT}/webapp/backend/.env"

log "Creating frontend production environment"
cat > "${APP_ROOT}/webapp/frontend/.env.production" <<EOF
NEXT_PUBLIC_API_BASE_URL=/api
NEXT_PUBLIC_APP_URL=https://${DOMAIN}
NEXT_PUBLIC_REVERB_APP_KEY=$(env_value REVERB_APP_KEY)
NEXT_PUBLIC_WEBSOCKET_HOST=${DOMAIN}
NEXT_PUBLIC_WEBSOCKET_PORT=443
NEXT_PUBLIC_WEBSOCKET_SCHEME=wss
SECURITY_CONTACT=$(env_value SECURITY_CONTACT)
CSP_AERET_FRAME_ORIGINS=$(env_value CSP_AERET_FRAME_ORIGINS)
EOF
run_cmd chown "${DIS_USER}:${DIS_GROUP}" "${APP_ROOT}/webapp/frontend/.env.production"
run_cmd chmod 0640 "${APP_ROOT}/webapp/frontend/.env.production"

log "Generating Nginx site configuration"
GENERATED_NGINX_DIR="${DIS_DATA_PATH}/storage/generated/nginx"
ensure_managed_directory "${GENERATED_NGINX_DIR}" root root 0755
GENERATED_NGINX_CONF="${GENERATED_NGINX_DIR}/dis.conf"
GENERATED_NGINX_TEMP="$(mktemp "${GENERATED_NGINX_DIR}/.dis.conf.XXXXXX")"
run_cmd cp "${APP_ROOT}/infrastructure/nginx/dis.conf" "${GENERATED_NGINX_TEMP}"
run_cmd sed -i "s/server_name _;/server_name ${DOMAIN} _;/" "${GENERATED_NGINX_TEMP}"
run_cmd sed -i "s#unix:/run/php/php[0-9.]*-fpm.sock#unix:/run/php/php${PHP_VERSION}-fpm.sock#" "${GENERATED_NGINX_TEMP}"
run_cmd chown root:root "${GENERATED_NGINX_TEMP}"
run_cmd chmod 0644 "${GENERATED_NGINX_TEMP}"
run_cmd mv -fT -- "${GENERATED_NGINX_TEMP}" "${GENERATED_NGINX_CONF}"

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
log "OSRM remains degraded until an administrator installs and activates verified map data from the admin page."
log "See ${APP_ROOT}/infrastructure/osrm/README.md for the OSRM activation and update workflow."
log "Future updates can be run with: sudo update"
