#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ ! -f "${SCRIPT_DIR}/lib/common.sh" ] && [ -f "${DIS_INSTALL_PATH:-/opt/dis}/scripts/lib/common.sh" ]; then
  SCRIPT_DIR="${DIS_INSTALL_PATH:-/opt/dis}/scripts"
fi
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"

require_root
for runtime_file in \
  "${SCRIPT_DIR}/prune-backups.sh" \
  "${SCRIPT_DIR}/lib/backup-retention.sh"; do
  require_file "${runtime_file}"
  root_controlled_bundle_source_is_safe "${runtime_file}" \
    || fail "A backup retention runtime dependency is not root-controlled: ${runtime_file}"
done
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

discard_runtime_config_snapshot() {
  local request_id="$1" snapshot

  [[ "${request_id}" =~ ^[a-f0-9]{32}$ ]] || return 0
  snapshot="${REQUEST_DIR}/${request_id}.config"
  if [ -f "${snapshot}" ] || [ -L "${snapshot}" ]; then
    rm -f -- "${snapshot}"
  fi
}

claim_runtime_config_snapshot() {
  local request_id="$1" source destination metadata

  source="${REQUEST_DIR}/${request_id}.config"
  destination="${WORK_DIR}/${request_id}.backup-config.json"
  [ ! -e "${destination}" ] && [ ! -L "${destination}" ] || return 1
  [ -f "${source}" ] && [ ! -L "${source}" ] || return 1
  metadata="$(stat -c '%U:%a:%h:%s' -- "${source}" 2>/dev/null || true)"
  [[ "${metadata}" =~ ^www-data:600:1:([0-9]+)$ ]] || return 1
  [ "${BASH_REMATCH[1]}" -ge 1 ] && [ "${BASH_REMATCH[1]}" -le 32768 ] || return 1
  mv -T -- "${source}" "${destination}" 2>/dev/null || return 1
  if [ -L "${destination}" ] || [ ! -f "${destination}" ] \
    || [ "$(stat -c '%U:%a:%h' -- "${destination}" 2>/dev/null || true)" != "www-data:600:1" ]; then
    rm -f -- "${destination}" 2>/dev/null || true
    return 1
  fi
  run_cmd chown root:root "${destination}"
  run_cmd chmod 0600 "${destination}"
  if ! root_owned_runtime_file_is_safe "${destination}" 600; then
    rm -f -- "${destination}" 2>/dev/null || true
    return 1
  fi
  printf '%s\n' "${destination}"
}

sweep_orphaned_runtime_config_snapshots() {
  local snapshot request_id metadata modified_at snapshot_size snapshot_age

  for snapshot in "${REQUEST_DIR}"/*.config; do
    request_id="$(basename "${snapshot}" .config)"
    [[ "${request_id}" =~ ^[a-f0-9]{32}$ ]] || continue
    [ -f "${snapshot}" ] && [ ! -L "${snapshot}" ] || continue
    metadata="$(stat -c '%U:%a:%h:%Y:%s' -- "${snapshot}" 2>/dev/null || true)"
    [[ "${metadata}" =~ ^www-data:600:1:([0-9]+):([0-9]+)$ ]] || continue
    modified_at="${BASH_REMATCH[1]}"
    snapshot_size="${BASH_REMATCH[2]}"
    [ "${snapshot_size}" -ge 1 ] && [ "${snapshot_size}" -le 32768 ] || continue
    snapshot_age="$(( $(date +%s) - modified_at ))"
    [ "${snapshot_age}" -ge 1800 ] || continue
    if [ -e "${REQUEST_DIR}/${request_id}.pending" ] \
      || [ -e "${WORK_DIR}/${request_id}.json" ] \
      || [ -e "${WORK_DIR}/${request_id}.probe" ]; then
      continue
    fi
    rm -f -- "${snapshot}"
  done
}

recover_abandoned_probe_claim() {
  local probe_file="$1" request_id request_owner actor_id result_file modified_at

  request_id="$(basename "${probe_file}" .probe)"
  [[ "${request_id}" =~ ^[a-f0-9]{32}$ ]] || return
  [ -f "${probe_file}" ] && [ ! -L "${probe_file}" ] || return
  modified_at="$(stat -c '%Y' -- "${probe_file}" 2>/dev/null || true)"
  [[ "${modified_at}" =~ ^[0-9]+$ ]] || return
  [ "$(( $(date +%s) - modified_at ))" -ge 120 ] || return

  request_owner="$(stat -c '%U' -- "${probe_file}" 2>/dev/null || true)"
  if [ "${request_owner}" = "root" ]; then
    actor_id="$(jq -r '.actor_id // ""' "${probe_file}" 2>/dev/null || true)"
    if [ -z "${actor_id}" ]; then
      request_owner="${DIS_USER}"
    elif [[ "${actor_id^^}" =~ ^[0-9A-HJKMNP-TV-Z]{26}$ ]]; then
      request_owner="www-data"
    fi
  fi
  if [ "${request_owner}" = "www-data" ] || [ "${request_owner}" = "${DIS_USER}" ]; then
    result_file="${REQUEST_DIR}/${request_id}.result"
    if [ ! -e "${result_file}" ] && [ ! -L "${result_file}" ]; then
      write_result "${result_file}" "failed" 124 \
        "An abandoned backup worker probe was recovered without running an operation." \
        "${request_owner}"
    fi
  fi
  rm -f -- "${probe_file}"
}

pending_request_is_probe() {
  local request_file="$1"

  [ -f "${request_file}" ] && [ ! -L "${request_file}" ] \
    && [ "$(stat -c '%s' -- "${request_file}" 2>/dev/null || printf 16385)" -le 16384 ] \
    && jq -e '.operation == "probe"' "${request_file}" >/dev/null 2>&1
}

pending_request_has_required_budget() {
  local request_file="$1" operation expires_at expires_epoch remaining_budget required_budget

  [ -f "${request_file}" ] && [ ! -L "${request_file}" ] \
    && [ "$(stat -c '%s' -- "${request_file}" 2>/dev/null || printf 16385)" -le 16384 ] \
    || return 0
  operation="$(jq -r '.operation // ""' "${request_file}" 2>/dev/null || true)"
  case "${operation}" in
    create|prune)
      required_budget=105
      ;;
    verify)
      required_budget=105
      ;;
    *)
      # Let the normal claimant reject malformed input, and keep asynchronous
      # restore requests on their established independent timeout contract.
      return 0
      ;;
  esac
  expires_at="$(jq -r '.expires_at // ""' "${request_file}" 2>/dev/null || true)"
  expires_epoch="$(date -u -d "${expires_at}" +%s 2>/dev/null || true)"
  [ -n "${expires_epoch}" ] || return 0
  remaining_budget="$(( expires_epoch - $(date +%s) ))"
  # An expired request is safe to claim for a failed result and cleanup. Only
  # leave a live request pending when its caller still owns the cancellation
  # race but there is no longer enough time for a complete operation.
  [ "${remaining_budget}" -gt 0 ] || return 0

  [ "${remaining_budget}" -ge "${required_budget}" ]
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
    discard_runtime_config_snapshot "${request_id}"
    rm -f -- "${WORK_DIR}/${request_id}.backup-config.json" 2>/dev/null || true
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
      discard_runtime_config_snapshot "${request_id}"
      rm -f -- "${WORK_DIR}/${request_id}.backup-config.json" 2>/dev/null || true
      return
    fi
  fi
  if [ "${request_owner}" != "www-data" ] && [ "${request_owner}" != "${DIS_USER}" ]; then
    remove_work_entry "${running_file}" 2>/dev/null || true
    discard_runtime_config_snapshot "${request_id}"
    rm -f -- "${WORK_DIR}/${request_id}.backup-config.json" 2>/dev/null || true
    return
  fi

  write_result "${result_file}" "failed" 124 \
    "Een eerder geclaimde backup request is afgebroken voordat een resultaat werd gepubliceerd. Controleer de DIS backup request service en worker-logs." \
    "${request_owner}"
  rm -f -- "${running_file}"
  discard_runtime_config_snapshot "${request_id}"
  for orphan_path in \
    "${WORK_DIR}/${request_id}.backup" \
    "${WORK_DIR}/${request_id}.restore-input" \
    "${WORK_DIR}/${request_id}.backup-config.json"; do
    if [ -e "${orphan_path}" ] || [ -L "${orphan_path}" ]; then
      remove_work_entry "${orphan_path}" || true
    fi
  done
  logger -p authpriv.err -t dis-security \
    "backup_request_recovered request_id=${request_id} state=failed exit_code=124" 2>/dev/null || true
}

process_request() {
  local request_file="$1" probe_only="${2:-0}" request_id request_owner running_file result_file operation target backup_path actor_id runtime_config_sha256 created_at created_epoch expires_at expires_epoch request_age current_epoch request_lifetime maximum_request_lifetime remaining_budget required_budget operation_timeout_seconds output exit_code state safe_local_backup execution_allowed
  local import_root original_backup_path original_backup_id claimed_backup_path snapshot_payload_limit
  local restore_block_file restore_receipt restore_receipt_time restore_key restore_snapshot_path restore_mutation_marker restore_attempt_started
  local claimed_runtime_config_file=""

  request_id="$(basename "${request_file}" .pending)"
  if [[ ! "${request_id}" =~ ^[a-f0-9]{32}$ ]]; then
    discard_invalid_pending_request "${request_file}"
    return
  fi

  if [ "${probe_only}" = "1" ]; then
    running_file="${WORK_DIR}/${request_id}.probe"
  else
    running_file="${WORK_DIR}/${request_id}.json"
  fi
  result_file="${REQUEST_DIR}/${request_id}.result"
  if ! mv -- "${request_file}" "${running_file}" 2>/dev/null; then
    return
  fi

  if [ -L "${running_file}" ] || [ ! -f "${running_file}" ]; then
    remove_work_entry "${running_file}" 2>/dev/null || true
    discard_runtime_config_snapshot "${request_id}"
    return
  fi
  if [ "$(stat -c '%s' "${running_file}")" -gt 16384 ]; then
    rm -f -- "${running_file}"
    discard_runtime_config_snapshot "${request_id}"
    return
  fi
  request_owner="$(stat -c '%U' "${running_file}")"
  if [ "${request_owner}" != "www-data" ] && [ "${request_owner}" != "${DIS_USER}" ]; then
    rm -f -- "${running_file}"
    discard_runtime_config_snapshot "${request_id}"
    return
  fi
  run_cmd chown root:root "${running_file}"
  run_cmd chmod 0600 "${running_file}"

  if ! jq -e '
    type == "object"
    and (.operation | type == "string" and test("^(create|prune|verify|restore|probe)$"))
    and (.target | type == "string" and test("^(local|samba)$"))
    and (
      ((.operation == "create" or .operation == "prune") and .backup_path == null)
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
      (
        .operation == "restore"
        and (has("expires_at") | not)
      )
      or (
        .operation != "restore"
        and (.expires_at | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
      )
    )
    and (
      .actor_id == null
      or (.actor_id | type == "string" and test("^[0-9A-HJKMNP-TV-Z]{26}$"; "i"))
    )
    and (
      (
        .operation == "prune"
        and (.runtime_config_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
      )
      or (
        .operation != "prune"
        and (has("runtime_config_sha256") | not)
      )
    )
    and ((keys_unsorted - ["operation", "target", "backup_path", "actor_id", "created_at", "expires_at", "runtime_config_sha256"]) | length == 0)
  ' "${running_file}" >/dev/null; then
    write_result "${result_file}" "failed" 2 "Invalid backup request." "${request_owner}"
    rm -f -- "${running_file}"
    discard_runtime_config_snapshot "${request_id}"
    return
  fi

  operation="$(jq -r '.operation // ""' "${running_file}")"
  target="$(jq -r '.target // "local"' "${running_file}")"
  backup_path="$(jq -r '.backup_path // ""' "${running_file}")"
  actor_id="$(jq -r '.actor_id // ""' "${running_file}")"
  runtime_config_sha256="$(jq -r '.runtime_config_sha256 // ""' "${running_file}")"
  created_at="$(jq -r '.created_at' "${running_file}")"
  expires_at="$(jq -r '.expires_at // ""' "${running_file}")"
  if [ "${probe_only}" = "1" ] && [ "${operation}" != "probe" ]; then
    write_result "${result_file}" "failed" 2 \
      "A pre-lock probe scan claimed a non-probe request; it was rejected without execution." "${request_owner}"
    rm -f -- "${running_file}"
    discard_runtime_config_snapshot "${request_id}"
    return
  fi
  if ! created_epoch="$(date -u -d "${created_at}" +%s 2>/dev/null)"; then
    write_result "${result_file}" "failed" 2 "Backup request timestamp is invalid." "${request_owner}"
    rm -f -- "${running_file}"
    discard_runtime_config_snapshot "${request_id}"
    return
  fi
  current_epoch="$(date +%s)"
  request_age="$(( current_epoch - created_epoch ))"
  if [ "${request_age}" -lt -60 ] \
    || { [ "${operation}" = "restore" ] && [ "${request_age}" -gt 900 ]; }; then
    write_result "${result_file}" "failed" 2 "Backup request expired before execution." "${request_owner}"
    rm -f -- "${running_file}"
    discard_runtime_config_snapshot "${request_id}"
    return
  fi

  if [ "${operation}" != "restore" ]; then
    if ! expires_epoch="$(date -u -d "${expires_at}" +%s 2>/dev/null)"; then
      write_result "${result_file}" "failed" 2 "Backup request deadline is invalid." "${request_owner}"
      rm -f -- "${running_file}"
      discard_runtime_config_snapshot "${request_id}"
      return
    fi
    request_lifetime="$(( expires_epoch - created_epoch ))"
    case "${operation}" in
      create|prune) maximum_request_lifetime=1020 ;;
      verify) maximum_request_lifetime=720 ;;
      probe) maximum_request_lifetime=30 ;;
      *) maximum_request_lifetime=0 ;;
    esac
    if [ "${request_lifetime}" -lt 1 ] \
      || [ "${request_lifetime}" -gt "${maximum_request_lifetime}" ]; then
      write_result "${result_file}" "failed" 2 "Backup request deadline exceeds the allowed execution window." "${request_owner}"
      rm -f -- "${running_file}"
      discard_runtime_config_snapshot "${request_id}"
      return
    fi
    case "${operation}" in
      create|prune|verify) required_budget=105 ;;
      probe) required_budget=1 ;;
      *) required_budget=1 ;;
    esac
    remaining_budget="$(( expires_epoch - current_epoch ))"
    if [ "${remaining_budget}" -lt "${required_budget}" ]; then
      write_result "${result_file}" "failed" 124 \
        "Backup request no longer has enough caller-bound execution time and was not started." "${request_owner}"
      rm -f -- "${running_file}"
      discard_runtime_config_snapshot "${request_id}"
      return
    fi
  fi

  if [ "${operation}" = "probe" ] && [ "${request_owner}" != "${DIS_USER}" ]; then
    write_result "${result_file}" "failed" 2 "Backup worker probes may only be submitted by the scheduler." "${request_owner}"
    rm -f -- "${running_file}"
    discard_runtime_config_snapshot "${request_id}"
    return
  fi
  if [ "${request_owner}" = "${DIS_USER}" ] \
    && { { [ "${operation}" != "create" ] && [ "${operation}" != "probe" ]; } || [ -n "${actor_id}" ]; }; then
    write_result "${result_file}" "failed" 2 "The scheduler may only create unclaimed backups or probe the worker." "${request_owner}"
    rm -f -- "${running_file}"
    discard_runtime_config_snapshot "${request_id}"
    return
  fi
  if [ "${request_owner}" = "www-data" ] && [ -z "${actor_id}" ]; then
    write_result "${result_file}" "failed" 2 "Authenticated backup requests require an actor id." "${request_owner}"
    rm -f -- "${running_file}"
    discard_runtime_config_snapshot "${request_id}"
    return
  fi

  if [ "${operation}" = "probe" ]; then
    write_result "${result_file}" "succeeded" 0 "Backup request worker is healthy." "${request_owner}"
    rm -f -- "${running_file}"
    discard_runtime_config_snapshot "${request_id}"
    return
  fi

  if [ "${operation}" = "prune" ]; then
    if ! claimed_runtime_config_file="$(claim_runtime_config_snapshot "${request_id}")"; then
      write_result "${result_file}" "failed" 2 \
        "Request-bound backup runtime configuration could not be claimed safely." "${request_owner}"
      rm -f -- "${running_file}"
      discard_runtime_config_snapshot "${request_id}"
      return
    fi
  else
    discard_runtime_config_snapshot "${request_id}"
  fi

  if [ "${operation}" = "prune" ]; then
    logger -p authpriv.notice -t dis-security \
      "backup_retention_requested request_id=${request_id} claimed_actor_id=${actor_id} target=${target} request_owner=${request_owner}" \
      2>/dev/null || true
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

  safe_local_backup=0
  if [ "${target}" = "local" ]; then
    safe_local_backup=1
  fi

  execution_allowed=1
  operation_timeout_seconds=0
  if [ "${operation}" != "restore" ]; then
    current_epoch="$(date +%s)"
    remaining_budget="$(( expires_epoch - current_epoch ))"
    case "${operation}" in
      create|prune|verify) required_budget=105 ;;
      *) required_budget=1 ;;
    esac
    if [ "${remaining_budget}" -lt "${required_budget}" ]; then
      execution_allowed=0
      output="Backup request no longer has enough caller-bound execution time and was not started."
      exit_code=124
    else
      operation_timeout_seconds="$(( remaining_budget - 45 ))"
      case "${operation}" in
        create|prune)
          [ "${operation_timeout_seconds}" -le 840 ] || operation_timeout_seconds=840
          ;;
        verify)
          [ "${operation_timeout_seconds}" -le 540 ] || operation_timeout_seconds=540
          ;;
      esac
    fi
  fi

  set +e
  if [ "${execution_allowed}" = "0" ]; then
    :
  else
    case "${operation}" in
      create)
      output="$(timeout --signal=TERM --kill-after=30s "${operation_timeout_seconds}s" \
        env DIS_SAFE_LOCAL_BACKUP="${safe_local_backup}" \
        DIS_SAFE_LOCAL_PREUPDATE_BACKUP=0 \
        BACKUP_TARGET="${target}" APP_ROOT="${APP_ROOT}" \
        bash "${SCRIPT_DIR}/backup.sh" 2>&1)"
      exit_code=$?
      ;;
    prune)
      output="$(timeout --signal=TERM --kill-after=30s "${operation_timeout_seconds}s" \
        env DIS_SAFE_LOCAL_BACKUP="${safe_local_backup}" \
        DIS_SAFE_LOCAL_PREUPDATE_BACKUP=0 \
        EXPECTED_BACKUP_RUNTIME_CONFIG_SHA256="${runtime_config_sha256}" \
        BACKUP_RUNTIME_CONFIG_FILE="${claimed_runtime_config_file}" \
        BACKUP_TARGET="${target}" APP_ROOT="${APP_ROOT}" \
        bash "${SCRIPT_DIR}/prune-backups.sh" 2>&1)"
      exit_code=$?
      ;;
    verify)
      output="$(timeout --signal=TERM --kill-after=30s "${operation_timeout_seconds}s" \
        env DIS_SAFE_LOCAL_BACKUP="${safe_local_backup}" \
        DIS_SAFE_LOCAL_PREUPDATE_BACKUP=0 APP_ROOT="${APP_ROOT}" \
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
            env DIS_SAFE_LOCAL_BACKUP="${safe_local_backup}" \
            DIS_SAFE_LOCAL_PREUPDATE_BACKUP=0 APP_ROOT="${APP_ROOT}" \
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
          env DIS_SAFE_LOCAL_BACKUP="${safe_local_backup}" \
          DIS_SAFE_LOCAL_PREUPDATE_BACKUP=0 APP_ROOT="${APP_ROOT}" \
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
  fi
  set -e

  if [ "${operation}" = "prune" ]; then
    if [ "${exit_code}" -eq 0 ]; then
      logger -p authpriv.notice -t dis-security \
        "backup_retention_completed request_id=${request_id} claimed_actor_id=${actor_id} target=${target} state=succeeded exit_code=0" \
        2>/dev/null || true
    else
      logger -p authpriv.err -t dis-security \
        "backup_retention_completed request_id=${request_id} claimed_actor_id=${actor_id} target=${target} state=failed exit_code=${exit_code}" \
        2>/dev/null || true
    fi
  fi

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
  if [ -n "${claimed_runtime_config_file}" ]; then
    rm -f -- "${claimed_runtime_config_file}"
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
  shopt -s nullglob
  # Health probes never require the global mutation lock. Process one first so
  # monitoring remains responsive while an update, backup or restore is active.
  # Probe-only mode revalidates the atomically claimed envelope and can never
  # dispatch a request that changed after this untrusted pre-scan.
  for request_file in "${REQUEST_DIR}"/*.pending; do
    if pending_request_is_probe "${request_file}"; then
      process_request "${request_file}" 1
      exit 0
    fi
  done

  flock -n 9 || exit 0
  for probe_file in "${WORK_DIR}"/*.probe; do
    recover_abandoned_probe_claim "${probe_file}"
  done
  sweep_orphaned_runtime_config_snapshots
  run_cmd install -d -m 0755 -o root -g root /run/lock
  exec {DIS_OPERATION_LOCK_FD}>/run/lock/dis-exclusive-operation.lock
  run_cmd chmod 0600 /run/lock/dis-exclusive-operation.lock
  # Leave real operations pending while another privileged mutation owns the
  # lock. The path unit/timer retries without publishing a false hard failure.
  flock -n "${DIS_OPERATION_LOCK_FD}" || exit 0
  DIS_OPERATION_LOCK_HELD=1
  export DIS_OPERATION_LOCK_HELD DIS_OPERATION_LOCK_FD

  for running_file in "${WORK_DIR}"/*.json; do
    recover_abandoned_request "${running_file}"
  done
  for request_file in "${REQUEST_DIR}"/*.pending; do
    if ! pending_request_has_required_budget "${request_file}"; then
      continue
    fi
    process_request "${request_file}"
    # Bound one systemd invocation to one request. PathExistsGlob (with the
    # timer as fallback) immediately schedules the next pending request.
    break
  done
) 9>"${LOCK_FILE}"
