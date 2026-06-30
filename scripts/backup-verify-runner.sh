#!/usr/bin/env bash
set -euo pipefail

DIS_INSTALL_PATH="${DIS_INSTALL_PATH:-/opt/dis}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ ! -f "${SCRIPT_DIR}/lib/common.sh" ] && [ -f "${DIS_INSTALL_PATH}/scripts/lib/common.sh" ]; then
  SCRIPT_DIR="${DIS_INSTALL_PATH}/scripts"
fi
source "${SCRIPT_DIR}/lib/common.sh"
load_data_path_from_env "${DIS_INSTALL_PATH}/.env"

BACKUP_PATH="${1:-}"

if [ -z "${BACKUP_PATH}" ]; then
  fail "Usage: backup-verify-runner.sh /opt/dis-data/backup/<timestamp>"
fi

validate_backup_path() {
  local requested root candidate base
  requested="$1"
  base="$(basename "${requested}")"

  if [[ ! "${base}" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; then
    fail "Invalid backup id."
  fi

  for root in "${DIS_DATA_PATH}/backup" "${DIS_INSTALL_PATH}/backup" "/mnt/dis-backup"; do
    if [ ! -d "${root}" ]; then
      continue
    fi
    candidate="$(realpath -m "${requested}")"
    root="$(realpath -m "${root}")"
    if [ "${candidate}" = "${root}/${base}" ] && [ -d "${candidate}" ]; then
      printf '%s' "${candidate}"
      return
    fi
  done

  fail "Backup path is not allowed."
}

exec bash "${SCRIPT_DIR}/verify-backup.sh" "$(validate_backup_path "${BACKUP_PATH}")"
