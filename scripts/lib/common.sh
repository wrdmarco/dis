#!/usr/bin/env bash
set -euo pipefail

DIS_INSTALL_PATH="${DIS_INSTALL_PATH:-/opt/dis}"
DIS_DATA_PATH="${DIS_DATA_PATH:-/opt/dis-data}"
DIS_USER="${DIS_USER:-dis}"
DIS_GROUP="${DIS_GROUP:-dis}"
PHP_VERSION="${PHP_VERSION:-8.5}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php${PHP_VERSION}-fpm}"
NGINX_SITE_NAME="${NGINX_SITE_NAME:-dis}"
COMMON_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() {
  printf '[dis] %s\n' "$*"
}

fail() {
  printf '[dis:error] %s\n' "$*" >&2
  exit 1
}

require_root() {
  if [ "${EUID}" -ne 0 ]; then
    fail "This command must be run as root."
  fi
}

require_ubuntu_2604() {
  if [ ! -r /etc/os-release ]; then
    fail "Cannot determine operating system. Ubuntu 26.04 LTS is required."
  fi

  # shellcheck disable=SC1091
  . /etc/os-release

  if [ "${ID:-}" != "ubuntu" ] || [ "${VERSION_ID:-}" != "26.04" ]; then
    fail "Ubuntu 26.04 LTS is required. Detected ${PRETTY_NAME:-unknown OS}."
  fi
}

run_cmd() {
  if [ "${DRY_RUN:-0}" = "1" ]; then
    printf '[dry-run] %s\n' "$*"
  else
    "$@"
  fi
}

require_file() {
  local path="$1"
  if [ ! -f "$path" ]; then
    fail "Required file not found: $path"
  fi
}

require_directory() {
  local path="$1"
  if [ ! -d "$path" ]; then
    fail "Required directory not found: $path"
  fi
}

ensure_directory() {
  local path="$1"
  local owner="${2:-${DIS_USER}}"
  local group="${3:-${DIS_GROUP}}"
  local mode="${4:-0750}"
  run_cmd install -d -m "$mode" -o "$owner" -g "$group" "$path"
}

require_root_controlled_parent() {
  secure_path_operation verify-parent "$1"
}

ensure_managed_directory() {
  secure_path_operation ensure-dir "$1" "$2" "$3" "$4"
}

secure_path_operation() {
  command -v python3 >/dev/null 2>&1 || fail "python3 is required for secure descriptor-based path operations."
  run_cmd python3 -I -S "${COMMON_LIB_DIR}/secure-path.py" "$@"
}

validate_plain_tree() {
  secure_path_operation validate-tree "$1"
}

repair_managed_tree() {
  secure_path_operation repair-tree "$1" "$2" "$3" "$4" "$5"
}

acquire_dis_operation_lock() {
  local operation="${1:-operation}"
  local lock_file="/run/lock/dis-exclusive-operation.lock"
  local inherited_fd_path

  if [ "${DIS_OPERATION_LOCK_HELD:-0}" = "1" ] \
    && [[ "${DIS_OPERATION_LOCK_FD:-}" =~ ^[0-9]+$ ]]; then
    inherited_fd_path="$(readlink -f "/proc/$$/fd/${DIS_OPERATION_LOCK_FD}" 2>/dev/null || true)"
    if [ "${inherited_fd_path}" = "${lock_file}" ]; then
      return 0
    fi
  fi

  run_cmd install -d -m 0755 -o root -g root /run/lock
  exec {DIS_OPERATION_LOCK_FD}>"${lock_file}"
  run_cmd chmod 0600 "${lock_file}"
  if ! flock -n "${DIS_OPERATION_LOCK_FD}"; then
    fail "Another DIS deployment, update, backup or restore operation is active; ${operation} was not started."
  fi
  DIS_OPERATION_LOCK_HELD=1
  export DIS_OPERATION_LOCK_HELD DIS_OPERATION_LOCK_FD
}

install_php_fpm_privileged_helpers_override() {
  local override_dir override_file

  override_dir="/etc/systemd/system/${PHP_FPM_SERVICE}.service.d"
  override_file="${override_dir}/dis-privileged-helpers.conf"

  ensure_directory "${override_dir}" root root 0755
  cat > "${override_file}" <<'EOF'
[Service]
NoNewPrivileges=false
RestrictSUIDSGID=false
EOF
  run_cmd chmod 0644 "${override_file}"
}

write_maintenance_page() {
  local page_path
  page_path="${DIS_INSTALL_PATH}/maintenance/__dis_maintenance.html"

  ensure_directory "$(dirname "${page_path}")" root root 0755
  cat > "${page_path}" <<'HTML'
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="20">
  <title>D.I.S onderhoud</title>
  <style>
    :root {
      color-scheme: dark;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      --bg: #070d16;
      --surface: rgba(13, 20, 31, .88);
      --surface-strong: #101927;
      --border: #223349;
      --blue: #80c7ff;
      --green: #7dd3a7;
      --text: #f8fbff;
      --muted: #aebdd0;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      min-height: 100vh;
      overflow: hidden;
      display: grid;
      place-items: center;
      background:
        radial-gradient(circle at 18% 18%, rgba(128, 199, 255, .16), transparent 30%),
        radial-gradient(circle at 84% 74%, rgba(125, 211, 167, .1), transparent 32%),
        linear-gradient(145deg, #060a10 0%, var(--bg) 46%, #0d1724 100%);
      color: var(--text);
    }

    body::before {
      content: "";
      position: fixed;
      inset: 0;
      pointer-events: none;
      background:
        linear-gradient(90deg, rgba(128, 199, 255, .045) 1px, transparent 1px),
        linear-gradient(180deg, rgba(128, 199, 255, .035) 1px, transparent 1px);
      background-size: 64px 64px;
      mask-image: linear-gradient(90deg, transparent, #000 16%, #000 84%, transparent);
    }

    .sky {
      position: fixed;
      inset: 0;
      pointer-events: none;
      overflow: hidden;
    }

    .drone-lane {
      position: absolute;
      left: -260px;
      top: 18%;
      width: 240px;
      height: 110px;
      animation: fly 13s linear infinite;
      opacity: .92;
      filter: drop-shadow(0 18px 28px rgba(0, 0, 0, .48));
    }

    .drone-lane:nth-child(2) { top: 42%; animation-duration: 17s; animation-delay: -7s; transform: scale(.78); opacity: .7; }
    .drone-lane:nth-child(3) { top: 70%; animation-duration: 19s; animation-delay: -12s; transform: scale(.92); opacity: .74; }

    .rotor-disc {
      transform-origin: center;
      animation: rotor .3s linear infinite;
      opacity: .76;
    }

    main {
      position: relative;
      z-index: 1;
      width: min(760px, calc(100vw - 40px));
      border: 1px solid rgba(128, 199, 255, .24);
      border-radius: 8px;
      background:
        linear-gradient(180deg, rgba(17, 29, 43, .94), rgba(9, 13, 18, .96)),
        var(--surface);
      box-shadow:
        0 28px 96px rgba(0, 0, 0, .52),
        inset 0 1px 0 rgba(255, 255, 255, .04);
      overflow: hidden;
    }

    main::before {
      content: "";
      position: absolute;
      inset: 0 0 auto;
      height: 3px;
      background: linear-gradient(90deg, var(--blue), var(--green), transparent);
    }

    .content {
      display: grid;
      gap: 22px;
      padding: clamp(24px, 5vw, 44px);
    }

    .status {
      display: inline-flex;
      width: fit-content;
      align-items: center;
      gap: 10px;
      color: var(--blue);
      font-size: 12px;
      font-weight: 800;
      text-transform: uppercase;
    }

    .status::before {
      content: "";
      width: 9px;
      height: 9px;
      border-radius: 999px;
      background: var(--green);
      box-shadow: 0 0 0 6px rgba(125, 211, 167, .12);
      animation: pulse 1.6s ease-in-out infinite;
    }

    h1 {
      margin: 0;
      max-width: 12ch;
      font-size: clamp(38px, 7vw, 72px);
      line-height: .94;
      letter-spacing: 0;
    }

    p {
      max-width: 58ch;
      margin: 0;
      color: var(--muted);
      font-size: clamp(16px, 2vw, 18px);
      line-height: 1.55;
    }

    .telemetry {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
    }

    .telemetry div {
      min-height: 76px;
      display: grid;
      gap: 6px;
      align-content: center;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: rgba(7, 12, 18, .62);
      padding: 12px;
    }

    .telemetry span {
      color: #8193a9;
      font-size: 12px;
      font-weight: 750;
      text-transform: uppercase;
    }

    .telemetry strong {
      color: var(--text);
      font-size: 17px;
    }

    @keyframes fly {
      from { translate: -12vw 0; }
      to { translate: calc(100vw + 300px) 0; }
    }

    @keyframes rotor {
      to { rotate: 360deg; }
    }

    @keyframes pulse {
      0%, 100% { transform: scale(1); opacity: .95; }
      50% { transform: scale(1.28); opacity: .62; }
    }

    @media (max-width: 680px) {
      body { place-items: end center; padding: 18px; }
      main { width: 100%; }
      .content { padding: 24px; }
      .telemetry { grid-template-columns: 1fr; }
      .drone-lane:nth-child(2) { top: 30%; }
      .drone-lane:nth-child(3) { display: none; }
    }

    @media (prefers-reduced-motion: reduce) {
      .drone-lane, .rotor-disc, .status::before { animation: none; }
      .drone-lane { translate: 18vw 0; }
      .drone-lane:nth-child(2), .drone-lane:nth-child(3) { display: none; }
    }
  </style>
</head>
<body>
  <div class="sky" aria-hidden="true">
    <svg class="drone-lane" viewBox="0 0 240 110" role="img" aria-label="">
      <defs><linearGradient id="body-a" x1="64" x2="174" y1="35" y2="76" gradientUnits="userSpaceOnUse"><stop stop-color="#e8f4ff"/><stop offset=".42" stop-color="#9bb3c9"/><stop offset="1" stop-color="#26384a"/></linearGradient><radialGradient id="rotor-a" cx="50%" cy="50%" r="50%"><stop stop-color="#f4fbff" stop-opacity=".48"/><stop offset=".58" stop-color="#a9dfff" stop-opacity=".16"/><stop offset="1" stop-color="#a9dfff" stop-opacity="0"/></radialGradient></defs>
      <path d="M118 61 L64 108 H176 L124 61Z" fill="#80c7ff" opacity=".12"/>
      <g class="rotor-disc"><ellipse cx="43" cy="24" rx="37" ry="9" fill="url(#rotor-a)"/><ellipse cx="197" cy="24" rx="37" ry="9" fill="url(#rotor-a)"/><ellipse cx="43" cy="80" rx="37" ry="9" fill="url(#rotor-a)"/><ellipse cx="197" cy="80" rx="37" ry="9" fill="url(#rotor-a)"/></g>
      <g fill="none" stroke="#8fb0c9" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"><path d="M93 50 L52 27"/><path d="M147 50 L188 27"/><path d="M92 61 L52 78"/><path d="M148 61 L188 78"/><path d="M88 86 C103 96 137 96 152 86"/></g>
      <g fill="#162131" stroke="#d6edf8" stroke-width="2.5"><circle cx="43" cy="24" r="10"/><circle cx="197" cy="24" r="10"/><circle cx="43" cy="80" r="10"/><circle cx="197" cy="80" r="10"/></g>
      <path d="M88 48 C92 33 106 26 120 26 C134 26 148 33 152 48 L158 65 C148 75 133 80 120 80 C107 80 92 75 82 65Z" fill="url(#body-a)" stroke="#e8f4ff" stroke-width="2.5"/>
      <path d="M98 48 H142" stroke="#334a5f" stroke-width="3" stroke-linecap="round" opacity=".7"/><rect x="107" y="70" width="26" height="17" rx="7" fill="#111a26" stroke="#d6edf8" stroke-width="2"/><circle cx="120" cy="79" r="5" fill="#0c1119" stroke="#80c7ff" stroke-width="2"/><circle cx="89" cy="60" r="3" fill="#ef4444"/><circle cx="151" cy="60" r="3" fill="#7dd3a7"/>
      <g fill="none" stroke="#eef8ff" stroke-width="2" stroke-linecap="round" opacity=".82"><path d="M15 24 H71"/><path d="M169 24 H225"/><path d="M15 80 H71"/><path d="M169 80 H225"/></g>
    </svg>
    <svg class="drone-lane" viewBox="0 0 240 110" role="img" aria-label="">
      <defs><linearGradient id="body-b" x1="64" x2="174" y1="35" y2="76" gradientUnits="userSpaceOnUse"><stop stop-color="#e8f4ff"/><stop offset=".42" stop-color="#9bb3c9"/><stop offset="1" stop-color="#26384a"/></linearGradient><radialGradient id="rotor-b" cx="50%" cy="50%" r="50%"><stop stop-color="#f4fbff" stop-opacity=".48"/><stop offset=".58" stop-color="#a9dfff" stop-opacity=".16"/><stop offset="1" stop-color="#a9dfff" stop-opacity="0"/></radialGradient></defs>
      <path d="M118 61 L64 108 H176 L124 61Z" fill="#80c7ff" opacity=".12"/>
      <g class="rotor-disc"><ellipse cx="43" cy="24" rx="37" ry="9" fill="url(#rotor-b)"/><ellipse cx="197" cy="24" rx="37" ry="9" fill="url(#rotor-b)"/><ellipse cx="43" cy="80" rx="37" ry="9" fill="url(#rotor-b)"/><ellipse cx="197" cy="80" rx="37" ry="9" fill="url(#rotor-b)"/></g>
      <g fill="none" stroke="#8fb0c9" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"><path d="M93 50 L52 27"/><path d="M147 50 L188 27"/><path d="M92 61 L52 78"/><path d="M148 61 L188 78"/><path d="M88 86 C103 96 137 96 152 86"/></g>
      <g fill="#162131" stroke="#d6edf8" stroke-width="2.5"><circle cx="43" cy="24" r="10"/><circle cx="197" cy="24" r="10"/><circle cx="43" cy="80" r="10"/><circle cx="197" cy="80" r="10"/></g>
      <path d="M88 48 C92 33 106 26 120 26 C134 26 148 33 152 48 L158 65 C148 75 133 80 120 80 C107 80 92 75 82 65Z" fill="url(#body-b)" stroke="#e8f4ff" stroke-width="2.5"/>
      <path d="M98 48 H142" stroke="#334a5f" stroke-width="3" stroke-linecap="round" opacity=".7"/><rect x="107" y="70" width="26" height="17" rx="7" fill="#111a26" stroke="#d6edf8" stroke-width="2"/><circle cx="120" cy="79" r="5" fill="#0c1119" stroke="#80c7ff" stroke-width="2"/><circle cx="89" cy="60" r="3" fill="#ef4444"/><circle cx="151" cy="60" r="3" fill="#7dd3a7"/>
      <g fill="none" stroke="#eef8ff" stroke-width="2" stroke-linecap="round" opacity=".82"><path d="M15 24 H71"/><path d="M169 24 H225"/><path d="M15 80 H71"/><path d="M169 80 H225"/></g>
    </svg>
    <svg class="drone-lane" viewBox="0 0 240 110" role="img" aria-label="">
      <defs><linearGradient id="body-c" x1="64" x2="174" y1="35" y2="76" gradientUnits="userSpaceOnUse"><stop stop-color="#e8f4ff"/><stop offset=".42" stop-color="#9bb3c9"/><stop offset="1" stop-color="#26384a"/></linearGradient><radialGradient id="rotor-c" cx="50%" cy="50%" r="50%"><stop stop-color="#f4fbff" stop-opacity=".48"/><stop offset=".58" stop-color="#a9dfff" stop-opacity=".16"/><stop offset="1" stop-color="#a9dfff" stop-opacity="0"/></radialGradient></defs>
      <path d="M118 61 L64 108 H176 L124 61Z" fill="#80c7ff" opacity=".12"/>
      <g class="rotor-disc"><ellipse cx="43" cy="24" rx="37" ry="9" fill="url(#rotor-c)"/><ellipse cx="197" cy="24" rx="37" ry="9" fill="url(#rotor-c)"/><ellipse cx="43" cy="80" rx="37" ry="9" fill="url(#rotor-c)"/><ellipse cx="197" cy="80" rx="37" ry="9" fill="url(#rotor-c)"/></g>
      <g fill="none" stroke="#8fb0c9" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"><path d="M93 50 L52 27"/><path d="M147 50 L188 27"/><path d="M92 61 L52 78"/><path d="M148 61 L188 78"/><path d="M88 86 C103 96 137 96 152 86"/></g>
      <g fill="#162131" stroke="#d6edf8" stroke-width="2.5"><circle cx="43" cy="24" r="10"/><circle cx="197" cy="24" r="10"/><circle cx="43" cy="80" r="10"/><circle cx="197" cy="80" r="10"/></g>
      <path d="M88 48 C92 33 106 26 120 26 C134 26 148 33 152 48 L158 65 C148 75 133 80 120 80 C107 80 92 75 82 65Z" fill="url(#body-c)" stroke="#e8f4ff" stroke-width="2.5"/>
      <path d="M98 48 H142" stroke="#334a5f" stroke-width="3" stroke-linecap="round" opacity=".7"/><rect x="107" y="70" width="26" height="17" rx="7" fill="#111a26" stroke="#d6edf8" stroke-width="2"/><circle cx="120" cy="79" r="5" fill="#0c1119" stroke="#80c7ff" stroke-width="2"/><circle cx="89" cy="60" r="3" fill="#ef4444"/><circle cx="151" cy="60" r="3" fill="#7dd3a7"/>
      <g fill="none" stroke="#eef8ff" stroke-width="2" stroke-linecap="round" opacity=".82"><path d="M15 24 H71"/><path d="M169 24 H225"/><path d="M15 80 H71"/><path d="M169 80 H225"/></g>
    </svg>
  </div>
  <main>
    <div class="content">
      <span class="status">Onderhoud actief</span>
      <h1>Drone Inzet Systeem wordt bijgewerkt</h1>
      <p>De operationele omgeving staat tijdelijk in onderhoud. De app en webconsole komen automatisch terug zodra de controle is afgerond.</p>
      <section class="telemetry" aria-label="Onderhoudsstatus">
        <div><span>Status</span><strong>Update in uitvoering</strong></div>
        <div><span>Monitoring</span><strong>Automatisch verversen</strong></div>
        <div><span>Terugkeer</span><strong>Na afronding update</strong></div>
      </section>
    </div>
  </main>
</body>
</html>
HTML
  run_cmd chmod 0644 "${page_path}"
}

enable_frontend_maintenance() {
  write_maintenance_page
  run_cmd touch "${DIS_INSTALL_PATH}/maintenance/frontend.lock"
}

disable_frontend_maintenance() {
  run_cmd rm -f "${DIS_INSTALL_PATH}/maintenance/frontend.lock"
}

systemd_unit_exists() {
  local unit="$1"
  systemctl cat "${unit}" >/dev/null 2>&1
}

systemd_service_exists() {
  local service="$1"
  systemd_unit_exists "${service}.service"
}

enable_deployment_maintenance() {
  local backend_dir="${1:-${DIS_INSTALL_PATH}/webapp/backend}"

  enable_frontend_maintenance
  if [ -f "${backend_dir}/artisan" ] && [ -f "${backend_dir}/vendor/autoload.php" ]; then
    log "Putting the backend in maintenance mode"
    run_cmd php "${backend_dir}/artisan" down --render="errors::503"
  fi
}

prepare_backend_for_deployment_verification() {
  local backend_dir="${1:-${DIS_INSTALL_PATH}/webapp/backend}"

  if [ -f "${backend_dir}/artisan" ] && [ -f "${backend_dir}/vendor/autoload.php" ]; then
    log "Bringing the backend up behind the deployment maintenance lock"
    run_cmd php "${backend_dir}/artisan" up
  fi
}

complete_deployment_maintenance() {
  local backend_dir="${1:-${DIS_INSTALL_PATH}/webapp/backend}"

  # Keep Nginx fail-closed until Laravel has successfully left maintenance mode.
  prepare_backend_for_deployment_verification "${backend_dir}"
  disable_frontend_maintenance
}

stop_dis_deployment_services() {
  local service

  log "Stopping DIS workers, realtime and frontend services for deployment"
  if systemd_unit_exists dis-backup-request.path; then
    run_cmd systemctl stop dis-backup-request.path
  fi
  if systemd_service_exists dis-backup-request; then
    run_cmd systemctl stop dis-backup-request
  fi
  for service in dis-queue dis-scheduler dis-websocket dis-frontend "${PHP_FPM_SERVICE}"; do
    if systemd_service_exists "${service}"; then
      run_cmd systemctl stop "${service}"
    fi
  done
}

restart_dis_web_services_for_verification() {
  local service

  # Nginx must load the maintenance-aware configuration before Laravel is
  # brought back up for readiness checks.
  for service in nginx "${PHP_FPM_SERVICE}" dis-frontend; do
    if systemd_service_exists "${service}"; then
      run_cmd systemctl restart "${service}"
    fi
  done
}

start_dis_operational_services() {
  local service

  log "Starting DIS workers and realtime services"
  for service in dis-queue dis-scheduler dis-websocket; do
    if systemd_service_exists "${service}"; then
      run_cmd systemctl start "${service}"
    fi
  done
  if systemd_unit_exists dis-backup-request.path; then
    run_cmd systemctl start dis-backup-request.path
  fi
}

require_dis_web_services() {
  local service

  for service in nginx "${PHP_FPM_SERVICE}" dis-frontend; do
    if ! systemd_service_exists "${service}"; then
      fail "Required systemd service is not installed: ${service}.service"
    fi
    if ! systemctl is-active --quiet "${service}"; then
      fail "Required systemd service is not active: ${service}.service"
    fi
  done
}

require_dis_runtime_services() {
  local service

  for service in nginx "${PHP_FPM_SERVICE}" dis-frontend dis-queue dis-scheduler dis-websocket; do
    if ! systemd_service_exists "${service}"; then
      fail "Required systemd service is not installed: ${service}.service"
    fi
    if ! systemctl is-active --quiet "${service}"; then
      fail "Required systemd service is not active: ${service}.service"
    fi
  done
  if ! systemd_unit_exists dis-backup-request.path; then
    fail "Required systemd unit is not installed: dis-backup-request.path"
  fi
  if ! systemctl is-active --quiet dis-backup-request.path; then
    fail "Required systemd unit is not active: dis-backup-request.path"
  fi
}

load_data_path_from_env() {
  local env_file="$1"
  local configured_path

  if [ ! -f "${env_file}" ]; then
    return
  fi

  configured_path="$(grep -E '^DIS_DATA_PATH=' "${env_file}" | tail -n 1 | cut -d '=' -f 2- || true)"
  configured_path="${configured_path%\"}"
  configured_path="${configured_path#\"}"
  configured_path="${configured_path%\'}"
  configured_path="${configured_path#\'}"

  if [ -n "${configured_path}" ]; then
    if [[ ! "${configured_path}" =~ ^/[A-Za-z0-9._/-]+$ ]] \
      || [[ "/${configured_path}/" == *"/../"* ]] \
      || [[ "/${configured_path}/" == *"/./"* ]] \
      || [[ "${configured_path}" == *"//"* ]]; then
      fail "DIS_DATA_PATH must be an absolute path without whitespace or traversal segments."
    fi
    DIS_DATA_PATH="${configured_path}"
    export DIS_DATA_PATH
  fi
}

install_backup_request_systemd_units() {
  local app_root="$1" escaped_data_path temporary_service temporary_path

  escaped_data_path="$(printf '%s' "${DIS_DATA_PATH}" | sed 's/[&|\\]/\\&/g')"
  temporary_service="$(mktemp /run/dis-backup-request.service.XXXXXX)"
  temporary_path="$(mktemp /run/dis-backup-request.path.XXXXXX)"
  sed "s|@DIS_DATA_PATH@|${escaped_data_path}|g" \
    "${app_root}/infrastructure/systemd/dis-backup-request.service" > "${temporary_service}"
  sed "s|@DIS_DATA_PATH@|${escaped_data_path}|g" \
    "${app_root}/infrastructure/systemd/dis-backup-request.path" > "${temporary_path}"
  run_cmd install -m 0644 "${temporary_service}" /etc/systemd/system/dis-backup-request.service
  run_cmd install -m 0644 "${temporary_path}" /etc/systemd/system/dis-backup-request.path
  run_cmd rm -f -- "${temporary_service}" "${temporary_path}"
}

load_backup_runtime_config() {
  local config_file="$1" key value
  local -a allowed_keys=(
    BACKUP_TARGET
    BACKUP_ROOT
    BACKUP_RETENTION_COUNT
    BACKUP_ENCRYPTION_KEY_FILE
    BACKUP_SAMBA_SHARE
    BACKUP_SAMBA_MOUNT
    BACKUP_SAMBA_USERNAME
    BACKUP_SAMBA_PASSWORD
    BACKUP_SAMBA_DOMAIN
    BACKUP_SAMBA_VERSION
  )

  if [ ! -e "${config_file}" ]; then
    return 0
  fi
  if [ -L "${config_file}" ] || [ ! -f "${config_file}" ]; then
    fail "Backup runtime configuration must be a regular file."
  fi
  if [ "$(stat -c '%s' "${config_file}")" -gt 32768 ]; then
    fail "Backup runtime configuration is too large."
  fi
  if ! command -v jq >/dev/null 2>&1; then
    fail "jq is required to parse backup runtime configuration safely."
  fi

  local allowed_json
  allowed_json="$(printf '%s\n' "${allowed_keys[@]}" | jq -Rsc 'split("\n")[:-1]')"
  jq -e --argjson allowed "${allowed_json}" '
    type == "object"
    and ((keys_unsorted - $allowed) | length == 0)
    and all(.[]; type == "string" and length <= 4096 and test("^[^\\u0000-\\u001F\\u007F]*$"))
  ' "${config_file}" >/dev/null || fail "Backup runtime configuration is invalid."

  while IFS= read -r -d '' key && IFS= read -r -d '' value; do
    case "${key}" in
      BACKUP_TARGET)
        [[ "${value}" =~ ^(local|samba)$ ]] || fail "Invalid BACKUP_TARGET."
        ;;
      BACKUP_ROOT)
        [ "${value}" = "${DIS_DATA_PATH}/backup" ] || fail "Invalid BACKUP_ROOT."
        ;;
      BACKUP_RETENTION_COUNT)
        [[ "${value}" =~ ^[0-9]{1,3}$ ]] && [ "${value}" -le 365 ] || fail "Invalid BACKUP_RETENTION_COUNT."
        ;;
      BACKUP_ENCRYPTION_KEY_FILE)
        [ "${value}" = "${DIS_DATA_PATH}/secrets/backup-encryption.key" ] || fail "Invalid BACKUP_ENCRYPTION_KEY_FILE."
        ;;
      BACKUP_SAMBA_MOUNT)
        [ "${value}" = "/mnt/dis-backup" ] || fail "Invalid BACKUP_SAMBA_MOUNT."
        ;;
      BACKUP_SAMBA_SHARE)
        if [ -n "${value}" ] && [[ ! "${value}" =~ ^//[A-Za-z0-9._:-]+/[^/\\]+$ ]]; then
          fail "Invalid BACKUP_SAMBA_SHARE."
        fi
        ;;
      BACKUP_SAMBA_VERSION)
        [ "${value}" = "3.1.1" ] || fail "BACKUP_SAMBA_VERSION must be 3.1.1."
        ;;
      BACKUP_SAMBA_USERNAME|BACKUP_SAMBA_PASSWORD|BACKUP_SAMBA_DOMAIN)
        ;;
      *)
        fail "Unsupported backup runtime configuration key."
        ;;
    esac

    printf -v "${key}" '%s' "${value}"
    export "${key}"
  done < <(jq -j 'to_entries[] | .key, "\u0000", .value, "\u0000"' "${config_file}")
}

ensure_data_layout() {
  ensure_managed_directory "${DIS_DATA_PATH}" root "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/backup" root "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/backup-imports" root root 1730
  ensure_managed_directory "${DIS_DATA_PATH}/backup-requests" root root 1730
  ensure_managed_directory "${DIS_DATA_PATH}/backup-request-work" root root 0700
  ensure_managed_directory "${DIS_DATA_PATH}/playwright-browsers" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_managed_directory "${DIS_DATA_PATH}/secrets" root "${DIS_GROUP}" 0750

  # Runtime users may write only explicit leaves. Every parent stays root-owned
  # and non-writable so a leaf cannot be replaced with a symlink before a root
  # deployment, backup, or restore operation.
  ensure_managed_directory "${DIS_DATA_PATH}/storage" root "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/storage/app" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_managed_directory "${DIS_DATA_PATH}/storage/generated" root root 0755
  ensure_managed_directory "${DIS_DATA_PATH}/storage/logs" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_managed_directory "${DIS_DATA_PATH}/storage/releases" root root 0750
  ensure_managed_directory "${DIS_DATA_PATH}/storage/tmp" "${DIS_USER}" "${DIS_GROUP}" 0770

  ensure_managed_directory "${DIS_DATA_PATH}/webapp" root root 0755
  ensure_managed_directory "${DIS_DATA_PATH}/webapp/backend" root root 0755
  ensure_managed_directory "${DIS_DATA_PATH}/webapp/backend/storage" root "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/webapp/backend/storage/app" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_managed_directory "${DIS_DATA_PATH}/webapp/backend/storage/framework" root "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/webapp/backend/storage/framework/cache" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_managed_directory "${DIS_DATA_PATH}/webapp/backend/storage/framework/sessions" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_managed_directory "${DIS_DATA_PATH}/webapp/backend/storage/framework/views" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_managed_directory "${DIS_DATA_PATH}/webapp/backend/storage/logs" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_managed_directory "${DIS_DATA_PATH}/webapp/backend/storage/tmp" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_managed_directory "${DIS_DATA_PATH}/webapp/backend/storage/composer" "${DIS_USER}" "${DIS_GROUP}" 0770
}

migrate_path_to_data() {
  local source="$1"
  local destination="$2"
  local staging previous

  if [ -L "${source}" ]; then
    return
  fi

  if [ -d "${source}" ]; then
    validate_plain_tree "${source}"
    if [ "${DRY_RUN:-0}" = "1" ]; then
      log "Would securely migrate ${source} to ${destination}"
      return
    fi

    if [ -d "${destination}" ]; then
      validate_plain_tree "${destination}"
      staging="$(mktemp -d "${destination}.migration.XXXXXX")"
      previous="$(mktemp -d "${destination}.previous.XXXXXX")"
      rmdir -- "${previous}"
      chmod 0700 "${staging}"

      # Both copies target a root-only staging directory. Validate after each
      # copy so a runtime race can at worst abort migration, never redirect a
      # privileged write through a newly planted link.
      secure_path_operation copy-tree "${destination}" "${staging}"
      validate_plain_tree "${staging}"
      secure_path_operation copy-tree "${source}" "${staging}"
      validate_plain_tree "${staging}"

      mv -T -- "${destination}" "${previous}"
      if ! mv -T -- "${staging}" "${destination}"; then
        mv -T -- "${previous}" "${destination}" 2>/dev/null || true
        rm -rf -- "${staging}"
        fail "Could not atomically install migrated runtime data."
      fi
      secure_path_operation remove-tree "${previous}"
      secure_path_operation remove-tree "${source}"
    else
      run_cmd mv "${source}" "${destination}"
    fi
  elif [ -f "${source}" ]; then
    if [ -f "${destination}" ]; then
      run_cmd rm -f "${source}"
    else
      run_cmd mv "${source}" "${destination}"
    fi
  fi
}

link_data_path() {
  local source="$1"
  local destination="$2"

  if [ -L "${source}" ] && [ "$(readlink "${source}")" = "${destination}" ]; then
    return
  fi

  if [ -e "${source}" ] && [ ! -L "${source}" ]; then
    migrate_path_to_data "${source}" "${destination}"
  fi

  if [ -d "${source}" ] && [ ! -L "${source}" ]; then
    fail "Could not replace ${source} with data symlink. Check ${destination} and remove the old directory after migration."
  fi

  run_cmd ln -sfn "${destination}" "${source}"
}

ensure_data_links() {
  local app_root="${1:-${DIS_INSTALL_PATH}}"

  ensure_data_layout
  link_data_path "${app_root}/backup" "${DIS_DATA_PATH}/backup"
  link_data_path "${app_root}/secrets" "${DIS_DATA_PATH}/secrets"
  link_data_path "${app_root}/storage" "${DIS_DATA_PATH}/storage"

  if [ -d "${app_root}/webapp/backend" ]; then
    link_data_path "${app_root}/webapp/backend/storage" "${DIS_DATA_PATH}/webapp/backend/storage"
  fi

  if [ -f "${DIS_DATA_PATH}/.env" ] || [ -f "${app_root}/.env" ]; then
    link_data_path "${app_root}/.env" "${DIS_DATA_PATH}/.env"
  fi
}

backup_encryption_key_file() {
  printf '%s\n' "${BACKUP_ENCRYPTION_KEY_FILE:-${DIS_DATA_PATH}/secrets/backup-encryption.key}"
}

backup_encryption_key_marker_file() {
  printf '%s.generation-v2\n' "$(backup_encryption_key_file)"
}

backup_key_cutover_pending_file() {
  printf '%s\n' "${DIS_DATA_PATH}/backup-key-cutover-v2.pending"
}

backup_key_generation_is_current() {
  local key_file marker_file expected_fingerprint actual_fingerprint marker_version marker_created_at

  key_file="$(backup_encryption_key_file)"
  marker_file="$(backup_encryption_key_marker_file)"
  [ "${key_file}" = "${DIS_DATA_PATH}/secrets/backup-encryption.key" ] || return 1
  [ -f "${key_file}" ] && [ ! -L "${key_file}" ] || return 1
  [ -f "${marker_file}" ] && [ ! -L "${marker_file}" ] || return 1
  [ "$(stat -c '%u:%a:%h' -- "${key_file}")" = "0:600:1" ] || return 1
  [ "$(stat -c '%u:%a:%h' -- "${marker_file}")" = "0:600:1" ] || return 1
  [ "$(stat -c '%s' -- "${key_file}")" -ge 32 ] \
    && [ "$(stat -c '%s' -- "${key_file}")" -le 4096 ] || return 1
  [ "$(wc -l < "${marker_file}")" -eq 3 ] || return 1

  marker_version="$(sed -n 's/^version=//p' "${marker_file}")"
  expected_fingerprint="$(sed -n 's/^fingerprint_sha256=//p' "${marker_file}")"
  marker_created_at="$(sed -n 's/^created_at=//p' "${marker_file}")"
  [ "${marker_version}" = "2" ] || return 1
  [[ "${expected_fingerprint}" =~ ^[a-f0-9]{64}$ ]] || return 1
  [[ "${marker_created_at}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || return 1
  actual_fingerprint="$(sha256sum "${key_file}" | awk '{print $1}')"
  [ "${actual_fingerprint}" = "${expected_fingerprint}" ]
}

create_backup_key_generation() (
  set -euo pipefail
  local key_file marker_file key_directory temporary_key temporary_marker fingerprint created_at

  key_file="$(backup_encryption_key_file)"
  marker_file="$(backup_encryption_key_marker_file)"
  key_directory="$(dirname "${key_file}")"
  temporary_key=""
  temporary_marker=""
  trap 'rm -f -- "${temporary_key}" "${temporary_marker}" 2>/dev/null || true' EXIT

  [ ! -e "${key_file}" ] && [ ! -L "${key_file}" ] \
    || fail "A backup encryption key already exists while creating a new generation."
  [ ! -e "${marker_file}" ] && [ ! -L "${marker_file}" ] \
    || fail "A backup key generation marker already exists."

  temporary_key="$(mktemp "${key_directory}/.backup-encryption-key.XXXXXX")"
  chmod 0600 "${temporary_key}"
  openssl rand -base64 48 > "${temporary_key}"
  chown root:root "${temporary_key}"
  chmod 0600 "${temporary_key}"
  sync -f "${temporary_key}"
  mv -T -- "${temporary_key}" "${key_file}"
  temporary_key=""

  fingerprint="$(sha256sum "${key_file}" | awk '{print $1}')"
  created_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  temporary_marker="$(mktemp "${key_directory}/.backup-key-generation.XXXXXX")"
  printf 'version=2\nfingerprint_sha256=%s\ncreated_at=%s\n' \
    "${fingerprint}" "${created_at}" > "${temporary_marker}"
  chown root:root "${temporary_marker}"
  chmod 0600 "${temporary_marker}"
  sync -f "${temporary_marker}"
  mv -T -- "${temporary_marker}" "${marker_file}"
  temporary_marker=""
  sync -f "${key_file}"
  sync -f "${marker_file}"
  sync -f "${key_directory}"
  trap - EXIT
)

begin_backup_key_cutover() {
  local key_file marker_file pending_file quarantine_parent quarantine_root cutover_id state_path

  key_file="$(backup_encryption_key_file)"
  marker_file="$(backup_encryption_key_marker_file)"
  pending_file="$(backup_key_cutover_pending_file)"
  quarantine_parent="${DIS_DATA_PATH}/legacy-backup-state"
  cutover_id="$(date -u +%Y%m%dT%H%M%SZ)-$(openssl rand -hex 8)"
  quarantine_root="${quarantine_parent}/${cutover_id}"

  ensure_managed_directory "${quarantine_parent}" root root 0700
  ensure_managed_directory "${quarantine_root}" root root 0700
  if [ ! -e "${pending_file}" ] && [ ! -L "${pending_file}" ]; then
    run_cmd install -m 0600 -o root -g root /dev/null "${pending_file}"
    printf '%s\n' "${cutover_id}" > "${pending_file}"
    sync -f "${pending_file}"
  fi

  for state_path in backup backup-imports backup-requests backup-request-work; do
    if [ -e "${DIS_DATA_PATH}/${state_path}" ] || [ -L "${DIS_DATA_PATH}/${state_path}" ]; then
      [ ! -L "${DIS_DATA_PATH}/${state_path}" ] && [ -d "${DIS_DATA_PATH}/${state_path}" ] \
        || fail "Legacy backup state path is not a real directory: ${DIS_DATA_PATH}/${state_path}"
      run_cmd mv -T -- "${DIS_DATA_PATH}/${state_path}" "${quarantine_root}/${state_path}"
    fi
  done

  if [ -e "${key_file}" ] || [ -L "${key_file}" ]; then
    if [ -f "${key_file}" ] && [ ! -L "${key_file}" ] \
      && [ "$(stat -c '%h' -- "${key_file}")" = "1" ]; then
      run_cmd chown root:root "${key_file}"
      run_cmd chmod 0600 "${key_file}"
      run_cmd mv -T -- "${key_file}" "${quarantine_root}/legacy-backup-encryption.key"
    else
      [ ! -d "${key_file}" ] || fail "Backup encryption key path is an unsafe directory."
      run_cmd rm -f -- "${key_file}"
    fi
  fi
  if [ -e "${marker_file}" ] || [ -L "${marker_file}" ]; then
    [ ! -d "${marker_file}" ] || fail "Backup key generation marker is an unsafe directory."
    run_cmd mv -T -- "${marker_file}" "${quarantine_root}/legacy-generation-marker"
  fi

  ensure_data_layout
  logger -p authpriv.warning -t dis-security \
    "backup_key_rotated generation=2 legacy_state=${cutover_id} normal_legacy_restore=disabled" 2>/dev/null || true
  BACKUP_KEY_CUTOVER_PENDING=1
  export BACKUP_KEY_CUTOVER_PENDING
}

ensure_backup_encryption_key() {
  local key_file key_directory marker_file pending_file

  key_file="$(backup_encryption_key_file)"
  [ "${key_file}" = "${DIS_DATA_PATH}/secrets/backup-encryption.key" ] \
    || fail "BACKUP_ENCRYPTION_KEY_FILE must use the protected DIS secrets path."
  key_directory="$(dirname "${key_file}")"
  marker_file="$(backup_encryption_key_marker_file)"
  pending_file="$(backup_key_cutover_pending_file)"
  ensure_managed_directory "${key_directory}" root root 0750
  require_root_controlled_parent "${key_file}"

  if backup_key_generation_is_current; then
    if [ -e "${pending_file}" ] || [ -L "${pending_file}" ]; then
      [ -f "${pending_file}" ] && [ ! -L "${pending_file}" ] \
        && [ "$(stat -c '%u:%a:%h' -- "${pending_file}")" = "0:600:1" ] \
        || fail "Backup key cutover state is unsafe."
      BACKUP_KEY_CUTOVER_PENDING=1
      export BACKUP_KEY_CUTOVER_PENDING
    fi
    printf '%s\n' "${key_file}"
    return
  fi

  [ "${DIS_BACKUP_KEY_CUTOVER_ALLOWED:-0}" = "1" ] \
    || fail "The backup trust key is missing, legacy or unsafe. Run setup or a full deployment under maintenance to initialise or rotate it."
  begin_backup_key_cutover
  create_backup_key_generation
  backup_key_generation_is_current || fail "Rotated backup key generation could not be verified."
  printf '%s\n' "${key_file}"
}

require_backup_encryption_key() {
  local key_file

  key_file="$(backup_encryption_key_file)"
  [ "${key_file}" = "${DIS_DATA_PATH}/secrets/backup-encryption.key" ] \
    || fail "BACKUP_ENCRYPTION_KEY_FILE must use the protected DIS secrets path."
  backup_key_generation_is_current \
    || fail "Backup trust key generation is missing, unsafe or does not match its marker."
  printf '%s\n' "${key_file}"
}

durably_sync_backup_tree() {
  local backup_path="$1" backup_parent

  backup_parent="$(dirname "${backup_path}")"
  secure_path_operation sync-tree "${backup_path}"
  run_cmd sync -f "${backup_path}"
  run_cmd sync -f "${backup_parent}"
}

finalize_backup_key_cutover() {
  local app_root="${1:-${DIS_INSTALL_PATH}}" pending_file backup_output backup_path

  pending_file="$(backup_key_cutover_pending_file)"
  if [ ! -e "${pending_file}" ] && [ ! -L "${pending_file}" ]; then
    return
  fi
  [ -f "${pending_file}" ] && [ ! -L "${pending_file}" ] \
    && [ "$(stat -c '%u:%a:%h' -- "${pending_file}")" = "0:600:1" ] \
    || fail "Backup key cutover state is unsafe."
  require_backup_encryption_key >/dev/null

  log "Creating and verifying the first trusted backup after backup-key rotation"
  backup_output="$(APP_ROOT="${app_root}" bash "${app_root}/scripts/backup.sh")"
  printf '%s\n' "${backup_output}"
  backup_path="$(printf '%s\n' "${backup_output}" | awk '/Backup created at / {print $NF}' | tail -n 1)"
  [ -n "${backup_path}" ] || fail "Post-cutover backup path could not be determined."
  APP_ROOT="${app_root}" bash "${app_root}/scripts/verify-backup.sh" "${backup_path}"
  durably_sync_backup_tree "${backup_path}"
  run_cmd rm -f -- "${pending_file}"
  sync -f "${DIS_DATA_PATH}"
  BACKUP_KEY_CUTOVER_PENDING=0
  export BACKUP_KEY_CUTOVER_PENDING
  log "Backup-key cutover completed; legacy backups remain quarantined from normal restore."
}

backup_authentication_tag() {
  local input_file="$1" key_file

  require_file "${input_file}"
  key_file="$(require_backup_encryption_key)"
  php -r '
    $key = file_get_contents($argv[1]);
    if ($key === false) { exit(2); }
    $tag = hash_hmac_file("sha256", $argv[2], $key);
    if ($tag === false) { exit(3); }
    echo $tag;
  ' "${key_file}" "${input_file}"
}

verify_backup_authentication_tag() {
  local backup_path="$1" expected actual

  require_file "${backup_path}/BACKUP.HMAC"
  require_file "${backup_path}/SHA256SUMS"
  expected="$(tr -d '\r\n' < "${backup_path}/BACKUP.HMAC")"
  [[ "${expected}" =~ ^[a-f0-9]{64}$ ]] || fail "Backup authentication tag is invalid."
  actual="$(backup_authentication_tag "${backup_path}/SHA256SUMS")"
  [ "${actual}" = "${expected}" ] || fail "Backup authentication failed."
}

verify_backup_snapshot_identity() {
  local backup_path="$1"
  local -a checksum_lines

  verify_backup_authentication_tag "${backup_path}"
  mapfile -t checksum_lines < "${backup_path}/SHA256SUMS"
  [ "${#checksum_lines[@]}" -eq 2 ] || fail "Backup checksum manifest must contain exactly two entries."
  grep -Eq '^[a-f0-9]{64} [ *]backup\.payload\.enc$' "${backup_path}/SHA256SUMS" \
    || fail "Backup payload checksum entry is invalid."
  grep -Eq '^[a-f0-9]{64} [ *]manifest\.json$' "${backup_path}/SHA256SUMS" \
    || fail "Backup manifest checksum entry is invalid."
  (cd "${backup_path}" && run_cmd sha256sum --check --strict SHA256SUMS)
}

snapshot_backup_file() {
  local source_file="$1" destination_file="$2" maximum_bytes="${3:-0}"
  local source_size copied_size copy_limit

  if [ -L "${source_file}" ] || [ ! -f "${source_file}" ]; then
    fail "Backup input $(basename "${source_file}") must be a regular, non-symlink file."
  fi

  source_size="$(stat -c '%s' -- "${source_file}")"
  [[ "${source_size}" =~ ^[0-9]+$ ]] || fail "Backup input size is invalid."
  # Bound an untrusted growing/special source while keeping large enterprise
  # backups supported. The destination is additionally limited to the exact
  # size observed before opening the source.
  [ "${source_size}" -le 8796093022208 ] || fail "Backup input exceeds the supported snapshot size."
  if [ "${maximum_bytes}" -gt 0 ] && [ "${source_size}" -gt "${maximum_bytes}" ]; then
    fail "Backup input $(basename "${source_file}") exceeds the allowed size."
  fi

  copy_limit=$((source_size + 1))
  if ! dd \
    if="${source_file}" \
    of="${destination_file}" \
    bs=4194304 \
    count="${copy_limit}" \
    iflag=nofollow,nonblock,fullblock,count_bytes \
    oflag=excl,nofollow \
    status=none; then
    fail "Backup input could not be snapshotted safely."
  fi

  if [ -L "${destination_file}" ] || [ ! -f "${destination_file}" ]; then
    fail "Backup snapshot is not a regular file."
  fi
  copied_size="$(stat -c '%s' -- "${destination_file}")"
  [ "${copied_size}" = "${source_size}" ] || fail "Backup input changed while it was being snapshotted."
  run_cmd chown root:root "${destination_file}"
  run_cmd chmod 0600 "${destination_file}"
}

snapshot_authenticated_backup_input() {
  local source_path="$1" destination_path="$2" payload_limit="${3:-0}"
  local configured_limit available_bytes filesystem_bytes reserve_bytes required_bytes payload_size

  if [ -L "${source_path}" ] || [ ! -d "${source_path}" ]; then
    fail "Backup input must be a regular directory."
  fi
  if [ -e "${destination_path}" ] || [ -L "${destination_path}" ]; then
    fail "Backup snapshot destination already exists."
  fi

  run_cmd install -d -m 0700 -o root -g root "${destination_path}"
  configured_limit="${BACKUP_MAX_SNAPSHOT_BYTES:-1099511627776}"
  [[ "${configured_limit}" =~ ^[0-9]+$ ]] \
    && [ "${configured_limit}" -ge 1073741824 ] \
    && [ "${configured_limit}" -le 8796093022208 ] \
    || fail "BACKUP_MAX_SNAPSHOT_BYTES is invalid."
  if [ "${payload_limit}" -eq 0 ] || [ "${payload_limit}" -gt "${configured_limit}" ]; then
    payload_limit="${configured_limit}"
  fi

  payload_size="$(stat -c '%s' -- "${source_path}/backup.payload.enc")"
  [[ "${payload_size}" =~ ^[0-9]+$ ]] || fail "Backup payload size is invalid."
  read -r filesystem_bytes available_bytes < <(df -PB1 "${destination_path}" | awk 'NR == 2 { print $2, $4 }')
  [[ "${filesystem_bytes}" =~ ^[0-9]+$ ]] && [[ "${available_bytes}" =~ ^[0-9]+$ ]] \
    || fail "Backup snapshot filesystem capacity could not be determined."
  reserve_bytes=$((filesystem_bytes / 20))
  [ "${reserve_bytes}" -ge 1073741824 ] || reserve_bytes=1073741824
  [ "${reserve_bytes}" -le 10737418240 ] || reserve_bytes=10737418240
  required_bytes=$((payload_size * 3 + reserve_bytes))
  [ "${available_bytes}" -ge "${required_bytes}" ] \
    || fail "Insufficient protected scratch space for backup verification."

  snapshot_backup_file "${source_path}/backup.payload.enc" "${destination_path}/backup.payload.enc" "${payload_limit}"
  snapshot_backup_file "${source_path}/manifest.json" "${destination_path}/manifest.json" 1048576
  snapshot_backup_file "${source_path}/SHA256SUMS" "${destination_path}/SHA256SUMS" 1048576
  snapshot_backup_file "${source_path}/BACKUP.HMAC" "${destination_path}/BACKUP.HMAC" 4096
}

extract_encrypted_backup_payload() {
  local encrypted_file="$1" destination="$2" key_file archive maximum_bytes

  require_file "${encrypted_file}"
  require_directory "${destination}"
  key_file="$(require_backup_encryption_key)"
  archive="$(mktemp "$(dirname "${destination}")/.dis-backup-payload.XXXXXX.tar")"
  chmod 0600 "${archive}"

  openssl enc -d -aes-256-cbc -pbkdf2 -iter 250000 -md sha256 \
    -pass "file:${key_file}" \
    -in "${encrypted_file}" \
    -out "${archive}"

  maximum_bytes="${BACKUP_MAX_RESTORE_BYTES:-2199023255552}"
  [[ "${maximum_bytes}" =~ ^[0-9]+$ ]] \
    && [ "${maximum_bytes}" -ge 1073741824 ] \
    && [ "${maximum_bytes}" -le 8796093022208 ] \
    || fail "BACKUP_MAX_RESTORE_BYTES is invalid."
  run_cmd python3 -I -S "${COMMON_LIB_DIR}/safe-extract.py" \
    "${archive}" "${destination}" --max-bytes "${maximum_bytes}"
  run_cmd rm -f -- "${archive}"
}

extract_storage_backup_archive() {
  local archive="$1" destination="$2" maximum_bytes

  maximum_bytes="${BACKUP_MAX_RESTORE_BYTES:-2199023255552}"
  [[ "${maximum_bytes}" =~ ^[0-9]+$ ]] \
    && [ "${maximum_bytes}" -ge 1073741824 ] \
    && [ "${maximum_bytes}" -le 8796093022208 ] \
    || fail "BACKUP_MAX_RESTORE_BYTES is invalid."
  run_cmd python3 -I -S "${COMMON_LIB_DIR}/safe-extract.py" \
    "${archive}" "${destination}" \
    --max-bytes "${maximum_bytes}" \
    --allowed-root storage \
    --allowed-root webapp/backend/storage \
    --allowed-root secrets
}

validate_storage_backup_archive() {
  local archive="$1" destination="$2" maximum_bytes

  maximum_bytes="${BACKUP_MAX_RESTORE_BYTES:-2199023255552}"
  [[ "${maximum_bytes}" =~ ^[0-9]+$ ]] \
    && [ "${maximum_bytes}" -ge 1073741824 ] \
    && [ "${maximum_bytes}" -le 8796093022208 ] \
    || fail "BACKUP_MAX_RESTORE_BYTES is invalid."
  run_cmd python3 -I -S "${COMMON_LIB_DIR}/safe-extract.py" \
    "${archive}" "${destination}" \
    --max-bytes "${maximum_bytes}" \
    --allowed-root storage \
    --allowed-root webapp/backend/storage \
    --allowed-root secrets \
    --validate-only
}

replace_managed_tree() {
  local source="$1" destination="$2" previous

  validate_plain_tree "${source}"
  require_root_controlled_parent "${destination}"
  [ -d "${destination}" ] && [ ! -L "${destination}" ] \
    || fail "Managed restore destination is not a real directory: ${destination}"
  [ "$(stat -c '%d' -- "${source}")" = "$(stat -c '%d' -- "$(dirname "${destination}")")" ] \
    || fail "Managed restore staging must be on the destination filesystem."

  previous="$(mktemp -d "${destination}.previous.XXXXXX")"
  rmdir -- "${previous}"
  mv -T -- "${destination}" "${previous}"
  if ! mv -T -- "${source}" "${destination}"; then
    mv -T -- "${previous}" "${destination}" 2>/dev/null || true
    fail "Could not atomically install restored runtime data."
  fi
  secure_path_operation remove-tree "${previous}"
}

cifs_backup_mount_is_hardened() {
  local mount_point="$1" expected_share="${2:-}" mounted_options required_option

  mountpoint -q "${mount_point}" || return 1
  [ "$(findmnt -n -o FSTYPE --target "${mount_point}")" = "cifs" ] || return 1
  if [ -n "${expected_share}" ] \
    && [ "$(findmnt -n -o SOURCE --target "${mount_point}")" != "${expected_share}" ]; then
    return 1
  fi
  mounted_options=",$(findmnt -n -o VFS-OPTIONS,FS-OPTIONS --target "${mount_point}" | tr ' ' ',') ,"
  mounted_options="${mounted_options// /}"
  for required_option in nosuid nodev noexec nosymfollow nounix forceuid forcegid; do
    [[ "${mounted_options}" == *",${required_option},"* ]] || return 1
  done
  return 0
}

detach_unsafe_cifs_backup_mount() {
  local mount_point="${1:-/mnt/dis-backup}"

  if mountpoint -q "${mount_point}" && ! cifs_backup_mount_is_hardened "${mount_point}"; then
    log "Detaching legacy or unsafe DIS backup mount at ${mount_point}"
    run_cmd umount --lazy "${mount_point}"
  fi
}

resolve_backup_root() {
  local app_root="$1"
  local target="${BACKUP_TARGET:-local}"
  local configured_root

  if [ "${BACKUP_SAMBA_ENABLED:-0}" = "1" ]; then
    target="samba"
  fi

  if [ "${target}" != "samba" ]; then
    configured_root="${BACKUP_ROOT:-${BACKUP_DISK_PATH:-${DIS_DATA_PATH}/backup}}"
    if [ "${configured_root}" = "${app_root}/backup" ] || [ "${configured_root}" = "${DIS_INSTALL_PATH}/backup" ] || [ "${configured_root}" = "/opt/dis/backup" ]; then
      configured_root="${DIS_DATA_PATH}/backup"
    fi
    printf '%s\n' "${configured_root}"
    return 0
  fi

  local share="${BACKUP_SAMBA_SHARE:-}"
  local mount_point="${BACKUP_SAMBA_MOUNT:-/mnt/dis-backup}"
  local username="${BACKUP_SAMBA_USERNAME:-}"
  local password="${BACKUP_SAMBA_PASSWORD:-}"
  local domain="${BACKUP_SAMBA_DOMAIN:-}"
  local version="${BACKUP_SAMBA_VERSION:-3.1.1}"
  local reader_group reader_gid credentials_root credentials_file options

  if [ -z "${share}" ]; then
    fail "BACKUP_SAMBA_SHARE is required when BACKUP_TARGET=samba."
  fi
  if [ -z "${username}" ] || [ -z "${password}" ]; then
    fail "BACKUP_SAMBA_USERNAME and BACKUP_SAMBA_PASSWORD are required when BACKUP_TARGET=samba."
  fi
  if ! command -v mount.cifs >/dev/null 2>&1; then
    fail "mount.cifs not found. Install cifs-utils before using Samba backups."
  fi
  [ "${version}" = "3.1.1" ] || fail "Only SMB 3.1.1 is allowed for backup storage."

  ensure_managed_directory "${mount_point}" root root 0750
  if mountpoint -q "${mount_point}"; then
    if ! cifs_backup_mount_is_hardened "${mount_point}" "${share}"; then
      run_cmd umount --lazy "${mount_point}"
      fail "Existing CIFS backup mount was detached because its source or security options were invalid."
    fi
    printf '%s\n' "${mount_point}"
    return 0
  fi

  if id www-data >/dev/null 2>&1; then
    reader_group=www-data
  else
    reader_group="${DIS_GROUP}"
  fi
  reader_gid="$(getent group "${reader_group}" | cut -d: -f3)"
  [[ "${reader_gid}" =~ ^[0-9]+$ ]] || fail "Could not resolve the Samba backup reader group."
  credentials_root=/run/dis-backup-mount
  ensure_managed_directory "${credentials_root}" root root 0700
  credentials_file="$(mktemp "${credentials_root}/credentials.XXXXXX")"
  trap 'rm -f "${credentials_file}"' RETURN
  chmod 0600 "${credentials_file}"
  {
    printf 'username=%s\n' "${username}"
    printf 'password=%s\n' "${password}"
    if [ -n "${domain}" ]; then
      printf 'domain=%s\n' "${domain}"
    fi
  } > "${credentials_file}"

  options="credentials=${credentials_file},vers=${version},iocharset=utf8,uid=0,gid=${reader_gid},forceuid,forcegid,dir_mode=0750,file_mode=0640,nosuid,nodev,noexec,nounix,nosymfollow"
  run_cmd mount -t cifs "${share}" "${mount_point}" -o "${options}"
  rm -f "${credentials_file}"
  trap - RETURN

  if ! cifs_backup_mount_is_hardened "${mount_point}" "${share}"; then
    run_cmd umount --lazy "${mount_point}" || true
    fail "CIFS backup mount verification failed."
  fi

  printf '%s\n' "${mount_point}"
}
