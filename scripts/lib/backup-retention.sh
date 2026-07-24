#!/usr/bin/env bash

# This file is sourced by root-only backup commands after scripts/lib/common.sh.

backup_runtime_config_sha256() {
  local target="${BACKUP_TARGET:-}"

  [[ "${target}" =~ ^(local|samba)$ ]] \
    || { fail "Backup runtime configuration target is invalid."; return 1; }

  {
    printf '%s\0%s\0' "BACKUP_TARGET" "${target}"
    printf '%s\0%s\0' "BACKUP_ROOT" "${BACKUP_ROOT:-}"
    printf '%s\0%s\0' "BACKUP_RETENTION_COUNT" "${BACKUP_RETENTION_COUNT:-}"
    printf '%s\0%s\0' "BACKUP_ENCRYPTION_KEY_FILE" "${BACKUP_ENCRYPTION_KEY_FILE:-}"
    if [ "${target}" = "samba" ]; then
      printf '%s\0%s\0' "BACKUP_SAMBA_SHARE" "${BACKUP_SAMBA_SHARE:-}"
      printf '%s\0%s\0' "BACKUP_SAMBA_MOUNT" "${BACKUP_SAMBA_MOUNT:-}"
      printf '%s\0%s\0' "BACKUP_SAMBA_USERNAME" "${BACKUP_SAMBA_USERNAME:-}"
      printf '%s\0%s\0' "BACKUP_SAMBA_PASSWORD" "${BACKUP_SAMBA_PASSWORD:-}"
      printf '%s\0%s\0' "BACKUP_SAMBA_DOMAIN" "${BACKUP_SAMBA_DOMAIN:-}"
      printf '%s\0%s\0' "BACKUP_SAMBA_VERSION" "${BACKUP_SAMBA_VERSION:-}"
    fi
  } | sha256sum | awk '{print $1}'
}

require_backup_runtime_config_binding() {
  local expected="$1"
  local requested_target="$2"
  local actual

  [[ "${expected}" =~ ^[a-f0-9]{64}$ ]] \
    || { fail "Expected backup runtime configuration fingerprint is invalid."; return 1; }
  [[ "${requested_target}" =~ ^(local|samba)$ ]] \
    || { fail "Requested backup retention target is invalid."; return 1; }

  if ! actual="$(backup_runtime_config_sha256)"; then
    fail "Backup runtime configuration fingerprint could not be calculated."
    return 1
  fi
  [ "${actual}" = "${expected}" ] \
    || { fail "Backup runtime configuration changed before retention execution."; return 1; }
  [ "${BACKUP_TARGET:-}" = "${requested_target}" ] \
    || { fail "Backup runtime configuration target does not match the retention request."; return 1; }
}

prune_old_backups() {
  local requested_root="$1"
  local keep="${BACKUP_RETENTION_COUNT:-0}"
  local root root_device backup_id candidate candidate_device candidate_root selection
  local -a backup_ids=()

  if ! [[ "${keep}" =~ ^[0-9]+$ ]] || [ "${keep}" -lt 1 ]; then
    return 0
  fi

  [ -n "${requested_root}" ] && [ -d "${requested_root}" ] && [ ! -L "${requested_root}" ] \
    || { fail "Backup retention root is not a regular directory."; return 1; }

  # Reject symbolic links in every parent component before canonicalising the
  # configured root. The marker itself need not exist.
  require_root_controlled_parent "${requested_root%/}/.retention-boundary" || return 1
  if ! root="$(realpath -e -- "${requested_root}")"; then
    fail "Backup retention root could not be resolved safely."
    return 1
  fi
  [ -d "${root}" ] && [ ! -L "${root}" ] \
    || { fail "Backup retention root could not be resolved safely."; return 1; }
  if ! root_device="$(stat -c '%d' -- "${root}")"; then
    fail "Backup retention root metadata could not be read."
    return 1
  fi

  if ! selection="$(
    find "${root}" -mindepth 1 -maxdepth 1 -type d \
      -regextype posix-extended -regex '.*/[0-9]{8}T[0-9]{6}Z$' -printf '%f\n' \
      | LC_ALL=C sort -r \
      | awk -v keep="${keep}" 'NR > keep { print }'
  )"; then
    fail "Backup retention snapshots could not be enumerated."
    return 1
  fi
  if [ -n "${selection}" ]; then
    mapfile -t backup_ids <<< "${selection}"
  fi

  # Resolve and bound every selected entry before deleting the first one. A
  # timestamp-shaped symlink or mounted directory must never become a delete
  # target. secure-path.py additionally refuses mounted subtrees and traverses
  # directory descriptors with O_NOFOLLOW while removing a validated target.
  for backup_id in "${backup_ids[@]}"; do
    [[ "${backup_id}" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] \
      || { fail "Backup retention selected an invalid snapshot id."; return 1; }
    candidate="${root}/${backup_id}"
    [ -d "${candidate}" ] && [ ! -L "${candidate}" ] \
      || { fail "Backup retention selected an unsafe snapshot."; return 1; }
    if ! candidate_root="$(realpath -e -- "${candidate}")"; then
      fail "Backup retention selected an unreadable snapshot."
      return 1
    fi
    [ "${candidate_root}" = "${candidate}" ] \
      || { fail "Backup retention selected a snapshot outside its target root."; return 1; }
    if ! candidate_device="$(stat -c '%d' -- "${candidate}")"; then
      fail "Backup retention selected a snapshot without safe metadata."
      return 1
    fi
    [ "${candidate_device}" = "${root_device}" ] \
      || { fail "Mounted backup snapshots may not be removed by retention."; return 1; }
  done

  if [ "${#backup_ids[@]}" -gt 0 ]; then
    log "Pruning old backups, keeping latest ${keep}"
  fi
  for backup_id in "${backup_ids[@]}"; do
    secure_path_operation remove-tree "${root}/${backup_id}" || return 1
  done
}
