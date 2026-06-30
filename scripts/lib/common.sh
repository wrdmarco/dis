#!/usr/bin/env bash
set -euo pipefail

DIS_INSTALL_PATH="${DIS_INSTALL_PATH:-/opt/dis}"
DIS_DATA_PATH="${DIS_DATA_PATH:-/opt/dis-data}"
DIS_USER="${DIS_USER:-dis}"
DIS_GROUP="${DIS_GROUP:-dis}"
PHP_VERSION="${PHP_VERSION:-8.5}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php${PHP_VERSION}-fpm}"
NGINX_SITE_NAME="${NGINX_SITE_NAME:-dis}"

log() {
  printf '[dis] %s\n' "$*"
}

fail() {
  printf '[dis:error] %s\n' "$*" >&2
  exit 1
}

require_root() {
  if [ "${EUID}" -ne 0 ]; then
    fail "This command must be run as root."
  fi
}

require_ubuntu_2604() {
  if [ ! -r /etc/os-release ]; then
    fail "Cannot determine operating system. Ubuntu 26.04 LTS is required."
  fi

  # shellcheck disable=SC1091
  . /etc/os-release

  if [ "${ID:-}" != "ubuntu" ] || [ "${VERSION_ID:-}" != "26.04" ]; then
    fail "Ubuntu 26.04 LTS is required. Detected ${PRETTY_NAME:-unknown OS}."
  fi
}

run_cmd() {
  if [ "${DRY_RUN:-0}" = "1" ]; then
    printf '[dry-run] %s\n' "$*"
  else
    "$@"
  fi
}

require_file() {
  local path="$1"
  if [ ! -f "$path" ]; then
    fail "Required file not found: $path"
  fi
}

require_directory() {
  local path="$1"
  if [ ! -d "$path" ]; then
    fail "Required directory not found: $path"
  fi
}

ensure_directory() {
  local path="$1"
  local owner="${2:-${DIS_USER}}"
  local group="${3:-${DIS_GROUP}}"
  local mode="${4:-0750}"
  run_cmd install -d -m "$mode" -o "$owner" -g "$group" "$path"
}

load_data_path_from_env() {
  local env_file="$1"
  local configured_path

  if [ ! -f "${env_file}" ]; then
    return
  fi

  configured_path="$(grep -E '^DIS_DATA_PATH=' "${env_file}" | tail -n 1 | cut -d '=' -f 2- || true)"
  configured_path="${configured_path%\"}"
  configured_path="${configured_path#\"}"
  configured_path="${configured_path%\'}"
  configured_path="${configured_path#\'}"

  if [ -n "${configured_path}" ]; then
    DIS_DATA_PATH="${configured_path}"
    export DIS_DATA_PATH
  fi
}

ensure_data_layout() {
  ensure_directory "${DIS_DATA_PATH}" root "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/backup" root "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/secrets" root "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/storage" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/storage/app" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/storage/generated" root root 0755
  ensure_directory "${DIS_DATA_PATH}/storage/logs" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/storage/releases" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/storage/tmp" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage/app" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage/framework/cache" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage/framework/sessions" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage/framework/views" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage/logs" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage/composer" "${DIS_USER}" "${DIS_GROUP}" 0750
}

migrate_path_to_data() {
  local source="$1"
  local destination="$2"

  if [ -L "${source}" ]; then
    return
  fi

  if [ -e "${destination}" ]; then
    if [ -d "${destination}" ] && [ -n "$(find "${destination}" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null || true)" ]; then
      return
    fi
    if [ -f "${destination}" ]; then
      return
    fi
  fi

  if [ -d "${source}" ]; then
    ensure_directory "$(dirname "${destination}")" root "${DIS_GROUP}" 0750
    if [ -d "${destination}" ]; then
      run_cmd rmdir "${destination}"
    fi
    run_cmd mv "${source}" "${destination}"
  elif [ -f "${source}" ]; then
    ensure_directory "$(dirname "${destination}")" root "${DIS_GROUP}" 0750
    run_cmd mv "${source}" "${destination}"
  fi
}

link_data_path() {
  local source="$1"
  local destination="$2"

  if [ -L "${source}" ] && [ "$(readlink "${source}")" = "${destination}" ]; then
    return
  fi

  if [ -e "${source}" ] && [ ! -L "${source}" ]; then
    migrate_path_to_data "${source}" "${destination}"
  fi

  run_cmd ln -sfn "${destination}" "${source}"
}

ensure_data_links() {
  local app_root="${1:-${DIS_INSTALL_PATH}}"

  ensure_data_layout
  link_data_path "${app_root}/backup" "${DIS_DATA_PATH}/backup"
  link_data_path "${app_root}/secrets" "${DIS_DATA_PATH}/secrets"
  link_data_path "${app_root}/storage" "${DIS_DATA_PATH}/storage"

  if [ -d "${app_root}/webapp/backend" ]; then
    link_data_path "${app_root}/webapp/backend/storage" "${DIS_DATA_PATH}/webapp/backend/storage"
  fi

  if [ -f "${DIS_DATA_PATH}/.env" ] || [ -f "${app_root}/.env" ]; then
    if [ -f "${app_root}/.env" ] && [ ! -L "${app_root}/.env" ] && [ ! -f "${DIS_DATA_PATH}/.env" ]; then
      migrate_path_to_data "${app_root}/.env" "${DIS_DATA_PATH}/.env"
    fi
    run_cmd ln -sfn "${DIS_DATA_PATH}/.env" "${app_root}/.env"
  fi
}

resolve_backup_root() {
  local app_root="$1"
  local target="${BACKUP_TARGET:-local}"
  local configured_root

  if [ "${BACKUP_SAMBA_ENABLED:-0}" = "1" ]; then
    target="samba"
  fi

  if [ "${target}" != "samba" ]; then
    configured_root="${BACKUP_ROOT:-${BACKUP_DISK_PATH:-${DIS_DATA_PATH}/backup}}"
    if [ "${configured_root}" = "${app_root}/backup" ] || [ "${configured_root}" = "${DIS_INSTALL_PATH}/backup" ] || [ "${configured_root}" = "/opt/dis/backup" ]; then
      configured_root="${DIS_DATA_PATH}/backup"
    fi
    printf '%s\n' "${configured_root}"
    return 0
  fi

  local share="${BACKUP_SAMBA_SHARE:-}"
  local mount_point="${BACKUP_SAMBA_MOUNT:-/mnt/dis-backup}"
  local username="${BACKUP_SAMBA_USERNAME:-}"
  local password="${BACKUP_SAMBA_PASSWORD:-}"
  local domain="${BACKUP_SAMBA_DOMAIN:-}"
  local version="${BACKUP_SAMBA_VERSION:-3.1.1}"

  if [ -z "${share}" ]; then
    fail "BACKUP_SAMBA_SHARE is required when BACKUP_TARGET=samba."
  fi
  if [ -z "${username}" ] || [ -z "${password}" ]; then
    fail "BACKUP_SAMBA_USERNAME and BACKUP_SAMBA_PASSWORD are required when BACKUP_TARGET=samba."
  fi
  if ! command -v mount.cifs >/dev/null 2>&1; then
    fail "mount.cifs not found. Install cifs-utils before using Samba backups."
  fi

  run_cmd install -d -m 0750 "${mount_point}"
  if mountpoint -q "${mount_point}"; then
    printf '%s\n' "${mount_point}"
    return 0
  fi

  local credentials_file
  credentials_file="$(mktemp)"
  trap 'rm -f "${credentials_file}"' RETURN
  chmod 0600 "${credentials_file}"
  {
    printf 'username=%s\n' "${username}"
    printf 'password=%s\n' "${password}"
    if [ -n "${domain}" ]; then
      printf 'domain=%s\n' "${domain}"
    fi
  } > "${credentials_file}"

  local options="credentials=${credentials_file},vers=${version},iocharset=utf8,dir_mode=0750,file_mode=0640"
  run_cmd mount -t cifs "${share}" "${mount_point}" -o "${options}"
  rm -f "${credentials_file}"
  trap - RETURN

  printf '%s\n' "${mount_point}"
}
