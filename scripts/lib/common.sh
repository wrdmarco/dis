#!/usr/bin/env bash
set -euo pipefail

DIS_INSTALL_PATH="${DIS_INSTALL_PATH:-/opt/dis}"
DIS_DATA_PATH="${DIS_DATA_PATH:-/opt/dis-data}"
DIS_USER="${DIS_USER:-dis}"
DIS_GROUP="${DIS_GROUP:-dis}"
PHP_VERSION="${PHP_VERSION:-8.5}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php${PHP_VERSION}-fpm}"
NGINX_SITE_NAME="${NGINX_SITE_NAME:-dis}"

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
    DIS_DATA_PATH="${configured_path}"
    export DIS_DATA_PATH
  fi
}

ensure_data_layout() {
  ensure_directory "${DIS_DATA_PATH}" root "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/backup" root "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/backup-requests" root "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/playwright-browsers" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/secrets" root "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/storage" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/storage/app" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/storage/generated" root root 0755
  ensure_directory "${DIS_DATA_PATH}/storage/logs" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/storage/releases" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_directory "${DIS_DATA_PATH}/storage/tmp" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage/app" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage/framework/cache" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage/framework/sessions" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage/framework/views" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage/logs" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_directory "${DIS_DATA_PATH}/webapp/backend/storage/composer" "${DIS_USER}" "${DIS_GROUP}" 0770
}

migrate_path_to_data() {
  local source="$1"
  local destination="$2"

  if [ -L "${source}" ]; then
    return
  fi

  if [ -d "${source}" ]; then
    ensure_directory "$(dirname "${destination}")" root "${DIS_GROUP}" 0750
    if [ -d "${destination}" ]; then
      run_cmd cp -a "${source}/." "${destination}/"
      run_cmd rm -rf "${source}"
    else
      run_cmd mv "${source}" "${destination}"
    fi
  elif [ -f "${source}" ]; then
    ensure_directory "$(dirname "${destination}")" root "${DIS_GROUP}" 0750
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
    if [ -f "${app_root}/.env" ] && [ ! -L "${app_root}/.env" ] && [ ! -f "${DIS_DATA_PATH}/.env" ]; then
      migrate_path_to_data "${app_root}/.env" "${DIS_DATA_PATH}/.env"
    fi
    run_cmd ln -sfn "${DIS_DATA_PATH}/.env" "${app_root}/.env"
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

  if [ -z "${share}" ]; then
    fail "BACKUP_SAMBA_SHARE is required when BACKUP_TARGET=samba."
  fi
  if [ -z "${username}" ] || [ -z "${password}" ]; then
    fail "BACKUP_SAMBA_USERNAME and BACKUP_SAMBA_PASSWORD are required when BACKUP_TARGET=samba."
  fi
  if ! command -v mount.cifs >/dev/null 2>&1; then
    fail "mount.cifs not found. Install cifs-utils before using Samba backups."
  fi

  run_cmd install -d -m 0750 "${mount_point}"
  if mountpoint -q "${mount_point}"; then
    printf '%s\n' "${mount_point}"
    return 0
  fi

  local credentials_file
  credentials_file="$(mktemp)"
  trap 'rm -f "${credentials_file}"' RETURN
  chmod 0600 "${credentials_file}"
  {
    printf 'username=%s\n' "${username}"
    printf 'password=%s\n' "${password}"
    if [ -n "${domain}" ]; then
      printf 'domain=%s\n' "${domain}"
    fi
  } > "${credentials_file}"

  local options="credentials=${credentials_file},vers=${version},iocharset=utf8,dir_mode=0750,file_mode=0640"
  run_cmd mount -t cifs "${share}" "${mount_point}" -o "${options}"
  rm -f "${credentials_file}"
  trap - RETURN

  printf '%s\n' "${mount_point}"
}
