#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
BACKEND_DIR="${APP_ROOT}/webapp/backend"
FRONTEND_DIR="${APP_ROOT}/webapp/frontend"
NGINX_SOURCE="${NGINX_SOURCE:-${APP_ROOT}/infrastructure/nginx/dis.conf}"
SKIP_DEPLOY_CACHE_CLEAR="${SKIP_DEPLOY_CACHE_CLEAR:-0}"
RUN_SEEDERS="${RUN_SEEDERS:-0}"

require_directory "${APP_ROOT}"
require_file "${APP_ROOT}/.env"
require_file "${NGINX_SOURCE}"
require_root

log "Deploying DIS from ${APP_ROOT}"
RELEASE_ID="$(date -u +%Y%m%dT%H%M%SZ)"
ensure_directory "${APP_ROOT}/storage/releases" "${DIS_USER}" "${DIS_GROUP}" 0750
printf '%s\n' "${RELEASE_ID}" > "${APP_ROOT}/storage/releases/current"
run_cmd chown "${DIS_USER}:${DIS_GROUP}" "${APP_ROOT}/storage/releases/current"

ensure_directory "${BACKEND_DIR}/storage/app" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${BACKEND_DIR}/storage/framework/cache" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${BACKEND_DIR}/storage/framework/sessions" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${BACKEND_DIR}/storage/framework/views" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${BACKEND_DIR}/storage/logs" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${BACKEND_DIR}/bootstrap/cache" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${BACKEND_DIR}/storage/composer" "${DIS_USER}" "${DIS_GROUP}" 0750
run_cmd ln -sfn "${APP_ROOT}/.env" "${BACKEND_DIR}/.env"
run_cmd chown -h "${DIS_USER}:${DIS_GROUP}" "${BACKEND_DIR}/.env"
if id www-data >/dev/null 2>&1; then
  run_cmd setfacl -m "u:www-data:r--" "${APP_ROOT}/.env"
  run_cmd setfacl -R -m "u:www-data:rwx" "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache"
  run_cmd setfacl -R -d -m "u:www-data:rwx" "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache"
fi

if [ -f "${BACKEND_DIR}/composer.json" ]; then
  log "Installing backend dependencies"
  COMPOSER_ENV=(env HOME="${BACKEND_DIR}/storage/composer" COMPOSER_HOME="${BACKEND_DIR}/storage/composer" COMPOSER_ALLOW_SUPERUSER=1)
  if [ -f "${BACKEND_DIR}/composer.lock" ]; then
    run_cmd "${COMPOSER_ENV[@]}" composer install --working-dir="${BACKEND_DIR}" --no-dev --prefer-dist --no-interaction --optimize-autoloader
  else
    run_cmd "${COMPOSER_ENV[@]}" composer update --working-dir="${BACKEND_DIR}" --no-dev --prefer-dist --no-interaction --optimize-autoloader
  fi
  run_cmd chown -R "${DIS_USER}:${DIS_GROUP}" "${BACKEND_DIR}/vendor" "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache"
  if id www-data >/dev/null 2>&1; then
    run_cmd setfacl -R -m "u:www-data:rx" "${BACKEND_DIR}/vendor"
    run_cmd setfacl -R -m "u:www-data:rwx" "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache"
    run_cmd setfacl -R -d -m "u:www-data:rwx" "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache"
  fi
  run_cmd runuser -u "${DIS_USER}" -- php "${BACKEND_DIR}/artisan" migrate --force
  if [ "${RUN_SEEDERS}" = "1" ]; then
    run_cmd runuser -u "${DIS_USER}" -- php "${BACKEND_DIR}/artisan" db:seed --force
  else
    log "Skipping database seeders. Set RUN_SEEDERS=1 only for first install or intentional reseeding."
  fi
  run_cmd runuser -u "${DIS_USER}" -- php "${BACKEND_DIR}/artisan" dis:self-check
fi

if [ -f "${FRONTEND_DIR}/package.json" ]; then
  log "Building frontend"
  if [ -f "${FRONTEND_DIR}/package-lock.json" ]; then
    log "Installing frontend dependencies from package-lock.json"
    if ! run_cmd npm --prefix "${FRONTEND_DIR}" ci; then
      log "npm ci failed. Falling back to npm install to refresh stale frontend lock state."
      run_cmd npm --prefix "${FRONTEND_DIR}" install
    fi
  else
    run_cmd npm --prefix "${FRONTEND_DIR}" install
  fi
  run_cmd npm --prefix "${FRONTEND_DIR}" run build
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
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/php/security.ini" "/etc/php/${PHP_VERSION}/fpm/conf.d/99-dis-security.ini"
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/php/opcache.ini" "/etc/php/${PHP_VERSION}/fpm/conf.d/99-dis-opcache.ini"
run_cmd install -m 0440 "${APP_ROOT}/infrastructure/sudoers/dis-update" /etc/sudoers.d/dis-update
run_cmd visudo -cf /etc/sudoers.d/dis-update
run_cmd install -m 0644 "${NGINX_SOURCE}" "/etc/nginx/sites-available/${NGINX_SITE_NAME}"
run_cmd ln -sfn "/etc/nginx/sites-available/${NGINX_SITE_NAME}" "/etc/nginx/sites-enabled/${NGINX_SITE_NAME}"
run_cmd rm -f /etc/nginx/sites-enabled/default
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/systemd/dis-queue.service" /etc/systemd/system/dis-queue.service
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/systemd/dis-scheduler.service" /etc/systemd/system/dis-scheduler.service
run_cmd install -m 0644 "${APP_ROOT}/infrastructure/systemd/dis-websocket.service" /etc/systemd/system/dis-websocket.service
run_cmd systemctl daemon-reload
run_cmd systemctl enable dis-queue dis-scheduler dis-websocket
run_cmd nginx -t

log "Restarting services"
for service in dis-queue dis-scheduler dis-websocket "${PHP_FPM_SERVICE}" nginx; do
  if systemctl list-unit-files "${service}.service" >/dev/null 2>&1; then
    run_cmd systemctl restart "${service}"
  fi
done

if [ "${SKIP_DEPLOY_CACHE_CLEAR}" != "1" ] && [ -f "${BACKEND_DIR}/artisan" ]; then
  log "Clearing application caches"
  run_cmd runuser -u "${DIS_USER}" -- php "${BACKEND_DIR}/artisan" optimize:clear
fi

log "Deployment finished"
