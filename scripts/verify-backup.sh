#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

BACKUP_PATH="${1:-}"

if [ -z "${BACKUP_PATH}" ]; then
  fail "Usage: verify-backup.sh /opt/dis/storage/backups/<timestamp>"
fi

require_directory "${BACKUP_PATH}"
require_file "${BACKUP_PATH}/database.dump"
require_file "${BACKUP_PATH}/storage.tar.gz"
require_file "${BACKUP_PATH}/source.tar.gz"
require_file "${BACKUP_PATH}/env.backup"
require_file "${BACKUP_PATH}/SHA256SUMS"
require_file "${BACKUP_PATH}/manifest.json"

log "Verifying backup checksums"
run_cmd sha256sum --check "${BACKUP_PATH}/SHA256SUMS"

log "Verifying storage archive"
run_cmd tar -tzf "${BACKUP_PATH}/storage.tar.gz" >/dev/null

log "Verifying source archive"
run_cmd tar -tzf "${BACKUP_PATH}/source.tar.gz" >/dev/null

log "Backup verification passed"
