#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
BACKUP_PATH="${1:-}"
REQUESTED_SAFE_LOCAL_BACKUP="${DIS_SAFE_LOCAL_BACKUP:-0}"
REQUESTED_SAFE_LOCAL_PREUPDATE_BACKUP="${DIS_SAFE_LOCAL_PREUPDATE_BACKUP:-0}"

if [ -z "${BACKUP_PATH}" ]; then
  fail "Usage: restore.sh /opt/dis-data/backup/<timestamp>"
fi

require_root
acquire_dis_operation_lock restore
require_directory "${BACKUP_PATH}"
EXPECTED_BACKUP_ID="${EXPECTED_BACKUP_ID:-$(basename "${BACKUP_PATH}")}"
load_data_path_from_env "${APP_ROOT}/.env"
ensure_data_links "${APP_ROOT}"
require_file "${APP_ROOT}/.env"

set -a
source "${APP_ROOT}/.env"
set +a
DIS_SAFE_LOCAL_BACKUP="${REQUESTED_SAFE_LOCAL_BACKUP}"
DIS_SAFE_LOCAL_PREUPDATE_BACKUP="${REQUESTED_SAFE_LOCAL_PREUPDATE_BACKUP}"
export DIS_SAFE_LOCAL_BACKUP DIS_SAFE_LOCAL_PREUPDATE_BACKUP
load_backup_runtime_config_for_operation "${APP_ROOT}/webapp/backend/storage/app/backup-config.json"
resolve_backup_root "${APP_ROOT}" >/dev/null

ensure_directory "${DIS_DATA_PATH}/backup-request-work" root root 0700
RESTORE_ROOT="$(mktemp -d "${DIS_DATA_PATH}/backup-request-work/restore.XXXXXX")"
chmod 0700 "${RESTORE_ROOT}"
RESTORE_MUTATION_STARTED=0
RESTORE_COMPLETE=0

restore_exit_handler() {
  local status="$?"

  trap - EXIT
  set +e
  if [ "${RESTORE_MUTATION_STARTED}" = "1" ] && [ "${RESTORE_COMPLETE}" != "1" ]; then
    stop_restore_runtime_services
    logger -p authpriv.err -t dis-security \
      "restore_failed backup_id=${EXPECTED_BACKUP_ID} exit_code=${status} maintenance=enabled" 2>/dev/null || true
    log "Restore failed after mutation started; maintenance remains enabled and operational services remain stopped."
  fi
  if [ -d "${RESTORE_ROOT}" ]; then
    secure_path_operation remove-tree "${RESTORE_ROOT}" >/dev/null 2>&1 || true
  fi
  exit "${status}"
}

repair_restored_data_permissions() {
  local runtime_leaf

  ensure_data_layout
  for runtime_leaf in \
    "${DIS_DATA_PATH}/storage/app" \
    "${DIS_DATA_PATH}/storage/logs" \
    "${DIS_DATA_PATH}/storage/tmp" \
    "${DIS_DATA_PATH}/webapp/backend/storage/app" \
    "${DIS_DATA_PATH}/webapp/backend/storage/framework/cache" \
    "${DIS_DATA_PATH}/webapp/backend/storage/framework/sessions" \
    "${DIS_DATA_PATH}/webapp/backend/storage/framework/views" \
    "${DIS_DATA_PATH}/webapp/backend/storage/logs" \
    "${DIS_DATA_PATH}/webapp/backend/storage/tmp" \
    "${DIS_DATA_PATH}/webapp/backend/storage/composer"; do
    repair_managed_tree "${runtime_leaf}" "${DIS_USER}" "${DIS_GROUP}" 0770 0660
    if id www-data >/dev/null 2>&1; then
      secure_path_operation acl-tree "${runtime_leaf}" www-data rwx rw-
    fi
  done

  repair_managed_tree "${DIS_DATA_PATH}/storage/generated" root root 0755 0644
  repair_managed_tree "${DIS_DATA_PATH}/storage/releases" root root 0750 0640
  repair_managed_tree "${DIS_DATA_PATH}/secrets" root root 0750 0600
  repair_speech_data_permissions
  if id www-data >/dev/null 2>&1; then
    # Restored uploads must retain the same PHP-FPM-to-worker boundary as a
    # normal deployment: dis gets access only to its worker-owned app storage.
    secure_path_operation acl-tree "${DIS_DATA_PATH}/webapp/backend/storage/app" "${DIS_USER}" rwx rw-
    run_cmd setfacl -m "u:www-data:--x" \
      "${DIS_DATA_PATH}" \
      "${DIS_DATA_PATH}/webapp" \
      "${DIS_DATA_PATH}/webapp/backend"
    run_cmd setfacl -m "u:www-data:r-x" \
      "${DIS_DATA_PATH}/webapp/backend/storage" \
      "${DIS_DATA_PATH}/webapp/backend/storage/framework" \
      "${DIS_DATA_PATH}/storage"
    if [ -f "${DIS_DATA_PATH}/.env" ]; then
      run_cmd setfacl -m "u:www-data:r--" "${DIS_DATA_PATH}/.env"
    fi
  fi
}

stop_restore_runtime_services() {
  local service

  if systemd_unit_exists dis-backup-request.timer; then
    run_cmd systemctl stop dis-backup-request.timer
  fi
  if systemd_unit_exists dis-backup-request.path; then
    run_cmd systemctl stop dis-backup-request.path
  fi
  if systemd_service_exists dis-speech; then
    run_cmd systemctl stop dis-speech
  fi
  if systemd_service_exists dis-tts-engine; then
    run_cmd systemctl stop dis-tts-engine
  fi
  for service in dis-media dis-queue dis-scheduler dis-websocket dis-frontend dis-incident-enrichment dis-knmi dis-knmi-realtime "${PHP_FPM_SERVICE}"; do
    if systemd_service_exists "${service}"; then
      run_cmd systemctl stop "${service}"
    fi
  done
}

reconcile_speech_runtime_after_restore() {
  local socket_path="/run/dis-tts/engine.sock"
  local deadline

  systemd_service_exists dis-tts-engine \
    || fail "Speech runtime reconciliation requires dis-tts-engine.service."
  if systemd_service_exists dis-speech && systemctl is-active --quiet dis-speech; then
    fail "Speech runtime reconciliation requires the speech queue worker to remain stopped."
  fi

  log "Starting only the local speech engine for post-restore verification"
  run_cmd systemctl start dis-tts-engine
  wait_for_systemd_service_stable dis-tts-engine 30 2 \
    || fail "The speech engine did not become stable for post-restore reconciliation."
  deadline=$((SECONDS + 30))
  while [ ! -S "${socket_path}" ]; do
    if [ "${SECONDS}" -ge "${deadline}" ]; then
      report_systemd_service_failure dis-tts-engine
      fail "The speech engine socket was not ready for post-restore reconciliation."
    fi
    sleep 1
  done

  # This command validates model revision/checksum against the local engine,
  # fails missing generated audio closed, and queues regeneration only when the
  # restored speech runtime is genuinely ready. A non-zero exit aborts restore.
  run_cmd runuser -u "${DIS_USER}" -- /usr/bin/php -d zend.exception_ignore_args=1 \
    "${APP_ROOT}/webapp/backend/artisan" speech:reconcile-runtime
  run_cmd systemctl stop dis-tts-engine
}

trap restore_exit_handler EXIT
if [ "${BACKUP_INPUT_ALREADY_SNAPSHOTTED:-0}" != "1" ]; then
  snapshot_authenticated_backup_input \
    "${BACKUP_PATH}" \
    "${RESTORE_ROOT}/input" \
    "${BACKUP_SNAPSHOT_MAX_PAYLOAD_BYTES:-0}"
  BACKUP_PATH="${RESTORE_ROOT}/input"
fi

BACKUP_INPUT_ALREADY_SNAPSHOTTED=1 \
BACKUP_VERIFY_STORAGE_METADATA_ONLY=1 \
EXPECTED_BACKUP_ID="${EXPECTED_BACKUP_ID}" \
  run_cmd bash "${SCRIPT_DIR}/verify-backup.sh" "${BACKUP_PATH}"

jq -e --arg database "${DB_DATABASE}" '.database == $database' "${BACKUP_PATH}/manifest.json" >/dev/null \
  || fail "Backup database identity does not match the configured database."
BACKUP_DIGEST="$(sha256sum "${BACKUP_PATH}/BACKUP.HMAC" | awk '{print $1}')"
logger -p authpriv.notice -t dis-security \
  "restore_started backup_id=${EXPECTED_BACKUP_ID} digest=${BACKUP_DIGEST}" 2>/dev/null || true

TEMPORARY_PAYLOAD="${RESTORE_ROOT}/payload"
run_cmd install -d -m 0700 -o root -g root "${TEMPORARY_PAYLOAD}"

log "Decrypting backup payload for restore"
extract_encrypted_backup_payload "${BACKUP_PATH}/backup.payload.enc" "${TEMPORARY_PAYLOAD}"
PAYLOAD_ROOT="${TEMPORARY_PAYLOAD}"

require_file "${PAYLOAD_ROOT}/database.dump"
require_file "${PAYLOAD_ROOT}/storage.tar.gz"

log "Preflighting storage archive into protected staging"
RESTORED_DATA="${RESTORE_ROOT}/restored-data"
run_cmd install -d -m 0700 -o root -g root "${RESTORED_DATA}"
extract_storage_backup_archive "${PAYLOAD_ROOT}/storage.tar.gz" "${RESTORED_DATA}"
require_directory "${RESTORED_DATA}/storage"
require_directory "${RESTORED_DATA}/webapp/backend/storage"
require_directory "${RESTORED_DATA}/secrets"

enable_frontend_maintenance
stop_restore_runtime_services
enable_backend_deployment_maintenance "${APP_ROOT}/webapp/backend"
if [ -n "${RESTORE_MUTATION_MARKER:-}" ]; then
  require_root_controlled_parent "${RESTORE_MUTATION_MARKER}"
  [ ! -e "${RESTORE_MUTATION_MARKER}" ] && [ ! -L "${RESTORE_MUTATION_MARKER}" ] \
    || fail "Restore mutation marker already exists."
  : > "${RESTORE_MUTATION_MARKER}"
  chmod 0600 "${RESTORE_MUTATION_MARKER}"
  sync -f "${RESTORE_MUTATION_MARKER}"
fi
RESTORE_MUTATION_STARTED=1

log "Restoring database from ${BACKUP_PATH}"
PGPASSWORD="${DB_PASSWORD}" run_cmd pg_restore \
  --host="${DB_HOST}" \
  --port="${DB_PORT}" \
  --username="${DB_USERNAME}" \
  --dbname="${DB_DATABASE}" \
  --clean \
  --if-exists \
  --exit-on-error \
  --single-transaction \
  "${PAYLOAD_ROOT}/database.dump"

log "Atomically installing restored runtime trees"
replace_managed_tree "${RESTORED_DATA}/storage" "${DIS_DATA_PATH}/storage"
replace_managed_tree "${RESTORED_DATA}/webapp/backend/storage" "${DIS_DATA_PATH}/webapp/backend/storage"
replace_managed_tree "${RESTORED_DATA}/secrets" "${DIS_DATA_PATH}/secrets"

repair_restored_data_permissions
ensure_data_links "${APP_ROOT}"
require_backup_encryption_key >/dev/null

log "Applying current database migrations and reconciling reproducible cache state"
regenerate_backend_package_manifest "${APP_ROOT}/webapp/backend"
run_cmd runuser -u "${DIS_USER}" -- env \
  PGOPTIONS="-c lock_timeout=60s -c statement_timeout=15min" \
  php "${APP_ROOT}/webapp/backend/artisan" migrate --force
reconcile_speech_runtime_after_restore
run_cmd runuser -u "${DIS_USER}" -- php "${APP_ROOT}/webapp/backend/artisan" \
  dis:reconcile-knmi-after-restore
run_cmd runuser -u "${DIS_USER}" -- php "${APP_ROOT}/webapp/backend/artisan" \
  dis:refresh-knmi-precipitation-outlook
log "Revoking restored authentication state"
run_cmd runuser -u "${DIS_USER}" -- php "${APP_ROOT}/webapp/backend/artisan" \
  dis:revoke-all-authentication-state --reason=backup-restore
reconcile_backend_generated_cache_permissions "${APP_ROOT}/webapp/backend"
if id www-data >/dev/null 2>&1; then
  run_cmd runuser -u www-data -- php "${APP_ROOT}/webapp/backend/artisan" dis:self-check
else
  run_cmd runuser -u "${DIS_USER}" -- php "${APP_ROOT}/webapp/backend/artisan" dis:self-check
fi

restart_dis_web_services_for_verification
DIS_SKIP_BACKUP_REQUEST_PROBE=1 start_dis_operational_services
prepare_backend_for_deployment_verification "${APP_ROOT}/webapp/backend"
require_dis_runtime_services
run_cmd env HEALTH_URL="http://127.0.0.1/health" bash "${SCRIPT_DIR}/healthcheck.sh"
complete_deployment_maintenance "${APP_ROOT}/webapp/backend"

RESTORE_COMPLETE=1
logger -p authpriv.notice -t dis-security \
  "restore_succeeded backup_id=${EXPECTED_BACKUP_ID} digest=${BACKUP_DIGEST} auth_state=revoked" 2>/dev/null || true

log "Restore completed"
