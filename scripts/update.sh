#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

UPDATE_SYSTEM=1
UPDATE_APP=1
UPDATE_SOURCE=1
CREATE_BACKUP="${CREATE_BACKUP:-1}"
RUN_HEALTHCHECK="${RUN_HEALTHCHECK:-1}"
SYSTEM_UPDATES_AVAILABLE=0
APP_UPDATES_AVAILABLE=0
APP_UPSTREAM=""
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
  --skip-healthcheck     Skip final local health check.
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
require_ubuntu_2604
require_directory "${DIS_INSTALL_PATH}"
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
    "/storage/tmp/" \
    "/storage/generated/" \
    "/webapp/backend/bootstrap/cache/" \
    "/webapp/backend/storage/logs/" \
    "/webapp/backend/storage/app/backup-config.env" \
    "/webapp/frontend/dist/" \
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
    if [ -d "${DIS_INSTALL_PATH}/backup" ]; then
      run_cmd chgrp -R "${DIS_GROUP}" "${DIS_INSTALL_PATH}/backup" || true
      run_cmd find "${DIS_INSTALL_PATH}/backup" -type d -exec chmod 0750 {} + || true
      run_cmd find "${DIS_INSTALL_PATH}/backup" -type f -exec chmod 0640 {} + || true
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
VITE_API_BASE_URL=/api
VITE_REVERB_APP_KEY=$(env_value REVERB_APP_KEY)
VITE_WEBSOCKET_HOST=${host}
VITE_WEBSOCKET_PORT=80
VITE_WEBSOCKET_SCHEME=ws
EOF
  run_cmd chown "${DIS_USER}:${DIS_GROUP}" "${DIS_INSTALL_PATH}/webapp/frontend/.env.production"
  run_cmd chmod 0640 "${DIS_INSTALL_PATH}/webapp/frontend/.env.production"
}

refresh_generated_nginx() {
  local app_url host generated_dir generated_conf
  app_url="$(env_value APP_URL)"
  host="${app_url#http://}"
  host="${host#https://}"
  host="${host%%/*}"

  if [ -z "${host}" ]; then
    fail "APP_URL must be set in ${DIS_INSTALL_PATH}/.env before updating."
  fi

  generated_dir="${DIS_INSTALL_PATH}/storage/generated/nginx"
  generated_conf="${generated_dir}/dis.conf"
  ensure_directory "${generated_dir}" root root 0755
  run_cmd cp "${DIS_INSTALL_PATH}/infrastructure/nginx/dis.conf" "${generated_conf}"
  run_cmd sed -i "s/server_name _;/server_name ${host} _;/" "${generated_conf}"
  run_cmd sed -i "s#unix:/run/php/php[0-9.]*-fpm.sock#unix:/run/php/php${PHP_VERSION}-fpm.sock#" "${generated_conf}"
  printf '%s' "${generated_conf}"
}

install_update_command() {
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
  run_cmd install -m 0755 "${DIS_INSTALL_PATH}/scripts/backup-verify-runner.sh" /usr/local/bin/dis-backup-verify
  run_cmd install -m 0755 "${DIS_INSTALL_PATH}/scripts/backup-restore-runner.sh" /usr/local/bin/dis-backup-restore
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

clear_application_caches() {
  local backend_dir frontend_dir
  backend_dir="${DIS_INSTALL_PATH}/webapp/backend"
  frontend_dir="${DIS_INSTALL_PATH}/webapp/frontend"

  log "Clearing backend and frontend caches"
  if [ -f "${backend_dir}/artisan" ]; then
    run_cmd runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" optimize:clear
  fi

  run_cmd rm -rf \
    "${backend_dir}/bootstrap/cache/"*.php \
    "${backend_dir}/storage/framework/cache/data/"* \
    "${backend_dir}/storage/framework/views/"* \
    "${frontend_dir}/.vite" \
    "${frontend_dir}/.cache" \
    "${frontend_dir}/node_modules/.vite" \
    "${frontend_dir}/node_modules/.cache" \
    "${frontend_dir}/tsconfig.tsbuildinfo" \
    "${frontend_dir}/tsconfig.node.tsbuildinfo" \
    "${frontend_dir}/vite.config.js" \
    "${frontend_dir}/vite.config.d.ts" \
    2>/dev/null || true

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

assert_backend_routes() {
  local backend_dir status
  backend_dir="${DIS_INSTALL_PATH}/webapp/backend"

  if [ ! -f "${backend_dir}/artisan" ]; then
    return
  fi

  log "Checking backup API route"
  status="$(backup_route_http_status)"
  if ! backup_route_status_is_valid "${status}"; then
    log "Backup API route check returned HTTP ${status}; clearing caches once more."
    clear_application_caches
    status="$(backup_route_http_status)"
  fi

  if ! backup_route_status_is_valid "${status}"; then
    fail "Backup API route check failed with HTTP ${status}."
  fi
}

backup_route_http_status() {
  curl -sS --max-time 10 -o /dev/null -w '%{http_code}' "http://127.0.0.1/api/admin/backups" 2>/dev/null || printf '000'
}

backup_route_status_is_valid() {
  case "$1" in
    200|401|403)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

create_pre_update_backup() {
  local backup_output backup_path

  if [ "${CREATE_BACKUP}" != "1" ]; then
    log "Skipping pre-update backup."
    return
  fi

  log "Creating and verifying pre-update backup"
  backup_output="$(APP_ROOT="${DIS_INSTALL_PATH}" bash "${SCRIPT_DIR}/backup.sh")"
  printf '%s\n' "${backup_output}"
  backup_path="$(printf '%s\n' "${backup_output}" | awk '/Backup created at / {print $NF}' | tail -n 1)"
  if [ -z "${backup_path}" ]; then
    fail "Backup path could not be determined."
  fi
  run_cmd bash "${SCRIPT_DIR}/verify-backup.sh" "${backup_path}"
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
    run_cmd git -C "${DIS_INSTALL_PATH}" reset --hard HEAD
  fi

  untracked_before="$(git -C "${DIS_INSTALL_PATH}" status --porcelain --untracked-files=all -- . ':(exclude)backup' | awk '/^\?\?/ {print}' || true)"
  if [ -n "${untracked_before}" ]; then
    log "Local untracked files detected; cleaning production checkout without stash."
  fi

  run_cmd git -C "${DIS_INSTALL_PATH}" clean -ffdx -- \
    . \
    ':(exclude)backup' \
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
    ':(exclude)webapp/backend/storage/app/backup-config.env' \
    >/dev/null 2>&1 || true

  run_cmd git -C "${DIS_INSTALL_PATH}" clean -ffdx -- \
    storage/tmp \
    storage/generated \
    webapp/backend/bootstrap/cache \
    webapp/backend/storage/logs \
    webapp/backend/storage/app/backup-config.env \
    webapp/frontend/dist \
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

if [ "${UPDATE_SYSTEM}" = "1" ]; then
  check_system_updates
fi

if [ "${UPDATE_APP}" = "1" ]; then
  check_app_updates
fi

if [ "${UPDATE_SYSTEM}" = "1" ]; then
  log "Ensuring DIS system dependencies"
  run_cmd apt-get install -y cifs-utils smbclient "php${PHP_VERSION}-gd"
fi

if [ "${SYSTEM_UPDATES_AVAILABLE}" = "0" ] && [ "${APP_UPDATES_AVAILABLE}" = "0" ]; then
  log "No updates available. Nothing to do."
  install_update_command
  install_update_privileges
  self_heal_permissions
  clear_application_caches
  exit 0
fi

create_pre_update_backup

if [ "${UPDATE_SYSTEM}" = "1" ]; then
  if [ "${SYSTEM_UPDATES_AVAILABLE}" = "1" ]; then
    log "Updating Ubuntu packages"
    run_cmd apt-get upgrade -y
    run_cmd apt-get autoremove -y
  else
    log "Skipping Ubuntu package update."
  fi
fi

if [ "${UPDATE_APP}" = "1" ]; then
  if [ "${APP_UPDATES_AVAILABLE}" = "1" ]; then
    if [ "${UPDATE_SOURCE}" = "1" ]; then
      if [ -d "${DIS_INSTALL_PATH}/.git" ]; then
        log "Pulling latest DIS source"
        reset_git_worktree_for_update
        run_cmd git -C "${DIS_INSTALL_PATH}" reset --hard "${APP_UPSTREAM}"
      else
        log "No Git checkout found at ${DIS_INSTALL_PATH}; skipping source update."
      fi
    fi

    write_frontend_env
    nginx_source="$(refresh_generated_nginx)"

    log "Deploying updated DIS application"
    APP_ROOT="${DIS_INSTALL_PATH}" NGINX_SOURCE="${nginx_source}" SKIP_DEPLOY_CACHE_CLEAR=1 bash "${SCRIPT_DIR}/deploy.sh"
    install_update_command
    install_update_privileges
    self_heal_permissions
    clear_application_caches
    assert_backend_routes
  else
    log "Skipping DIS application deploy."
    install_update_command
    install_update_privileges
    self_heal_permissions
    clear_application_caches
    assert_backend_routes
  fi
fi

if [ "${RUN_HEALTHCHECK}" = "1" ]; then
  log "Running final local health check"
  HEALTH_URL="http://127.0.0.1/health" bash "${SCRIPT_DIR}/healthcheck.sh"
fi

log "DIS system and application update completed."
