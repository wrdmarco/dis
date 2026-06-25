#!/usr/bin/env bash
set -euo pipefail

DIS_INSTALL_PATH="${DIS_INSTALL_PATH:-/opt/dis}"
DIS_USER="${DIS_USER:-dis}"
DIS_GROUP="${DIS_GROUP:-dis}"
PHP_VERSION="${PHP_VERSION:-8.3}"
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
