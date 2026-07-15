#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

BACKUP_PATH="${1:-}"

if [ -z "${BACKUP_PATH}" ]; then
  fail "Usage: verify-backup.sh /opt/dis-data/backup/<timestamp>"
fi

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
require_root
acquire_dis_operation_lock backup-verification
load_data_path_from_env "${APP_ROOT}/.env"
ensure_data_links "${APP_ROOT}"
if [ -f "${APP_ROOT}/.env" ]; then
  set -a
  source "${APP_ROOT}/.env"
  set +a
  load_backup_runtime_config_for_operation "${APP_ROOT}/webapp/backend/storage/app/backup-config.json"
  resolve_backup_root "${APP_ROOT}" >/dev/null
fi

require_directory "${BACKUP_PATH}"
EXPECTED_BACKUP_ID="${EXPECTED_BACKUP_ID:-$(basename "${BACKUP_PATH}")}"
ensure_directory "${DIS_DATA_PATH}/backup-request-work" root root 0700
VERIFICATION_ROOT="$(mktemp -d "${DIS_DATA_PATH}/backup-request-work/verify.XXXXXX")"
chmod 0700 "${VERIFICATION_ROOT}"
verification_exit_handler() {
  local status="$?"

  trap - EXIT
  if [ -d "${VERIFICATION_ROOT}" ]; then
    secure_path_operation remove-tree "${VERIFICATION_ROOT}" >/dev/null 2>&1 || true
  fi
  exit "${status}"
}
trap verification_exit_handler EXIT

if [ "${BACKUP_INPUT_ALREADY_SNAPSHOTTED:-0}" != "1" ]; then
  snapshot_authenticated_backup_input \
    "${BACKUP_PATH}" \
    "${VERIFICATION_ROOT}/input" \
    "${BACKUP_SNAPSHOT_MAX_PAYLOAD_BYTES:-0}"
  BACKUP_PATH="${VERIFICATION_ROOT}/input"
fi

if [ "${BACKUP_IDENTITY_VERIFIED:-0}" != "1" ]; then
  verify_backup_snapshot_identity "${BACKUP_PATH}"
fi
jq -e '.format == "dis-encrypted-backup-v1" and .encrypted == true and .cipher == "aes-256-cbc-pbkdf2-sha256"' \
  "${BACKUP_PATH}/manifest.json" >/dev/null || fail "Unsupported or unauthenticated backup format."
jq -e '.version | type == "string" and test("^[0-9]+\\.[0-9]+\\.[0-9]+$")' \
  "${BACKUP_PATH}/manifest.json" >/dev/null || fail "Backup application version is invalid."
CURRENT_VERSION="$(tr -d '\r\n' < "${APP_ROOT}/VERSION")"
[[ "${CURRENT_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "Current application version is invalid."
BACKUP_VERSION="$(jq -r '.version' "${BACKUP_PATH}/manifest.json")"
IFS=. read -r backup_major backup_minor backup_patch <<< "${BACKUP_VERSION}"
IFS=. read -r current_major current_minor current_patch <<< "${CURRENT_VERSION}"
if [ "${backup_major}" -gt "${current_major}" ] \
  || { [ "${backup_major}" -eq "${current_major}" ] && [ "${backup_minor}" -gt "${current_minor}" ]; } \
  || { [ "${backup_major}" -eq "${current_major}" ] && [ "${backup_minor}" -eq "${current_minor}" ] \
    && [ "${backup_patch}" -gt "${current_patch}" ]; }; then
  fail "Backup was created by a newer unsupported application version."
fi
if [[ "${EXPECTED_BACKUP_ID}" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; then
  jq -e --arg expected "${EXPECTED_BACKUP_ID}" '.created_at == $expected' \
    "${BACKUP_PATH}/manifest.json" >/dev/null || fail "Backup identity does not match its authenticated manifest."
fi

TEMPORARY_PAYLOAD="${VERIFICATION_ROOT}/payload"
run_cmd install -d -m 0700 -o root -g root "${TEMPORARY_PAYLOAD}"

log "Decrypting backup payload for verification"
extract_encrypted_backup_payload "${BACKUP_PATH}/backup.payload.enc" "${TEMPORARY_PAYLOAD}"
PAYLOAD_ROOT="${TEMPORARY_PAYLOAD}"

require_file "${PAYLOAD_ROOT}/database.dump"
require_file "${PAYLOAD_ROOT}/storage.tar.gz"
require_file "${PAYLOAD_ROOT}/source.tar.gz"
require_file "${PAYLOAD_ROOT}/env.backup"

log "Verifying PostgreSQL dump"
run_cmd pg_restore --list "${PAYLOAD_ROOT}/database.dump" >/dev/null

log "Verifying storage archive"
STORAGE_VERIFICATION_ROOT="${VERIFICATION_ROOT}/storage"
run_cmd install -d -m 0700 -o root -g root "${STORAGE_VERIFICATION_ROOT}"
case "${BACKUP_VERIFY_STORAGE_METADATA_ONLY:-0}" in
  0)
    extract_storage_backup_archive "${PAYLOAD_ROOT}/storage.tar.gz" "${STORAGE_VERIFICATION_ROOT}"
    require_directory "${STORAGE_VERIFICATION_ROOT}/storage"
    require_directory "${STORAGE_VERIFICATION_ROOT}/webapp/backend/storage"
    require_directory "${STORAGE_VERIFICATION_ROOT}/secrets"
    ;;
  1)
    validate_storage_backup_archive "${PAYLOAD_ROOT}/storage.tar.gz" "${STORAGE_VERIFICATION_ROOT}"
    ;;
  *)
    fail "BACKUP_VERIFY_STORAGE_METADATA_ONLY must be 0 or 1."
    ;;
esac

log "Verifying source archive"
run_cmd tar -tzf "${PAYLOAD_ROOT}/source.tar.gz" >/dev/null

log "Backup verification passed"
