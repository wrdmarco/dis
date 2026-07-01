#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ ! -f "${SCRIPT_DIR}/lib/common.sh" ] && [ -f "${DIS_INSTALL_PATH:-/opt/dis}/scripts/lib/common.sh" ]; then
  SCRIPT_DIR="${DIS_INSTALL_PATH:-/opt/dis}/scripts"
fi
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
REQUEST_DIR="${DIS_DATA_PATH}/backup-requests"
LOCK_FILE="${REQUEST_DIR}/worker.lock"

require_root
load_data_path_from_env "${APP_ROOT}/.env"
ensure_data_links "${APP_ROOT}"
ensure_directory "${REQUEST_DIR}" root "${DIS_GROUP}" 0770

if ! command -v jq >/dev/null 2>&1; then
  fail "jq is required for backup request processing."
fi

safe_backup_path() {
  local requested="$1" root candidate base
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

write_result() {
  local result_file="$1" state="$2" exit_code="$3" output="$4"

  jq -n \
    --arg state "${state}" \
    --argjson exit_code "${exit_code}" \
    --arg output "${output}" \
    --arg finished_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{state: $state, exit_code: $exit_code, output: $output, finished_at: $finished_at}' > "${result_file}"
  run_cmd chgrp "${DIS_GROUP}" "${result_file}" || true
  run_cmd chmod 0640 "${result_file}" || true
}

process_request() {
  local request_file="$1" request_id running_file result_file operation target backup_path output exit_code state

  request_id="$(basename "${request_file}" .pending)"
  if [[ ! "${request_id}" =~ ^[A-Za-z0-9._-]+$ ]]; then
    return
  fi

  running_file="${REQUEST_DIR}/${request_id}.running"
  result_file="${REQUEST_DIR}/${request_id}.result"
  if ! mv "${request_file}" "${running_file}" 2>/dev/null; then
    return
  fi

  operation="$(jq -r '.operation // ""' "${running_file}")"
  target="$(jq -r '.target // "local"' "${running_file}")"
  backup_path="$(jq -r '.backup_path // ""' "${running_file}")"

  set +e
  case "${operation}" in
    create)
      output="$(BACKUP_TARGET="${target}" APP_ROOT="${APP_ROOT}" bash "${SCRIPT_DIR}/backup.sh" 2>&1)"
      exit_code=$?
      ;;
    verify)
      output="$(bash "${SCRIPT_DIR}/verify-backup.sh" "$(safe_backup_path "${backup_path}")" 2>&1)"
      exit_code=$?
      ;;
    restore)
      output="$(bash "${SCRIPT_DIR}/restore.sh" "$(safe_backup_path "${backup_path}")" 2>&1)"
      exit_code=$?
      ;;
    *)
      output="Unknown backup operation."
      exit_code=2
      ;;
  esac
  set -e

  if [ "${exit_code}" -eq 0 ]; then
    state="succeeded"
  else
    state="failed"
  fi

  write_result "${result_file}" "${state}" "${exit_code}" "${output}"
  rm -f "${running_file}"
}

(
  flock -n 9 || exit 0
  shopt -s nullglob
  for request_file in "${REQUEST_DIR}"/*.pending; do
    process_request "${request_file}"
  done
) 9>"${LOCK_FILE}"
