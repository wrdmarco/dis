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
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
BACKEND_DIR="${APP_ROOT}/webapp/backend"
FRONTEND_DIR="${APP_ROOT}/webapp/frontend"
NGINX_SOURCE="${NGINX_SOURCE:-${APP_ROOT}/infrastructure/nginx/dis.conf}"
SKIP_DEPLOY_CACHE_CLEAR="${SKIP_DEPLOY_CACHE_CLEAR:-0}"
RUN_SEEDERS="${RUN_SEEDERS:-0}"
DIS_DEPLOYMENT_OWNER="${DIS_DEPLOYMENT_OWNER:-deploy}"
DIS_DEFER_OPERATIONAL_SERVICES="${DIS_DEFER_OPERATIONAL_SERVICES:-0}"
RELEASE_MARKER_TEMP=""

require_directory "${APP_ROOT}"
require_file "${NGINX_SOURCE}"
require_root
acquire_dis_operation_lock deployment
load_data_path_from_env "${APP_ROOT}/.env"
ensure_data_links "${APP_ROOT}"
require_file "${APP_ROOT}/.env"

ENV_FILE="${APP_ROOT}/.env"

# This deploy script is executed from the newly checked-out release even when
# the parent updater still has the previous release's shell functions loaded.
# Keep the new worker's fixed Ubuntu runtime dependency self-contained at this
# checkout boundary so an app-only first rollout cannot publish a dead worker.
ensure_wallboard_media_runtime_dependencies
ensure_knmi_forecast_runtime_dependencies

deployment_exit_handler() {
  local status="$1"

  trap - EXIT
  if [ -n "${RELEASE_MARKER_TEMP}" ]; then
    rm -f -- "${RELEASE_MARKER_TEMP}" 2>/dev/null || true
  fi
  if [ "${status}" -ne 0 ]; then
    log "Deployment failed; maintenance remains enabled and stopped DIS services remain stopped. Correct the failure and rerun the deployment."
  fi
  exit "${status}"
}

trap 'deployment_exit_handler "$?"' EXIT
# Do not bootstrap a just-updated source tree through the previous release's
# dependencies or executable caches. Nginx closes the public surface first;
# Laravel maintenance is enabled after dependencies and manifests are ready.
if [ "${DIS_DEPLOYMENT_OWNER}" = "deploy" ]; then
  announce_wallboard_maintenance maintenance
  # The checked-out release can contain new inline CSP hashes that the active
  # Nginx configuration does not know yet. Publish a CSP-neutral page until
  # the matching configuration has been validated and activated below.
  enable_frontend_maintenance bootstrap
else
  [ -f "${DIS_INSTALL_PATH}/maintenance/frontend.lock" ] \
    && [ ! -L "${DIS_INSTALL_PATH}/maintenance/frontend.lock" ] \
    || fail "Nested deployment requires maintenance to be owned by its parent operation."
  log "Preserving the parent operation's compatible maintenance page during release cutover"
fi
stop_dis_deployment_services
DIS_BACKUP_KEY_CUTOVER_ALLOWED=1 ensure_backup_encryption_key >/dev/null

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

set_env_value() {
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

harden_web_session_environment() {
  local app_url authority trusted_proxies
  app_url="$(env_value APP_URL)"
  case "${app_url}" in
    https://*) ;;
    *) fail "APP_URL must use https for a production DIS deployment." ;;
  esac

  authority="${app_url#https://}"
  authority="${authority%%/*}"
  if [ -z "${authority}" ]; then
    fail "APP_URL must contain a public host."
  fi

  trusted_proxies="$(env_value TRUSTED_PROXIES)"
  case "${trusted_proxies}" in
    ""|"*"|"**") trusted_proxies="127.0.0.1,::1" ;;
  esac

  set_env_value TRUSTED_PROXIES "${trusted_proxies}"
  set_env_value CORS_ALLOWED_ORIGINS "https://${authority}"
  set_env_value SESSION_DRIVER database
  set_env_value SESSION_LIFETIME 120
  set_env_value SESSION_ABSOLUTE_LIFETIME 720
  set_env_value SESSION_COOKIE __Host-dis_session
  set_env_value SESSION_DOMAIN ""
  set_env_value SESSION_SECURE_COOKIE true
  set_env_value SESSION_SAME_SITE lax
  set_env_value SESSION_TRUSTED_ORIGINS "https://${authority}"
  set_env_value SANCTUM_STATEFUL_DOMAINS "${authority}"
}

write_frontend_security_environment() {
  local app_url authority websocket_host
  app_url="$(env_value APP_URL)"
  authority="${app_url#https://}"
  authority="${authority%%/*}"
  websocket_host="${authority%%:*}"

  cat > "${FRONTEND_DIR}/.env.production" <<EOF
NEXT_PUBLIC_API_BASE_URL=/api
NEXT_PUBLIC_APP_URL=https://${authority}
NEXT_PUBLIC_REVERB_APP_KEY=$(env_value REVERB_APP_KEY)
NEXT_PUBLIC_WEBSOCKET_HOST=${websocket_host}
NEXT_PUBLIC_WEBSOCKET_PORT=443
NEXT_PUBLIC_WEBSOCKET_SCHEME=wss
SECURITY_CONTACT=$(env_value SECURITY_CONTACT)
CSP_AERET_FRAME_ORIGINS=$(env_value CSP_AERET_FRAME_ORIGINS)
EOF
  run_cmd chown root:"${DIS_GROUP}" "${FRONTEND_DIR}/.env.production"
  run_cmd chmod 0640 "${FRONTEND_DIR}/.env.production"
}

canonical_public_host() {
  local app_url authority host port label
  local -a labels

  app_url="$(env_value APP_URL)"
  case "${app_url}" in
    https://*) ;;
    *) fail "APP_URL must use https before generating Nginx configuration." ;;
  esac

  authority="${app_url#https://}"
  if [[ "${authority}" == */ ]]; then
    authority="${authority%/}"
  fi
  if [ -z "${authority}" ] \
    || [[ "${authority}" == *"/"* || "${authority}" == *"@"* || "${authority}" == *"?"* || "${authority}" == *"#"* ]]; then
    fail "APP_URL must contain only a public hostname and optional port."
  fi

  host="${authority}"
  if [[ "${authority}" == *:* ]]; then
    host="${authority%%:*}"
    port="${authority#*:}"
    if ! [[ "${port}" =~ ^[0-9]+$ ]] || [ "${port}" -lt 1 ] || [ "${port}" -gt 65535 ]; then
      fail "APP_URL contains an invalid port."
    fi
  fi

  if [ -z "${host}" ] || [ "${#host}" -gt 253 ] || [[ "${host}" == .* || "${host}" == *. || "${host}" == *..* ]]; then
    fail "APP_URL contains an invalid public hostname."
  fi
  IFS='.' read -r -a labels <<< "${host}"
  for label in "${labels[@]}"; do
    if ! [[ "${label}" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$ ]]; then
      fail "APP_URL contains an invalid public hostname."
    fi
  done

  printf '%s' "${host}"
}

prepare_canonical_nginx_source() {
  local source public_host generated_dir generated_conf temporary_conf

  source="${NGINX_SOURCE}"
  require_file "${source}"
  public_host="$(canonical_public_host)"
  generated_dir="${DIS_DATA_PATH}/storage/generated/nginx"
  generated_conf="${generated_dir}/dis.conf"
  ensure_managed_directory "${generated_dir}" root root 0755
  temporary_conf="$(mktemp "${generated_dir}/.dis.conf.XXXXXX")"

  run_cmd cp "${source}" "${temporary_conf}"
  run_cmd sed -i "s/server_name _;/server_name ${public_host} _;/" "${temporary_conf}"
  run_cmd sed -E -i "s/^([[:space:]]*)server_name[[:space:]]+[^;[:space:]]+[[:space:]]+_;$/\\1server_name ${public_host} _;/" "${temporary_conf}"
  run_cmd sed -i "s#unix:/run/php/php[0-9.]*-fpm.sock#unix:/run/php/php${PHP_VERSION}-fpm.sock#" "${temporary_conf}"
  if [ "$(grep -Fxc "    server_name ${public_host} _;" "${temporary_conf}" || true)" -ne 1 ] \
    || grep -Eq '^[[:space:]]*server_name[[:space:]]+_;' "${temporary_conf}"; then
    rm -f -- "${temporary_conf}"
    fail "Nginx source does not contain exactly one canonical APP_URL server_name."
  fi

  run_cmd chown root:root "${temporary_conf}"
  run_cmd chmod 0644 "${temporary_conf}"
  run_cmd mv -fT -- "${temporary_conf}" "${generated_conf}"
  NGINX_SOURCE="${generated_conf}"
}

harden_web_session_environment
write_frontend_security_environment
prepare_canonical_nginx_source

# Cut the standalone page and its exact CSP policy over as one early release
# boundary. This keeps the bootstrap page brief while the slower dependency,
# build and migration work continues behind the rich maintenance screen.
run_cmd install -m 0644 "${NGINX_SOURCE}" "/etc/nginx/sites-available/${NGINX_SITE_NAME}"
run_cmd ln -sfn "/etc/nginx/sites-available/${NGINX_SITE_NAME}" "/etc/nginx/sites-enabled/${NGINX_SITE_NAME}"
run_cmd rm -f /etc/nginx/sites-enabled/default
run_cmd nginx -t
if systemd_service_exists nginx; then
  # A restart waits for the old worker generation to leave; a graceful reload
  # could let a keep-alive client receive new HTML with the previous CSP.
  run_cmd systemctl restart nginx
fi
write_maintenance_page

run_cmd rm -f -- "${BACKEND_DIR}/storage/app/backup-config.env"

log "Deploying DIS from ${APP_ROOT}"
RELEASE_ID="$(date -u +%Y%m%dT%H%M%SZ)"
ensure_managed_directory "${DIS_DATA_PATH}/storage/releases" root root 0750
RELEASE_MARKER_TEMP="$(mktemp "${DIS_DATA_PATH}/storage/releases/.current.XXXXXX")"
run_cmd chmod 0640 "${RELEASE_MARKER_TEMP}"
printf '%s\n' "${RELEASE_ID}" > "${RELEASE_MARKER_TEMP}"
run_cmd mv -fT -- "${RELEASE_MARKER_TEMP}" "${DIS_DATA_PATH}/storage/releases/current"
RELEASE_MARKER_TEMP=""

ensure_directory "${BACKEND_DIR}/storage/app" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${BACKEND_DIR}/storage/framework/cache" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${BACKEND_DIR}/storage/framework/sessions" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${BACKEND_DIR}/storage/framework/views" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${BACKEND_DIR}/storage/logs" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${BACKEND_DIR}/bootstrap/cache" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${BACKEND_DIR}/storage/composer" "${DIS_USER}" "${DIS_GROUP}" 0750
APP_ROOT="${APP_ROOT}" bash "${SCRIPT_DIR}/self-heal-permissions.sh"
run_cmd ln -sfn "${APP_ROOT}/.env" "${BACKEND_DIR}/.env"
run_cmd chown -h root:root "${BACKEND_DIR}/.env"
if id www-data >/dev/null 2>&1; then
  run_cmd gpasswd -d www-data "${DIS_GROUP}" >/dev/null 2>&1 || true
  run_cmd setfacl -m "u:www-data:r--" "${APP_ROOT}/.env"
fi

if [ -f "${BACKEND_DIR}/composer.json" ]; then
  log "Installing backend dependencies"
  [ -f "${BACKEND_DIR}/composer.lock" ] || fail "composer.lock is required for a reproducible deployment."
  COMPOSER_DEPLOY_HOME="$(mktemp -d "${TMPDIR:-/var/tmp}/dis-composer-deploy.XXXXXX")"
  run_cmd chmod 0700 "${COMPOSER_DEPLOY_HOME}"
  run_cmd rm -rf -- "${BACKEND_DIR}/vendor"
  if ! run_cmd env \
    HOME="${COMPOSER_DEPLOY_HOME}" \
    COMPOSER_HOME="${COMPOSER_DEPLOY_HOME}" \
    COMPOSER_ALLOW_SUPERUSER=1 \
    composer install \
      --working-dir="${BACKEND_DIR}" \
      --no-dev \
      --prefer-dist \
      --no-interaction \
      --optimize-autoloader \
      --no-plugins \
      --no-scripts; then
    run_cmd rm -rf -- "${COMPOSER_DEPLOY_HOME}"
    fail "Backend dependency installation failed."
  fi
  run_cmd rm -rf -- "${COMPOSER_DEPLOY_HOME}"
  run_cmd chown -R root:root "${BACKEND_DIR}/vendor"
  run_cmd chmod -R u=rwX,go=rX "${BACKEND_DIR}/vendor"
  record_backend_dependency_state "${BACKEND_DIR}"
  APP_ROOT="${APP_ROOT}" bash "${SCRIPT_DIR}/self-heal-permissions.sh"
  if [ "${SKIP_DEPLOY_CACHE_CLEAR}" != "1" ]; then
    invalidate_backend_generated_cache "${BACKEND_DIR}"
    log "Clearing application caches"
    run_cmd runuser -u "${DIS_USER}" -- php "${BACKEND_DIR}/artisan" optimize:clear
  fi
  regenerate_backend_package_manifest "${BACKEND_DIR}"
  enable_backend_deployment_maintenance "${BACKEND_DIR}"
  run_cmd runuser -u "${DIS_USER}" -- php "${BACKEND_DIR}/artisan" migrate --force
  if [ "${RUN_SEEDERS}" = "1" ]; then
    run_cmd runuser -u "${DIS_USER}" -- php "${BACKEND_DIR}/artisan" db:seed --force
  else
    log "Skipping database seeders. Set RUN_SEEDERS=1 only for first install or intentional reseeding."
  fi

  # Laravel replaces generated cache files using the caller's umask. Reconcile
  # the completed cache tree after all Artisan writes so a restrictive root
  # shell umask cannot remove the PHP-FPM identity's explicit read access.
  log "Reconciling generated backend cache permissions"
  reconcile_backend_generated_cache_permissions "${BACKEND_DIR}"

  if id www-data >/dev/null 2>&1; then
    run_cmd runuser -u www-data -- php "${BACKEND_DIR}/artisan" dis:self-check
  else
    run_cmd runuser -u "${DIS_USER}" -- php "${BACKEND_DIR}/artisan" dis:self-check
  fi
fi

if [ -f "${FRONTEND_DIR}/package.json" ]; then
  log "Building frontend"
  [ -f "${FRONTEND_DIR}/package-lock.json" ] || fail "package-lock.json is required for a reproducible deployment."
  log "Installing frontend dependencies from package-lock.json"
  run_cmd npm --prefix "${FRONTEND_DIR}" ci --ignore-scripts
  run_cmd rm -rf "${FRONTEND_DIR}/.next"
  run_cmd npm --prefix "${FRONTEND_DIR}" run build
  run_cmd npm --prefix "${FRONTEND_DIR}" prune --omit=dev --ignore-scripts
  run_cmd chown -R root:root "${FRONTEND_DIR}/.next" "${FRONTEND_DIR}/node_modules"
  run_cmd chmod -R u=rwX,go=rX "${FRONTEND_DIR}/.next" "${FRONTEND_DIR}/node_modules"
  ensure_directory "${FRONTEND_DIR}/.next/cache" "${DIS_USER}" "${DIS_GROUP}" 0750
  run_cmd chown -R "${DIS_USER}:${DIS_GROUP}" "${FRONTEND_DIR}/.next/cache"
fi

log "Installing Nginx and systemd configuration"
run_cmd chmod 0755 "${APP_ROOT}/setup.sh" "${APP_ROOT}/update.sh" "${APP_ROOT}/uninstall.sh"
run_cmd find "${APP_ROOT}/scripts" -type f -name "*.sh" -exec chmod 0755 {} +
if [ -e /usr/local/bin/update ] && ! grep -q "${APP_ROOT}/update.sh" /usr/local/bin/update 2>/dev/null; then
  fail "/usr/local/bin/update already exists and is not managed by DIS."
fi
cat > /usr/local/bin/update <<EOF
#!/usr/bin/env bash
exec bash "${APP_ROOT}/update.sh" "\$@"
EOF
run_cmd chmod 0755 /usr/local/bin/update
run_cmd install -m 0755 "${APP_ROOT}/scripts/web-update-runner.sh" /usr/local/bin/dis-update-runner
ensure_directory /var/log/dis root "${DIS_GROUP}" 0750
if [ ! -e /var/log/dis/system-update-runner.log ]; then
  run_cmd install -m 0640 -o root -g "${DIS_GROUP}" /dev/null /var/log/dis/system-update-runner.log
fi
run_cmd chown root:"${DIS_GROUP}" /var/log/dis/system-update-runner.log
run_cmd chmod 0640 /var/log/dis/system-update-runner.log
if id www-data >/dev/null 2>&1; then
  run_cmd setfacl -m "u:www-data:rx" /var/log/dis
  run_cmd setfacl -m "u:www-data:r--" /var/log/dis/system-update-runner.log
fi
run_cmd install -m 0755 "${APP_ROOT}/scripts/backup-request-worker.sh" /usr/local/bin/dis-backup-request-worker
run_cmd install -m 0755 "${APP_ROOT}/scripts/snapshot-backup-input.sh" /usr/local/bin/dis-snapshot-backup-input
install_osrm_admin_runtime_bundle "${APP_ROOT}"
remove_legacy_backup_entrypoints
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/php/security.ini" "/etc/php/${PHP_VERSION}/fpm/conf.d/99-dis-security.ini"
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/php/opcache.ini" "/etc/php/${PHP_VERSION}/fpm/conf.d/99-dis-opcache.ini"
install_php_fpm_privileged_helpers_override
run_cmd install -m 0440 "${APP_ROOT}/infrastructure/sudoers/dis-update" /etc/sudoers.d/dis-update
run_cmd visudo -cf /etc/sudoers.d/dis-update
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/systemd/dis-queue.service" /etc/systemd/system/dis-queue.service
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/systemd/dis-media.service" /etc/systemd/system/dis-media.service
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/systemd/dis-knmi.service" /etc/systemd/system/dis-knmi.service
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/systemd/dis-incident-enrichment.service" /etc/systemd/system/dis-incident-enrichment.service
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/systemd/dis-scheduler.service" /etc/systemd/system/dis-scheduler.service
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/systemd/dis-websocket.service" /etc/systemd/system/dis-websocket.service
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/systemd/dis-frontend.service" /etc/systemd/system/dis-frontend.service
install_backup_request_systemd_units "${APP_ROOT}"
install_osrm_admin_layout
install_osrm_admin_request_systemd_units "${APP_ROOT}"
run_cmd systemctl daemon-reload
run_cmd systemctl enable \
  dis-queue dis-media dis-scheduler dis-websocket dis-frontend dis-incident-enrichment dis-knmi \
  dis-backup-request.path dis-backup-request.timer \
  dis-osrm-admin-request.path dis-osrm-admin-request.timer
APP_ROOT="${APP_ROOT}" bash "${APP_ROOT}/scripts/osrm.sh" reconcile
APP_ROOT="${APP_ROOT}" bash "${APP_ROOT}/scripts/osrm.sh" publish-status

finalize_backup_key_cutover "${APP_ROOT}"

log "Starting the web tier behind maintenance for verification"
restart_dis_web_services_for_verification
prepare_backend_for_deployment_verification "${BACKEND_DIR}"
require_dis_web_services
HEALTH_URL="http://127.0.0.1/health" bash "${SCRIPT_DIR}/healthcheck.sh"

if [ "${DIS_DEFER_OPERATIONAL_SERVICES}" != "1" ]; then
  start_dis_operational_services
  require_dis_runtime_services
fi

if [ "${DIS_DEPLOYMENT_OWNER}" = "deploy" ]; then
  complete_deployment_maintenance "${BACKEND_DIR}"
  log "Deployment finished"
else
  log "Deployment verified; maintenance remains owned by ${DIS_DEPLOYMENT_OWNER}."
  log "Nested deployment phase finished"
fi

trap - EXIT
