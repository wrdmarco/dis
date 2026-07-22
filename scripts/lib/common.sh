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
OSRM_ADMIN_RUNTIME_PARENT="/usr/local/lib/dis"
OSRM_ADMIN_RUNTIME_DIR="${OSRM_ADMIN_RUNTIME_PARENT}/osrm-admin"
OSRM_ADMIN_WORKER_PATH="/usr/local/bin/dis-osrm-admin-request-worker"
WALLBOARD_MAINTENANCE_NOTICE_PATH="${DIS_INSTALL_PATH}/maintenance/wallboard-status.json"
WALLBOARD_MAINTENANCE_NOTICE_SECONDS=6
WALLBOARD_MAINTENANCE_NOTICE_TTL_SECONDS=21600
SPEECH_UV_VERSION=0.11.30
SPEECH_UV_ARCHIVE_SHA256=04bc7d180d6138bf6dc08387acf507a823f397a98fea55da36b0ccc7fbce3b68
SPEECH_UV_ARCHIVE_URL="https://github.com/astral-sh/uv/releases/download/${SPEECH_UV_VERSION}/uv-x86_64-unknown-linux-gnu.tar.gz"

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

set_managed_env_secret() (
  set -euo pipefail

  local env_file="$1" key="$2" value="$3"
  local resolved_env temporary="" line found=0

  require_root
  [[ "${key}" =~ ^[A-Z][A-Z0-9_]*$ ]] \
    || fail "Invalid managed environment secret name."
  [ -n "${value}" ] && [[ "${value}" != *$'\n'* ]] && [[ "${value}" != *$'\r'* ]] \
    || fail "Invalid managed environment secret value."
  resolved_env="$(readlink -f -- "${env_file}" 2>/dev/null || true)"
  [ "${resolved_env}" = "${DIS_DATA_PATH}/.env" ] \
    || fail "The managed environment secret target does not resolve to ${DIS_DATA_PATH}/.env."
  [ -f "${resolved_env}" ] && [ ! -L "${resolved_env}" ] \
    && [ "$(stat -c '%h' -- "${resolved_env}" 2>/dev/null || true)" = "1" ] \
    || fail "The managed environment file is not a safe regular file."
  require_root_controlled_parent "${resolved_env}"

  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would set managed environment secret ${key}."
    return 0
  fi

  temporary="$(mktemp "${resolved_env}.secret.XXXXXX")"
  cleanup_managed_env_secret() {
    local exit_code="$?"
    trap - EXIT INT TERM
    rm -f -- "${temporary:-}" 2>/dev/null || true
    exit "${exit_code}"
  }
  trap cleanup_managed_env_secret EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
  chmod 0600 "${temporary}"

  while IFS= read -r line || [ -n "${line}" ]; do
    case "${line}" in
      "${key}="*)
        printf '%s=%s\n' "${key}" "${value}" >> "${temporary}"
        found=1
        ;;
      *)
        printf '%s\n' "${line}" >> "${temporary}"
        ;;
    esac
  done < "${resolved_env}"
  if [ "${found}" = "0" ]; then
    printf '%s=%s\n' "${key}" "${value}" >> "${temporary}"
  fi

  chown root:"${DIS_GROUP}" "${temporary}"
  chmod 0640 "${temporary}"
  sync -f "${temporary}"
  mv -fT -- "${temporary}" "${resolved_env}"
  temporary=""
  sync -f "${resolved_env}"
  sync -f "$(dirname "${resolved_env}")"
  if id www-data >/dev/null 2>&1; then
    setfacl -m "u:www-data:r--" "${resolved_env}"
  fi
  trap - EXIT INT TERM
)

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
  [ -x /usr/bin/python3 ] || fail "python3 is required for secure descriptor-based path operations."
  root_controlled_bundle_source_is_safe "${COMMON_LIB_DIR}/secure-path.py" \
    || fail "The secure path helper is not root-controlled."
  run_cmd /usr/bin/python3 -I -S "${COMMON_LIB_DIR}/secure-path.py" "$@"
}

root_owned_runtime_directory_is_safe() {
  local path="$1" expected_mode="$2"

  [ -d "${path}" ] && [ ! -L "${path}" ] \
    && [ "$(stat -c '%u:%g:%a' -- "${path}" 2>/dev/null || true)" = "0:0:${expected_mode}" ]
}

root_owned_runtime_file_is_safe() {
  local path="$1" expected_mode="$2"

  [ -f "${path}" ] && [ ! -L "${path}" ] \
    && [ "$(stat -c '%u:%g:%a:%h' -- "${path}" 2>/dev/null || true)" = "0:0:${expected_mode}:1" ]
}

root_controlled_bundle_source_is_safe() {
  local path="$1" parent current="" component metadata mode

  [ -f "${path}" ] && [ ! -L "${path}" ] || return 1
  metadata="$(/usr/bin/stat -c '%u:%a:%h' -- "${path}" 2>/dev/null || true)"
  [[ "${metadata}" =~ ^0:([0-7]+):1$ ]] || return 1
  mode="${BASH_REMATCH[1]}"
  (( (8#${mode} & 8#022) == 0 )) || return 1
  metadata="$(/usr/bin/stat -c '%u:%a' -- / 2>/dev/null || true)"
  [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1
  mode="${BASH_REMATCH[1]}"
  (( (8#${mode} & 8#022) == 0 )) || return 1
  parent="${path%/*}"
  IFS='/' read -r -a bundle_source_components <<< "${parent#/}"
  for component in "${bundle_source_components[@]}"; do
    [ -n "${component}" ] || continue
    current="${current}/${component}"
    [ -d "${current}" ] && [ ! -L "${current}" ] || return 1
    metadata="$(/usr/bin/stat -c '%u:%a' -- "${current}" 2>/dev/null || true)"
    [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1
    mode="${BASH_REMATCH[1]}"
    (( (8#${mode} & 8#022) == 0 )) || return 1
  done
}

wallboard_media_runtime_dependencies_are_safe() {
  local binary metadata mode owner

  [ -x /usr/bin/dpkg-query ] || return 1
  [ "$(/usr/bin/dpkg-query -W -f='${db:Status-Abbrev}' ffmpeg 2>/dev/null || true)" = "ii " ] \
    || return 1

  for binary in /usr/bin/ffmpeg /usr/bin/ffprobe; do
    [ -f "${binary}" ] && [ -x "${binary}" ] && [ ! -L "${binary}" ] || return 1
    owner="$(/usr/bin/dpkg-query -S "${binary}" 2>/dev/null || true)"
    [[ "${owner}" == ffmpeg:* ]] || return 1
    metadata="$(/usr/bin/stat -c '%u:%g:%a' -- "${binary}" 2>/dev/null || true)"
    [[ "${metadata}" =~ ^0:0:([0-7]+)$ ]] || return 1
    mode="${BASH_REMATCH[1]}"
    (( (8#${mode} & 8#022) == 0 )) || return 1
  done
}

ensure_wallboard_media_runtime_dependencies() {
  local reinstall=()

  if wallboard_media_runtime_dependencies_are_safe; then
    return 0
  fi

  [ -x /usr/bin/apt-get ] || fail "apt-get is required to install the fixed Ubuntu ffmpeg runtime dependency."
  [ -x /usr/bin/dpkg-query ] || fail "dpkg-query is required to verify the Ubuntu ffmpeg runtime dependency."
  if [ "$(/usr/bin/dpkg-query -W -f='${db:Status-Abbrev}' ffmpeg 2>/dev/null || true)" = "ii " ]; then
    reinstall=(--reinstall)
  fi

  log "Installing the fixed Ubuntu ffmpeg runtime dependency required by wallboard media"
  run_cmd env DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get install -y --no-install-recommends "${reinstall[@]}" ffmpeg
  if [ "${DRY_RUN:-0}" != "1" ] && ! wallboard_media_runtime_dependencies_are_safe; then
    fail "The Ubuntu ffmpeg package did not provide safe root-controlled /usr/bin/ffmpeg and /usr/bin/ffprobe binaries."
  fi
}

knmi_forecast_runtime_package_is_safe() {
  local package="$1"
  local binary metadata mode owner requirement
  local requirements=()

  [ -x /usr/bin/dpkg-query ] || return 1
  [ "$(/usr/bin/dpkg-query -W -f='${db:Status-Abbrev}' "${package}" 2>/dev/null || true)" = "ii " ] || return 1

  case "${package}" in
    libeccodes-tools)
      requirements=(/usr/bin/grib_count:libeccodes-tools /usr/bin/grib_get:libeccodes-tools)
      ;;
    hdf5-tools)
      requirements=(/usr/bin/h5dump:hdf5-tools)
      ;;
    *)
      return 1
      ;;
  esac

  for requirement in "${requirements[@]}"; do
    binary="${requirement%%:*}"
    [ -f "${binary}" ] && [ -x "${binary}" ] && [ ! -L "${binary}" ] || return 1
    owner="$(/usr/bin/dpkg-query -S "${binary}" 2>/dev/null || true)"
    [[ "${owner}" == "${package}":* ]] || return 1
    metadata="$(/usr/bin/stat -c '%u:%g:%a' -- "${binary}" 2>/dev/null || true)"
    [[ "${metadata}" =~ ^0:0:([0-7]+)$ ]] || return 1
    mode="${BASH_REMATCH[1]}"
    (( (8#${mode} & 8#022) == 0 )) || return 1
  done
}

knmi_forecast_runtime_dependencies_are_safe() {
  knmi_forecast_runtime_package_is_safe libeccodes-tools \
    && knmi_forecast_runtime_package_is_safe hdf5-tools
}

ensure_knmi_forecast_runtime_dependencies() {
  local package
  local reinstall=()

  if knmi_forecast_runtime_dependencies_are_safe; then
    return 0
  fi

  [ -x /usr/bin/apt-get ] || fail "apt-get is required to install the fixed Ubuntu KNMI forecast runtime dependencies."
  [ -x /usr/bin/dpkg-query ] || fail "dpkg-query is required to verify the Ubuntu KNMI forecast runtime dependencies."
  for package in libeccodes-tools hdf5-tools; do
    if knmi_forecast_runtime_package_is_safe "${package}"; then
      continue
    fi
    reinstall=()
    if [ "$(/usr/bin/dpkg-query -W -f='${db:Status-Abbrev}' "${package}" 2>/dev/null || true)" = "ii " ]; then
      reinstall=(--reinstall)
    fi
    log "Installing the fixed Ubuntu ${package} runtime dependency required by KNMI forecasts"
    run_cmd env DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get install -y --no-install-recommends "${reinstall[@]}" "${package}"
  done
  if [ "${DRY_RUN:-0}" != "1" ] && ! knmi_forecast_runtime_dependencies_are_safe; then
    fail "The Ubuntu forecast packages did not provide safe root-controlled GRIB and HDF5 tools."
  fi
}

install_speech_engine_runtime() (
  set -euo pipefail

  local app_root="$1"
  local engine_root="${app_root}/speech-engine"
  local archive=""
  local source_path=""
  local temporary=""
  local uv_binary=""
  local checksum=""
  local python_source_count=0
  local -a uv_environment

  require_root
  [ "$(uname -s)" = "Linux" ] && [ "$(uname -m)" = "x86_64" ] \
    || fail "The managed speech runtime currently supports Linux x86_64 only."
  id "${DIS_USER}" >/dev/null 2>&1 \
    || fail "The DIS service account must exist before installing the speech runtime."

  for source_path in \
    "${engine_root}/pyproject.toml" \
    "${engine_root}/uv.lock" \
    "${engine_root}/model-packages.requirements.txt" \
    "${engine_root}/dis_tts_engine/__main__.py"; do
    root_controlled_bundle_source_is_safe "${source_path}" \
      || fail "Unsafe speech runtime source: ${source_path}"
  done
  [ -z "$(/usr/bin/find -P "${engine_root}/dis_tts_engine" -type l -print -quit)" ] \
    || fail "The speech engine source tree may not contain symbolic links."
  while IFS= read -r -d '' source_path; do
    root_controlled_bundle_source_is_safe "${source_path}" \
      || fail "Unsafe speech engine Python source: ${source_path}"
    python_source_count=$((python_source_count + 1))
  done < <(/usr/bin/find -P "${engine_root}/dis_tts_engine" -type f -name '*.py' -print0)
  [ "${python_source_count}" -gt 0 ] || fail "The speech engine contains no Python source files."
  grep -Fq 'source = { registry = "https://download.pytorch.org/whl/cpu" }' "${engine_root}/uv.lock" \
    || fail "The speech runtime lock does not pin the CPU-only PyTorch index."
  if grep -Eq '^name = "(nvidia-[^"]+|triton)"$' "${engine_root}/uv.lock"; then
    fail "The speech runtime lock unexpectedly contains GPU runtime packages."
  fi

  ensure_data_layout
  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would install the checksum-pinned uv ${SPEECH_UV_VERSION} runtime and locked speech dependencies."
    return 0
  fi

  for source_path in /usr/bin/curl /usr/bin/tar /usr/bin/sha256sum; do
    [ -x "${source_path}" ] || fail "Required speech runtime installer is missing: ${source_path}"
  done

  temporary="$(mktemp -d /tmp/dis-speech-runtime.XXXXXX)"
  case "${temporary}" in
    /tmp/dis-speech-runtime.*) ;;
    *) fail "Could not create a safely scoped speech runtime staging directory." ;;
  esac
  chmod 0755 "${temporary}"
  cleanup_speech_runtime_install() {
    local exit_code="$?"
    trap - EXIT INT TERM
    case "${temporary:-}" in
      /tmp/dis-speech-runtime.*)
        [ ! -L "${temporary}" ] && rm -rf -- "${temporary}" 2>/dev/null || true
        ;;
    esac
    exit "${exit_code}"
  }
  trap cleanup_speech_runtime_install EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM

  archive="${temporary}/uv.tar.gz"
  /usr/bin/curl \
    --proto '=https' \
    --tlsv1.2 \
    --fail \
    --location \
    --silent \
    --show-error \
    --connect-timeout 30 \
    --max-time 600 \
    --max-filesize 67108864 \
    --output "${archive}" \
    "${SPEECH_UV_ARCHIVE_URL}"
  checksum="$(/usr/bin/sha256sum "${archive}" | awk '{print $1}')"
  [ "${checksum}" = "${SPEECH_UV_ARCHIVE_SHA256}" ] \
    || fail "The downloaded uv archive failed its pinned SHA-256 verification."

  /usr/bin/tar \
    --extract \
    --gzip \
    --file "${archive}" \
    --directory "${temporary}" \
    --strip-components=1 \
    --no-same-owner \
    --no-same-permissions \
    uv-x86_64-unknown-linux-gnu/uv
  uv_binary="${temporary}/uv"
  [ -f "${uv_binary}" ] && [ ! -L "${uv_binary}" ] \
    || fail "The verified uv archive did not contain the expected executable."
  chmod 0755 "${uv_binary}"
  [ "$("${uv_binary}" --version)" = "uv ${SPEECH_UV_VERSION}" ] \
    || fail "The verified uv executable reported an unexpected version."

  uv_environment=(
    "XDG_CACHE_HOME=${DIS_DATA_PATH}/tts/uv-cache/xdg"
    "UV_CACHE_DIR=${DIS_DATA_PATH}/tts/uv-cache"
    "UV_PYTHON_INSTALL_DIR=${DIS_DATA_PATH}/tts/python"
    "UV_PROJECT_ENVIRONMENT=${DIS_DATA_PATH}/tts/runtime"
    "UV_LINK_MODE=copy"
    "UV_MANAGED_PYTHON=1"
    "UV_NO_MODIFY_PATH=1"
    "UV_NO_PROGRESS=1"
  )

  log "Installing the managed Python 3.11 speech runtime"
  log "Speech runtime phase 1/3: installing the pinned Python interpreter"
  runuser -u "${DIS_USER}" -- env "${uv_environment[@]}" \
    "${uv_binary}" python install 3.11
  log "Speech runtime phase 2/3: installing locked CPU-only speech dependencies"
  runuser -u "${DIS_USER}" -- env "${uv_environment[@]}" \
    "${uv_binary}" sync \
      --project "${engine_root}" \
      --locked \
      --no-dev \
      --python 3.11
  log "Speech runtime phase 3/3: installing the pinned VoxCPM package"
  runuser -u "${DIS_USER}" -- env "${uv_environment[@]}" \
    "${uv_binary}" pip install \
      --python "${DIS_DATA_PATH}/tts/runtime/bin/python" \
      --no-deps \
      -r "${engine_root}/model-packages.requirements.txt"

  [ -x "${DIS_DATA_PATH}/tts/runtime/bin/python" ] \
    || fail "The managed speech Python runtime was not installed."
  [ "$(runuser -u "${DIS_USER}" -- "${DIS_DATA_PATH}/tts/runtime/bin/python" \
      -c 'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}")')" = "3.11" ] \
    || fail "The managed speech runtime is not Python 3.11."
  runuser -u "${DIS_USER}" -- env \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONPATH="${engine_root}" \
    "${DIS_DATA_PATH}/tts/runtime/bin/python" -c \
      'import importlib.util; import dis_tts_engine; assert all(importlib.util.find_spec(name) for name in ("torch", "torchaudio", "chatterbox", "voxcpm"))'

  trap - EXIT INT TERM
  rm -rf -- "${temporary}"
)

verify_osrm_admin_runtime_library() {
  local path

  root_owned_runtime_directory_is_safe "${OSRM_ADMIN_RUNTIME_PARENT}" 755 \
    || fail "The OSRM admin runtime parent is not an immutable root-owned directory."
  root_owned_runtime_directory_is_safe "${OSRM_ADMIN_RUNTIME_DIR}" 755 \
    || fail "The OSRM admin runtime bundle is not an immutable root-owned directory."
  for path in common.sh containers.conf osrm.sh secure-path.py dis-osrm.service; do
    root_owned_runtime_file_is_safe "${OSRM_ADMIN_RUNTIME_DIR}/${path}" 644 \
      || fail "Unsafe OSRM admin runtime bundle file: ${path}"
  done
  [ "$(find -P "${OSRM_ADMIN_RUNTIME_DIR}" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort)" = \
    $'common.sh\ncontainers.conf\ndis-osrm.service\nosrm.sh\nsecure-path.py' ] \
    || fail "The OSRM admin runtime bundle contains unexpected entries."
}

verify_existing_osrm_admin_runtime_library_for_upgrade() {
  local entries path

  root_owned_runtime_directory_is_safe "${OSRM_ADMIN_RUNTIME_PARENT}" 755 \
    && root_owned_runtime_directory_is_safe "${OSRM_ADMIN_RUNTIME_DIR}" 755 \
    || fail "The existing OSRM admin runtime directory is unsafe."
  entries="$(find -P "${OSRM_ADMIN_RUNTIME_DIR}" -mindepth 1 -maxdepth 1 \
    -printf '%f\n' | LC_ALL=C sort)"
  case "${entries}" in
    $'common.sh\ndis-osrm.service\nosrm.sh\nsecure-path.py')
      for path in common.sh osrm.sh secure-path.py dis-osrm.service; do
        root_owned_runtime_file_is_safe "${OSRM_ADMIN_RUNTIME_DIR}/${path}" 644 \
          || fail "Unsafe existing OSRM admin runtime file: ${path}"
      done
      ;;
    $'common.sh\ncontainers.conf\ndis-osrm.service\nosrm.sh\nsecure-path.py')
      verify_osrm_admin_runtime_library
      ;;
    *)
      fail "The existing OSRM admin runtime bundle has an unexpected layout."
      ;;
  esac
}

verify_osrm_admin_runtime_bundle() {
  verify_osrm_admin_runtime_library
  root_owned_runtime_file_is_safe "${OSRM_ADMIN_WORKER_PATH}" 755 \
    || fail "The OSRM admin request worker is not an immutable root-owned executable."
}

install_osrm_admin_runtime_bundle() (
  set -euo pipefail

  local app_root="$1" source_path staging worker_staging previous="" worker_previous=""
  local installed_library=0 installed_worker=0

  require_root
  acquire_dis_operation_lock osrm-admin-runtime-install
  if systemd_service_exists dis-osrm-admin-request \
    && systemctl is-active --quiet dis-osrm-admin-request; then
    fail "The OSRM admin worker must be inactive before its runtime bundle is replaced."
  fi
  if systemd_unit_exists dis-osrm-admin-request.path \
    && systemctl is-active --quiet dis-osrm-admin-request.path; then
    fail "The OSRM admin Path unit must be inactive before its runtime bundle is replaced."
  fi
  if systemd_unit_exists dis-osrm-admin-request.timer \
    && systemctl is-active --quiet dis-osrm-admin-request.timer; then
    fail "The OSRM admin Timer unit must be inactive before its runtime bundle is replaced."
  fi
  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would atomically install and verify the root-owned OSRM admin runtime bundle."
    return 0
  fi

  for source_path in \
    "${app_root}/scripts/lib/common.sh" \
    "${app_root}/scripts/lib/secure-path.py" \
    "${app_root}/scripts/osrm-containers.conf" \
    "${app_root}/scripts/osrm.sh" \
    "${app_root}/scripts/osrm-admin-request-worker.sh" \
    "${app_root}/infrastructure/systemd/dis-osrm.service"; do
    root_controlled_bundle_source_is_safe "${source_path}" \
      || fail "Unsafe OSRM admin runtime source: ${source_path}"
  done

  ensure_managed_directory "${OSRM_ADMIN_RUNTIME_PARENT}" root root 0755
  require_root_controlled_parent "${OSRM_ADMIN_WORKER_PATH}"
  staging="$(mktemp -d "${OSRM_ADMIN_RUNTIME_PARENT}/.osrm-admin.XXXXXX")"
  worker_staging="$(mktemp /usr/local/bin/.dis-osrm-admin-request-worker.XXXXXX)"

  cleanup_osrm_admin_bundle_install() {
    local exit_code="$?"
    trap - EXIT INT TERM

    if [ "${exit_code}" -ne 0 ]; then
      if [ "${installed_worker}" = "1" ]; then
        rm -f -- "${OSRM_ADMIN_WORKER_PATH}" 2>/dev/null || true
      fi
      if [ -n "${worker_previous}" ] && { [ -e "${worker_previous}" ] || [ -L "${worker_previous}" ]; }; then
        mv -T -- "${worker_previous}" "${OSRM_ADMIN_WORKER_PATH}" 2>/dev/null || true
      fi
      if [ "${installed_library}" = "1" ] && [ -d "${OSRM_ADMIN_RUNTIME_DIR}" ] && [ ! -L "${OSRM_ADMIN_RUNTIME_DIR}" ]; then
        secure_path_operation remove-tree "${OSRM_ADMIN_RUNTIME_DIR}" >/dev/null 2>&1 || true
      fi
      if [ -n "${previous}" ] && [ -d "${previous}" ] && [ ! -L "${previous}" ]; then
        mv -T -- "${previous}" "${OSRM_ADMIN_RUNTIME_DIR}" 2>/dev/null || true
      fi
    fi

    if [ -n "${staging:-}" ] && [ -d "${staging}" ] && [ ! -L "${staging}" ]; then
      secure_path_operation remove-tree "${staging}" >/dev/null 2>&1 || true
    fi
    rm -f -- "${worker_staging:-}" 2>/dev/null || true
    exit "${exit_code}"
  }
  trap cleanup_osrm_admin_bundle_install EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM

  chown root:root "${staging}"
  chmod 0755 "${staging}"
  install -m 0644 -o root -g root "${app_root}/scripts/lib/common.sh" "${staging}/common.sh"
  install -m 0644 -o root -g root "${app_root}/scripts/lib/secure-path.py" "${staging}/secure-path.py"
  install -m 0644 -o root -g root "${app_root}/scripts/osrm-containers.conf" "${staging}/containers.conf"
  install -m 0644 -o root -g root "${app_root}/scripts/osrm.sh" "${staging}/osrm.sh"
  install -m 0644 -o root -g root "${app_root}/infrastructure/systemd/dis-osrm.service" "${staging}/dis-osrm.service"
  install -m 0755 -o root -g root "${app_root}/scripts/osrm-admin-request-worker.sh" "${worker_staging}"

  for source_path in common.sh containers.conf osrm.sh secure-path.py dis-osrm.service; do
    root_owned_runtime_file_is_safe "${staging}/${source_path}" 644 \
      || fail "Could not stage a safe OSRM admin runtime file: ${source_path}"
  done
  root_owned_runtime_file_is_safe "${worker_staging}" 755 \
    || fail "Could not stage a safe OSRM admin request worker."
  sync -f "${staging}/common.sh" "${staging}/containers.conf" \
    "${staging}/secure-path.py" "${staging}/osrm.sh" \
    "${staging}/dis-osrm.service" "${worker_staging}"
  sync -f "${staging}"

  if [ -e "${OSRM_ADMIN_RUNTIME_DIR}" ] || [ -L "${OSRM_ADMIN_RUNTIME_DIR}" ]; then
    verify_existing_osrm_admin_runtime_library_for_upgrade
    previous="${OSRM_ADMIN_RUNTIME_PARENT}/.osrm-admin.previous.$$.$RANDOM"
    mv -T -- "${OSRM_ADMIN_RUNTIME_DIR}" "${previous}"
  fi
  if [ -e "${OSRM_ADMIN_WORKER_PATH}" ] || [ -L "${OSRM_ADMIN_WORKER_PATH}" ]; then
    root_owned_runtime_file_is_safe "${OSRM_ADMIN_WORKER_PATH}" 755 \
      || fail "Refusing to replace an unsafe OSRM admin worker path."
    worker_previous="/usr/local/bin/.dis-osrm-admin-request-worker.previous.$$.$RANDOM"
    mv -T -- "${OSRM_ADMIN_WORKER_PATH}" "${worker_previous}"
  fi

  mv -T -- "${staging}" "${OSRM_ADMIN_RUNTIME_DIR}"
  staging=""
  installed_library=1
  mv -T -- "${worker_staging}" "${OSRM_ADMIN_WORKER_PATH}"
  worker_staging=""
  installed_worker=1
  verify_osrm_admin_runtime_bundle
  sync -f "${OSRM_ADMIN_RUNTIME_PARENT}" /usr/local/bin

  if [ -n "${previous}" ]; then
    secure_path_operation remove-tree "${previous}"
    previous=""
  fi
  if [ -n "${worker_previous}" ]; then
    rm -f -- "${worker_previous}"
    worker_previous=""
  fi
  installed_library=0
  installed_worker=0
  trap - EXIT INT TERM
)

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

write_wallboard_maintenance_notice() (
  set -euo pipefail

  local kind="$1" estimated_duration_seconds="${2:-}" directory temporary="" started_epoch started_at estimated_completion_at expires_at metadata
  case "${kind}" in
    update|maintenance) ;;
    *) fail "Unsupported wallboard maintenance notice kind: ${kind}" ;;
  esac

  directory="$(dirname "${WALLBOARD_MAINTENANCE_NOTICE_PATH}")"
  ensure_directory "${directory}" root root 0755
  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would publish the ${kind} notice for paired wallboards."
    return 0
  fi

  started_epoch="$(date -u +%s)"
  started_at="$(date -u -d "@${started_epoch}" +%Y-%m-%dT%H:%M:%SZ)"
  expires_at="$(date -u -d "@$((started_epoch + WALLBOARD_MAINTENANCE_NOTICE_TTL_SECONDS))" +%Y-%m-%dT%H:%M:%SZ)"
  temporary="$(mktemp "${directory}/.wallboard-status.XXXXXX")"
  trap 'rm -f -- "${temporary}" 2>/dev/null || true' EXIT
  if [ "${kind}" = "update" ] && [[ "${estimated_duration_seconds}" =~ ^[0-9]+$ ]] \
    && [ "${estimated_duration_seconds}" -ge 180 ] && [ "${estimated_duration_seconds}" -le 2700 ]; then
    estimated_completion_at="$(date -u -d "@$((started_epoch + estimated_duration_seconds))" +%Y-%m-%dT%H:%M:%SZ)"
    printf '{"version":2,"active":true,"kind":"%s","started_at":"%s","estimated_duration_seconds":%s,"estimated_completion_at":"%s","expires_at":"%s"}\n' \
      "${kind}" "${started_at}" "${estimated_duration_seconds}" "${estimated_completion_at}" "${expires_at}" > "${temporary}"
  else
    printf '{"version":1,"active":true,"kind":"%s","started_at":"%s","expires_at":"%s"}\n' \
      "${kind}" "${started_at}" "${expires_at}" > "${temporary}"
  fi
  run_cmd chown root:root "${temporary}"
  run_cmd chmod 0644 "${temporary}"
  run_cmd mv -fT -- "${temporary}" "${WALLBOARD_MAINTENANCE_NOTICE_PATH}"
  temporary=""

  metadata="$(stat -c '%u:%g:%a:%h' -- "${WALLBOARD_MAINTENANCE_NOTICE_PATH}" 2>/dev/null || true)"
  [ "${metadata}" = "0:0:644:1" ] \
    || fail "The wallboard maintenance notice is not safely root-controlled."
  if id www-data >/dev/null 2>&1; then
    require_user_can_open_file_for_reading \
      www-data "${WALLBOARD_MAINTENANCE_NOTICE_PATH}" "the wallboard maintenance notice"
  fi
  trap - EXIT
)

announce_wallboard_maintenance() {
  local kind="$1"
  local estimated_duration_seconds="${2:-}"

  log "Publishing maintenance notice to paired wallboards before service interruption"
  write_wallboard_maintenance_notice "${kind}" "${estimated_duration_seconds}"
  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would wait ${WALLBOARD_MAINTENANCE_NOTICE_SECONDS} seconds for wallboard control polls."
    return 0
  fi
  sleep "${WALLBOARD_MAINTENANCE_NOTICE_SECONDS}"
}

estimate_update_duration_seconds() (
  set +e

  local includes_system_updates="$1" backend_dir estimate fallback option=""
  backend_dir="${DIS_INSTALL_PATH}/webapp/backend"
  if [ "${includes_system_updates}" = "1" ]; then
    fallback=1500
    option="--system"
  else
    fallback=900
  fi

  if [ ! -f "${backend_dir}/artisan" ]; then
    printf '%s\n' "${fallback}"
    return 0
  fi

  if [ -n "${option}" ]; then
    estimate="$(runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" dis:estimate-update-duration "${option}" 2>/dev/null | tail -n 1)"
  else
    estimate="$(runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" dis:estimate-update-duration 2>/dev/null | tail -n 1)"
  fi
  if [[ ! "${estimate}" =~ ^[0-9]+$ ]] || [ "${estimate}" -lt 180 ] || [ "${estimate}" -gt 2700 ]; then
    estimate="${fallback}"
  fi

  printf '%s\n' "${estimate}"
)

clear_wallboard_maintenance_notice() {
  local metadata

  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would clear the wallboard maintenance notice after successful verification."
    return 0
  fi
  if [ -e "${WALLBOARD_MAINTENANCE_NOTICE_PATH}" ] || [ -L "${WALLBOARD_MAINTENANCE_NOTICE_PATH}" ]; then
    metadata="$(stat -c '%u:%g:%a:%h' -- "${WALLBOARD_MAINTENANCE_NOTICE_PATH}" 2>/dev/null || true)"
    [ -f "${WALLBOARD_MAINTENANCE_NOTICE_PATH}" ] \
      && [ ! -L "${WALLBOARD_MAINTENANCE_NOTICE_PATH}" ] \
      && [ "${metadata}" = "0:0:644:1" ] \
      || fail "Refusing to clear an unsafe wallboard maintenance notice."
    run_cmd rm -f -- "${WALLBOARD_MAINTENANCE_NOTICE_PATH}"
  fi
}

publish_maintenance_page_file() (
  set -euo pipefail

  local temporary="$1" page_path="$2" metadata size
  [ -f "${temporary}" ] && [ ! -L "${temporary}" ] \
    || fail "The generated maintenance page is not a safe regular file."
  size="$(stat -c '%s' -- "${temporary}" 2>/dev/null || true)"
  [[ "${size}" =~ ^[0-9]+$ ]] && [ "${size}" -ge 1 ] && [ "${size}" -le 262144 ] \
    || fail "The generated maintenance page has an invalid size."

  run_cmd chown root:root "${temporary}"
  run_cmd chmod 0644 "${temporary}"
  run_cmd mv -fT -- "${temporary}" "${page_path}"

  metadata="$(stat -c '%u:%g:%a:%h' -- "${page_path}" 2>/dev/null || true)"
  [ -f "${page_path}" ] \
    && [ ! -L "${page_path}" ] \
    && [ "${metadata}" = "0:0:644:1" ] \
    || fail "The standalone maintenance page is not safely root-controlled."
)

write_maintenance_page() (
  set -euo pipefail

  local page_path template_path renderer_path temporary=""
  page_path="${DIS_INSTALL_PATH}/maintenance/__dis_maintenance.html"
  template_path="${DIS_INSTALL_PATH}/webapp/backend/resources/views/errors/503.blade.php"
  renderer_path="${DIS_INSTALL_PATH}/scripts/render-maintenance-page.php"

  ensure_directory "$(dirname "${page_path}")" root root 0755
  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would render the standalone maintenance page from the shared wallboard-style template."
    return 0
  fi

  require_file "${template_path}"
  require_file "${renderer_path}"
  command -v php >/dev/null 2>&1 || fail "PHP is required to render the maintenance page."

  temporary="$(mktemp "$(dirname "${page_path}")/.dis-maintenance-page.XXXXXX")"
  trap 'rm -f -- "${temporary}" 2>/dev/null || true' EXIT
  if ! php "${renderer_path}" "${template_path}" "${WALLBOARD_MAINTENANCE_NOTICE_PATH}" > "${temporary}"; then
    fail "The standalone maintenance page could not be rendered safely."
  fi

  publish_maintenance_page_file "${temporary}" "${page_path}"
  temporary=""
  trap - EXIT
)

write_bootstrap_maintenance_page() (
  set -euo pipefail

  local page_path temporary=""
  page_path="${DIS_INSTALL_PATH}/maintenance/__dis_maintenance.html"
  ensure_directory "$(dirname "${page_path}")" root root 0755
  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would publish the CSP-neutral bootstrap maintenance page."
    return 0
  fi

  temporary="$(mktemp "$(dirname "${page_path}")/.dis-maintenance-bootstrap.XXXXXX")"
  trap 'rm -f -- "${temporary}" 2>/dev/null || true' EXIT
  cat > "${temporary}" <<'HTML'
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="20">
  <title>D.I.S. onderhoud</title>
</head>
<body>
  <main>
    <p>D.I.S. · Drone Inzet Systeem</p>
    <h1>Systeemonderhoud wordt voorbereid</h1>
  </main>
</body>
</html>
HTML
  publish_maintenance_page_file "${temporary}" "${page_path}"
  temporary=""
  trap - EXIT
)

enable_frontend_maintenance() {
  local page_mode="${1:-full}"
  case "${page_mode}" in
    full) write_maintenance_page ;;
    bootstrap) write_bootstrap_maintenance_page ;;
    *) fail "Unsupported frontend maintenance page mode: ${page_mode}" ;;
  esac
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

backend_maintenance_framework_directory() {
  local backend_dir="$1"
  local expected="${DIS_DATA_PATH}/webapp/backend/storage/framework"
  local linked resolved_expected

  ensure_managed_directory "${expected}" root "${DIS_GROUP}" 0750
  linked="$(readlink -f -- "${backend_dir}/storage/framework" 2>/dev/null || true)"
  resolved_expected="$(readlink -f -- "${expected}" 2>/dev/null || true)"
  [ -n "${linked}" ] && [ "${linked}" = "${resolved_expected}" ] \
    || fail "Backend maintenance storage does not resolve to the managed runtime path."
  printf '%s\n' "${resolved_expected}"
}

remove_backend_maintenance_file() {
  local path="$1"

  if [ -e "${path}" ] || [ -L "${path}" ]; then
    [ -f "${path}" ] && [ ! -L "${path}" ] \
      && [ "$(stat -c '%h' -- "${path}" 2>/dev/null || true)" = "1" ] \
      || fail "Unsafe Laravel maintenance file: ${path}"
    run_cmd rm -f -- "${path}"
  fi
}

stage_backend_maintenance_files() {
  local backend_dir="$1"
  local framework_dir name path

  framework_dir="$(backend_maintenance_framework_directory "${backend_dir}")"
  for name in down maintenance.php; do
    path="${framework_dir}/${name}"
    remove_backend_maintenance_file "${path}"
    run_cmd install -m 0600 -o "${DIS_USER}" -g "${DIS_GROUP}" /dev/null "${path}"
  done
}

finalize_backend_maintenance_files() {
  local backend_dir="$1"
  local framework_dir name path metadata

  framework_dir="$(backend_maintenance_framework_directory "${backend_dir}")"
  for name in down maintenance.php; do
    path="${framework_dir}/${name}"
    metadata="$(stat -c '%U:%G:%a:%h' -- "${path}" 2>/dev/null || true)"
    [ "${metadata}" = "${DIS_USER}:${DIS_GROUP}:600:1" ] \
      || fail "Laravel did not create a safe managed maintenance file: ${path}"
    run_cmd chown root:root "${path}"
    run_cmd chmod 0600 "${path}"
    if id www-data >/dev/null 2>&1; then
      run_cmd setfacl -m "u:www-data:r--" "${path}"
      require_user_can_open_file_for_reading \
        www-data "${path}" "the Laravel ${name} maintenance file"
    fi
  done
}

clear_backend_maintenance_files() {
  local backend_dir="$1"
  local framework_dir name

  framework_dir="$(backend_maintenance_framework_directory "${backend_dir}")"
  for name in down maintenance.php; do
    remove_backend_maintenance_file "${framework_dir}/${name}"
  done
}

enable_backend_deployment_maintenance() {
  local backend_dir="${1:-${DIS_INSTALL_PATH}/webapp/backend}"

  if [ -f "${backend_dir}/artisan" ] && [ -f "${backend_dir}/vendor/autoload.php" ]; then
    log "Putting the backend in maintenance mode"
    if [ "${DRY_RUN:-0}" = "1" ]; then
      log "Would stage managed Laravel maintenance files."
      run_cmd runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" down --render="errors::503"
      return 0
    fi
    stage_backend_maintenance_files "${backend_dir}"
    (
      umask 0077
      run_cmd runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" down --render="errors::503"
    )
    finalize_backend_maintenance_files "${backend_dir}"
  fi
}

enable_deployment_maintenance() {
  local backend_dir="${1:-${DIS_INSTALL_PATH}/webapp/backend}"

  enable_frontend_maintenance
  enable_backend_deployment_maintenance "${backend_dir}"
}

prepare_backend_for_deployment_verification() {
  local backend_dir="${1:-${DIS_INSTALL_PATH}/webapp/backend}"

  if [ -f "${backend_dir}/artisan" ] && [ -f "${backend_dir}/vendor/autoload.php" ]; then
    log "Bringing the backend up behind the deployment maintenance lock"
    if [ "${DRY_RUN:-0}" = "1" ]; then
      log "Would clear managed Laravel maintenance files."
      run_cmd runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" up
      return 0
    fi
    clear_backend_maintenance_files "${backend_dir}"
    run_cmd runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" up
  fi
}

complete_deployment_maintenance() {
  local backend_dir="${1:-${DIS_INSTALL_PATH}/webapp/backend}"

  # Keep Nginx fail-closed until Laravel has successfully left maintenance mode.
  prepare_backend_for_deployment_verification "${backend_dir}"
  clear_wallboard_maintenance_notice
  disable_frontend_maintenance
}

stop_dis_deployment_services() {
  local service worker_state worker_wait_deadline

  log "Stopping DIS workers, realtime and frontend services for deployment"
  if systemd_unit_exists dis-osrm-admin-request.timer; then
    run_cmd systemctl stop dis-osrm-admin-request.timer
  fi
  if systemd_unit_exists dis-osrm-admin-request.path; then
    run_cmd systemctl stop dis-osrm-admin-request.path
  fi
  if systemd_service_exists dis-osrm-admin-request; then
    # A Path unit can start the oneshot just before deployment acquires the
    # global operation lock. It then exits without claiming a request. Stop new
    # starts above and wait briefly instead of killing or misclassifying it.
    worker_wait_deadline=$((SECONDS + 60))
    while true; do
      worker_state="$(systemctl show dis-osrm-admin-request --property=ActiveState --value 2>/dev/null || true)"
      case "${worker_state}" in
        active|activating|deactivating|reloading)
          if [ "${SECONDS}" -ge "${worker_wait_deadline}" ]; then
            fail "DIS OSRM admin request worker did not become idle within 60 seconds; deployment was not allowed to interrupt it."
          fi
          sleep 1
          ;;
        failed)
          run_cmd systemctl reset-failed dis-osrm-admin-request
          break
          ;;
        inactive)
          break
          ;;
        *)
          fail "Could not determine a safe idle state for dis-osrm-admin-request.service (state: ${worker_state:-unknown})."
          ;;
      esac
    done
  fi
  if systemd_unit_exists dis-backup-request.timer; then
    run_cmd systemctl stop dis-backup-request.timer
  fi
  if systemd_unit_exists dis-backup-request.path; then
    run_cmd systemctl stop dis-backup-request.path
  fi
  if systemd_service_exists dis-backup-request; then
    # The root worker may already have atomically claimed a request. Killing it
    # would strand that request in backup-request-work, and can interrupt restore
    # preflight. New requests are blocked above; let the current request finish.
    worker_wait_deadline=$((SECONDS + 360))
    while true; do
      worker_state="$(systemctl show dis-backup-request --property=ActiveState --value 2>/dev/null || true)"
      case "${worker_state}" in
        active|activating|deactivating|reloading)
          if [ "${SECONDS}" -ge "${worker_wait_deadline}" ]; then
            fail "DIS backup request worker did not become idle within 360 seconds; deployment was not allowed to interrupt it."
          fi
          sleep 1
          ;;
        failed)
          log "Resetting an inactive failed DIS backup request worker before deployment"
          run_cmd systemctl reset-failed dis-backup-request
          break
          ;;
        inactive)
          break
          ;;
        *)
          fail "Could not determine a safe idle state for dis-backup-request.service (state: ${worker_state:-unknown})."
          ;;
      esac
    done
  fi
  # Give the dedicated speech worker its bounded cleanup window before stopping
  # its local engine. Systemd then kills the isolated cgroup; atomic staging and
  # the long Redis lease make interrupted reproducible work safely retryable.
  if systemd_service_exists dis-speech; then
    run_cmd systemctl stop dis-speech
  fi
  if systemd_service_exists dis-tts-engine; then
    run_cmd systemctl stop dis-tts-engine
  fi
  # Stop the interruptible media worker next. Its SIGTERM contract republishes
  # an in-flight transcode before the remaining deployment services go down.
  for service in dis-media dis-queue dis-scheduler dis-websocket dis-frontend dis-incident-enrichment dis-knmi dis-knmi-realtime "${PHP_FPM_SERVICE}"; do
    if systemd_service_exists "${service}"; then
      run_cmd systemctl stop "${service}"
    fi
  done
}

restart_dis_web_services_for_verification() {
  local service

  require_dis_frontend_release_artifacts

  # Nginx must load the maintenance-aware configuration before Laravel is
  # brought back up for readiness checks.
  for service in nginx "${PHP_FPM_SERVICE}" dis-frontend; do
    if systemd_service_exists "${service}"; then
      run_cmd systemctl restart "${service}"
    fi
  done
}

require_dis_frontend_release_artifacts() {
  local frontend_dir="${1:-${DIS_INSTALL_PATH}/webapp/frontend}"
  local path

  [ -x /usr/bin/node ] || fail "The frontend runtime is missing: /usr/bin/node is not executable."
  for path in \
    "${frontend_dir}/package.json" \
    "${frontend_dir}/.next/BUILD_ID" \
    "${frontend_dir}/node_modules/next/dist/bin/next"; do
    if [ ! -f "${path}" ] || [ -L "${path}" ]; then
      fail "The current frontend release is not restartable; required artifact is missing or unsafe: ${path}"
    fi
    require_user_can_open_file_for_reading \
      "${DIS_USER}" "${path}" "a required frontend release artifact"
  done
  for path in "${frontend_dir}/.next/server" "${frontend_dir}/.next/static"; do
    if [ ! -d "${path}" ] || [ -L "${path}" ]; then
      fail "The current frontend release is not restartable; required directory is missing or unsafe: ${path}"
    fi
    require_user_can_open_directory_for_reading \
      "${DIS_USER}" "${path}" "a required frontend release directory"
  done
}

report_systemd_service_failure() {
  local service="$1"

  printf '[dis:error] systemd diagnostics for %s.service:\n' "${service}" >&2
  systemctl show "${service}.service" --no-pager \
    --property=ActiveState,SubState,Result,ExecMainCode,ExecMainStatus,NRestarts >&2 2>/dev/null || true
  systemctl status "${service}.service" --no-pager --full --lines=20 >&2 2>/dev/null || true
}

wait_for_systemd_service_stable() {
  local service="$1" timeout_seconds="${2:-30}" required_samples="${3:-2}"
  local deadline stable_samples=0

  [[ "${timeout_seconds}" =~ ^[1-9][0-9]*$ ]] \
    || fail "Invalid systemd readiness timeout: ${timeout_seconds}"
  [[ "${required_samples}" =~ ^[1-9][0-9]*$ ]] \
    || fail "Invalid systemd stability sample count: ${required_samples}"
  deadline=$((SECONDS + timeout_seconds))

  while [ "${SECONDS}" -lt "${deadline}" ]; do
    if systemctl is-active --quiet "${service}.service"; then
      stable_samples=$((stable_samples + 1))
      if [ "${stable_samples}" -ge "${required_samples}" ]; then
        return 0
      fi
    else
      stable_samples=0
    fi
    sleep 1
  done

  report_systemd_service_failure "${service}"
  return 1
}

wait_for_dis_speech_engine_readiness() {
  local timeout_seconds="${1:-30}" required_samples="${2:-2}"
  local deadline socket_path="/run/dis-tts/engine.sock"

  wait_for_systemd_service_stable dis-tts-engine "${timeout_seconds}" "${required_samples}" \
    || return 1
  deadline=$((SECONDS + timeout_seconds))
  while [ "${SECONDS}" -lt "${deadline}" ]; do
    if [ -S "${socket_path}" ]; then
      return 0
    fi
    if ! systemctl is-active --quiet dis-tts-engine.service; then
      break
    fi
    sleep 1
  done

  report_systemd_service_failure dis-tts-engine
  return 1
}

wait_for_dis_frontend_http_readiness() {
  local timeout_seconds="${1:-30}" required_samples="${2:-2}"
  local deadline stable_samples=0 status_code

  command -v curl >/dev/null 2>&1 || fail "curl is required for frontend readiness checks."
  [[ "${timeout_seconds}" =~ ^[1-9][0-9]*$ ]] \
    || fail "Invalid frontend readiness timeout: ${timeout_seconds}"
  [[ "${required_samples}" =~ ^[1-9][0-9]*$ ]] \
    || fail "Invalid frontend stability sample count: ${required_samples}"
  deadline=$((SECONDS + timeout_seconds))

  while [ "${SECONDS}" -lt "${deadline}" ]; do
    status_code=""
    if systemctl is-active --quiet dis-frontend.service; then
      status_code="$(curl --silent --output /dev/null --write-out '%{http_code}' \
        --noproxy '*' --connect-timeout 2 --max-time 5 \
        http://127.0.0.1:3000/login 2>/dev/null || true)"
    fi
    if [[ "${status_code}" =~ ^[23][0-9][0-9]$ ]]; then
      stable_samples=$((stable_samples + 1))
      if [ "${stable_samples}" -ge "${required_samples}" ]; then
        return 0
      fi
    else
      stable_samples=0
    fi
    sleep 1
  done

  report_systemd_service_failure dis-frontend
  return 1
}

start_dis_operational_services() {
  local service

  log "Starting DIS workers and realtime services"
  if systemd_unit_exists dis-osrm-admin-request.path; then
    run_cmd systemctl start dis-osrm-admin-request.path
  fi
  if systemd_unit_exists dis-osrm-admin-request.timer; then
    run_cmd systemctl start dis-osrm-admin-request.timer
  fi
  if systemd_unit_exists dis-backup-request.path; then
    run_cmd systemctl start dis-backup-request.path
  fi
  if systemd_unit_exists dis-backup-request.timer; then
    run_cmd systemctl start dis-backup-request.timer
  fi
  # Verify the privileged broker before the scheduler can enqueue a long-running
  # automatic backup ahead of this readiness probe. Restore already runs inside
  # the same single worker and therefore explicitly skips this recursive probe.
  if [ "${DIS_SKIP_BACKUP_REQUEST_PROBE:-0}" != "1" ]; then
    run_cmd runuser -u "${DIS_USER}" -- php "${DIS_INSTALL_PATH}/webapp/backend/artisan" \
      dis:check-backup-request-worker --timeout=30
  fi
  if systemd_service_exists dis-tts-engine; then
    run_cmd systemctl start dis-tts-engine
    wait_for_dis_speech_engine_readiness 30 2 \
      || fail "The speech engine did not expose its socket before the speech worker start."
  fi
  if systemd_service_exists dis-speech; then
    run_cmd systemctl start dis-speech
  fi
  for service in dis-media dis-queue dis-scheduler dis-websocket dis-incident-enrichment dis-knmi dis-knmi-realtime; do
    if systemd_service_exists "${service}"; then
      run_cmd systemctl start "${service}"
    fi
  done
}

require_dis_web_services() {
  local service

  for service in nginx "${PHP_FPM_SERVICE}" dis-frontend; do
    if ! systemd_service_exists "${service}"; then
      fail "Required systemd service is not installed: ${service}.service"
    fi
    if ! wait_for_systemd_service_stable "${service}"; then
      fail "Required systemd service did not become stably active: ${service}.service"
    fi
  done
  if ! wait_for_dis_frontend_http_readiness; then
    fail "DIS frontend did not become HTTP-ready on 127.0.0.1:3000."
  fi
}

require_dis_runtime_services() {
  local service

  for service in nginx "${PHP_FPM_SERVICE}" dis-frontend dis-tts-engine dis-speech dis-queue dis-media dis-scheduler dis-websocket dis-incident-enrichment dis-knmi dis-knmi-realtime; do
    if ! systemd_service_exists "${service}"; then
      fail "Required systemd service is not installed: ${service}.service"
    fi
    if ! wait_for_systemd_service_stable "${service}"; then
      fail "Required systemd service did not become stably active: ${service}.service"
    fi
  done
  if ! wait_for_dis_frontend_http_readiness; then
    fail "DIS frontend did not remain HTTP-ready on 127.0.0.1:3000."
  fi
  if ! systemd_unit_exists dis-backup-request.path; then
    fail "Required systemd unit is not installed: dis-backup-request.path"
  fi
  if ! systemctl is-active --quiet dis-backup-request.path; then
    fail "Required systemd unit is not active: dis-backup-request.path"
  fi
  if ! systemd_unit_exists dis-backup-request.timer; then
    fail "Required systemd unit is not installed: dis-backup-request.timer"
  fi
  if ! systemctl is-active --quiet dis-backup-request.timer; then
    fail "Required systemd unit is not active: dis-backup-request.timer"
  fi
  if ! systemd_unit_exists dis-osrm-admin-request.path; then
    fail "Required systemd unit is not installed: dis-osrm-admin-request.path"
  fi
  if ! systemctl is-enabled --quiet dis-osrm-admin-request.path; then
    fail "Required systemd unit is not enabled: dis-osrm-admin-request.path"
  fi
  if ! systemctl is-active --quiet dis-osrm-admin-request.path; then
    fail "Required systemd unit is not active: dis-osrm-admin-request.path"
  fi
  if ! systemd_unit_exists dis-osrm-admin-request.timer; then
    fail "Required systemd unit is not installed: dis-osrm-admin-request.timer"
  fi
  if ! systemctl is-enabled --quiet dis-osrm-admin-request.timer; then
    fail "Required systemd unit is not enabled: dis-osrm-admin-request.timer"
  fi
  if ! systemctl is-active --quiet dis-osrm-admin-request.timer; then
    fail "Required systemd unit is not active: dis-osrm-admin-request.timer"
  fi
  verify_osrm_admin_runtime_bundle
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
  run_cmd install -m 0644 "${app_root}/infrastructure/systemd/dis-backup-request.timer" /etc/systemd/system/dis-backup-request.timer
  run_cmd rm -f -- "${temporary_service}" "${temporary_path}"
}

require_user_can_open_file_for_reading() {
  local user="$1" path="$2" description="$3"

  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would verify that ${user} can open ${description} for reading."
    return 0
  fi
  if ! runuser -u "${user}" -- /usr/bin/dd \
    "if=${path}" of=/dev/null bs=1 count=1 status=none; then
    fail "${user} cannot open ${description} for reading: ${path}"
  fi
}

require_user_can_open_directory_for_reading() {
  local user="$1" path="$2" description="$3"

  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would verify that ${user} can open ${description} for reading."
    return 0
  fi
  if ! runuser -u "${user}" -- /usr/bin/find "${path}" \
    -mindepth 1 -maxdepth 1 -print -quit >/dev/null; then
    fail "${user} cannot open ${description} for reading: ${path}"
  fi
  if ! runuser -u "${user}" -- /bin/sh -c 'cd -- "$1"' sh "${path}"; then
    fail "${user} cannot enter ${description}: ${path}"
  fi
}

require_user_cannot_open_file_for_writing() {
  local user="$1" path="$2" description="$3"

  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would verify that ${user} cannot open ${description} for writing."
    return 0
  fi
  if runuser -u "${user}" -- /usr/bin/dd \
    if=/dev/null "of=${path}" bs=1 count=0 conv=notrunc oflag=nofollow status=none \
    2>/dev/null; then
    fail "${user} unexpectedly can open ${description} for writing: ${path}"
  fi
}

require_user_cannot_create_file_in_directory() {
  local user="$1" path="$2" description="$3" probe=""

  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would verify that ${user} cannot create a file in ${description}."
    return 0
  fi
  if probe="$(runuser -u "${user}" -- /usr/bin/mktemp \
    -p "${path}" .dis-permission-probe.XXXXXXXX 2>/dev/null)"; then
    case "${probe}" in
      "${path}"/.dis-permission-probe.*) rm -f -- "${probe}" ;;
      *) fail "The ${description} write probe returned an unsafe path." ;;
    esac
    fail "${user} unexpectedly can create files in ${description}: ${path}"
  fi
}

reconcile_backend_source_permissions() {
  local backend_dir="$1"
  local source_file source_root

  # A root Git checkout inherits the caller's umask. Under 0027/0077, files
  # replaced by an update can therefore become unreadable to the managed PHP
  # identity even though their Git mode is 100644. Repair only immutable
  # Laravel source roots; runtime storage, vendor and bootstrap/cache retain
  # their dedicated ownership and ACL contracts below.
  ensure_managed_directory "${backend_dir%/*}" root root 0755
  ensure_managed_directory "${backend_dir}" root root 0755
  ensure_managed_directory "${backend_dir}/bootstrap" root root 0755
  for source_root in app config database public resources routes; do
    [ -d "${backend_dir}/${source_root}" ] && [ ! -L "${backend_dir}/${source_root}" ] \
      || fail "Backend source root is missing or unsafe: ${source_root}"
    repair_managed_tree "${backend_dir}/${source_root}" root root 0755 0644
  done

  for source_file in \
    artisan \
    composer.json \
    composer.lock \
    bootstrap/app.php \
    bootstrap/providers.php; do
    root_controlled_bundle_source_is_safe "${backend_dir}/${source_file}" \
      || fail "Backend source file is not safely root-controlled: ${source_file}"
    run_cmd chown root:root "${backend_dir}/${source_file}"
    run_cmd chmod 0644 "${backend_dir}/${source_file}"
    root_owned_runtime_file_is_safe "${backend_dir}/${source_file}" 644 \
      || fail "Backend source file permissions could not be reconciled: ${source_file}"
  done

  require_user_can_open_file_for_reading \
    "${DIS_USER}" "${backend_dir}/config/dis.php" "the Laravel DIS configuration source"
  if id www-data >/dev/null 2>&1; then
    require_user_can_open_file_for_reading \
      www-data "${backend_dir}/config/dis.php" "the Laravel DIS configuration source"
  fi
}

reconcile_backend_generated_cache_permissions() {
  local backend_dir="$1"
  local cache_dir="${backend_dir}/bootstrap/cache"

  ensure_managed_directory "${cache_dir}" "${DIS_USER}" "${DIS_GROUP}" 0770
  repair_managed_tree "${cache_dir}" "${DIS_USER}" "${DIS_GROUP}" 0770 0660
  if id www-data >/dev/null 2>&1; then
    secure_path_operation acl-tree "${cache_dir}" www-data r-x r--
  fi
}

invalidate_backend_generated_cache() {
  local backend_dir="$1"
  local cache_dir="${backend_dir}/bootstrap/cache"

  reconcile_backend_generated_cache_permissions "${backend_dir}"
  # Never boot a new source/vendor combination through executable config,
  # route, event or provider caches left by the previous release.
  run_cmd rm -f -- "${cache_dir}/"*.php
}

regenerate_backend_package_manifest() {
  local backend_dir="$1"
  local cache_dir="${backend_dir}/bootstrap/cache"
  local manifest manifest_name

  require_file "${backend_dir}/artisan"
  require_file "${backend_dir}/vendor/autoload.php"

  # An earlier root invocation may have generated a manifest using a caller
  # umask that prevents the managed application identity from bootstrapping.
  invalidate_backend_generated_cache "${backend_dir}"
  run_cmd runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" package:discover --ansi

  # Laravel atomically replaces this file and derives its mode from umask().
  # Reconcile both the mode and ACL after the replacement, independent of the
  # interactive shell or service environment that launched the lifecycle.
  reconcile_backend_generated_cache_permissions "${backend_dir}"
  for manifest_name in packages.php services.php; do
    manifest="${cache_dir}/${manifest_name}"
    require_file "${manifest}"
    if id www-data >/dev/null 2>&1; then
      require_user_can_open_file_for_reading \
        www-data "${manifest}" "the generated Laravel ${manifest_name} manifest"
      require_user_cannot_open_file_for_writing \
        www-data "${manifest}" "the generated Laravel ${manifest_name} manifest"
    fi
  done
  if id www-data >/dev/null 2>&1; then
    require_user_cannot_create_file_in_directory \
      www-data "${cache_dir}" "the generated Laravel cache directory"
  fi
}

backend_dependency_state_is_current() {
  local backend_dir="$1"
  local lock_file="${backend_dir}/composer.lock"
  local marker="${backend_dir}/vendor/.dis-composer-lock.sha256"
  local actual_digest recorded_digest

  root_controlled_bundle_source_is_safe "${lock_file}" || return 1
  root_owned_runtime_file_is_safe "${backend_dir}/vendor/autoload.php" 644 || return 1
  root_owned_runtime_file_is_safe "${marker}" 644 || return 1

  actual_digest="$(/usr/bin/sha256sum -- "${lock_file}")"
  actual_digest="${actual_digest%% *}"
  recorded_digest="$(tr -d '\r\n' < "${marker}")"
  [[ "${actual_digest}" =~ ^[a-f0-9]{64}$ ]] \
    && [ "${recorded_digest}" = "${actual_digest}" ]
}

record_backend_dependency_state() (
  set -euo pipefail

  local backend_dir="$1"
  local lock_file="${backend_dir}/composer.lock"
  local marker="${backend_dir}/vendor/.dis-composer-lock.sha256"
  local actual_digest temporary=""

  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would record the verified Composer dependency state."
    return 0
  fi

  require_file "${backend_dir}/vendor/autoload.php"
  root_controlled_bundle_source_is_safe "${lock_file}" \
    || fail "composer.lock must remain root-controlled before recording dependency state."

  actual_digest="$(/usr/bin/sha256sum -- "${lock_file}")"
  actual_digest="${actual_digest%% *}"
  [[ "${actual_digest}" =~ ^[a-f0-9]{64}$ ]] \
    || fail "Could not calculate the Composer lock digest."

  temporary="$(mktemp "${backend_dir}/vendor/.dis-composer-lock.sha256.XXXXXX")"
  trap 'rm -f -- "${temporary}" 2>/dev/null || true' EXIT
  printf '%s\n' "${actual_digest}" > "${temporary}"
  run_cmd chown root:root "${temporary}"
  run_cmd chmod 0644 "${temporary}"
  run_cmd mv -fT -- "${temporary}" "${marker}"
  temporary=""
  root_owned_runtime_file_is_safe "${marker}" 644 \
    || fail "The Composer dependency state marker is unsafe."
  trap - EXIT
)

install_osrm_admin_layout() {
  local status_path="/var/log/dis/osrm-status.json"

  ensure_managed_directory "${DIS_DATA_PATH}/osrm-admin" root root 0750
  ensure_managed_directory "${DIS_DATA_PATH}/osrm-admin/requests" root root 1730
  ensure_managed_directory "${DIS_DATA_PATH}/osrm-admin/work" root root 0700
  ensure_managed_directory "${DIS_DATA_PATH}/osrm-admin/results" root "${DIS_GROUP}" 0750
  ensure_directory /var/log/dis root "${DIS_GROUP}" 0750
  if [ ! -e "${status_path}" ] && [ ! -L "${status_path}" ]; then
    run_cmd install -m 0640 -o root -g "${DIS_GROUP}" /dev/null "${status_path}"
  fi
  if [ -e "${status_path}" ] || [ -L "${status_path}" ]; then
    [ -f "${status_path}" ] && [ ! -L "${status_path}" ] \
      && [ "$(stat -c '%h' -- "${status_path}")" = "1" ] \
      || fail "The OSRM status snapshot path is unsafe."
    run_cmd chown root:"${DIS_GROUP}" "${status_path}"
    run_cmd chmod 0640 "${status_path}"
  fi
  if id www-data >/dev/null 2>&1; then
    run_cmd setfacl -m "u:www-data:r-x" /var/log/dis
    run_cmd setfacl -m "u:www-data:r--" "${status_path}"
    run_cmd setfacl -m "u:www-data:--x" "${DIS_DATA_PATH}" "${DIS_DATA_PATH}/osrm-admin"
    run_cmd setfacl -m "u:www-data:-wx" "${DIS_DATA_PATH}/osrm-admin/requests"
    run_cmd setfacl -m "u:www-data:r-x" "${DIS_DATA_PATH}/osrm-admin/results"
    run_cmd setfacl -x "d:u:www-data" "${DIS_DATA_PATH}/osrm-admin/requests" 2>/dev/null || true
    require_user_can_open_file_for_reading www-data "${status_path}" "the OSRM status snapshot"
    require_user_can_open_directory_for_reading www-data \
      "${DIS_DATA_PATH}/osrm-admin/results" "the OSRM result directory"
    require_user_cannot_open_file_for_writing www-data \
      "${status_path}" "the OSRM status snapshot"
    require_user_cannot_create_file_in_directory www-data \
      "${DIS_DATA_PATH}/osrm-admin/results" "the OSRM result directory"
  fi
  if id "${DIS_USER}" >/dev/null 2>&1; then
    run_cmd setfacl -m "u:${DIS_USER}:--x" "${DIS_DATA_PATH}/osrm-admin"
    run_cmd setfacl -m "u:${DIS_USER}:r-x" "${DIS_DATA_PATH}/osrm-admin/results"
    require_user_can_open_file_for_reading "${DIS_USER}" "${status_path}" "the OSRM status snapshot"
    require_user_can_open_directory_for_reading "${DIS_USER}" \
      "${DIS_DATA_PATH}/osrm-admin/results" "the OSRM result directory"
    require_user_cannot_open_file_for_writing "${DIS_USER}" \
      "${status_path}" "the OSRM status snapshot"
    require_user_cannot_create_file_in_directory "${DIS_USER}" \
      "${DIS_DATA_PATH}/osrm-admin/results" "the OSRM result directory"
  fi
}

install_osrm_admin_request_systemd_units() {
  local app_root="$1" escaped_app_root escaped_data_path temporary_path temporary_service

  escaped_app_root="$(printf '%s' "${app_root}" | sed 's/[&|\\]/\\&/g')"
  escaped_data_path="$(printf '%s' "${DIS_DATA_PATH}" | sed 's/[&|\\]/\\&/g')"
  temporary_service="$(mktemp /run/dis-osrm-admin-request.service.XXXXXX)"
  temporary_path="$(mktemp /run/dis-osrm-admin-request.path.XXXXXX)"
  sed -e "s|@APP_ROOT@|${escaped_app_root}|g" \
    -e "s|@DIS_DATA_PATH@|${escaped_data_path}|g" \
    "${app_root}/infrastructure/systemd/dis-osrm-admin-request.service" > "${temporary_service}"
  sed "s|@DIS_DATA_PATH@|${escaped_data_path}|g" \
    "${app_root}/infrastructure/systemd/dis-osrm-admin-request.path" > "${temporary_path}"
  run_cmd install -m 0644 "${temporary_service}" /etc/systemd/system/dis-osrm-admin-request.service
  run_cmd install -m 0644 "${temporary_path}" /etc/systemd/system/dis-osrm-admin-request.path
  run_cmd install -m 0644 \
    "${app_root}/infrastructure/systemd/dis-osrm-admin-request.timer" \
    /etc/systemd/system/dis-osrm-admin-request.timer
  run_cmd rm -f -- "${temporary_service}" "${temporary_path}"
}

remove_legacy_backup_entrypoints() {
  if systemd_unit_exists dis-backup-mount.service; then
    run_cmd systemctl disable --now dis-backup-mount.service >/dev/null 2>&1 || true
  fi

  run_cmd rm -f -- \
    /etc/systemd/system/dis-backup-mount.service \
    /usr/local/bin/dis-backup-mount \
    /usr/local/bin/dis-backup-verify \
    /usr/local/bin/dis-backup-restore
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

  if [ -L "${config_file}" ]; then
    fail "Backup runtime configuration must be a regular file."
  fi
  if [ ! -e "${config_file}" ]; then
    return 0
  fi
  if [ ! -f "${config_file}" ]; then
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
    and all(to_entries[];
      if .key == "BACKUP_RETENTION_COUNT" and (.value | type) == "number"
      then .value >= 0 and .value <= 365 and .value == (.value | floor)
      else (.value | type) == "string"
        and (.value | length) <= 4096
        and (.value | test("^[^\\u0000-\\u001F\\u007F]*$"))
      end
    )
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

safe_local_backup_retention_count() {
  local config_file="$1"
  local retention="0"

  if ! retention="$(
    {
      unset BACKUP_RETENTION_COUNT
      load_backup_runtime_config "${config_file}"
      printf '%s\n' "${BACKUP_RETENTION_COUNT:-0}"
    } 2>/dev/null
  )"; then
    retention=0
  fi

  if [[ "${retention}" =~ ^[0-9]{1,3}$ ]] && [ "$((10#${retention}))" -le 365 ]; then
    printf '%s\n' "$((10#${retention}))"
  else
    printf '0\n'
  fi
}

load_backup_runtime_config_for_operation() {
  local config_file="$1"
  local safe_local="${DIS_SAFE_LOCAL_BACKUP:-0}"
  local pre_update_safe_local="${DIS_SAFE_LOCAL_PREUPDATE_BACKUP:-0}"

  case "${safe_local}" in
    0|1) ;;
    *) fail "DIS_SAFE_LOCAL_BACKUP must be 0 or 1." ;;
  esac
  case "${pre_update_safe_local}" in
    0|1) ;;
    *) fail "DIS_SAFE_LOCAL_PREUPDATE_BACKUP must be 0 or 1." ;;
  esac

  if [ "${safe_local}" = "1" ] || [ "${pre_update_safe_local}" = "1" ]; then
    log "Using isolated local backup configuration."
    DIS_EFFECTIVE_SAFE_LOCAL_BACKUP=1
    BACKUP_TARGET=local
    BACKUP_ROOT="${DIS_DATA_PATH}/backup"
    if [ "${pre_update_safe_local}" = "1" ]; then
      BACKUP_RETENTION_COUNT=0
    else
      BACKUP_RETENTION_COUNT="$(safe_local_backup_retention_count "${config_file}")"
    fi
    BACKUP_ENCRYPTION_KEY_FILE="${DIS_DATA_PATH}/secrets/backup-encryption.key"
    BACKUP_SAMBA_ENABLED=0
    unset BACKUP_SAMBA_SHARE BACKUP_SAMBA_MOUNT BACKUP_SAMBA_USERNAME \
      BACKUP_SAMBA_PASSWORD BACKUP_SAMBA_DOMAIN BACKUP_SAMBA_VERSION
    export DIS_EFFECTIVE_SAFE_LOCAL_BACKUP BACKUP_TARGET BACKUP_ROOT \
      BACKUP_RETENTION_COUNT BACKUP_ENCRYPTION_KEY_FILE BACKUP_SAMBA_ENABLED
    return 0
  fi

  DIS_EFFECTIVE_SAFE_LOCAL_BACKUP=0
  export DIS_EFFECTIVE_SAFE_LOCAL_BACKUP
  load_backup_runtime_config "${config_file}"
}

ensure_data_layout() {
  ensure_managed_directory "${DIS_DATA_PATH}" root "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/backup" root "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/backup-imports" root root 1730
  ensure_managed_directory "${DIS_DATA_PATH}/backup-requests" root root 1730
  ensure_managed_directory "${DIS_DATA_PATH}/backup-request-work" root root 0700
  ensure_managed_directory "${DIS_DATA_PATH}/osrm-admin" root root 0750
  ensure_managed_directory "${DIS_DATA_PATH}/osrm-admin/requests" root root 1730
  ensure_managed_directory "${DIS_DATA_PATH}/osrm-admin/work" root root 0700
  ensure_managed_directory "${DIS_DATA_PATH}/osrm-admin/results" root "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/playwright-browsers" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_managed_directory "${DIS_DATA_PATH}/secrets" root "${DIS_GROUP}" 0750

  # Speech models and the Python environment are reproducible runtime data and
  # deliberately live outside the encrypted application-storage backup. The
  # root-owned parent prevents the service account from replacing managed
  # leaves with links before a privileged deployment or permission repair.
  ensure_managed_directory "${DIS_DATA_PATH}/tts" root "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/tts/models" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/tts/cache" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_managed_directory "${DIS_DATA_PATH}/tts/runtime" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/tts/staging" "${DIS_USER}" "${DIS_GROUP}" 0770
  ensure_managed_directory "${DIS_DATA_PATH}/tts/state" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/tts/uv-cache" "${DIS_USER}" "${DIS_GROUP}" 0750
  ensure_managed_directory "${DIS_DATA_PATH}/tts/python" "${DIS_USER}" "${DIS_GROUP}" 0750

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

repair_speech_data_permissions() {
  local speech_service

  for speech_service in dis-speech dis-tts-engine; do
    if systemd_service_exists "${speech_service}" \
      && systemctl is-active --quiet "${speech_service}"; then
      fail "Speech staging recovery requires ${speech_service} to be stopped."
    fi
  done
  ensure_data_layout

  # Do not recursively rewrite the model, Python or virtual-environment trees:
  # those package-manager-owned trees legitimately contain links and are never
  # read by PHP. Queue inputs remain private to the dis worker and engine.
  repair_managed_tree "${DIS_DATA_PATH}/tts/cache" "${DIS_USER}" "${DIS_GROUP}" 0770 0640
  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would remove interrupted speech cache part files."
  else
    /usr/bin/find -P "${DIS_DATA_PATH}/tts/cache" -xdev -type f -name '*.part' -delete
  fi
  # Staging is entirely reproducible. A cgroup kill may bypass PHP/Python
  # finally blocks, so clear private jobs, references and partial output while
  # both speech services are stopped instead of carrying ambiguous bytes into
  # the next queue attempt.
  secure_path_operation remove-tree "${DIS_DATA_PATH}/tts/staging"
  ensure_managed_directory "${DIS_DATA_PATH}/tts/staging" "${DIS_USER}" "${DIS_GROUP}" 0770
  repair_managed_tree "${DIS_DATA_PATH}/tts/state" "${DIS_USER}" "${DIS_GROUP}" 0750 0640

  if id www-data >/dev/null 2>&1; then
    run_cmd setfacl -m "u:www-data:--x" "${DIS_DATA_PATH}/tts"
    run_cmd setfacl -x "d:u:www-data" "${DIS_DATA_PATH}/tts" 2>/dev/null || true
    # PHP can read published cache output. It receives socket access separately
    # and deliberately has no access to private queue/engine staging inputs.
    secure_path_operation acl-tree "${DIS_DATA_PATH}/tts/cache" www-data r-x r--
    secure_path_operation acl-tree "${DIS_DATA_PATH}/tts/staging" www-data --- ---
  fi
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
  local legacy_storage_self_link="${DIS_DATA_PATH}/storage/storage"
  local legacy_backend_storage_self_link="${DIS_DATA_PATH}/webapp/backend/storage/storage"

  ensure_data_layout
  if [ -L "${legacy_storage_self_link}" ] \
    && [ "$(readlink -- "${legacy_storage_self_link}")" = "${DIS_DATA_PATH}/storage" ]; then
    log "Removing legacy recursive storage self-link"
    run_cmd rm -f -- "${legacy_storage_self_link}"
  fi
  if [ -L "${legacy_backend_storage_self_link}" ] \
    && [ "$(readlink -- "${legacy_backend_storage_self_link}")" = "${DIS_DATA_PATH}/webapp/backend/storage" ]; then
    log "Removing legacy recursive backend storage self-link"
    run_cmd rm -f -- "${legacy_backend_storage_self_link}"
  fi
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
    oflag=nofollow \
    conv=excl \
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
