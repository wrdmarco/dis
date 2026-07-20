#!/usr/bin/env bash
set -euo pipefail

LIFECYCLE_SOURCE_PATH="${BASH_SOURCE[0]}"
case "${LIFECYCLE_SOURCE_PATH}" in */*) SCRIPT_DIR="${LIFECYCLE_SOURCE_PATH%/*}" ;; *) SCRIPT_DIR=. ;; esac
LIFECYCLE_SOURCE_NAME="${LIFECYCLE_SOURCE_PATH##*/}"
SCRIPT_DIR="$(cd -- "${SCRIPT_DIR}" && pwd -P)"
bootstrap_root_lifecycle_source() {
  local path="$1" parent current="" component metadata mode
  [ -f "${path}" ] && [ ! -L "${path}" ] || return 1
  metadata="$(/usr/bin/stat -c '%u:%a:%h' -- "${path}" 2>/dev/null || true)"; [[ "${metadata}" =~ ^0:([0-7]+):1$ ]] || return 1
  mode="${BASH_REMATCH[1]}"; (( (8#${mode} & 8#022) == 0 )) || return 1
  metadata="$(/usr/bin/stat -c '%u:%a' -- / 2>/dev/null || true)"; [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1; mode="${BASH_REMATCH[1]}"; (( (8#${mode} & 8#022) == 0 )) || return 1
  parent="${path%/*}"; IFS='/' read -r -a bootstrap_components <<< "${parent#/}"
  for component in "${bootstrap_components[@]}"; do [ -n "${component}" ] || continue; current="${current}/${component}"; [ -d "${current}" ] && [ ! -L "${current}" ] || return 1; metadata="$(/usr/bin/stat -c '%u:%a' -- "${current}" 2>/dev/null || true)"; [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1; mode="${BASH_REMATCH[1]}"; (( (8#${mode} & 8#022) == 0 )) || return 1; done
}
if [ "${EUID}" -eq 0 ]; then [ ! -L "${BASH_SOURCE[0]}" ] && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/${LIFECYCLE_SOURCE_NAME}" && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/lib/common.sh" || { printf '[dis:error] Lifecycle sources must be root-owned, single-link and non-writable by group/world.\n' >&2; exit 1; }; fi
unset -f bootstrap_root_lifecycle_source
APP_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd -P)"
source "${SCRIPT_DIR}/lib/common.sh"

UPDATE_SYSTEM=1
UPDATE_APP=1
UPDATE_SOURCE=1
CREATE_BACKUP="${CREATE_BACKUP:-1}"
RUN_HEALTHCHECK="${RUN_HEALTHCHECK:-1}"
SYSTEM_UPDATES_AVAILABLE=0
APP_UPDATES_AVAILABLE=0
APP_UPSTREAM=""
DEPLOYMENT_SERVICES_STOPPED=0
UPDATE_MUTATION_STARTED=0
UPDATE_ESTIMATED_DURATION_SECONDS=""
UPDATE_PHASE="initialization"
DIS_GIT_REMOTE_URL="${DIS_GIT_REMOTE_URL:-https://github.com/wrdmarco/dis.git}"
DIS_GIT_BRANCH="${DIS_GIT_BRANCH:-main}"

usage() {
  cat <<'USAGE'
Usage: sudo update [options]
       sudo /opt/dis/update.sh [options]
       sudo /opt/dis/scripts/update.sh [options]

Options:
  --skip-system          Do not run apt update/upgrade.
  --skip-app             Do not deploy the DIS application.
  --skip-source          Do not run git pull before deploying.
  --skip-backup          Do not create a pre-update backup.
  --skip-healthcheck     Skip the additional final health check; mandatory readiness checks remain.
  -h, --help             Show this help.
USAGE
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --skip-system)
      UPDATE_SYSTEM=0
      shift
      ;;
    --skip-app)
      UPDATE_APP=0
      shift
      ;;
    --skip-source)
      UPDATE_SOURCE=0
      shift
      ;;
    --skip-backup)
      CREATE_BACKUP=0
      shift
      ;;
    --skip-healthcheck)
      RUN_HEALTHCHECK=0
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      fail "Unknown option: $1"
      ;;
  esac
done

require_root
acquire_dis_operation_lock update
require_ubuntu_2604
require_directory "${DIS_INSTALL_PATH}"
load_data_path_from_env "${DIS_INSTALL_PATH}/.env"
ensure_data_links "${DIS_INSTALL_PATH}"
detach_unsafe_cifs_backup_mount /mnt/dis-backup
require_file "${DIS_INSTALL_PATH}/.env"

if [ "${APP_ROOT}" != "${DIS_INSTALL_PATH}" ]; then
  fail "DIS update script must run from ${DIS_INSTALL_PATH}. Current script path is ${APP_ROOT}."
fi

ensure_runtime_git_excludes() {
  local exclude_file pattern

  if [ ! -d "${DIS_INSTALL_PATH}/.git" ]; then
    return
  fi

  exclude_file="${DIS_INSTALL_PATH}/.git/info/exclude"
  run_cmd install -d -m 0755 "$(dirname "${exclude_file}")"
  run_cmd touch "${exclude_file}"
  for pattern in \
    "/backup/" \
    "/maintenance/" \
    "/storage/tmp/" \
    "/storage/generated/" \
    "/webapp/backend/bootstrap/cache/" \
    "/webapp/backend/storage/logs/" \
    "/webapp/backend/storage/app/backup-config.json" \
    "/webapp/frontend/dist/" \
    "/webapp/frontend/.next/" \
    "/webapp/frontend/.vite/" \
    "/webapp/frontend/.cache/" \
    "/webapp/frontend/node_modules/.vite/" \
    "/webapp/frontend/node_modules/.cache/" \
    "/webapp/frontend/*.tsbuildinfo" \
    "/webapp/frontend/vite.config.js" \
    "/webapp/frontend/vite.config.d.ts"; do
    if ! grep -qxF "${pattern}" "${exclude_file}" 2>/dev/null; then
      printf '%s\n' "${pattern}" >> "${exclude_file}"
    fi
  done
}

recover_stashed_backups() {
  local stash_ref untracked_tree restored backup_id backup_file destination

  if [ ! -d "${DIS_INSTALL_PATH}/.git" ]; then
    return
  fi

  restored=0
  while IFS= read -r stash_ref; do
    if ! git -C "${DIS_INSTALL_PATH}" rev-parse --verify "${stash_ref}^3" >/dev/null 2>&1; then
      continue
    fi
    untracked_tree="${stash_ref}^3"

    while IFS= read -r backup_file; do
      backup_id="$(printf '%s' "${backup_file}" | cut -d '/' -f 2)"
      if ! [[ "${backup_id}" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; then
        continue
      fi
      destination="${DIS_INSTALL_PATH}/${backup_file}"
      if [ -f "${destination}" ]; then
        continue
      fi

      run_cmd install -d -m 0750 -o root -g "${DIS_GROUP}" "$(dirname "${destination}")"
      git -C "${DIS_INSTALL_PATH}" show "${untracked_tree}:${backup_file}" > "${destination}"
      run_cmd chgrp "${DIS_GROUP}" "${destination}" || true
      run_cmd chmod 0640 "${destination}" || true
      restored=1
    done < <(git -C "${DIS_INSTALL_PATH}" ls-tree -r --name-only "${untracked_tree}" | grep -E '^backup/[0-9]{8}T[0-9]{6}Z/' || true)
  done < <(git -C "${DIS_INSTALL_PATH}" stash list --format='%gd %s' | awk '/server-local-before-update/ {print $1}')

  if [ "${restored}" = "1" ]; then
    log "Recovered backup files from previous update stash."
    if [ -d "${DIS_DATA_PATH}/backup" ]; then
      run_cmd chgrp -R "${DIS_GROUP}" "${DIS_DATA_PATH}/backup" || true
      run_cmd find "${DIS_DATA_PATH}/backup" -type d -exec chmod 0750 {} + || true
      run_cmd find "${DIS_DATA_PATH}/backup" -type f -exec chmod 0640 {} + || true
    fi
  fi
}

drop_dis_update_stashes() {
  local stash_ref dropped

  if [ ! -d "${DIS_INSTALL_PATH}/.git" ]; then
    return
  fi

  dropped=0
  while IFS= read -r stash_ref; do
    run_cmd git -C "${DIS_INSTALL_PATH}" stash drop "${stash_ref}" >/dev/null 2>&1 || true
    dropped=1
  done < <(git -C "${DIS_INSTALL_PATH}" stash list --format='%gd %s' | awk '/server-local-before-update/ {print $1}')

  if [ "${dropped}" = "1" ]; then
    log "Removed old DIS update stashes after recovery."
  fi
}

env_value() {
  local key="$1"
  local value
  value="$(grep -E "^${key}=" "${DIS_INSTALL_PATH}/.env" | tail -n 1 | cut -d '=' -f 2- || true)"
  value="${value%\"}"
  value="${value#\"}"
  value="${value%\'}"
  value="${value#\'}"
  printf '%s' "${value}"
}

write_frontend_env() {
  local app_url host
  app_url="$(env_value APP_URL)"
  host="${app_url#http://}"
  host="${host#https://}"
  host="${host%%/*}"

  if [ -z "${host}" ]; then
    fail "APP_URL must be set in ${DIS_INSTALL_PATH}/.env before updating."
  fi

  log "Refreshing frontend production environment"
  cat > "${DIS_INSTALL_PATH}/webapp/frontend/.env.production" <<EOF
NEXT_PUBLIC_API_BASE_URL=/api
NEXT_PUBLIC_APP_URL=${app_url}
NEXT_PUBLIC_REVERB_APP_KEY=$(env_value REVERB_APP_KEY)
NEXT_PUBLIC_WEBSOCKET_HOST=${host}
NEXT_PUBLIC_WEBSOCKET_PORT=443
NEXT_PUBLIC_WEBSOCKET_SCHEME=wss
SECURITY_CONTACT=$(env_value SECURITY_CONTACT)
CSP_AERET_FRAME_ORIGINS=$(env_value CSP_AERET_FRAME_ORIGINS)
EOF
  run_cmd chown root:"${DIS_GROUP}" "${DIS_INSTALL_PATH}/webapp/frontend/.env.production"
  run_cmd chmod 0640 "${DIS_INSTALL_PATH}/webapp/frontend/.env.production"
}

refresh_generated_nginx() {
  local app_url host generated_dir generated_conf temporary_conf
  app_url="$(env_value APP_URL)"
  host="${app_url#http://}"
  host="${host#https://}"
  host="${host%%/*}"

  if [ -z "${host}" ]; then
    fail "APP_URL must be set in ${DIS_INSTALL_PATH}/.env before updating."
  fi

  generated_dir="${DIS_DATA_PATH}/storage/generated/nginx"
  generated_conf="${generated_dir}/dis.conf"
  ensure_managed_directory "${generated_dir}" root root 0755
  temporary_conf="$(mktemp "${generated_dir}/.dis.conf.XXXXXX")"
  run_cmd cp "${DIS_INSTALL_PATH}/infrastructure/nginx/dis.conf" "${temporary_conf}"
  run_cmd sed -i "s/server_name _;/server_name ${host} _;/" "${temporary_conf}"
  run_cmd sed -i "s#unix:/run/php/php[0-9.]*-fpm.sock#unix:/run/php/php${PHP_VERSION}-fpm.sock#" "${temporary_conf}"
  run_cmd chown root:root "${temporary_conf}"
  run_cmd chmod 0644 "${temporary_conf}"
  run_cmd mv -fT -- "${temporary_conf}" "${generated_conf}"
  printf '%s' "${generated_conf}"
}

install_update_command() {
  local install_osrm_runtime="${1:-1}"

  [[ "${install_osrm_runtime}" =~ ^[01]$ ]] \
    || fail "install_update_command received an invalid OSRM runtime mode."
  log "Installing global update command"
  if [ -e /usr/local/bin/update ] && ! grep -q "${DIS_INSTALL_PATH}/update.sh" /usr/local/bin/update 2>/dev/null; then
    fail "/usr/local/bin/update already exists and is not managed by DIS."
  fi
  cat > /usr/local/bin/update <<EOF
#!/usr/bin/env bash
exec bash "${DIS_INSTALL_PATH}/update.sh" "\$@"
EOF
  run_cmd chmod 0755 /usr/local/bin/update
  run_cmd install -m 0755 "${DIS_INSTALL_PATH}/scripts/web-update-runner.sh" /usr/local/bin/dis-update-runner
  ensure_directory /var/log/dis root "${DIS_GROUP}" 0750
  if [ ! -e /var/log/dis/system-update-runner.log ]; then
    run_cmd install -m 0640 -o root -g "${DIS_GROUP}" /dev/null /var/log/dis/system-update-runner.log
  fi
  run_cmd chown root:"${DIS_GROUP}" /var/log/dis/system-update-runner.log
  run_cmd chmod 0640 /var/log/dis/system-update-runner.log
  if id www-data >/dev/null 2>&1; then
    run_cmd setfacl -m "u:www-data:rx" /var/log/dis
    run_cmd setfacl -m "u:www-data:r--" /var/log/dis/system-update-runner.log
  fi
  run_cmd install -m 0755 "${DIS_INSTALL_PATH}/scripts/backup-request-worker.sh" /usr/local/bin/dis-backup-request-worker
  run_cmd install -m 0755 "${DIS_INSTALL_PATH}/scripts/snapshot-backup-input.sh" /usr/local/bin/dis-snapshot-backup-input
  if [ "${install_osrm_runtime}" = "1" ]; then
    install_osrm_admin_runtime_bundle "${DIS_INSTALL_PATH}"
  fi
  remove_legacy_backup_entrypoints
  install_php_fpm_privileged_helpers_override
  install_backup_request_systemd_units "${DIS_INSTALL_PATH}"
  install_osrm_admin_layout
  install_osrm_admin_request_systemd_units "${DIS_INSTALL_PATH}"
  run_cmd systemctl daemon-reload
  run_cmd systemctl enable \
    dis-backup-request.path dis-backup-request.timer \
    dis-osrm-admin-request.path dis-osrm-admin-request.timer >/dev/null
}

install_update_privileges() {
  local sudoers_source
  sudoers_source="${DIS_INSTALL_PATH}/infrastructure/sudoers/dis-update"

  if [ ! -f "${sudoers_source}" ]; then
    return
  fi

  log "Installing update sudo privileges"
  run_cmd install -m 0440 "${sudoers_source}" /etc/sudoers.d/dis-update
  run_cmd visudo -cf /etc/sudoers.d/dis-update
}

reconcile_osrm() {
  if [ ! -f "${DIS_INSTALL_PATH}/scripts/osrm.sh" ]; then
    return
  fi

  log "Reconciling optional local OSRM routing"
  APP_ROOT="${DIS_INSTALL_PATH}" bash "${DIS_INSTALL_PATH}/scripts/osrm.sh" reconcile
  APP_ROOT="${DIS_INSTALL_PATH}" bash "${DIS_INSTALL_PATH}/scripts/osrm.sh" publish-status
}

clear_application_caches() {
  local backend_dir frontend_dir
  backend_dir="${DIS_INSTALL_PATH}/webapp/backend"
  frontend_dir="${DIS_INSTALL_PATH}/webapp/frontend"

  log "Clearing backend and frontend caches"
  if [ -f "${backend_dir}/artisan" ]; then
    invalidate_backend_generated_cache "${backend_dir}"
    run_cmd runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" optimize:clear
  fi

  run_cmd rm -rf \
    "${backend_dir}/bootstrap/cache/"*.php \
    "${backend_dir}/storage/framework/cache/data/"* \
    "${backend_dir}/storage/framework/views/"* \
    "${frontend_dir}/.vite" \
    "${frontend_dir}/.next/cache" \
    "${frontend_dir}/.cache" \
    "${frontend_dir}/node_modules/.vite" \
    "${frontend_dir}/node_modules/.cache" \
    "${frontend_dir}/tsconfig.tsbuildinfo" \
    "${frontend_dir}/tsconfig.node.tsbuildinfo" \
    "${frontend_dir}/vite.config.js" \
    "${frontend_dir}/vite.config.d.ts" \
    2>/dev/null || true

  if [ -f "${backend_dir}/artisan" ] && [ -f "${backend_dir}/vendor/autoload.php" ]; then
    regenerate_backend_package_manifest "${backend_dir}"
  fi

  if command -v npm >/dev/null 2>&1; then
    run_cmd npm cache clean --force >/dev/null 2>&1 || true
    run_cmd npm cache verify >/dev/null 2>&1 || true
  fi
}

self_heal_permissions() {
  if [ -f "${SCRIPT_DIR}/self-heal-permissions.sh" ]; then
    APP_ROOT="${DIS_INSTALL_PATH}" bash "${SCRIPT_DIR}/self-heal-permissions.sh"
  fi
}

run_update_permission_self_heal() {
  local phase="$1"

  UPDATE_PHASE="${phase}"
  if ! self_heal_permissions; then
    fail "Permission self-heal failed during update phase: ${phase}. Review the preceding secure-path or filesystem error."
  fi
}

put_webapp_in_production() {
  local backend_dir
  backend_dir="${DIS_INSTALL_PATH}/webapp/backend"

  complete_deployment_maintenance "${backend_dir}"
}

assert_backend_routes() {
  local backend_dir routes
  backend_dir="${DIS_INSTALL_PATH}/webapp/backend"

  if [ ! -f "${backend_dir}/artisan" ]; then
    return
  fi

  log "Checking required backend routes behind maintenance"
  routes="$(runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" route:list --path=api/admin/backups --except-vendor)"
  if ! grep -Fq 'api/admin/backups' <<< "${routes}"; then
    fail "Required backup API route is not registered."
  fi
}

update_exit_handler() {
  local status="$1" recovery_status

  trap - EXIT
  if [ "${status}" -ne 0 ]; then
    printf '[dis:error] Update failed during phase "%s" with exit status %s.\n' "${UPDATE_PHASE}" "${status}" >&2
    if [ "${UPDATE_MUTATION_STARTED}" = "0" ]; then
      set +e
      recover_current_release_after_pre_mutation_failure
      recovery_status="$?"
      set -e
      if [ "${recovery_status}" -eq 0 ]; then
        log "Update failed before package or source mutation; the verified current release is back in production. Correct the failure and rerun update."
      else
        log "Update failed and current-release recovery did not complete; maintenance remains enabled and stopped DIS services remain stopped. Correct the failure and rerun update."
      fi
    else
      log "Update failed after system or application mutation started; maintenance remains enabled and stopped DIS services remain stopped. Correct the failure and rerun update."
    fi
  fi
  exit "${status}"
}

recover_current_release_after_pre_mutation_failure() (
  set -euo pipefail

  if [ -e "${DIS_DATA_PATH}/backup-key-cutover-v2.pending" ]; then
    fail "Backup-key cutover is pending; the current release cannot be reopened before a trusted backup completes."
  fi

  log "Update stopped before mutation; verifying and reopening the current release"
  verify_update_and_open_production
)

verify_update_and_open_production() {
  local backend_dir
  backend_dir="${DIS_INSTALL_PATH}/webapp/backend"

  if [ "${DEPLOYMENT_SERVICES_STOPPED}" = "1" ]; then
    run_cmd nginx -t
    restart_dis_web_services_for_verification
  fi

  prepare_backend_for_deployment_verification "${backend_dir}"
  require_dis_web_services
  assert_backend_routes

  log "Running mandatory local readiness check"
  HEALTH_URL="http://127.0.0.1/health" bash "${SCRIPT_DIR}/healthcheck.sh"

  if [ "${DEPLOYMENT_SERVICES_STOPPED}" = "1" ]; then
    start_dis_operational_services
  fi
  require_dis_runtime_services

  if [ "${RUN_HEALTHCHECK}" = "1" ]; then
    log "Running final local health check"
    HEALTH_URL="http://127.0.0.1/health" bash "${SCRIPT_DIR}/healthcheck.sh"
  fi

  put_webapp_in_production
}

create_pre_update_backup() {
  local backup_output backup_path config_file safe_local_fallback

  if [ "${CREATE_BACKUP}" != "1" ]; then
    log "Skipping pre-update backup."
    return
  fi

  log "Creating and verifying pre-update backup"
  config_file="${DIS_INSTALL_PATH}/webapp/backend/storage/app/backup-config.json"
  safe_local_fallback=0
  if [ -e "${config_file}" ] || [ -L "${config_file}" ]; then
    if ! (load_backup_runtime_config "${config_file}") >/dev/null 2>&1; then
      safe_local_fallback=1
      log "Configured backup runtime data is invalid; using a protected local backup for this update."
    fi
  fi

  if [ "${safe_local_fallback}" = "1" ]; then
    backup_output="$(DIS_SAFE_LOCAL_PREUPDATE_BACKUP=1 APP_ROOT="${DIS_INSTALL_PATH}" bash "${SCRIPT_DIR}/backup.sh")"
  else
    backup_output="$(APP_ROOT="${DIS_INSTALL_PATH}" bash "${SCRIPT_DIR}/backup.sh")"
  fi
  printf '%s\n' "${backup_output}"
  backup_path="$(printf '%s\n' "${backup_output}" | awk '/Backup created at / {print $NF}' | tail -n 1)"
  if [ -z "${backup_path}" ]; then
    fail "Backup path could not be determined."
  fi
  if [ "${safe_local_fallback}" = "1" ]; then
    run_cmd env DIS_SAFE_LOCAL_PREUPDATE_BACKUP=1 APP_ROOT="${DIS_INSTALL_PATH}" \
      bash "${SCRIPT_DIR}/verify-backup.sh" "${backup_path}"
  else
    run_cmd bash "${SCRIPT_DIR}/verify-backup.sh" "${backup_path}"
  fi
}

reset_git_checkout_for_update() {
  local target="$1"

  # Git applies the caller's umask when it materializes changed files. Keep
  # tracked 100644 sources readable by the managed application identities,
  # without changing the restrictive umask used for secrets/runtime data.
  (
    umask 0022
    run_cmd git -C "${DIS_INSTALL_PATH}" reset --hard "${target}"
  )
}

reset_git_worktree_for_update() {
  local status_before untracked_before

  if [ ! -d "${DIS_INSTALL_PATH}/.git" ]; then
    return
  fi

  ensure_runtime_git_excludes

  status_before="$(git -C "${DIS_INSTALL_PATH}" status --porcelain --untracked-files=no -- . ':(exclude)backup' || true)"
  if [ -n "${status_before}" ]; then
    log "Local tracked Git changes detected; resetting production checkout without stash."
    reset_git_checkout_for_update HEAD
  fi

  untracked_before="$(git -C "${DIS_INSTALL_PATH}" status --porcelain --untracked-files=all -- . ':(exclude)backup' | awk '/^\?\?/ {print}' || true)"
  if [ -n "${untracked_before}" ]; then
    log "Local untracked files detected; cleaning production checkout without stash."
  fi

  run_cmd git -C "${DIS_INSTALL_PATH}" clean -ffdx -- \
    . \
    ':(exclude)backup' \
    ':(exclude)backup/**' \
    ':(exclude)maintenance' \
    ':(exclude)maintenance/**' \
    ':(exclude)storage' \
    ':(exclude)storage/**' \
    ':(exclude).env' \
    ':(exclude).env.*' \
    ':(exclude)secrets' \
    ':(exclude)secrets/**' \
    ':(exclude)webapp/backend/storage' \
    ':(exclude)webapp/backend/storage/**' \
    ':(exclude)webapp/backend/vendor' \
    ':(exclude)webapp/backend/vendor/**' \
    ':(exclude)webapp/backend/.env' \
    ':(exclude)webapp/frontend/node_modules' \
    ':(exclude)webapp/frontend/node_modules/**' \
    ':(exclude)webapp/frontend/.env.production' \
    ':(exclude)app/android/local.properties' \
    ':(exclude)app/android/app/build' \
    ':(exclude)app/android/app/build/**' \
    ':(exclude)app/android/build' \
    ':(exclude)app/android/build/**' \
    ':(exclude)webapp/backend/storage/app/backup-config.json' \
    >/dev/null 2>&1 || true

  run_cmd git -C "${DIS_INSTALL_PATH}" clean -ffdx -- \
    storage/tmp \
    storage/generated \
    webapp/backend/bootstrap/cache \
    webapp/backend/storage/logs \
    webapp/backend/storage/app/backup-config.env \
    webapp/frontend/dist-next \
    webapp/frontend/dist-previous \
    webapp/frontend/.next \
    webapp/frontend/.vite \
    webapp/frontend/.cache \
    webapp/frontend/node_modules/.vite \
    webapp/frontend/node_modules/.cache \
    webapp/frontend/tsconfig.tsbuildinfo \
    webapp/frontend/tsconfig.node.tsbuildinfo \
    webapp/frontend/vite.config.js \
    webapp/frontend/vite.config.d.ts \
    >/dev/null 2>&1 || true
}

ensure_git_remote() {
  local origin_url branch target_upstream

  if [ ! -d "${DIS_INSTALL_PATH}/.git" ]; then
    return
  fi

  run_cmd git config --system --add safe.directory "${DIS_INSTALL_PATH}" >/dev/null 2>&1 || true

  origin_url="$(git -C "${DIS_INSTALL_PATH}" remote get-url origin 2>/dev/null || true)"
  if [ -z "${origin_url}" ]; then
    log "Git origin remote missing; configuring ${DIS_GIT_REMOTE_URL}."
    run_cmd git -C "${DIS_INSTALL_PATH}" remote add origin "${DIS_GIT_REMOTE_URL}"
  fi

  if ! git -C "${DIS_INSTALL_PATH}" fetch --prune origin "${DIS_GIT_BRANCH}:refs/remotes/origin/${DIS_GIT_BRANCH}" >/dev/null 2>&1; then
    log "Git origin fetch failed; resetting origin remote to ${DIS_GIT_REMOTE_URL}."
    run_cmd git -C "${DIS_INSTALL_PATH}" remote remove origin >/dev/null 2>&1 || true
    run_cmd git -C "${DIS_INSTALL_PATH}" remote add origin "${DIS_GIT_REMOTE_URL}"
    run_cmd git -C "${DIS_INSTALL_PATH}" fetch --prune origin "${DIS_GIT_BRANCH}:refs/remotes/origin/${DIS_GIT_BRANCH}"
  fi

  branch="$(git -C "${DIS_INSTALL_PATH}" rev-parse --abbrev-ref HEAD)"
  if [ "${branch}" = "HEAD" ]; then
    log "Git checkout is detached; switching to ${DIS_GIT_BRANCH}."
    reset_git_worktree_for_update
    if git -C "${DIS_INSTALL_PATH}" show-ref --verify --quiet "refs/heads/${DIS_GIT_BRANCH}"; then
      run_cmd git -C "${DIS_INSTALL_PATH}" checkout "${DIS_GIT_BRANCH}"
    else
      run_cmd git -C "${DIS_INSTALL_PATH}" checkout -B "${DIS_GIT_BRANCH}" "origin/${DIS_GIT_BRANCH}"
    fi
    branch="${DIS_GIT_BRANCH}"
  fi

  target_upstream="origin/${branch}"
  if ! git -C "${DIS_INSTALL_PATH}" rev-parse --verify "${target_upstream}" >/dev/null 2>&1; then
    target_upstream="origin/${DIS_GIT_BRANCH}"
  fi

  if git -C "${DIS_INSTALL_PATH}" rev-parse --verify "${target_upstream}" >/dev/null 2>&1; then
    run_cmd git -C "${DIS_INSTALL_PATH}" branch --set-upstream-to="${target_upstream}" "${branch}" >/dev/null 2>&1 || true
  fi
}

check_system_updates() {
  local update_count

  log "Checking Ubuntu package updates"
  run_cmd apt-get update
  update_count="$(apt list --upgradable 2>/dev/null | sed '1d' | wc -l | tr -d ' ')"

  if [ "${update_count}" -gt 0 ]; then
    SYSTEM_UPDATES_AVAILABLE=1
    log "Ubuntu package updates available: ${update_count}"
  else
    log "No Ubuntu package updates available."
  fi
}

check_app_updates() {
  local upstream branch counts behind

  if [ "${UPDATE_SOURCE}" != "1" ]; then
    APP_UPDATES_AVAILABLE=1
    log "Source update check skipped; application deploy requested."
    return
  fi

  if [ ! -d "${DIS_INSTALL_PATH}/.git" ]; then
    APP_UPDATES_AVAILABLE=1
    log "No Git checkout found at ${DIS_INSTALL_PATH}; application deploy requested."
    return
  fi

  log "Checking DIS application updates"
  ensure_git_remote
  run_cmd git -C "${DIS_INSTALL_PATH}" fetch --prune

  upstream="$(git -C "${DIS_INSTALL_PATH}" rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || true)"
  if [ -z "${upstream}" ]; then
    branch="$(git -C "${DIS_INSTALL_PATH}" rev-parse --abbrev-ref HEAD)"
    if [ "${branch}" = "HEAD" ]; then
      branch="${DIS_GIT_BRANCH}"
    fi
    upstream="origin/${branch}"
    if ! git -C "${DIS_INSTALL_PATH}" rev-parse --verify "${upstream}" >/dev/null 2>&1; then
      upstream="origin/${DIS_GIT_BRANCH}"
    fi
    if ! git -C "${DIS_INSTALL_PATH}" rev-parse --verify "${upstream}" >/dev/null 2>&1; then
      fail "Git branch at ${DIS_INSTALL_PATH} has no upstream branch configured and ${upstream} was not found."
    fi
    log "No upstream branch configured; using ${upstream}."
  fi
  APP_UPSTREAM="${upstream}"

  counts="$(git -C "${DIS_INSTALL_PATH}" rev-list --left-right --count "HEAD...${upstream}")"
  behind="$(printf '%s' "${counts}" | awk '{print $2}')"

  if [ "${behind}" -gt 0 ]; then
    APP_UPDATES_AVAILABLE=1
    log "DIS application updates available: ${behind} commit(s)."
  else
    log "No DIS application updates available."
  fi
}

ensure_runtime_git_excludes
recover_stashed_backups
drop_dis_update_stashes
run_cmd rm -f -- "${DIS_INSTALL_PATH}/webapp/backend/storage/app/backup-config.env"

log "Ensuring wallboard media runtime dependencies"
ensure_wallboard_media_runtime_dependencies

log "Preflighting the current frontend release before deployment maintenance"
require_dis_frontend_release_artifacts

trap 'update_exit_handler "$?"' EXIT
UPDATE_PHASE="notifying wallboards before update maintenance"
UPDATE_ESTIMATED_DURATION_SECONDS="$(estimate_update_duration_seconds "${UPDATE_SYSTEM}")"
log "Estimated update duration: ${UPDATE_ESTIMATED_DURATION_SECONDS} seconds."
announce_wallboard_maintenance update "${UPDATE_ESTIMATED_DURATION_SECONDS}"
UPDATE_PHASE="enabling frontend maintenance"
enable_frontend_maintenance
# Mark the partial stop before the first systemd mutation. If a broker fails to
# become idle, the pre-mutation recovery path must restart Path/Timer units that
# were already stopped by stop_dis_deployment_services.
DEPLOYMENT_SERVICES_STOPPED=1
UPDATE_PHASE="stopping deployment services"
stop_dis_deployment_services
run_update_permission_self_heal "initial permission self-heal"
UPDATE_PHASE="enabling backend maintenance"
enable_deployment_maintenance "${DIS_INSTALL_PATH}/webapp/backend"

if [ "${UPDATE_SYSTEM}" = "1" ]; then
  UPDATE_PHASE="checking Ubuntu package updates"
  check_system_updates
fi

if [ "${UPDATE_APP}" = "1" ]; then
  UPDATE_PHASE="checking DIS application updates"
  check_app_updates
fi

UPDATE_PHASE="verifying backup trust key"
DIS_BACKUP_KEY_CUTOVER_ALLOWED=1 ensure_backup_encryption_key >/dev/null

if [ "${UPDATE_SYSTEM}" = "1" ]; then
  UPDATE_PHASE="ensuring DIS system dependencies"
  log "Ensuring DIS system dependencies"
  run_cmd apt-get install -y cifs-utils smbclient "php${PHP_VERSION}-gd"
fi

if [ "${SYSTEM_UPDATES_AVAILABLE}" = "0" ] && [ "${APP_UPDATES_AVAILABLE}" = "0" ]; then
  UPDATE_PHASE="refreshing no-update runtime configuration"
  log "No updates available. Nothing to do."
  install_update_command
  install_update_privileges
  run_update_permission_self_heal "no-update permission self-heal before cache cleanup"
  clear_application_caches
  run_update_permission_self_heal "no-update permission self-heal after cache cleanup"
  UPDATE_PHASE="reconciling routing after no-update verification"
  reconcile_osrm
  finalize_backup_key_cutover "${DIS_INSTALL_PATH}"
  UPDATE_PHASE="reopening verified current release"
  verify_update_and_open_production
  trap - EXIT
  log "DIS system and application update completed."
  exit 0
fi

UPDATE_PHASE="creating and verifying pre-update backup"
create_pre_update_backup

if [ "${UPDATE_SYSTEM}" = "1" ]; then
  if [ "${SYSTEM_UPDATES_AVAILABLE}" = "1" ]; then
    UPDATE_MUTATION_STARTED=1
    UPDATE_PHASE="updating Ubuntu packages"
    log "Updating Ubuntu packages"
    run_cmd apt-get upgrade -y
    run_cmd apt-get autoremove -y
  else
    log "Skipping Ubuntu package update."
  fi
fi

if [ "${UPDATE_APP}" = "1" ]; then
  if [ "${APP_UPDATES_AVAILABLE}" = "1" ]; then
    UPDATE_MUTATION_STARTED=1
    UPDATE_PHASE="updating DIS application source"
    if [ "${UPDATE_SOURCE}" = "1" ]; then
      if [ -d "${DIS_INSTALL_PATH}/.git" ]; then
        log "Pulling latest DIS source"
        reset_git_worktree_for_update
        reset_git_checkout_for_update "${APP_UPSTREAM}"
      else
        log "No Git checkout found at ${DIS_INSTALL_PATH}; skipping source update."
      fi
    fi

    write_frontend_env
    nginx_source="$(refresh_generated_nginx)"

    UPDATE_PHASE="deploying updated DIS application"
    log "Deploying updated DIS application"
    APP_ROOT="${DIS_INSTALL_PATH}" \
      NGINX_SOURCE="${nginx_source}" \
      SKIP_DEPLOY_CACHE_CLEAR=1 \
      DIS_DEPLOYMENT_OWNER=update \
      DIS_DEFER_OPERATIONAL_SERVICES=1 \
      bash "${SCRIPT_DIR}/deploy.sh"
    UPDATE_PHASE="stopping services after nested deployment"
    stop_dis_deployment_services
    # The nested deployment has already atomically installed and verified the
    # new OSRM runtime bundle. The parent updater still has its pre-update shell
    # functions in memory and must not reinterpret that new bundle schema.
    install_update_command 0
    install_update_privileges
    run_update_permission_self_heal "post-deploy permission self-heal before cache cleanup"
    clear_application_caches
    run_update_permission_self_heal "post-deploy permission self-heal after cache cleanup"
    assert_backend_routes
  else
    log "Skipping DIS application deploy."
    install_update_command
    install_update_privileges
    run_update_permission_self_heal "existing-release permission self-heal before cache cleanup"
    clear_application_caches
    run_update_permission_self_heal "existing-release permission self-heal after cache cleanup"
    assert_backend_routes
  fi
fi

if [ "${UPDATE_APP}" != "1" ] || [ "${APP_UPDATES_AVAILABLE}" = "0" ]; then
  UPDATE_PHASE="reconciling routing"
  reconcile_osrm
fi

UPDATE_PHASE="finalizing backup-key cutover"
finalize_backup_key_cutover "${DIS_INSTALL_PATH}"
UPDATE_PHASE="verifying and reopening production"
verify_update_and_open_production
trap - EXIT

log "DIS system and application update completed."
