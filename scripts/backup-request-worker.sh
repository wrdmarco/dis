#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ ! -f "${SCRIPT_DIR}/lib/common.sh" ] && [ -f "${DIS_INSTALL_PATH:-/opt/dis}/scripts/lib/common.sh" ]; then
  SCRIPT_DIR="${DIS_INSTALL_PATH:-/opt/dis}/scripts"
fi
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"

require_root
load_data_path_from_env "${APP_ROOT}/.env"
REQUEST_DIR="${DIS_DATA_PATH}/backup-requests"
WORK_DIR="${DIS_DATA_PATH}/backup-request-work"
LOCK_DIR="/run/dis-backup-request"
LOCK_FILE="${LOCK_DIR}/worker.lock"
ensure_data_links "${APP_ROOT}"
ensure_directory "${REQUEST_DIR}" root root 1730
ensure_directory "${WORK_DIR}" root root 0700
ensure_directory "${LOCK_DIR}" root root 0700

if ! command -v jq >/dev/null 2>&1; then
  fail "jq is required for backup request processing."
fi

ensure_samba_backup_mount() {
  (
    set -a
    source "${APP_ROOT}/.env"
    set +a
    load_backup_runtime_config "${APP_ROOT}/webapp/backend/storage/app/backup-config.json"
    BACKUP_TARGET=samba
    export BACKUP_TARGET
    resolve_backup_root "${APP_ROOT}" >/dev/null
  )
}

safe_backup_path() {
  local requested="$1" root candidate base resolved_root import_root
  base="$(basename "${requested}")"

  if [[ ! "${base}" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] && [[ ! "${base}" =~ ^[a-f0-9]{32}$ ]]; then
    fail "Invalid backup id."
  fi

  import_root="$(realpath -m "${DIS_DATA_PATH}/backup-imports")"
  for root in "${DIS_DATA_PATH}/backup" "${DIS_DATA_PATH}/backup-imports" "${DIS_INSTALL_PATH}/backup" "/mnt/dis-backup"; do
    if [ ! -d "${root}" ]; then
      continue
    fi
    candidate="$(realpath -m "${requested}")"
    resolved_root="$(realpath -m "${root}")"
    if { [ "${resolved_root}" = "${import_root}" ] && [[ "${base}" =~ ^[a-f0-9]{32}$ ]]; } \
      || { [ "${resolved_root}" != "${import_root}" ] && [[ "${base}" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; }; then
      if [ "${candidate}" = "${resolved_root}/${base}" ] && [ -d "${candidate}" ]; then
        printf '%s' "${candidate}"
        return
      fi
    fi
  done

  fail "Backup path is not allowed."
}

write_result() {
  local result_file="$1" state="$2" exit_code="$3" output="$4" result_owner="$5" temporary_result

  temporary_result="$(mktemp "${WORK_DIR}/.result.XXXXXX")"
  if ! jq -n \
    --arg state "${state}" \
    --argjson exit_code "${exit_code}" \
    --arg output "${output}" \
    --arg finished_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{state: $state, exit_code: $exit_code, output: $output, finished_at: $finished_at}' > "${temporary_result}"; then
    rm -f -- "${temporary_result}"
    return 1
  fi
  run_cmd chown "${result_owner}:${DIS_GROUP}" "${temporary_result}"
  run_cmd chmod 0640 "${temporary_result}"
  run_cmd mv -fT -- "${temporary_result}" "${result_file}"
}

remove_work_entry() {
  local entry="$1"

  if [ -d "${entry}" ] && [ ! -L "${entry}" ]; then
    secure_path_operation remove-tree "${entry}"
  else
    rm -f -- "${entry}"
  fi
}

discard_invalid_pending_request() {
  local request_file="$1" quarantine

  quarantine="${WORK_DIR}/rejected-request.$$.$(date +%s%N).${RANDOM}"
  if ! mv -T -- "${request_file}" "${quarantine}" 2>/dev/null; then
    logger -p authpriv.warning -t dis-security \
      "backup_request_rejected reason=invalid_request_id quarantine=failed" 2>/dev/null || true
    return
  fi

  if remove_work_entry "${quarantine}"; then
    logger -p authpriv.warning -t dis-security \
      "backup_request_rejected reason=invalid_request_id quarantine=removed" 2>/dev/null || true
  else
    logger -p authpriv.err -t dis-security \
      "backup_request_rejected reason=invalid_request_id quarantine=cleanup_failed" 2>/dev/null || true
  fi
}

recover_abandoned_request() {
  local running_file="$1" request_id request_owner actor_id result_file orphan_path

  request_id="$(basename "${running_file}" .json)"
  if [[ ! "${request_id}" =~ ^[a-f0-9]{32}$ ]] \
    || [ -L "${running_file}" ] || [ ! -f "${running_file}" ] \
    || [ "$(stat -c '%s' "${running_file}" 2>/dev/null || printf 16385)" -gt 16384 ]; then
    remove_work_entry "${running_file}" 2>/dev/null || true
    return
  fi

  result_file="${REQUEST_DIR}/${request_id}.result"
  if [ -f "${result_file}" ]; then
    rm -f -- "${running_file}"
    return
  fi

  request_owner="$(stat -c '%U' "${running_file}")"
  if [ "${request_owner}" = "root" ]; then
    actor_id="$(jq -r '.actor_id // ""' "${running_file}" 2>/dev/null || true)"
    if [ -z "${actor_id}" ]; then
      request_owner="${DIS_USER}"
    elif [[ "${actor_id^^}" =~ ^[0-9A-HJKMNP-TV-Z]{26}$ ]]; then
      request_owner="www-data"
    else
      remove_work_entry "${running_file}" 2>/dev/null || true
      return
    fi
  fi
  if [ "${request_owner}" != "www-data" ] && [ "${request_owner}" != "${DIS_USER}" ]; then
    remove_work_entry "${running_file}" 2>/dev/null || true
    return
  fi

  write_result "${result_file}" "failed" 124 \
    "Een eerder geclaimde backup request is afgebroken voordat een resultaat werd gepubliceerd. Controleer de DIS backup request service en worker-logs." \
    "${request_owner}"
  rm -f -- "${running_file}"
  for orphan_path in "${WORK_DIR}/${request_id}.backup" "${WORK_DIR}/${request_id}.restore-input"; do
    if [ -e "${orphan_path}" ] || [ -L "${orphan_path}" ]; then
      remove_work_entry "${orphan_path}" || true
    fi
  done
  logger -p authpriv.err -t dis-security \
    "backup_request_recovered request_id=${request_id} state=failed exit_code=124" 2>/dev/null || true
}

process_request() {
  local request_file="$1" request_id request_owner running_file result_file operation target backup_path actor_id created_at created_epoch request_age output exit_code state
  local import_root original_backup_path original_backup_id claimed_backup_path snapshot_payload_limit
  local restore_block_file restore_receipt restore_receipt_time restore_key restore_snapshot_path restore_mutation_marker restore_attempt_started

  request_id="$(basename "${request_file}" .pending)"
  if [[ ! "${request_id}" =~ ^[a-f0-9]{32}$ ]]; then
    discard_invalid_pending_request "${request_file}"
    return
  fi

  running_file="${WORK_DIR}/${request_id}.json"
  result_file="${REQUEST_DIR}/${request_id}.result"
  if ! mv -- "${request_file}" "${running_file}" 2>/dev/null; then
    return
  fi

  if [ -L "${running_file}" ] || [ ! -f "${running_file}" ]; then
    remove_work_entry "${running_file}" 2>/dev/null || true
    return
  fi
  if [ "$(stat -c '%s' "${running_file}")" -gt 16384 ]; then
    rm -f -- "${running_file}"
    return
  fi
  request_owner="$(stat -c '%U' "${running_file}")"
  if [ "${request_owner}" != "www-data" ] && [ "${request_owner}" != "${DIS_USER}" ]; then
    rm -f -- "${running_file}"
    return
  fi
  run_cmd chown root:root "${running_file}"
  run_cmd chmod 0600 "${running_file}"

  if ! jq -e '
    type == "object"
    and (.operation | type == "string" and test("^(create|verify|restore|probe)$"))
    and (.target | type == "string" and test("^(local|samba)$"))
    and (
      (.operation == "create" and .backup_path == null)
      or (
        .operation == "probe"
        and .target == "local"
        and .backup_path == null
        and .actor_id == null
      )
      or (
        (.operation == "verify" or .operation == "restore")
        and (.backup_path | type == "string" and length >= 1 and length <= 4096)
      )
    )
    and (.created_at | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
    and (
      .actor_id == null
      or (.actor_id | type == "string" and test("^[0-9A-HJKMNP-TV-Z]{26}$"; "i"))
    )
    and ((keys_unsorted - ["operation", "target", "backup_path", "actor_id", "created_at"]) | length == 0)
  ' "${running_file}" >/dev/null; then
    write_result "${result_file}" "failed" 2 "Invalid backup request." "${request_owner}"
    rm -f -- "${running_file}"
    return
  fi

  operation="$(jq -r '.operation // ""' "${running_file}")"
  target="$(jq -r '.target // "local"' "${running_file}")"
  backup_path="$(jq -r '.backup_path // ""' "${running_file}")"
  actor_id="$(jq -r '.actor_id // ""' "${running_file}")"
  created_at="$(jq -r '.created_at' "${running_file}")"
  if ! created_epoch="$(date -u -d "${created_at}" +%s 2>/dev/null)"; then
    write_result "${result_file}" "failed" 2 "Backup request timestamp is invalid." "${request_owner}"
    rm -f -- "${running_file}"
    return
  fi
  request_age="$(( $(date +%s) - created_epoch ))"
  if [ "${request_age}" -lt -60 ] || [ "${request_age}" -gt 300 ]; then
    write_result "${result_file}" "failed" 2 "Backup request expired before execution." "${request_owner}"
    rm -f -- "${running_file}"
    return
  fi

  if [ "${operation}" = "probe" ] && [ "${request_owner}" != "${DIS_USER}" ]; then
    write_result "${result_file}" "failed" 2 "Backup worker probes may only be submitted by the scheduler." "${request_owner}"
    rm -f -- "${running_file}"
    return
  fi
  if [ "${request_owner}" = "${DIS_USER}" ] \
    && { { [ "${operation}" != "create" ] && [ "${operation}" != "probe" ]; } || [ -n "${actor_id}" ]; }; then
    write_result "${result_file}" "failed" 2 "The scheduler may only create unclaimed backups or probe the worker." "${request_owner}"
    rm -f -- "${running_file}"
    return
  fi
  if [ "${request_owner}" = "www-data" ] && [ -z "${actor_id}" ]; then
    write_result "${result_file}" "failed" 2 "Authenticated backup requests require an actor id." "${request_owner}"
    rm -f -- "${running_file}"
    return
  fi

  if [ "${operation}" = "probe" ]; then
    write_result "${result_file}" "succeeded" 0 "Backup request worker is healthy." "${request_owner}"
    rm -f -- "${running_file}"
    return
  fi

  original_backup_path=""
  original_backup_id=""
  claimed_backup_path=""
  snapshot_payload_limit=0
  restore_block_file="${WORK_DIR}/restore.blocked"
  restore_receipt=""
  restore_snapshot_path=""
  restore_mutation_marker="${WORK_DIR}/${request_id}.mutation-started"
  restore_attempt_started=0
  if [ "${operation}" = "verify" ] || [ "${operation}" = "restore" ]; then
    if [ "${target}" = "samba" ] && ! ensure_samba_backup_mount >/dev/null 2>&1; then
      write_result "${result_file}" "failed" 2 "Configured Samba backup storage is unavailable." "${request_owner}"
      rm -f -- "${running_file}"
      return
    fi
    if ! backup_path="$(safe_backup_path "${backup_path}")"; then
      write_result "${result_file}" "failed" 2 "Backup path is not allowed." "${request_owner}"
      rm -f -- "${running_file}"
      return
    fi

    original_backup_id="$(basename "${backup_path}")"
    import_root="$(realpath -m "${DIS_DATA_PATH}/backup-imports")"
    if [[ "${backup_path}" == "${import_root}/"* ]]; then
      snapshot_payload_limit=2147483648
      original_backup_path="${backup_path}"
      claimed_backup_path="${WORK_DIR}/${request_id}.backup"
      if [ -e "${claimed_backup_path}" ] || [ -L "${claimed_backup_path}" ] \
        || ! mv -T -- "${backup_path}" "${claimed_backup_path}" 2>/dev/null; then
        write_result "${result_file}" "failed" 2 "Uploaded backup could not be claimed safely." "${request_owner}"
        rm -f -- "${running_file}"
        return
      fi
      if [ -L "${claimed_backup_path}" ] || [ ! -d "${claimed_backup_path}" ]; then
        rm -rf -- "${claimed_backup_path}"
        write_result "${result_file}" "failed" 2 "Uploaded backup is not a regular directory." "${request_owner}"
        rm -f -- "${running_file}"
        return
      fi
      backup_path="${claimed_backup_path}"
    fi

    if [ "${operation}" = "restore" ]; then
      if [ ! -f "${restore_block_file}" ]; then
        restore_snapshot_path="${WORK_DIR}/${request_id}.restore-input"
        if ! timeout --signal=TERM --kill-after=30s 300s \
          env APP_ROOT="${APP_ROOT}" \
          bash "${SCRIPT_DIR}/snapshot-backup-input.sh" \
            "${backup_path}" "${restore_snapshot_path}" "${snapshot_payload_limit}"; then
          if [ -d "${restore_snapshot_path}" ]; then
            secure_path_operation remove-tree "${restore_snapshot_path}" || true
          fi
          if [ -n "${claimed_backup_path}" ] && [ -d "${claimed_backup_path}" ]; then
            secure_path_operation remove-tree "${claimed_backup_path}" || true
            claimed_backup_path=""
          fi
          write_result "${result_file}" "failed" 2 "Backup input could not be snapshotted safely." "${request_owner}"
          rm -f -- "${running_file}"
          return
        fi
        backup_path="${restore_snapshot_path}"
        restore_key="$(sha256sum "${backup_path}/BACKUP.HMAC" | awk '{print $1}')"
        restore_receipt="${WORK_DIR}/restore-${restore_key}.receipt"
        logger -p authpriv.notice -t dis-security \
          "restore_requested request_id=${request_id} claimed_actor_id=${actor_id} backup_ref=${restore_key} request_owner=${request_owner}" \
          2>/dev/null || true
      fi
    fi
  fi

  set +e
  case "${operation}" in
    create)
      output="$(timeout --signal=TERM --kill-after=30s 840s \
        env BACKUP_TARGET="${target}" APP_ROOT="${APP_ROOT}" \
        bash "${SCRIPT_DIR}/backup.sh" 2>&1)"
      exit_code=$?
      ;;
    verify)
      output="$(timeout --signal=TERM --kill-after=30s 540s \
        env APP_ROOT="${APP_ROOT}" \
        EXPECTED_BACKUP_ID="${original_backup_id}" \
        BACKUP_SNAPSHOT_MAX_PAYLOAD_BYTES="${snapshot_payload_limit}" \
        bash "${SCRIPT_DIR}/verify-backup.sh" "${backup_path}" 2>&1)"
      exit_code=$?
      ;;
    restore)
      if [ -f "${restore_block_file}" ]; then
        output="A previous restore failed after mutation started. Root operator intervention is required before another restore."
        exit_code=2
      elif [ -f "${restore_receipt}" ]; then
        restore_receipt_time="$(stat -c '%Y' "${restore_receipt}")"
        if [ "$(( $(date +%s) - restore_receipt_time ))" -le 3600 ]; then
          output="This authenticated backup was already restored successfully within the idempotency window."
          exit_code=0
        else
          rm -f -- "${restore_receipt}"
          rm -f -- "${restore_mutation_marker}"
          printf '%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ) ${request_id} ${original_backup_id}" > "${restore_block_file}"
          chmod 0600 "${restore_block_file}"
          sync -f "${restore_block_file}"
          restore_attempt_started=1
          output="$(timeout --signal=TERM --kill-after=30s 1140s \
            env APP_ROOT="${APP_ROOT}" \
            BACKUP_INPUT_ALREADY_SNAPSHOTTED=1 \
            BACKUP_IDENTITY_VERIFIED=1 \
            RESTORE_MUTATION_MARKER="${restore_mutation_marker}" \
            EXPECTED_BACKUP_ID="${original_backup_id}" \
            BACKUP_SNAPSHOT_MAX_PAYLOAD_BYTES="${snapshot_payload_limit}" \
            bash "${SCRIPT_DIR}/restore.sh" "${backup_path}" 2>&1)"
          exit_code=$?
        fi
      else
        rm -f -- "${restore_mutation_marker}"
        printf '%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ) ${request_id} ${original_backup_id}" > "${restore_block_file}"
        chmod 0600 "${restore_block_file}"
        sync -f "${restore_block_file}"
        restore_attempt_started=1
        output="$(timeout --signal=TERM --kill-after=30s 1140s \
          env APP_ROOT="${APP_ROOT}" \
          BACKUP_INPUT_ALREADY_SNAPSHOTTED=1 \
          BACKUP_IDENTITY_VERIFIED=1 \
          RESTORE_MUTATION_MARKER="${restore_mutation_marker}" \
          EXPECTED_BACKUP_ID="${original_backup_id}" \
          BACKUP_SNAPSHOT_MAX_PAYLOAD_BYTES="${snapshot_payload_limit}" \
          bash "${SCRIPT_DIR}/restore.sh" "${backup_path}" 2>&1)"
        exit_code=$?
      fi
      if [ "${exit_code}" -eq 0 ]; then
        printf '%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ) ${original_backup_id}" > "${restore_receipt}"
        chmod 0600 "${restore_receipt}"
        rm -f -- "${restore_block_file}"
        rm -f -- "${restore_mutation_marker}"
      elif [ "${restore_attempt_started}" = "1" ] && [ ! -f "${restore_mutation_marker}" ]; then
        rm -f -- "${restore_block_file}"
      fi
      ;;
    *)
      output="Unknown backup operation."
      exit_code=2
      ;;
  esac
  set -e

  if [ -n "${claimed_backup_path}" ]; then
    if [ "${operation}" = "verify" ] && [ "${exit_code}" -eq 0 ]; then
      if [ -e "${original_backup_path}" ] || [ -L "${original_backup_path}" ] \
        || ! mv -T -- "${claimed_backup_path}" "${original_backup_path}" 2>/dev/null; then
        output="${output}"$'\n'"Verified upload could not be returned to the import inbox safely."
        exit_code=1
      else
        claimed_backup_path=""
      fi
    fi
    if [ -n "${claimed_backup_path}" ]; then
      secure_path_operation remove-tree "${claimed_backup_path}"
    fi
  fi
  if [ -n "${restore_snapshot_path}" ] && [ -d "${restore_snapshot_path}" ]; then
    secure_path_operation remove-tree "${restore_snapshot_path}"
  fi

  if [ "${exit_code}" -eq 0 ]; then
    state="succeeded"
  else
    state="failed"
  fi

  write_result "${result_file}" "${state}" "${exit_code}" "${output}" "${request_owner}"
  rm -f -- "${running_file}"
}

(
  flock -n 9 || exit 0
  shopt -s nullglob
  for running_file in "${WORK_DIR}"/*.json; do
    recover_abandoned_request "${running_file}"
  done
  for request_file in "${REQUEST_DIR}"/*.pending; do
    process_request "${request_file}"
    # Bound one systemd invocation to one request. PathExistsGlob (with the
    # timer as fallback) immediately schedules the next pending request.
    break
  done
) 9>"${LOCK_FILE}"
