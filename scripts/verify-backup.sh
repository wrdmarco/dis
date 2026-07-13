#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

BACKUP_PATH="${1:-}"

if [ -z "${BACKUP_PATH}" ]; then
  fail "Usage: verify-backup.sh /opt/dis-data/backup/<timestamp>"
fi

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
load_data_path_from_env "${APP_ROOT}/.env"
ensure_data_links "${APP_ROOT}"
if [ -f "${APP_ROOT}/.env" ]; then
  set -a
  source "${APP_ROOT}/.env"
  if [ -f "${APP_ROOT}/webapp/backend/storage/app/backup-config.env" ]; then
    source "${APP_ROOT}/webapp/backend/storage/app/backup-config.env"
  fi
  set +a
  resolve_backup_root "${APP_ROOT}" >/dev/null
fi

require_directory "${BACKUP_PATH}"
require_file "${BACKUP_PATH}/SHA256SUMS"
require_file "${BACKUP_PATH}/manifest.json"

log "Verifying backup checksums"
(cd "${BACKUP_PATH}" && awk '{ name=$2; sub(/^\*/, "", name); count=split(name, parts, "/"); print $1 "  " parts[count] }' SHA256SUMS | run_cmd sha256sum --check -)

PAYLOAD_ROOT="${BACKUP_PATH}"
TEMPORARY_PAYLOAD=""
if [ -f "${BACKUP_PATH}/backup.payload.enc" ]; then
  TEMPORARY_PAYLOAD="$(mktemp -d "${TMPDIR:-/var/tmp}/dis-backup-verify.XXXXXX")"
  chmod 0700 "${TEMPORARY_PAYLOAD}"
  trap 'rm -rf -- "${TEMPORARY_PAYLOAD}"' EXIT

  log "Decrypting backup payload for verification"
  extract_encrypted_backup_payload "${BACKUP_PATH}/backup.payload.enc" "${TEMPORARY_PAYLOAD}"
  PAYLOAD_ROOT="${TEMPORARY_PAYLOAD}"
fi

require_file "${PAYLOAD_ROOT}/database.dump"
require_file "${PAYLOAD_ROOT}/storage.tar.gz"
require_file "${PAYLOAD_ROOT}/source.tar.gz"
require_file "${PAYLOAD_ROOT}/env.backup"

log "Verifying PostgreSQL dump"
run_cmd pg_restore --list "${PAYLOAD_ROOT}/database.dump" >/dev/null

log "Verifying storage archive"
run_cmd tar -tzf "${PAYLOAD_ROOT}/storage.tar.gz" >/dev/null

log "Verifying source archive"
run_cmd tar -tzf "${PAYLOAD_ROOT}/source.tar.gz" >/dev/null

log "Backup verification passed"
