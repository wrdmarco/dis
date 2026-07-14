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

log "Installing required Ubuntu packages"
run_cmd apt-get update
run_cmd apt-get install -y \
  acl ca-certificates curl git gnupg jq openssl python3 rsync sudo unzip \
  nginx postgresql postgresql-client redis-server redis-tools cifs-utils smbclient \
  "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-redis" \
  "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
  "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-gd" \
  nodejs npm

ensure_data_links "${DIS_INSTALL_PATH}"
detach_unsafe_cifs_backup_mount /mnt/dis-backup

log "Installing Composer"
COMPOSER_INSTALL_ROOT="$(mktemp -d "${TMPDIR:-/var/tmp}/dis-composer-installer.XXXXXX")"
chmod 0700 "${COMPOSER_INSTALL_ROOT}"
cleanup_composer_installer() {
  rm -rf -- "${COMPOSER_INSTALL_ROOT}" 2>/dev/null || true
}
trap cleanup_composer_installer EXIT
EXPECTED_COMPOSER_SIGNATURE="$(curl -fsSL https://composer.github.io/installer.sig)"
run_cmd curl -fsSL https://getcomposer.org/installer -o "${COMPOSER_INSTALL_ROOT}/composer-setup.php"
ACTUAL_COMPOSER_SIGNATURE="$(php -r "echo hash_file('sha384', '${COMPOSER_INSTALL_ROOT}/composer-setup.php');")"
if [ "${EXPECTED_COMPOSER_SIGNATURE}" != "${ACTUAL_COMPOSER_SIGNATURE}" ]; then
  fail "Composer installer signature verification failed."
fi
run_cmd php "${COMPOSER_INSTALL_ROOT}/composer-setup.php" --install-dir=/usr/local/bin --filename=composer --quiet
cleanup_composer_installer
trap - EXIT

if id www-data >/dev/null 2>&1; then
  run_cmd gpasswd -d www-data "${DIS_GROUP}" >/dev/null 2>&1 || true
fi

if systemd_service_exists "${PHP_FPM_SERVICE}"; then
  run_cmd systemctl stop "${PHP_FPM_SERVICE}"
fi

APP_ROOT="${DIS_INSTALL_PATH}" bash "${SCRIPT_DIR}/self-heal-permissions.sh"

log "Enabling system services"
run_cmd systemctl enable --now postgresql
run_cmd systemctl enable --now redis-server
run_cmd systemctl enable --now "${PHP_FPM_SERVICE}"
run_cmd systemctl enable --now nginx

log "Install completed. For first install use setup.sh; for updates configure .env and run scripts/deploy.sh."
