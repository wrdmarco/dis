#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

BACKUP_PATH="${1:-}"

if [ -z "${BACKUP_PATH}" ]; then
  fail "Usage: backup-restore-runner.sh /opt/dis/backup/<timestamp>"
fi

validate_backup_path() {
  local requested root candidate base
  requested="$1"
  base="$(basename "${requested}")"

  if [[ ! "${base}" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; then
    fail "Invalid backup id."
  fi

  for root in "${DIS_INSTALL_PATH}/backup" "/mnt/dis-backup"; do
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

exec bash "${SCRIPT_DIR}/restore.sh" "$(validate_backup_path "${BACKUP_PATH}")"
