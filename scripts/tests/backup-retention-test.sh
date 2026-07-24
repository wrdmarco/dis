#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-backup-retention-test.XXXXXX")"
BACKUP_ROOT="${TEST_ROOT}/backups"
OUTSIDE_ROOT="${TEST_ROOT}/outside"

cleanup() {
  rm -rf -- "${TEST_ROOT}"
}
trap cleanup EXIT

fail() {
  printf '%s\n' "$*" >&2
  return 1
}

log() {
  :
}

require_root_controlled_parent() {
  :
}

secure_path_operation() {
  [ "$1" = "remove-tree" ] || return 1
  shift
  rm -rf -- "$1"
}

source "${APP_ROOT}/scripts/lib/backup-retention.sh"

mkdir -p "${BACKUP_ROOT}" "${OUTSIDE_ROOT}"

BACKUP_TARGET=local
RUNTIME_TEST_BACKUP_ROOT="${BACKUP_ROOT}"
BACKUP_ROOT=/opt/dis-data/backup
BACKUP_RETENTION_COUNT=7
BACKUP_ENCRYPTION_KEY_FILE=/opt/dis-data/secrets/backup-encryption.key
LOCAL_CONFIG_SHA256="$(backup_runtime_config_sha256)"
[ "${LOCAL_CONFIG_SHA256}" = "3e1dc4f5bf27a01542d3982e9d4c58079865826c61dec76ff4dcfe9e61ac6ded" ]
require_backup_runtime_config_binding "${LOCAL_CONFIG_SHA256}" local
if require_backup_runtime_config_binding \
  aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
  local >/dev/null 2>&1; then
  printf 'A changed runtime configuration fingerprint was unexpectedly accepted.\n' >&2
  exit 1
fi
if require_backup_runtime_config_binding "${LOCAL_CONFIG_SHA256}" samba >/dev/null 2>&1; then
  printf 'A runtime configuration fingerprint was accepted for the wrong target.\n' >&2
  exit 1
fi

BACKUP_TARGET=samba
BACKUP_SAMBA_SHARE=//backup.example.test/dis
BACKUP_SAMBA_MOUNT=/mnt/dis-backup
BACKUP_SAMBA_USERNAME=backup-user
BACKUP_SAMBA_PASSWORD=valid-backup-password
BACKUP_SAMBA_DOMAIN=DIS
BACKUP_SAMBA_VERSION=3.1.1
SAMBA_CONFIG_SHA256="$(backup_runtime_config_sha256)"
[ "${SAMBA_CONFIG_SHA256}" = "e3e5b03704db8772905f6b4065acd81ef640365902be0361df01528dbd72c27b" ]
require_backup_runtime_config_binding "${SAMBA_CONFIG_SHA256}" samba
if require_backup_runtime_config_binding "${SAMBA_CONFIG_SHA256}" local >/dev/null 2>&1; then
  printf 'A Samba runtime configuration fingerprint was accepted for the local target.\n' >&2
  exit 1
fi

BACKUP_TARGET=local
BACKUP_ROOT="${RUNTIME_TEST_BACKUP_ROOT}"

printf 'keep\n' > "${OUTSIDE_ROOT}/marker"
for backup_id in \
  20260720T021500Z \
  20260721T021500Z \
  20260722T021500Z \
  20260723T021500Z \
  20260724T021500Z; do
  mkdir "${BACKUP_ROOT}/${backup_id}"
  printf '%s\n' "${backup_id}" > "${BACKUP_ROOT}/${backup_id}/manifest.json"
done
mkdir "${BACKUP_ROOT}/not-a-backup"
SYMLINK_SUPPORTED=0
if ln -s "${OUTSIDE_ROOT}" "${BACKUP_ROOT}/20260719T021500Z" 2>/dev/null \
  && [ -L "${BACKUP_ROOT}/20260719T021500Z" ]; then
  SYMLINK_SUPPORTED=1
else
  rm -rf -- "${BACKUP_ROOT}/20260719T021500Z"
fi

BACKUP_RETENTION_COUNT=2
prune_old_backups "${BACKUP_ROOT}"

[ -d "${BACKUP_ROOT}/20260724T021500Z" ]
[ -d "${BACKUP_ROOT}/20260723T021500Z" ]
[ ! -e "${BACKUP_ROOT}/20260722T021500Z" ]
[ ! -e "${BACKUP_ROOT}/20260721T021500Z" ]
[ ! -e "${BACKUP_ROOT}/20260720T021500Z" ]
[ -d "${BACKUP_ROOT}/not-a-backup" ]
[ "${SYMLINK_SUPPORTED}" = "0" ] || [ -L "${BACKUP_ROOT}/20260719T021500Z" ]
[ "$(cat "${OUTSIDE_ROOT}/marker")" = "keep" ]

mkdir "${BACKUP_ROOT}/20260718T021500Z"
BACKUP_RETENTION_COUNT=0
prune_old_backups "${BACKUP_ROOT}"
[ -d "${BACKUP_ROOT}/20260718T021500Z" ]

if [ "${SYMLINK_SUPPORTED}" = "1" ]; then
  ln -s "${BACKUP_ROOT}" "${TEST_ROOT}/linked-root"
  if (BACKUP_RETENTION_COUNT=1; prune_old_backups "${TEST_ROOT}/linked-root"); then
    printf 'A symbolic-link retention root was unexpectedly accepted.\n' >&2
    exit 1
  fi
  [ -d "${BACKUP_ROOT}/20260718T021500Z" ]
fi

printf 'Backup retention safety test passed.\n'
