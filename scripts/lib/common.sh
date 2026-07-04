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

    .lane {
      position: absolute;
      left: -180px;
      width: 170px;
      height: 70px;
      animation: fly 13s linear infinite;
      opacity: .92;
    }

    .lane--one { top: 12%; animation-duration: 12s; }
    .lane--two { top: 31%; animation-duration: 16s; animation-delay: -6s; transform: scale(.78); opacity: .68; }
    .lane--three { top: 68%; animation-duration: 18s; animation-delay: -11s; transform: scale(.9); opacity: .72; }

    .drone {
      width: 170px;
      height: 70px;
      filter: drop-shadow(0 10px 22px rgba(0, 0, 0, .42));
    }

    .rotor {
      transform-origin: center;
      animation: rotor .36s linear infinite;
    }

    .beam {
      opacity: .22;
      animation: scan 2.4s ease-in-out infinite;
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
      to { translate: calc(100vw + 220px) 0; }
    }

    @keyframes rotor {
      to { rotate: 360deg; }
    }

    @keyframes scan {
      0%, 100% { opacity: .12; }
      50% { opacity: .34; }
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
      .lane--two { top: 22%; }
      .lane--three { top: 48%; }
    }

    @media (prefers-reduced-motion: reduce) {
      .lane, .rotor, .beam, .status::before { animation: none; }
      .lane { transform: none; translate: 18vw 0; }
      .lane--two, .lane--three { display: none; }
    }
  </style>
</head>
<body>
  <div class="sky" aria-hidden="true">
    <div class="lane lane--one">
      <svg class="drone" viewBox="0 0 170 70" role="img" aria-label="">
        <path class="beam" d="M82 40 L52 70 H118 L88 40Z" fill="#80c7ff"/>
        <g fill="none" stroke="#80c7ff" stroke-width="4" stroke-linecap="round">
          <path d="M50 34 H120"/>
          <path d="M85 22 V46"/>
          <path d="M62 34 L42 20"/>
          <path d="M108 34 L128 20"/>
        </g>
        <g fill="#101927" stroke="#d6f3ff" stroke-width="3">
          <ellipse cx="85" cy="34" rx="23" ry="11"/>
          <circle cx="42" cy="20" r="7"/>
          <circle cx="128" cy="20" r="7"/>
          <circle cx="42" cy="49" r="7"/>
          <circle cx="128" cy="49" r="7"/>
        </g>
        <g class="rotor" fill="none" stroke="#d6f3ff" stroke-width="2">
          <path d="M23 20 H61"/>
          <path d="M109 20 H147"/>
          <path d="M23 49 H61"/>
          <path d="M109 49 H147"/>
        </g>
        <circle cx="85" cy="34" r="4" fill="#7dd3a7"/>
      </svg>
    </div>
    <div class="lane lane--two">
      <svg class="drone" viewBox="0 0 170 70" role="img" aria-label="">
        <path class="beam" d="M82 40 L52 70 H118 L88 40Z" fill="#80c7ff"/>
        <g fill="none" stroke="#80c7ff" stroke-width="4" stroke-linecap="round">
          <path d="M50 34 H120"/>
          <path d="M85 22 V46"/>
          <path d="M62 34 L42 20"/>
          <path d="M108 34 L128 20"/>
        </g>
        <g fill="#101927" stroke="#d6f3ff" stroke-width="3">
          <ellipse cx="85" cy="34" rx="23" ry="11"/>
          <circle cx="42" cy="20" r="7"/>
          <circle cx="128" cy="20" r="7"/>
          <circle cx="42" cy="49" r="7"/>
          <circle cx="128" cy="49" r="7"/>
        </g>
        <g class="rotor" fill="none" stroke="#d6f3ff" stroke-width="2">
          <path d="M23 20 H61"/>
          <path d="M109 20 H147"/>
          <path d="M23 49 H61"/>
          <path d="M109 49 H147"/>
        </g>
        <circle cx="85" cy="34" r="4" fill="#7dd3a7"/>
      </svg>
    </div>
    <div class="lane lane--three">
      <svg class="drone" viewBox="0 0 170 70" role="img" aria-label="">
        <path class="beam" d="M82 40 L52 70 H118 L88 40Z" fill="#80c7ff"/>
        <g fill="none" stroke="#80c7ff" stroke-width="4" stroke-linecap="round">
          <path d="M50 34 H120"/>
          <path d="M85 22 V46"/>
          <path d="M62 34 L42 20"/>
          <path d="M108 34 L128 20"/>
        </g>
        <g fill="#101927" stroke="#d6f3ff" stroke-width="3">
          <ellipse cx="85" cy="34" rx="23" ry="11"/>
          <circle cx="42" cy="20" r="7"/>
          <circle cx="128" cy="20" r="7"/>
          <circle cx="42" cy="49" r="7"/>
          <circle cx="128" cy="49" r="7"/>
        </g>
        <g class="rotor" fill="none" stroke="#d6f3ff" stroke-width="2">
          <path d="M23 20 H61"/>
          <path d="M109 20 H147"/>
          <path d="M23 49 H61"/>
          <path d="M109 49 H147"/>
        </g>
        <circle cx="85" cy="34" r="4" fill="#7dd3a7"/>
      </svg>
    </div>
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
