#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

require_root
require_ubuntu_2604

log "Preparing DIS installation at ${DIS_INSTALL_PATH}"

if ! getent group "${DIS_GROUP}" >/dev/null 2>&1; then
  run_cmd groupadd --system "${DIS_GROUP}"
fi

if ! id "${DIS_USER}" >/dev/null 2>&1; then
  run_cmd useradd --system --gid "${DIS_GROUP}" --home-dir "${DIS_INSTALL_PATH}" --shell /usr/sbin/nologin "${DIS_USER}"
fi

ensure_directory "${DIS_INSTALL_PATH}" root root 0755
ensure_directory "${DIS_INSTALL_PATH}/storage" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${DIS_INSTALL_PATH}/storage/app" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${DIS_INSTALL_PATH}/backup" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${DIS_INSTALL_PATH}/storage/logs" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${DIS_INSTALL_PATH}/storage/tmp" "${DIS_USER}" "${DIS_GROUP}" 0750
ensure_directory "${DIS_INSTALL_PATH}/secrets" root "${DIS_GROUP}" 0750

log "Installing required Ubuntu packages"
run_cmd apt-get update
run_cmd apt-get install -y \
  acl ca-certificates curl git gnupg jq openssl rsync sudo unzip \
  nginx postgresql postgresql-client redis-server redis-tools cifs-utils smbclient \
  "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-redis" \
  "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
  "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl" \
  nodejs npm

log "Installing Composer"
EXPECTED_COMPOSER_SIGNATURE="$(curl -fsSL https://composer.github.io/installer.sig)"
run_cmd curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
ACTUAL_COMPOSER_SIGNATURE="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
if [ "${EXPECTED_COMPOSER_SIGNATURE}" != "${ACTUAL_COMPOSER_SIGNATURE}" ]; then
  run_cmd rm -f /tmp/composer-setup.php
  fail "Composer installer signature verification failed."
fi
run_cmd php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
run_cmd rm -f /tmp/composer-setup.php

if id www-data >/dev/null 2>&1; then
  run_cmd usermod -aG "${DIS_GROUP}" www-data
fi

log "Enabling system services"
run_cmd systemctl enable --now postgresql
run_cmd systemctl enable --now redis-server
run_cmd systemctl enable --now "${PHP_FPM_SERVICE}"
run_cmd systemctl enable --now nginx

log "Install completed. For first install use setup.sh; for updates configure .env and run scripts/deploy.sh."
