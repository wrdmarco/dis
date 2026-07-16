#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIS_DATA_PATH_WAS_EXPLICIT="${DIS_DATA_PATH+x}"
if [ "${SCRIPT_DIR}" = "/usr/local/lib/dis/osrm-admin" ]; then
  OSRM_COMMON_SOURCE="${SCRIPT_DIR}/common.sh"
else
  OSRM_COMMON_SOURCE="${SCRIPT_DIR}/lib/common.sh"
fi
# shellcheck source=scripts/lib/common.sh
source "${OSRM_COMMON_SOURCE}"
APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"

if [ "${DIS_DATA_PATH_WAS_EXPLICIT}" != "x" ] && [ -f "${APP_ROOT}/.env" ]; then
  load_data_path_from_env "${APP_ROOT}/.env"
fi

OSRM_USER="dis-osrm"
OSRM_GROUP="dis-osrm"
OSRM_IMPORT_USER="dis-osrm-build"
OSRM_DATA_ROOT="${DIS_DATA_PATH}/osrm"
OSRM_RELEASES_ROOT="${OSRM_DATA_ROOT}/releases"
OSRM_CURRENT_LINK="${OSRM_DATA_ROOT}/current"
OSRM_PREVIOUS_LINK="${OSRM_DATA_ROOT}/previous"
OSRM_STATUS_FILE="${OSRM_DATA_ROOT}/status.json"
OSRM_PACKAGE_PROVENANCE_FILE="${OSRM_DATA_ROOT}/package-provenance.json"
OSRM_BUILD_TOOL_PROVENANCE_FILE="${OSRM_DATA_ROOT}/build-tool-provenance.json"
OSRM_ACTIVATION_PENDING_FILE="${OSRM_DATA_ROOT}/activation.pending"
OSRM_SERVICE="dis-osrm.service"
OSRM_SERVICE_TEMPLATE="${OSRM_ADMIN_RUNTIME_DIR}/dis-osrm.service"
OSRM_RUNTIME_SCRIPT="${OSRM_ADMIN_RUNTIME_DIR}/osrm.sh"
OSRM_ENDPOINT="http://127.0.0.1:5000"
OSRM_UBUNTU_ARCHIVE_KEYRING="/usr/share/keyrings/ubuntu-archive-keyring.gpg"
OSRM_CONTAINER_IMAGE_VERSION="v26.5.0-amd64-debian"
OSRM_CONTAINER_IMAGE_DIGEST="sha256:51299b2a506807dc0ed7d3afcd5f04d9754ece85e9dd39a35669c2b4904304f2"
OSRM_CONTAINER_IMAGE="ghcr.io/project-osrm/osrm-backend@${OSRM_CONTAINER_IMAGE_DIGEST}"
OSRM_CONTAINER_SOURCE="https://github.com/Project-OSRM/osrm-backend"
OSRM_CONTAINER_REVISION="3c32a51bf58d12bf30efd0808d0b6ad51d334122"
OSRM_CONTAINER_PROFILE="/opt/car.lua"
OSRM_PODMAN_PATH="/usr/bin/podman"
OSRM_PODMAN_STORAGE_DRIVER="vfs"
OSRM_PODMAN_GRAPH_ROOT="/var/lib/containers/dis-osrm-vfs"
OSRM_PODMAN_RUN_ROOT="/run/containers/dis-osrm-vfs"
OSRM_PODMAN_GLOBAL_ARGS=(
  "--storage-driver=${OSRM_PODMAN_STORAGE_DRIVER}"
  "--root=${OSRM_PODMAN_GRAPH_ROOT}"
  "--runroot=${OSRM_PODMAN_RUN_ROOT}"
)
OSRM_MAX_PBF_BYTES="${OSRM_MAX_PBF_BYTES:-53687091200}"
OSRM_IMPORT_DISK_FACTOR="${OSRM_IMPORT_DISK_FACTOR:-8}"
OSRM_IMPORT_DISK_RESERVE_BYTES="${OSRM_IMPORT_DISK_RESERVE_BYTES:-2147483648}"
OSRM_IMPORT_MEMORY_MAX="${OSRM_IMPORT_MEMORY_MAX:-12G}"
OSRM_IMPORT_CPU_QUOTA="${OSRM_IMPORT_CPU_QUOTA:-400%}"
OSRM_IMPORT_TIMEOUT_SECONDS="${OSRM_IMPORT_TIMEOUT_SECONDS:-21600}"
OSRM_HEALTH_TIMEOUT_SECONDS="${OSRM_HEALTH_TIMEOUT_SECONDS:-120}"
OSRM_HEALTH_MAX_SNAP_METERS="${OSRM_HEALTH_MAX_SNAP_METERS:-250}"
OSRM_BELGIUM_HEALTH_COORDINATE="${OSRM_BELGIUM_HEALTH_COORDINATE:-4.3517,50.8503}"
OSRM_RELEASE_RETENTION="${OSRM_RELEASE_RETENTION:-3}"

validate_managed_path() {
  local label="$1"
  local path="$2"

  if [[ ! "${path}" =~ ^/[A-Za-z0-9._/-]+$ ]] \
    || [[ "/${path}/" == *"/../"* ]] \
    || [[ "/${path}/" == *"/./"* ]] \
    || [[ "${path}" == *"//"* ]]; then
    fail "${label} must be an absolute path without whitespace or traversal segments."
  fi
}

usage() {
  cat <<'USAGE'
Usage:
  sudo /usr/local/lib/dis/osrm-admin/osrm.sh install-package
  sudo /usr/local/lib/dis/osrm-admin/osrm.sh install-build-tool
  sudo /usr/local/lib/dis/osrm-admin/osrm.sh verify-build-tool
  sudo /usr/local/lib/dis/osrm-admin/osrm.sh provision
  sudo /usr/local/lib/dis/osrm-admin/osrm.sh reconcile
  sudo /usr/local/lib/dis/osrm-admin/osrm.sh import \
    --pbf /root/region.osm.pbf \
    --sha256 <64-character-sha256> \
    [--source-manifest <root-owned-source-manifest.json>] \
    --health-coordinate <longitude,latitude>
  sudo /usr/local/lib/dis/osrm-admin/osrm.sh health
  sudo /usr/local/lib/dis/osrm-admin/osrm.sh verify
  sudo /usr/local/lib/dis/osrm-admin/osrm.sh status
  sudo /usr/local/lib/dis/osrm-admin/osrm.sh publish-status
  sudo /usr/local/lib/dis/osrm-admin/osrm.sh sweep-scratch
  sudo /usr/local/lib/dis/osrm-admin/osrm.sh prune

Commands:
  install-package  Install and attest Ubuntu Podman, then pull the official OSRM
                   image by its immutable amd64 manifest digest. No mutable tag,
                   foreign package suite or public routing service is used.
  install-build-tool
                   Independently install and attest the Ubuntu osmium-tool package
                   used only to inspect and merge verified source extracts.
  verify-build-tool
                   Fail unless /usr/bin/osmium matches its separate protected
                   Ubuntu package receipt and APT hold.
  provision        Create the isolated service account and protected data layout,
                   then install and enable the local-only systemd unit.
  reconcile        Start and health-check OSRM when the pinned container and a prepared dataset
                   exist; otherwise stop it and record an explicit degraded state.
  import           Safely preprocess and atomically activate an operator-supplied
                   .osm.pbf. An expected SHA-256 and a known in-region probe
                   coordinate are required. Managed NL+BE imports additionally
                   require a strict root-owned source manifest.
  health           Check the active service using the stored probe coordinate.
  verify           Verify the checksums and permissions of the active dataset.
  status           Print the durable routing state and systemd service state.
  publish-status   Atomically publish the bounded admin-facing OSRM snapshot.
  sweep-scratch    Remove only stale, directly managed preprocessing scratch
                   directories while protecting a declared active scratch path.
  prune            Retain a bounded set of releases without removing current,
                   previous or pending-activation targets.

Import resource defaults can be overridden for one invocation with:
  OSRM_IMPORT_MEMORY_MAX=12G
  OSRM_IMPORT_CPU_QUOTA=400%
  OSRM_IMPORT_TIMEOUT_SECONDS=21600
  OSRM_MAX_PBF_BYTES=53687091200
  OSRM_IMPORT_DISK_FACTOR=8
  OSRM_HEALTH_MAX_SNAP_METERS=250

The HTTP listener is deliberately fixed to 127.0.0.1:5000 so backend routing
cannot accidentally expose OSRM publicly. Data follows the validated DIS_DATA_PATH.
USAGE
}

ensure_osrm_group_primary_members() {
  local gid="$1"
  local passwd_entries passwd_gid passwd_name

  passwd_entries="$(getent passwd)" \
    || fail "The system account database could not be checked for OSRM group isolation."
  while IFS=: read -r passwd_name _ _ passwd_gid _; do
    [ "${passwd_gid}" = "${gid}" ] || continue
    case "${passwd_name}" in
      "${OSRM_USER}"|"${OSRM_IMPORT_USER}") ;;
      *) fail "Account ${passwd_name} may not share the primary OSRM group." ;;
    esac
  done <<< "${passwd_entries}"
}

ensure_osrm_identity() {
  local account account_groups shell primary_group uid uid_min gid gid_min supplementary_members

  if ! getent group "${OSRM_GROUP}" >/dev/null 2>&1; then
    run_cmd groupadd --system "${OSRM_GROUP}"
  fi
  gid="$(getent group "${OSRM_GROUP}" | cut -d: -f3)"
  gid_min="$(awk '$1 == "GID_MIN" { value=$2 } END { print value }' /etc/login.defs)"
  [[ "${gid}" =~ ^[0-9]+$ ]] && [ "${gid}" != "0" ] \
    || fail "The OSRM service group must not use the root group id."
  [[ "${gid_min}" =~ ^[0-9]+$ ]] \
    || fail "The system account gid boundary could not be determined."
  [ "${gid}" -lt "${gid_min}" ] \
    || fail "The OSRM service group must be a system group."
  supplementary_members="$(getent group "${OSRM_GROUP}" | cut -d: -f4)"
  [ -z "${supplementary_members}" ] \
    || fail "The OSRM service group may not contain supplementary users."
  ensure_osrm_group_primary_members "${gid}"

  if ! id "${OSRM_USER}" >/dev/null 2>&1; then
    run_cmd useradd \
      --system \
      --gid "${OSRM_GROUP}" \
      --home-dir "${OSRM_DATA_ROOT}" \
      --no-create-home \
      --shell /usr/sbin/nologin \
      "${OSRM_USER}"
  fi

  if ! id "${OSRM_IMPORT_USER}" >/dev/null 2>&1; then
    run_cmd useradd \
      --system \
      --gid "${OSRM_GROUP}" \
      --home-dir "${OSRM_DATA_ROOT}" \
      --no-create-home \
      --shell /usr/sbin/nologin \
      "${OSRM_IMPORT_USER}"
  fi

  uid_min="$(awk '$1 == "UID_MIN" { value=$2 } END { print value }' /etc/login.defs)"
  [[ "${uid_min}" =~ ^[0-9]+$ ]] \
    || fail "The system account uid boundary could not be determined."
  for account in "${OSRM_USER}" "${OSRM_IMPORT_USER}"; do
    uid="$(id -u "${account}")"
    primary_group="$(id -gn "${account}")"
    shell="$(getent passwd "${account}" | cut -d: -f7)"
    [ "${uid}" != "0" ] || fail "OSRM service accounts may not use uid 0."
    [ "${uid}" -lt "${uid_min}" ] \
      || fail "OSRM service account ${account} must be a system account."
    [ "${primary_group}" = "${OSRM_GROUP}" ] \
      || fail "OSRM service account ${account} must use primary group ${OSRM_GROUP}."
    account_groups="$(id -G "${account}")"
    [ "${account_groups}" = "${gid}" ] \
      || fail "OSRM service account ${account} may not belong to supplementary groups."
    case "${shell}" in
      /usr/sbin/nologin|/sbin/nologin|/bin/false) ;;
      *) fail "OSRM service account ${account} must use a non-login shell." ;;
    esac
  done
  [ "$(id -u "${OSRM_USER}")" != "$(id -u "${OSRM_IMPORT_USER}")" ] \
    || fail "OSRM runtime and import accounts must be distinct."
  ensure_osrm_group_primary_members "${gid}"
}

ensure_osrm_layout() {
  local managed_file

  ensure_osrm_identity
  ensure_managed_directory "${OSRM_DATA_ROOT}" root "${OSRM_GROUP}" 0750
  ensure_managed_directory "${OSRM_RELEASES_ROOT}" root "${OSRM_GROUP}" 0750
  repair_managed_tree "${OSRM_RELEASES_ROOT}" root "${OSRM_GROUP}" 0750 0640
  for managed_file in \
    "${OSRM_STATUS_FILE}" \
    "${OSRM_PACKAGE_PROVENANCE_FILE}" \
    "${OSRM_BUILD_TOOL_PROVENANCE_FILE}" \
    "${OSRM_ACTIVATION_PENDING_FILE}"; do
    if [ -e "${managed_file}" ] || [ -L "${managed_file}" ]; then
      [ -f "${managed_file}" ] && [ ! -L "${managed_file}" ] \
        && [ "$(stat -c '%h' -- "${managed_file}")" = "1" ] \
        || fail "The OSRM managed state path is unsafe: ${managed_file}"
      run_cmd chown root:"${OSRM_GROUP}" "${managed_file}"
      run_cmd chmod 0640 "${managed_file}"
    fi
  done
  run_cmd setfacl -m "u:${OSRM_USER}:--x" "${DIS_DATA_PATH}"
  run_cmd setfacl -m "u:${OSRM_IMPORT_USER}:--x" "${DIS_DATA_PATH}"
  require_user_can_open_directory_for_reading \
    "${OSRM_USER}" "${OSRM_DATA_ROOT}" "the OSRM data directory"
  require_user_can_open_directory_for_reading \
    "${OSRM_USER}" "${OSRM_RELEASES_ROOT}" "the OSRM releases directory"
  require_user_can_open_directory_for_reading \
    "${OSRM_IMPORT_USER}" "${OSRM_DATA_ROOT}" "the OSRM data directory"
  require_user_can_open_directory_for_reading \
    "${OSRM_IMPORT_USER}" "${OSRM_RELEASES_ROOT}" "the OSRM releases directory"
}

write_status() {
  local state="$1"
  local detail="$2"
  local dataset_sha="${3:-}"
  local temporary

  if [ -n "${dataset_sha}" ]; then
    [[ "${dataset_sha}" =~ ^[a-f0-9]{64}$ ]] \
      || fail "The OSRM status dataset SHA-256 is invalid."
  fi
  ensure_osrm_layout
  temporary="$(mktemp "${OSRM_DATA_ROOT}/.status.XXXXXX")"
  jq -n \
    --arg state "${state}" \
    --arg detail "${detail}" \
    --arg dataset_sha256 "${dataset_sha}" \
    --arg updated_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{
      version: 2,
      state: $state,
      detail: $detail,
      dataset_sha256: (if $dataset_sha256 == "" then null else $dataset_sha256 end),
      endpoint: "http://127.0.0.1:5000",
      updated_at: $updated_at
    }' > "${temporary}"
  run_cmd chown root:"${OSRM_GROUP}" "${temporary}"
  run_cmd chmod 0640 "${temporary}"
  run_cmd sync -f "${temporary}"
  run_cmd mv -fT -- "${temporary}" "${OSRM_STATUS_FILE}"
  run_cmd sync -f "${OSRM_DATA_ROOT}"
}

trusted_tool_path() {
  local tool="$1"
  local path uid mode

  path="$(command -v "${tool}" 2>/dev/null || true)"
  [ -n "${path}" ] && [ -x "${path}" ] || return 1
  path="$(readlink -f -- "${path}")"
  [ -f "${path}" ] && [ ! -L "${path}" ] || return 1
  [ "$(stat -c '%h' -- "${path}")" = "1" ] || return 1
  uid="$(stat -c '%u' -- "${path}")"
  mode="$(stat -c '%a' -- "${path}")"
  [ "${uid}" = "0" ] || return 1
  (( (8#${mode} & 8#022) == 0 )) || return 1
  python3 -I -S "${COMMON_LIB_DIR}/secure-path.py" verify-parent "${path}" >/dev/null 2>&1 \
    || return 1
  printf '%s\n' "${path}"
}

approved_ubuntu_archive_source() {
  local source_uri="$1"
  local source_pocket="$2"

  [[ "${source_uri}" =~ ^https?://([a-z0-9-]+\.)*archive\.ubuntu\.com(:[0-9]+)?/ubuntu/?$|^https?://security\.ubuntu\.com(:[0-9]+)?/ubuntu/?$|^https?://ports\.ubuntu\.com(:[0-9]+)?/ubuntu-ports/?$ ]] \
    && [[ "${source_pocket}" =~ ^resolute(-updates|-security)?/(main|universe)$ ]]
}

trusted_ubuntu_archive_keyring() {
  local mode owner uid

  [ -f "${OSRM_UBUNTU_ARCHIVE_KEYRING}" ] \
    && [ ! -L "${OSRM_UBUNTU_ARCHIVE_KEYRING}" ] \
    && [ "$(stat -c '%h' -- "${OSRM_UBUNTU_ARCHIVE_KEYRING}")" = "1" ] || return 1
  uid="$(stat -c '%u' -- "${OSRM_UBUNTU_ARCHIVE_KEYRING}")"
  mode="$(stat -c '%a' -- "${OSRM_UBUNTU_ARCHIVE_KEYRING}")"
  [ "${uid}" = "0" ] || return 1
  (( (8#${mode} & 8#022) == 0 )) || return 1
  owner="$(dpkg-query -S "${OSRM_UBUNTU_ARCHIVE_KEYRING}" 2>/dev/null | head -n 1)"
  [[ "${owner}" == ubuntu-keyring:* ]] || return 1
  python3 -I -S "${COMMON_LIB_DIR}/secure-path.py" verify-parent \
    "${OSRM_UBUNTU_ARCHIVE_KEYRING}" >/dev/null 2>&1 || return 1
  printf '%s\n' "${OSRM_UBUNTU_ARCHIVE_KEYRING}"
}

prepare_approved_apt_sources() {
  local destination="$1"
  local keyring policy source_component source_pocket source_suite source_uri
  local -A seen=()

  : > "${destination}"
  keyring="$(trusted_ubuntu_archive_keyring)" || return 1
  # apt-cache labels are localized. Force the stable C output before parsing
  # and derive the temporary source list only from repositories already
  # configured on this host; no archive URL or component is synthesized.
  policy="$(LC_ALL=C apt-cache policy 2>/dev/null || true)"
  while IFS='|' read -r source_uri source_pocket; do
    approved_ubuntu_archive_source "${source_uri}" "${source_pocket}" || continue
    [ -z "${seen[${source_uri}|${source_pocket}]+x}" ] || continue
    seen["${source_uri}|${source_pocket}"]=1
    source_suite="${source_pocket%%/*}"
    source_component="${source_pocket#*/}"
    printf 'deb [signed-by=%s] %s %s %s\n' \
      "${keyring}" \
      "${source_uri}" \
      "${source_suite}" \
      "${source_component}" >> "${destination}"
  done < <(awk '
    $1 ~ /^[0-9]+$/ && $2 ~ /^https?:\/\// && $3 ~ /^[^/]+\/(main|universe)$/ {
      print $2 "|" $3
    }
  ' <<< "${policy}")

  [ -s "${destination}" ]
}

official_package_candidate() {
  local source_list="$1"
  local package_name="$2"
  local policy

  [[ "${package_name}" =~ ^[a-z0-9][a-z0-9+.-]*$ ]] || return 1
  policy="$(LC_ALL=C apt-cache \
    -o "Dir::Etc::sourcelist=${source_list}" \
    -o "Dir::Etc::sourceparts=-" \
    policy "${package_name}" 2>/dev/null || true)"
  awk '$1 == "Candidate:" { print $2; exit }' <<< "${policy}"
}

official_podman_candidate() {
  official_package_candidate "$1" podman
}

official_osmium_candidate() {
  official_package_candidate "$1" osmium-tool
}

required_podman_tool_owned_by_package() {
  local owner path

  path="$(trusted_tool_path podman)" || return 1
  [ "${path}" = "${OSRM_PODMAN_PATH}" ] || return 1
  owner="$(dpkg-query -S "${path}" 2>/dev/null | head -n 1)"
  [[ "${owner}" == podman:* ]]
}

package_fingerprint() {
  local package_name="$1" entry entry_hash manifest target

  [[ "${package_name}" =~ ^[a-z0-9][a-z0-9+.-]*$ ]] || return 1
  manifest="$(mktemp "${TMPDIR:-/tmp}/dis-osrm-package.XXXXXX")" || return 1
  while IFS= read -r entry; do
    [[ "${entry}" == /* ]] || {
      rm -f -- "${manifest}"
      return 1
    }
    if [ -L "${entry}" ]; then
      target="$(readlink -- "${entry}")" || {
        rm -f -- "${manifest}"
        return 1
      }
      printf 'L|%s|%s\n' "${entry}" "${target}" >> "${manifest}"
    elif [ -f "${entry}" ]; then
      entry_hash="$(sha256sum -- "${entry}" | awk '{ print $1 }')" || {
        rm -f -- "${manifest}"
        return 1
      }
      printf 'F|%s|%s\n' "${entry}" "${entry_hash}" >> "${manifest}"
    fi
  done < <(LC_ALL=C dpkg-query -L "${package_name}" 2>/dev/null | LC_ALL=C sort -u)

  [ -s "${manifest}" ] || {
    rm -f -- "${manifest}"
    return 1
  }
  entry_hash="$(sha256sum -- "${manifest}" | awk '{ print $1 }')"
  rm -f -- "${manifest}"
  printf '%s\n' "${entry_hash}"
}

podman_package_fingerprint() {
  package_fingerprint podman
}

osmium_package_fingerprint() {
  package_fingerprint osmium-tool
}

container_provenance_matches() {
  local expected_fingerprint="$1" expected_podman_version="$2"
  local expected_image_id="$3" expected_profile_sha="$4"
  local mode uid

  [ -f "${OSRM_PACKAGE_PROVENANCE_FILE}" ] \
    && [ ! -L "${OSRM_PACKAGE_PROVENANCE_FILE}" ] \
    && [ "$(stat -c '%h' -- "${OSRM_PACKAGE_PROVENANCE_FILE}")" = "1" ] || return 1
  uid="$(stat -c '%u' -- "${OSRM_PACKAGE_PROVENANCE_FILE}")"
  mode="$(stat -c '%a' -- "${OSRM_PACKAGE_PROVENANCE_FILE}")"
  [ "${uid}" = "0" ] || return 1
  (( (8#${mode} & 8#022) == 0 )) || return 1
  jq -e \
    --arg podman_version "${expected_podman_version}" \
    --arg podman_fingerprint "${expected_fingerprint}" \
    --arg image "${OSRM_CONTAINER_IMAGE}" \
    --arg image_version "${OSRM_CONTAINER_IMAGE_VERSION}" \
    --arg image_digest "${OSRM_CONTAINER_IMAGE_DIGEST}" \
    --arg image_id "${expected_image_id}" \
    --arg profile_path "${OSRM_CONTAINER_PROFILE}" \
    --arg profile_sha256 "${expected_profile_sha}" '
      type == "object"
      and keys == ["image","image_digest","image_id","image_version","podman_files_sha256","podman_version","profile_path","profile_sha256","runtime","source","verified_at"]
      and .runtime == "podman"
      and .source == "ghcr.io/project-osrm/osrm-backend"
      and .podman_version == $podman_version
      and .podman_files_sha256 == $podman_fingerprint
      and .image == $image
      and .image_version == $image_version
      and .image_digest == $image_digest
      and .image_id == $image_id
      and .profile_path == $profile_path
      and .profile_sha256 == $profile_sha256
      and (.verified_at | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
    ' "${OSRM_PACKAGE_PROVENANCE_FILE}" >/dev/null 2>&1
}

write_container_provenance() {
  local fingerprint="$1" podman_version="$2" image_id="$3" profile_sha="$4"
  local temporary

  [[ "${fingerprint}" =~ ^[a-f0-9]{64}$ ]] || fail "The Podman package fingerprint is invalid."
  [ -n "${podman_version}" ] || fail "The Podman package version is invalid."
  [[ "${image_id}" =~ ^sha256:[a-f0-9]{64}$ ]] || fail "The OSRM container image id is invalid."
  [[ "${profile_sha}" =~ ^[a-f0-9]{64}$ ]] || fail "The OSRM container profile fingerprint is invalid."
  ensure_osrm_layout
  temporary="$(mktemp "${OSRM_DATA_ROOT}/.package-provenance.XXXXXX")"
  jq -n \
    --arg podman_version "${podman_version}" \
    --arg podman_files_sha256 "${fingerprint}" \
    --arg image "${OSRM_CONTAINER_IMAGE}" \
    --arg image_version "${OSRM_CONTAINER_IMAGE_VERSION}" \
    --arg image_digest "${OSRM_CONTAINER_IMAGE_DIGEST}" \
    --arg image_id "${image_id}" \
    --arg profile_path "${OSRM_CONTAINER_PROFILE}" \
    --arg profile_sha256 "${profile_sha}" \
    --arg verified_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{
      runtime: "podman",
      source: "ghcr.io/project-osrm/osrm-backend",
      podman_version: $podman_version,
      podman_files_sha256: $podman_files_sha256,
      image: $image,
      image_version: $image_version,
      image_digest: $image_digest,
      image_id: $image_id,
      profile_path: $profile_path,
      profile_sha256: $profile_sha256,
      verified_at: $verified_at
    }' > "${temporary}"
  run_cmd chown root:"${OSRM_GROUP}" "${temporary}"
  run_cmd chmod 0640 "${temporary}"
  run_cmd sync -f "${temporary}"
  run_cmd mv -fT -- "${temporary}" "${OSRM_PACKAGE_PROVENANCE_FILE}"
  run_cmd sync -f "${OSRM_DATA_ROOT}"
}

podman_image_metadata_is_valid() {
  "${OSRM_PODMAN_PATH}" "${OSRM_PODMAN_GLOBAL_ARGS[@]}" \
    image inspect "${OSRM_CONTAINER_IMAGE}" 2>/dev/null \
    | jq -e \
      --arg digest "${OSRM_CONTAINER_IMAGE_DIGEST}" \
      --arg version "${OSRM_CONTAINER_IMAGE_VERSION}" \
      --arg source "${OSRM_CONTAINER_SOURCE}" \
      --arg revision "${OSRM_CONTAINER_REVISION}" '
        type == "array" and length == 1
        and .[0].Digest == $digest
        and .[0].Architecture == "amd64"
        and .[0].Os == "linux"
        and .[0].Labels["org.opencontainers.image.version"] == $version
        and .[0].Labels["org.opencontainers.image.source"] == $source
        and .[0].Labels["org.opencontainers.image.revision"] == $revision
        and .[0].Labels["org.opencontainers.image.licenses"] == "BSD-2-Clause"
        and (.[0].Id | type == "string" and test("^(sha256:)?[a-f0-9]{64}$"))
      ' >/dev/null
}

podman_image_id() {
  local image_id

  image_id="$("${OSRM_PODMAN_PATH}" "${OSRM_PODMAN_GLOBAL_ARGS[@]}" \
    image inspect --format '{{.Id}}' "${OSRM_CONTAINER_IMAGE}" 2>/dev/null \
    | tr -d '\r\n')"
  image_id="${image_id#sha256:}"
  [[ "${image_id}" =~ ^[a-f0-9]{64}$ ]] || return 1
  printf 'sha256:%s\n' "${image_id}"
}

podman_profile_sha() {
  "${OSRM_PODMAN_PATH}" "${OSRM_PODMAN_GLOBAL_ARGS[@]}" \
    run --rm --pull=never --network=none --read-only \
    --cap-drop=all --security-opt=no-new-privileges --pids-limit=32 \
    "${OSRM_CONTAINER_IMAGE}" sha256sum "${OSRM_CONTAINER_PROFILE}" 2>/dev/null \
    | awk '{ print $1 }'
}

podman_package_is_held() {
  apt-mark showhold 2>/dev/null | grep -Fxq podman
}

hold_podman_package() {
  run_cmd apt-mark hold podman >/dev/null
  podman_package_is_held \
    || fail "The verified Podman package could not be protected from unattended upgrades."
}

installed_container_runtime_matches_receipt() {
  local fingerprint image_id podman_version profile_sha

  podman_version="$(dpkg-query -W -f='${Version}' podman 2>/dev/null || true)"
  [ -n "${podman_version}" ] || return 1
  required_podman_tool_owned_by_package || return 1
  podman_package_is_held || return 1
  fingerprint="$(podman_package_fingerprint)" || return 1
  podman_image_metadata_is_valid || return 1
  image_id="$(podman_image_id)"
  profile_sha="$(podman_profile_sha)"
  container_provenance_matches "${fingerprint}" "${podman_version}" "${image_id}" "${profile_sha}"
}

osrm_tools_available() {
  installed_container_runtime_matches_receipt
}

required_osmium_tool_owned_by_package() {
  local owner path

  path="$(trusted_tool_path osmium)" || return 1
  [ "${path}" = "/usr/bin/osmium" ] || return 1
  owner="$(dpkg-query -S "${path}" 2>/dev/null | head -n 1)"
  [[ "${owner}" == osmium-tool:* ]]
}

build_tool_provenance_matches() {
  local expected_fingerprint="$1" expected_version="$2"
  local actual_fingerprint actual_version mode uid

  [ -f "${OSRM_BUILD_TOOL_PROVENANCE_FILE}" ] \
    && [ ! -L "${OSRM_BUILD_TOOL_PROVENANCE_FILE}" ] \
    && [ "$(stat -c '%h' -- "${OSRM_BUILD_TOOL_PROVENANCE_FILE}")" = "1" ] || return 1
  uid="$(stat -c '%u' -- "${OSRM_BUILD_TOOL_PROVENANCE_FILE}")"
  mode="$(stat -c '%a' -- "${OSRM_BUILD_TOOL_PROVENANCE_FILE}")"
  [ "${uid}" = "0" ] || return 1
  (( (8#${mode} & 8#022) == 0 )) || return 1
  actual_version="$(jq -er '
    select(type == "object" and keys == ["installed_files_sha256","package","source","verified_at","version"])
    | select(.package == "osmium-tool" and .source == "configured-official-ubuntu-archive")
    | .version | select(type == "string" and length > 0)
  ' "${OSRM_BUILD_TOOL_PROVENANCE_FILE}" 2>/dev/null)" || return 1
  actual_fingerprint="$(jq -er '.installed_files_sha256 | select(type == "string" and test("^[a-f0-9]{64}$"))' \
    "${OSRM_BUILD_TOOL_PROVENANCE_FILE}" 2>/dev/null)" || return 1
  [ "${actual_version}" = "${expected_version}" ] \
    && [ "${actual_fingerprint}" = "${expected_fingerprint}" ]
}

write_build_tool_provenance() {
  local fingerprint="$1" version="$2" temporary

  [[ "${fingerprint}" =~ ^[a-f0-9]{64}$ ]] || fail "The osmium-tool package fingerprint is invalid."
  [ -n "${version}" ] || fail "The osmium-tool package version is invalid."
  ensure_osrm_layout
  temporary="$(mktemp "${OSRM_DATA_ROOT}/.build-tool-provenance.XXXXXX")"
  jq -n \
    --arg version "${version}" \
    --arg installed_files_sha256 "${fingerprint}" \
    --arg verified_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{
      package: "osmium-tool",
      source: "configured-official-ubuntu-archive",
      version: $version,
      installed_files_sha256: $installed_files_sha256,
      verified_at: $verified_at
    }' > "${temporary}"
  run_cmd chown root:"${OSRM_GROUP}" "${temporary}"
  run_cmd chmod 0640 "${temporary}"
  run_cmd sync -f "${temporary}"
  run_cmd mv -fT -- "${temporary}" "${OSRM_BUILD_TOOL_PROVENANCE_FILE}"
  run_cmd sync -f "${OSRM_DATA_ROOT}"
}

osmium_package_is_held() {
  apt-mark showhold 2>/dev/null | grep -Fxq osmium-tool
}

hold_osmium_package() {
  run_cmd apt-mark hold osmium-tool >/dev/null
  osmium_package_is_held \
    || fail "The verified osmium-tool package could not be protected from unattended upgrades."
}

osmium_tool_available() {
  local fingerprint installed_version

  installed_version="$(dpkg-query -W -f='${Version}' osmium-tool 2>/dev/null || true)"
  [ -n "${installed_version}" ] || return 1
  fingerprint="$(osmium_package_fingerprint)" || return 1
  build_tool_provenance_matches "${fingerprint}" "${installed_version}" \
    && osmium_package_is_held \
    && required_osmium_tool_owned_by_package
}

install_build_tool() {
  local candidate fingerprint installed_version source_list
  local -a reinstall_argument=()

  require_root
  require_ubuntu_2604
  acquire_dis_operation_lock osrm-build-tool

  if osmium_tool_available; then
    installed_version="$(dpkg-query -W -f='${Version}' osmium-tool)"
    log "Verified Ubuntu osmium-tool ${installed_version} is already installed and held."
    return 0
  fi

  source_list="$(mktemp "${TMPDIR:-/tmp}/dis-osmium-sources.XXXXXX")"
  if ! prepare_approved_apt_sources "${source_list}"; then
    rm -f -- "${source_list}"
    log "OSRM map build tool unavailable: no approved Ubuntu 26.04 archive source is configured."
    return 0
  fi
  candidate="$(official_osmium_candidate "${source_list}")"
  if [ -z "${candidate}" ] || [ "${candidate}" = "(none)" ]; then
    rm -f -- "${source_list}"
    log "OSRM map build tool unavailable: Ubuntu has no installable osmium-tool candidate."
    return 0
  fi

  installed_version="$(dpkg-query -W -f='${Version}' osmium-tool 2>/dev/null || true)"
  [ -z "${installed_version}" ] || reinstall_argument=(--reinstall)
  log "Installing osmium-tool ${candidate} from the configured official Ubuntu archive"
  if ! run_cmd apt-get \
    -o "Dir::Etc::sourcelist=${source_list}" \
    -o "Dir::Etc::sourceparts=-" \
    -o "Acquire::AllowInsecureRepositories=false" \
    -o "APT::Get::AllowUnauthenticated=false" \
    install -y --no-install-recommends --allow-change-held-packages \
      "${reinstall_argument[@]}" "osmium-tool=${candidate}"; then
    rm -f -- "${source_list}"
    fail "Ubuntu offers osmium-tool ${candidate}, but its approved-source installation failed."
  fi
  rm -f -- "${source_list}"
  hold_osmium_package

  installed_version="$(dpkg-query -W -f='${Version}' osmium-tool 2>/dev/null || true)"
  [ "${installed_version}" = "${candidate}" ] \
    || fail "The installed osmium-tool version does not match the verified Ubuntu candidate."
  required_osmium_tool_owned_by_package \
    || fail "osmium-tool installed, but /usr/bin/osmium is not a trusted package-owned tool."
  fingerprint="$(osmium_package_fingerprint)" \
    || fail "The installed osmium-tool files could not be fingerprinted."
  write_build_tool_provenance "${fingerprint}" "${candidate}"
  build_tool_provenance_matches "${fingerprint}" "${candidate}" \
    || fail "The durable osmium-tool package provenance receipt could not be verified."
}

verify_build_tool() {
  require_root
  osmium_tool_available \
    || fail "osmium-tool does not match its separate protected Ubuntu package receipt and APT hold."
  log "Verified osmium-tool build dependency is available."
}

install_package() {
  local candidate fingerprint image_id installed_version profile_sha source_list
  local -a reinstall_argument=()

  require_root
  require_ubuntu_2604
  acquire_dis_operation_lock osrm-package

  if installed_container_runtime_matches_receipt; then
    log "Verified OSRM ${OSRM_CONTAINER_IMAGE_VERSION} container runtime is already installed and pinned."
    return 0
  fi

  source_list="$(mktemp "${TMPDIR:-/tmp}/dis-podman-sources.XXXXXX")"
  if ! prepare_approved_apt_sources "${source_list}"; then
    rm -f -- "${source_list}"
    fail "No approved Ubuntu 26.04 archive source is configured for Podman."
  fi
  candidate="$(official_podman_candidate "${source_list}")"
  if [ -z "${candidate}" ] || [ "${candidate}" = "(none)" ]; then
    rm -f -- "${source_list}"
    fail "Ubuntu 26.04 has no installable Podman candidate in the configured official archives."
  fi

  installed_version="$(dpkg-query -W -f='${Version}' podman 2>/dev/null || true)"
  [ -z "${installed_version}" ] || reinstall_argument=(--reinstall)
  log "Installing Podman ${candidate} from the configured official Ubuntu archive"
  if ! run_cmd apt-get \
    -o "Dir::Etc::sourcelist=${source_list}" \
    -o "Dir::Etc::sourceparts=-" \
    -o "Acquire::AllowInsecureRepositories=false" \
    -o "APT::Get::AllowUnauthenticated=false" \
    install -y --no-install-recommends --allow-change-held-packages \
      "${reinstall_argument[@]}" "podman=${candidate}"; then
    rm -f -- "${source_list}"
    fail "Ubuntu offers Podman ${candidate}, but its approved-source installation failed."
  fi
  rm -f -- "${source_list}"
  hold_podman_package

  installed_version="$(dpkg-query -W -f='${Version}' podman 2>/dev/null || true)"
  [ "${installed_version}" = "${candidate}" ] \
    || fail "The installed Podman version does not match the verified Ubuntu candidate."
  required_podman_tool_owned_by_package \
    || fail "Podman installed, but /usr/bin/podman is not a trusted package-owned tool."
  fingerprint="$(podman_package_fingerprint)" \
    || fail "The installed Podman files could not be fingerprinted."

  log "Pulling official OSRM ${OSRM_CONTAINER_IMAGE_VERSION} by immutable amd64 digest"
  run_cmd "${OSRM_PODMAN_PATH}" "${OSRM_PODMAN_GLOBAL_ARGS[@]}" \
    pull --arch amd64 --os linux "${OSRM_CONTAINER_IMAGE}" \
    || {
      if [ "$(systemd-detect-virt --container 2>/dev/null || true)" = "lxc" ]; then
        fail "The pinned official OSRM container image could not be pulled. The dedicated VFS store is active; enable the Proxmox LXC nesting feature on the DIS container host and retry."
      fi
      fail "The pinned official OSRM container image could not be pulled."
    }
  podman_image_metadata_is_valid \
    || fail "The pulled OSRM container does not match its pinned digest and OCI metadata."
  image_id="$(podman_image_id)"
  profile_sha="$(podman_profile_sha)"
  [[ "${image_id}" =~ ^sha256:[a-f0-9]{64}$ ]] \
    || fail "The pulled OSRM container image id is invalid."
  [[ "${profile_sha}" =~ ^[a-f0-9]{64}$ ]] \
    || fail "The pinned OSRM container car profile is unavailable or invalid."
  write_container_provenance "${fingerprint}" "${candidate}" "${image_id}" "${profile_sha}"
  installed_container_runtime_matches_receipt \
    || fail "The durable Podman and OSRM container provenance receipt could not be verified."
}

provision() {
  local escaped_app_root escaped_data_path escaped_runtime_script temporary_unit

  require_root
  acquire_dis_operation_lock osrm-provision
  ensure_osrm_layout
  verify_osrm_admin_runtime_library
  require_file "${OSRM_SERVICE_TEMPLATE}"

  escaped_app_root="$(printf '%s' "${APP_ROOT}" | sed 's/[&|\\]/\\&/g')"
  escaped_data_path="$(printf '%s' "${DIS_DATA_PATH}" | sed 's/[&|\\]/\\&/g')"
  escaped_runtime_script="$(printf '%s' "${OSRM_RUNTIME_SCRIPT}" | sed 's/[&|\\]/\\&/g')"
  temporary_unit="$(mktemp /run/dis-osrm.service.XXXXXX)"
  sed \
    -e "s|@APP_ROOT@|${escaped_app_root}|g" \
    -e "s|@DIS_DATA_PATH@|${escaped_data_path}|g" \
    -e "s|@OSRM_RUNTIME_SCRIPT@|${escaped_runtime_script}|g" \
    "${OSRM_SERVICE_TEMPLATE}" > "${temporary_unit}"
  run_cmd install -m 0644 "${temporary_unit}" "/etc/systemd/system/${OSRM_SERVICE}"
  run_cmd rm -f -- "${temporary_unit}"
  run_cmd systemctl daemon-reload
  run_cmd systemctl enable "${OSRM_SERVICE}" >/dev/null 2>&1

  if [ ! -f "${OSRM_STATUS_FILE}" ]; then
    write_status degraded "No prepared OSRM dataset has been activated."
  fi
}

read_active_release() {
  local target release uid mode

  [ -L "${OSRM_CURRENT_LINK}" ] || return 1
  target="$(readlink -- "${OSRM_CURRENT_LINK}")"
  [[ "${target}" =~ ^releases/[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$ ]] || return 1
  release="${OSRM_DATA_ROOT}/${target}"
  [ -d "${release}" ] && [ ! -L "${release}" ] || return 1
  uid="$(stat -c '%u' -- "${release}")"
  mode="$(stat -c '%a' -- "${release}")"
  [ "${uid}" = "0" ] || return 1
  (( (8#${mode} & 8#022) == 0 )) || return 1
  printf '%s\n' "${release}"
}

read_probe_coordinate() {
  local release="$1"
  local coordinate

  [ -f "${release}/health-coordinate" ] && [ ! -L "${release}/health-coordinate" ] || return 1
  coordinate="$(tr -d '\r\n' < "${release}/health-coordinate")"
  validate_coordinate "${coordinate}" || return 1
  printf '%s\n' "${coordinate}"
}

read_belgium_probe_coordinate() {
  local release="$1" coordinate

  [ -f "${release}/health-coordinate-belgium" ] \
    && [ ! -L "${release}/health-coordinate-belgium" ] || return 1
  coordinate="$(tr -d '\r\n' < "${release}/health-coordinate-belgium")"
  validate_belgium_coordinate "${coordinate}" || return 1
  printf '%s\n' "${coordinate}"
}

validate_coordinate() {
  local coordinate="$1"
  local longitude latitude remainder

  [[ "${coordinate}" == *,* ]] || return 1
  longitude="${coordinate%%,*}"
  remainder="${coordinate#*,}"
  [[ "${remainder}" != *,* ]] || return 1
  latitude="${remainder}"
  [[ "${longitude}" =~ ^-?[0-9]+([.][0-9]+)?$ ]] || return 1
  [[ "${latitude}" =~ ^-?[0-9]+([.][0-9]+)?$ ]] || return 1
  awk -v lon="${longitude}" -v lat="${latitude}" \
    'BEGIN { exit !(lon >= -180 && lon <= 180 && lat >= -90 && lat <= 90) }'
}

validate_belgium_coordinate() {
  local coordinate="$1" longitude latitude

  validate_coordinate "${coordinate}" || return 1
  longitude="${coordinate%%,*}"
  latitude="${coordinate#*,}"
  awk -v lon="${longitude}" -v lat="${latitude}" \
    'BEGIN { exit !(lon >= 2.4 && lon <= 6.5 && lat >= 49.4 && lat <= 51.6) }'
}

health_coordinate_once() {
  local coordinate="$1" response

  response="$(curl \
    --silent \
    --show-error \
    --fail \
    --connect-timeout 2 \
    --max-time 5 \
    "${OSRM_ENDPOINT}/nearest/v1/driving/${coordinate}?number=1&radiuses=${OSRM_HEALTH_MAX_SNAP_METERS}" 2>/dev/null)" || return 1
  jq -e --argjson max_snap "${OSRM_HEALTH_MAX_SNAP_METERS}" \
    '.code == "Ok"
      and (.waypoints | type == "array")
      and (.waypoints | length == 1)
      and (.waypoints[0].distance | type == "number")
      and (.waypoints[0].distance >= 0)
      and (.waypoints[0].distance <= $max_snap)' \
    >/dev/null 2>&1 <<< "${response}"
}

release_manifest_is_json_object() {
  local release="$1" manifest

  manifest="${release}/manifest.json"
  [ -f "${manifest}" ] && [ ! -L "${manifest}" ] \
    && [ "$(stat -c '%h' -- "${manifest}" 2>/dev/null || true)" = '1' ] \
    && jq -e 'type == "object"' "${manifest}" >/dev/null 2>&1
}

release_has_composite_source_manifest() {
  local release="$1"

  jq -e '.source_manifest != null' "${release}/manifest.json" >/dev/null 2>&1
}

health_once() {
  local release coordinate belgium_coordinate source_manifest

  [[ "${OSRM_HEALTH_MAX_SNAP_METERS}" =~ ^[1-9][0-9]*$ ]] \
    && [ "${OSRM_HEALTH_MAX_SNAP_METERS}" -le 5000 ] || return 1
  release="$(read_active_release)" || return 1
  release_manifest_is_json_object "${release}" || return 1
  coordinate="$(read_probe_coordinate "${release}")" || return 1
  health_coordinate_once "${coordinate}" || return 1
  if release_has_composite_source_manifest "${release}"; then
    source_manifest="$(dataset_source_manifest_for_release "${release}")" || return 1
    validate_source_manifest_json "${source_manifest}" || return 1
    belgium_coordinate="$(read_belgium_probe_coordinate "${release}")" || return 1
    health_coordinate_once "${belgium_coordinate}" || return 1
  fi
}

wait_for_health() {
  local deadline
  deadline=$((SECONDS + OSRM_HEALTH_TIMEOUT_SECONDS))

  while [ "${SECONDS}" -lt "${deadline}" ]; do
    if systemctl is-active --quiet "${OSRM_SERVICE}" && health_once; then
      return 0
    fi
    sleep 2
  done
  return 1
}

dataset_sha_for_release() {
  local release="$1"

  [ -d "${release}" ] && [ ! -L "${release}" ] || return 1
  jq -er '.source_sha256 | select(type == "string" and test("^[a-f0-9]{64}$"))' \
    "${release}/manifest.json" 2>/dev/null
}

dataset_source_manifest_for_release() {
  local release="$1" source_manifest

  [ -d "${release}" ] && [ ! -L "${release}" ] || return 1
  source_manifest="$(jq -ec '.source_manifest | select(type == "object")' \
    "${release}/manifest.json" 2>/dev/null)" || return 1
  validate_source_manifest_json "${source_manifest}" || return 1
  printf '%s\n' "${source_manifest}"
}

active_dataset_sha() {
  local release

  release="$(read_active_release)" || return 1
  dataset_sha_for_release "${release}"
}

effective_dataset_sha() {
  if [ -e "${OSRM_ACTIVATION_PENDING_FILE}" ] \
    || [ -L "${OSRM_ACTIVATION_PENDING_FILE}" ]; then
    read_pending_activation || return 1
    if pending_activation_owner_is_alive; then
      active_dataset_sha
      return
    fi
    [ -n "${OSRM_PENDING_CURRENT_TARGET}" ] || return 1
    dataset_sha_for_release "${OSRM_DATA_ROOT}/${OSRM_PENDING_CURRENT_TARGET}"
    return
  fi

  active_dataset_sha
}

record_failed_activation_status() {
  local restored_target="$1"
  local rollback_sha

  rollback_sha="$(active_dataset_sha 2>/dev/null || true)"
  if [ -n "${restored_target}" ] \
    && [ -n "${rollback_sha}" ] \
    && wait_for_health; then
    write_status ready \
      "The imported dataset was rejected; the previous healthy dataset remains active." \
      "${rollback_sha}"
  else
    write_status degraded \
      "The imported dataset failed readiness and no healthy previous dataset is available." \
      "${rollback_sha}"
  fi
}

reconcile() {
  local dataset_sha

  require_root
  acquire_dis_operation_lock osrm-reconcile
  provision
  recover_pending_activation strict \
    || fail "An interrupted OSRM activation could not be restored safely."

  if ! osrm_tools_available; then
    run_cmd systemctl stop "${OSRM_SERVICE}" >/dev/null 2>&1 || true
    write_status degraded "The pinned OSRM container runtime is unavailable or does not match the protected receipt."
    log "OSRM degraded: the pinned container runtime is unavailable or does not match the protected receipt."
    return 0
  fi

  if ! read_active_release >/dev/null; then
    run_cmd systemctl stop "${OSRM_SERVICE}" >/dev/null 2>&1 || true
    write_status degraded "No prepared OSRM dataset has been activated."
    log "OSRM degraded: no prepared dataset is active."
    return 0
  fi

  if ! run_cmd systemctl restart "${OSRM_SERVICE}" || ! wait_for_health; then
    run_cmd systemctl stop "${OSRM_SERVICE}" >/dev/null 2>&1 || true
    dataset_sha="$(active_dataset_sha 2>/dev/null || true)"
    write_status degraded "The local OSRM service failed its readiness check." "${dataset_sha}"
    log "OSRM degraded: local readiness check failed; DIS will use its routing fallback."
    return 0
  fi

  dataset_sha="$(active_dataset_sha 2>/dev/null || true)"
  write_status ready "Local road-network routing is available." "${dataset_sha}"
  log "OSRM is ready on ${OSRM_ENDPOINT}."
}

resolve_profile() {
  local requested="$1"

  [ -z "${requested}" ] \
    || fail "Custom host OSRM profiles are not accepted by the pinned container runtime."
  podman_profile_sha >/dev/null \
    || fail "The pinned OSRM container car profile is unavailable."
  printf '%s\n' "${OSRM_CONTAINER_PROFILE}"
}

validate_import_limits() {
  [[ "${OSRM_MAX_PBF_BYTES}" =~ ^[1-9][0-9]*$ ]] || fail "OSRM_MAX_PBF_BYTES is invalid."
  [ "${OSRM_MAX_PBF_BYTES}" -le 1099511627776 ] || fail "OSRM_MAX_PBF_BYTES may not exceed 1 TiB."
  [[ "${OSRM_IMPORT_DISK_FACTOR}" =~ ^[2-9]$|^1[0-6]$ ]] || fail "OSRM_IMPORT_DISK_FACTOR must be between 2 and 16."
  [[ "${OSRM_IMPORT_DISK_RESERVE_BYTES}" =~ ^[1-9][0-9]*$ ]] || fail "OSRM_IMPORT_DISK_RESERVE_BYTES is invalid."
  [[ "${OSRM_IMPORT_MEMORY_MAX}" =~ ^[1-9][0-9]*([KMGT])?$ ]] || fail "OSRM_IMPORT_MEMORY_MAX is invalid."
  [[ "${OSRM_IMPORT_CPU_QUOTA}" =~ ^[1-9][0-9]{1,3}%$ ]] || fail "OSRM_IMPORT_CPU_QUOTA is invalid."
  [[ "${OSRM_IMPORT_TIMEOUT_SECONDS}" =~ ^[1-9][0-9]*$ ]] || fail "OSRM_IMPORT_TIMEOUT_SECONDS is invalid."
  [ "${OSRM_IMPORT_TIMEOUT_SECONDS}" -le 86400 ] || fail "OSRM_IMPORT_TIMEOUT_SECONDS may not exceed 86400."
  [[ "${OSRM_HEALTH_TIMEOUT_SECONDS}" =~ ^[1-9][0-9]*$ ]] || fail "OSRM_HEALTH_TIMEOUT_SECONDS is invalid."
  [ "${OSRM_HEALTH_TIMEOUT_SECONDS}" -le 600 ] || fail "OSRM_HEALTH_TIMEOUT_SECONDS may not exceed 600."
  [[ "${OSRM_HEALTH_MAX_SNAP_METERS}" =~ ^[1-9][0-9]*$ ]] || fail "OSRM_HEALTH_MAX_SNAP_METERS is invalid."
  [ "${OSRM_HEALTH_MAX_SNAP_METERS}" -le 5000 ] || fail "OSRM_HEALTH_MAX_SNAP_METERS may not exceed 5000."
}

run_import_stage() {
  local stage="$1"
  local staging="$2"
  local token="$3"
  shift 3

  local import_gid import_uid
  local -a parent_properties=()

  if [ -n "${OSRM_IMPORT_PARENT_UNIT:-}" ]; then
    [ "${OSRM_IMPORT_PARENT_UNIT}" = "dis-osrm-admin-request.service" ] \
      || fail "OSRM_IMPORT_PARENT_UNIT is not an approved DIS unit."
    parent_properties=(
      "--property=PartOf=${OSRM_IMPORT_PARENT_UNIT}"
      "--property=BindsTo=${OSRM_IMPORT_PARENT_UNIT}"
    )
  fi

  import_uid="$(id -u "${OSRM_IMPORT_USER}")"
  import_gid="$(id -g "${OSRM_IMPORT_USER}")"
  [[ "${import_uid}" =~ ^[1-9][0-9]*$ ]] && [[ "${import_gid}" =~ ^[1-9][0-9]*$ ]] \
    || fail "The isolated OSRM import identity is invalid."

  log "Running OSRM ${stage} stage with systemd resource limits"
  run_cmd systemd-run \
    --quiet \
    --collect \
    --wait \
    --pipe \
    --unit="dis-osrm-import-${stage}-${token}" \
    --property="WorkingDirectory=${staging}" \
    --property="MemoryMax=${OSRM_IMPORT_MEMORY_MAX}" \
    --property="CPUQuota=${OSRM_IMPORT_CPU_QUOTA}" \
    --property="RuntimeMaxSec=${OSRM_IMPORT_TIMEOUT_SECONDS}" \
    --property="TasksMax=512" \
    --property="NoNewPrivileges=yes" \
    --property="PrivateTmp=yes" \
    --property="ProtectHome=yes" \
    --property="ProtectSystem=strict" \
    --property="ProtectKernelTunables=yes" \
    --property="ProtectKernelModules=yes" \
    --property="ProtectControlGroups=yes" \
    --property="RestrictSUIDSGID=yes" \
    --property="RestrictRealtime=yes" \
    --property="LockPersonality=yes" \
    --property="RestrictAddressFamilies=AF_UNIX AF_NETLINK" \
    --property="IPAddressDeny=any" \
    --property="ReadWritePaths=${staging} /var/lib/containers -/run/containers" \
    "${parent_properties[@]}" \
    -- "${OSRM_PODMAN_PATH}" "${OSRM_PODMAN_GLOBAL_ARGS[@]}" run \
      --rm \
      --pull=never \
      --network=none \
      --cgroups=disabled \
      --read-only \
      --cap-drop=all \
      --security-opt=no-new-privileges \
      --pids-limit=512 \
      --user "${import_uid}:${import_gid}" \
      --volume "${staging}:/data:rw,rprivate" \
      --workdir /data \
      --tmpfs /tmp:rw,noexec,nosuid,nodev,size=64m \
      "${OSRM_CONTAINER_IMAGE}" "$@"
}

safe_cleanup_staging() {
  local staging="$1"
  local resolved_parent

  [ -n "${staging}" ] || return 0
  [[ "${staging}" == "${OSRM_DATA_ROOT}"/.import.* ]] || return 0
  [ -e "${staging}" ] || return 0
  [ -d "${staging}" ] && [ ! -L "${staging}" ] || return 0
  resolved_parent="$(readlink -f -- "$(dirname "${staging}")")"
  [ "${resolved_parent}" = "$(readlink -f -- "${OSRM_DATA_ROOT}")" ] || return 0
  secure_path_operation remove-tree "${staging}"
}

cleanup_prepared_link() {
  local directory="${1:-}"
  local link="${2:-}"

  [ -n "${directory}" ] || return 0
  [[ "${directory}" == "${OSRM_DATA_ROOT}"/.*-link.* ]] || return 1
  [ -d "${directory}" ] && [ ! -L "${directory}" ] || return 1
  if [ -n "${link}" ]; then
    run_cmd rm -f -- "${link}"
  fi
  run_cmd rmdir -- "${directory}"
}

validate_release_target() {
  local target="$1"
  local allow_empty="${2:-false}"

  if [ -z "${target}" ]; then
    [ "${allow_empty}" = "true" ]
    return
  fi
  [[ "${target}" =~ ^releases/[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$ ]] || return 1
  [ -d "${OSRM_DATA_ROOT}/${target}" ] && [ ! -L "${OSRM_DATA_ROOT}/${target}" ]
}

process_start_ticks() {
  local pid="$1"
  local ticks

  [[ "${pid}" =~ ^[1-9][0-9]*$ ]] || return 1
  [ -r "/proc/${pid}/stat" ] || return 1
  ticks="$(sed 's/^.*) //' "/proc/${pid}/stat" | awk '{ print $20 }')"
  [[ "${ticks}" =~ ^[1-9][0-9]*$ ]] || return 1
  printf '%s\n' "${ticks}"
}

current_boot_id() {
  local boot_id

  [ -r /proc/sys/kernel/random/boot_id ] || return 1
  boot_id="$(tr -d '\r\n' < /proc/sys/kernel/random/boot_id)"
  [[ "${boot_id}" =~ ^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$ ]] \
    || return 1
  printf '%s\n' "${boot_id}"
}

write_pending_activation() {
  local current_target="$1"
  local previous_target="$2"
  local candidate_target="$3"
  local boot_id owner_start_ticks temporary

  validate_release_target "${current_target}" true || return 1
  validate_release_target "${previous_target}" true || return 1
  validate_release_target "${candidate_target}" false || return 1
  [ ! -e "${OSRM_ACTIVATION_PENDING_FILE}" ] \
    && [ ! -L "${OSRM_ACTIVATION_PENDING_FILE}" ] || return 1
  boot_id="$(current_boot_id)" || return 1
  owner_start_ticks="$(process_start_ticks "$$")" || return 1

  temporary="$(mktemp "${OSRM_DATA_ROOT}/.activation-pending.XXXXXX")" || return 1
  printf 'version=2\ncurrent_before=%s\nprevious_before=%s\ncandidate=%s\nboot_id=%s\nowner_pid=%s\nowner_start_ticks=%s\n' \
    "${current_target}" \
    "${previous_target}" \
    "${candidate_target}" \
    "${boot_id}" \
    "$$" \
    "${owner_start_ticks}" > "${temporary}"
  if ! run_cmd chown root:"${OSRM_GROUP}" "${temporary}" \
    || ! run_cmd chmod 0640 "${temporary}" \
    || ! run_cmd sync -f "${temporary}" \
    || ! run_cmd mv -fT -- "${temporary}" "${OSRM_ACTIVATION_PENDING_FILE}" \
    || ! run_cmd sync -f "${OSRM_DATA_ROOT}"; then
    run_cmd rm -f -- "${temporary}" >/dev/null 2>&1 || true
    return 1
  fi
}

OSRM_PENDING_CURRENT_TARGET=""
OSRM_PENDING_PREVIOUS_TARGET=""
OSRM_PENDING_CANDIDATE_TARGET=""
OSRM_PENDING_BOOT_ID=""
OSRM_PENDING_OWNER_PID=""
OSRM_PENDING_OWNER_START_TICKS=""

read_pending_activation() {
  local -a lines=()
  local mode uid

  [ -e "${OSRM_ACTIVATION_PENDING_FILE}" ] || [ -L "${OSRM_ACTIVATION_PENDING_FILE}" ] \
    || return 1
  [ -f "${OSRM_ACTIVATION_PENDING_FILE}" ] \
    && [ ! -L "${OSRM_ACTIVATION_PENDING_FILE}" ] \
    && [ "$(stat -c '%h' -- "${OSRM_ACTIVATION_PENDING_FILE}")" = "1" ] || return 2
  uid="$(stat -c '%u' -- "${OSRM_ACTIVATION_PENDING_FILE}")"
  mode="$(stat -c '%a' -- "${OSRM_ACTIVATION_PENDING_FILE}")"
  [ "${uid}" = "0" ] || return 2
  (( (8#${mode} & 8#022) == 0 )) || return 2
  mapfile -t lines < "${OSRM_ACTIVATION_PENDING_FILE}"
  [ "${#lines[@]}" = "7" ] \
    && [ "${lines[0]}" = "version=2" ] \
    && [[ "${lines[1]}" == current_before=* ]] \
    && [[ "${lines[2]}" == previous_before=* ]] \
    && [[ "${lines[3]}" == candidate=* ]] \
    && [[ "${lines[4]}" == boot_id=* ]] \
    && [[ "${lines[5]}" == owner_pid=* ]] \
    && [[ "${lines[6]}" == owner_start_ticks=* ]] || return 2

  OSRM_PENDING_CURRENT_TARGET="${lines[1]#current_before=}"
  OSRM_PENDING_PREVIOUS_TARGET="${lines[2]#previous_before=}"
  OSRM_PENDING_CANDIDATE_TARGET="${lines[3]#candidate=}"
  OSRM_PENDING_BOOT_ID="${lines[4]#boot_id=}"
  OSRM_PENDING_OWNER_PID="${lines[5]#owner_pid=}"
  OSRM_PENDING_OWNER_START_TICKS="${lines[6]#owner_start_ticks=}"
  validate_release_target "${OSRM_PENDING_CURRENT_TARGET}" true || return 2
  validate_release_target "${OSRM_PENDING_PREVIOUS_TARGET}" true || return 2
  validate_release_target "${OSRM_PENDING_CANDIDATE_TARGET}" false || return 2
  [[ "${OSRM_PENDING_BOOT_ID}" =~ ^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$ ]] \
    || return 2
  [[ "${OSRM_PENDING_OWNER_PID}" =~ ^[1-9][0-9]*$ ]] || return 2
  [[ "${OSRM_PENDING_OWNER_START_TICKS}" =~ ^[1-9][0-9]*$ ]] || return 2
}

pending_activation_owner_is_alive() {
  local boot_id start_ticks

  boot_id="$(current_boot_id)" || return 1
  [ "${boot_id}" = "${OSRM_PENDING_BOOT_ID}" ] || return 1
  start_ticks="$(process_start_ticks "${OSRM_PENDING_OWNER_PID}")" || return 1
  [ "${start_ticks}" = "${OSRM_PENDING_OWNER_START_TICKS}" ]
}

clear_pending_activation() {
  [ -e "${OSRM_ACTIVATION_PENDING_FILE}" ] || [ -L "${OSRM_ACTIVATION_PENDING_FILE}" ] \
    || return 0
  [ -f "${OSRM_ACTIVATION_PENDING_FILE}" ] \
    && [ ! -L "${OSRM_ACTIVATION_PENDING_FILE}" ] \
    && [ "$(stat -c '%h' -- "${OSRM_ACTIVATION_PENDING_FILE}")" = "1" ] || return 1
  run_cmd rm -f -- "${OSRM_ACTIVATION_PENDING_FILE}" \
    && run_cmd sync -f "${OSRM_DATA_ROOT}"
}

restore_dataset_pointers() {
  local current_target="$1"
  local previous_target="$2"
  local current_directory="" current_link="" previous_directory="" previous_link=""
  local restore_failed=0 target

  for target in "${current_target}" "${previous_target}"; do
    validate_release_target "${target}" true || return 1
  done

  # Prepare both replacement links before touching either live pointer. An
  # empty target represents an exactly absent pointer and is restored by
  # removing the corresponding managed symlink.
  if [ -n "${current_target}" ]; then
    current_directory="$(mktemp -d "${OSRM_DATA_ROOT}/.restore-current-link.XXXXXX")" || return 1
    if ! run_cmd chmod 0700 "${current_directory}" \
      || ! run_cmd ln -s "${current_target}" "${current_directory}/current"; then
      cleanup_prepared_link "${current_directory}" "${current_directory}/current" || true
      return 1
    fi
    current_link="${current_directory}/current"
  fi

  if [ -n "${previous_target}" ]; then
    previous_directory="$(mktemp -d "${OSRM_DATA_ROOT}/.restore-previous-link.XXXXXX")" || {
      cleanup_prepared_link "${current_directory}" "${current_link}" || true
      return 1
    }
    if ! run_cmd chmod 0700 "${previous_directory}" \
      || ! run_cmd ln -s "${previous_target}" "${previous_directory}/previous"; then
      cleanup_prepared_link "${current_directory}" "${current_link}" || true
      cleanup_prepared_link "${previous_directory}" "${previous_directory}/previous" || true
      return 1
    fi
    previous_link="${previous_directory}/previous"
  fi

  if [ -n "${current_target}" ]; then
    run_cmd mv -fT -- "${current_link}" "${OSRM_CURRENT_LINK}" || restore_failed=1
  else
    run_cmd rm -f -- "${OSRM_CURRENT_LINK}" || restore_failed=1
  fi

  if [ -n "${previous_target}" ]; then
    run_cmd mv -fT -- "${previous_link}" "${OSRM_PREVIOUS_LINK}" || restore_failed=1
  else
    run_cmd rm -f -- "${OSRM_PREVIOUS_LINK}" || restore_failed=1
  fi

  run_cmd sync -f "${OSRM_DATA_ROOT}" || restore_failed=1
  cleanup_prepared_link "${current_directory}" "${current_link}" || true
  cleanup_prepared_link "${previous_directory}" "${previous_link}" || true
  [ "${restore_failed}" = "0" ]
}

OSRM_SERVE_RELEASE_OVERRIDE=""

recover_pending_activation() {
  local recovery_mode="${1:-strict}"

  OSRM_SERVE_RELEASE_OVERRIDE=""
  if [ ! -e "${OSRM_ACTIVATION_PENDING_FILE}" ] \
    && [ ! -L "${OSRM_ACTIVATION_PENDING_FILE}" ]; then
    return 0
  fi
  read_pending_activation \
    || return 1

  if pending_activation_owner_is_alive; then
    if [ "${recovery_mode}" = "serve" ]; then
      log "OSRM candidate activation is still owned by the active import process"
      return 0
    fi
    return 1
  fi

  log "Recovering interrupted OSRM dataset activation"
  if restore_dataset_pointers \
    "${OSRM_PENDING_CURRENT_TARGET}" \
    "${OSRM_PENDING_PREVIOUS_TARGET}" \
    && clear_pending_activation; then
    OSRM_ACTIVATION_PENDING=0
    return 0
  fi

  # The systemd service deliberately runs without write access to the
  # root-controlled pointer directory. On an unattended reboot it can still
  # fail closed to the last committed release recorded in the durable marker;
  # the next privileged reconcile repairs the pointers and removes the marker.
  if [ "${recovery_mode}" = "serve" ] \
    && [ -n "${OSRM_PENDING_CURRENT_TARGET}" ] \
    && validate_release_target "${OSRM_PENDING_CURRENT_TARGET}" false; then
    OSRM_SERVE_RELEASE_OVERRIDE="${OSRM_DATA_ROOT}/${OSRM_PENDING_CURRENT_TARGET}"
    log "Serving the last committed OSRM release until privileged pointer recovery completes"
    return 0
  fi
  return 1
}

is_managed_scratch_path() {
  local path="$1" name resolved_parent resolved_root

  [ -n "${path}" ] || return 1
  name="${path##*/}"
  [[ "${name}" =~ ^\.(import|admin-download)\.[A-Za-z0-9]{6}$ ]] || return 1
  [ "${path}" = "${OSRM_DATA_ROOT}/${name}" ] || return 1
  resolved_root="$(readlink -f -- "${OSRM_DATA_ROOT}" 2>/dev/null)" || return 1
  resolved_parent="$(readlink -f -- "$(dirname -- "${path}")" 2>/dev/null)" || return 1
  [ "${resolved_parent}" = "${resolved_root}" ]
}

sweep_stale_scratch() {
  local active_scratch="${OSRM_ACTIVE_SCRATCH_PATH:-}"
  local build_uid candidate mode name root_mode root_uid uid removed=0
  local -a candidates

  require_root
  acquire_dis_operation_lock osrm-scratch-sweep
  [ -d "${OSRM_DATA_ROOT}" ] && [ ! -L "${OSRM_DATA_ROOT}" ] \
    || fail "The OSRM data root is not a real managed directory; scratch recovery stopped safely."
  root_uid="$(stat -c '%u' -- "${OSRM_DATA_ROOT}")"
  root_mode="$(stat -c '%a' -- "${OSRM_DATA_ROOT}")"
  [[ "${root_mode}" =~ ^[0-7]{3,4}$ ]] \
    && [ "${root_uid}" = "0" ] \
    && (( (8#${root_mode} & 8#022) == 0 )) \
    || fail "The OSRM data root is not root-controlled; scratch recovery stopped safely."
  build_uid="$(id -u "${OSRM_IMPORT_USER}" 2>/dev/null)" \
    || fail "The isolated OSRM import account is unavailable for scratch recovery."
  [[ "${build_uid}" =~ ^[1-9][0-9]*$ ]] \
    || fail "The isolated OSRM import account has an unsafe uid."

  if [ -e "${OSRM_ACTIVATION_PENDING_FILE}" ] || [ -L "${OSRM_ACTIVATION_PENDING_FILE}" ]; then
    read_pending_activation \
      || fail "Pending OSRM activation metadata is unsafe; scratch recovery stopped safely."
    if pending_activation_owner_is_alive; then
      log "An active OSRM activation owner is present; stale scratch recovery is deferred"
      return 0
    fi
  fi

  if [ -n "${active_scratch}" ]; then
    is_managed_scratch_path "${active_scratch}" \
      || fail "OSRM_ACTIVE_SCRATCH_PATH is not an exact managed scratch path."
    if [ -e "${active_scratch}" ] || [ -L "${active_scratch}" ]; then
      [ -d "${active_scratch}" ] && [ ! -L "${active_scratch}" ] \
        || fail "The active OSRM scratch path is not a real directory."
      uid="$(stat -c '%u' -- "${active_scratch}")"
      mode="$(stat -c '%a' -- "${active_scratch}")"
      [[ "${mode}" =~ ^[0-7]{3,4}$ ]] \
        && { [ "${uid}" = "0" ] || [ "${uid}" = "${build_uid}" ]; } \
        && (( (8#${mode} & 8#022) == 0 )) \
        || fail "The active OSRM scratch path is not safely controlled."
    fi
  fi

  candidates=(
    "${OSRM_DATA_ROOT}"/.import.*
    "${OSRM_DATA_ROOT}"/.admin-download.*
  )
  for candidate in "${candidates[@]}"; do
    [ -e "${candidate}" ] || [ -L "${candidate}" ] || continue
    is_managed_scratch_path "${candidate}" || continue
    [ "${candidate}" != "${active_scratch}" ] || continue
    name="${candidate##*/}"
    if [ ! -d "${candidate}" ] || [ -L "${candidate}" ]; then
      log "WARNING: Refusing to remove non-directory OSRM scratch entry ${name}"
      continue
    fi
    uid="$(stat -c '%u' -- "${candidate}")"
    mode="$(stat -c '%a' -- "${candidate}")"
    if ! [[ "${mode}" =~ ^[0-7]{3,4}$ ]] \
      || { [ "${uid}" != "0" ] && [ "${uid}" != "${build_uid}" ]; } \
      || (( (8#${mode} & 8#022) != 0 )); then
      log "WARNING: Refusing to remove uncontrolled OSRM scratch directory ${name}"
      continue
    fi
    log "Removing stale OSRM scratch directory ${name}"
    secure_path_operation remove-tree "${candidate}"
    removed=1
  done
  if [ "${removed}" = "1" ]; then
    run_cmd sync -f "${OSRM_DATA_ROOT}"
  fi
}

prune_releases() {
  local link mode release_id release_path target uid keep_count=0 removed=0
  local -a releases=()
  declare -A protected=()

  require_root
  [[ "${OSRM_RELEASE_RETENTION}" =~ ^[0-9]+$ ]] \
    && [ "${OSRM_RELEASE_RETENTION}" -ge 3 ] \
    && [ "${OSRM_RELEASE_RETENTION}" -le 20 ] \
    || fail "OSRM_RELEASE_RETENTION must be between 3 and 20."
  acquire_dis_operation_lock osrm-prune
  [ -d "${OSRM_RELEASES_ROOT}" ] && [ ! -L "${OSRM_RELEASES_ROOT}" ] || return 0

  for link in "${OSRM_CURRENT_LINK}" "${OSRM_PREVIOUS_LINK}"; do
    if [ -e "${link}" ] && [ ! -L "${link}" ]; then
      fail "An OSRM release pointer is not a managed symlink; retention stopped safely."
    fi
    [ -L "${link}" ] || continue
    target="$(readlink -- "${link}")"
    validate_release_target "${target}" false \
      || fail "An OSRM release pointer is unsafe; retention stopped safely."
    protected["${target#releases/}"]=1
  done

  if [ -e "${OSRM_ACTIVATION_PENDING_FILE}" ] || [ -L "${OSRM_ACTIVATION_PENDING_FILE}" ]; then
    read_pending_activation \
      || fail "Pending OSRM activation metadata is unsafe; retention stopped safely."
    for target in \
      "${OSRM_PENDING_CURRENT_TARGET}" \
      "${OSRM_PENDING_PREVIOUS_TARGET}" \
      "${OSRM_PENDING_CANDIDATE_TARGET}"; do
      [ -n "${target}" ] || continue
      validate_release_target "${target}" false \
        || fail "A pending OSRM release target is unsafe; retention stopped safely."
      protected["${target#releases/}"]=1
    done
  fi

  mapfile -t releases < <(
    find "${OSRM_RELEASES_ROOT}" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' \
      | grep -E '^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$' \
      | LC_ALL=C sort -r || true
  )
  for release_id in "${releases[@]}"; do
    release_path="${OSRM_RELEASES_ROOT}/${release_id}"
    [ -d "${release_path}" ] && [ ! -L "${release_path}" ] \
      || fail "An OSRM release selected for retention is unsafe."
    uid="$(stat -c '%u' -- "${release_path}")"
    mode="$(stat -c '%a' -- "${release_path}")"
    [ "${uid}" = "0" ] && (( (8#${mode} & 8#022) == 0 )) \
      || fail "An OSRM release selected for retention is not root-controlled."
    if [ "${protected[${release_id}]:-0}" = "1" ]; then
      keep_count=$((keep_count + 1))
    fi
  done

  for release_id in "${releases[@]}"; do
    [ "${protected[${release_id}]:-0}" != "1" ] || continue
    if [ "${keep_count}" -lt "${OSRM_RELEASE_RETENTION}" ]; then
      keep_count=$((keep_count + 1))
      continue
    fi
    release_path="${OSRM_RELEASES_ROOT}/${release_id}"
    log "Removing expired OSRM release ${release_id} under bounded retention"
    secure_path_operation remove-tree "${release_path}"
    removed=1
  done
  if [ "${removed}" = "1" ]; then
    run_cmd sync -f "${OSRM_RELEASES_ROOT}"
  fi
}

prune_releases_best_effort() {
  if ! (prune_releases); then
    log "WARNING: OSRM is committed and healthy, but post-activation release retention failed; retry 'osrm.sh prune' during maintenance."
  else
    log "OSRM release retention completed; current, previous and pending targets remain protected."
  fi
  return 0
}

OSRM_ACTIVATED_FROM=""
OSRM_PREVIOUS_BEFORE_ACTIVATION=""

activate_release() {
  local release_id="$1"
  local old_target=""
  local previous_target=""
  local current_directory current_link previous_directory previous_link

  [ -d "${OSRM_RELEASES_ROOT}/${release_id}" ] && [ ! -L "${OSRM_RELEASES_ROOT}/${release_id}" ] \
    || fail "The prepared OSRM release is not a managed directory."
  [ ! -e "${OSRM_ACTIVATION_PENDING_FILE}" ] \
    && [ ! -L "${OSRM_ACTIVATION_PENDING_FILE}" ] \
    || fail "A prior OSRM activation is still pending recovery."

  if [ -e "${OSRM_CURRENT_LINK}" ] && [ ! -L "${OSRM_CURRENT_LINK}" ]; then
    fail "OSRM current dataset pointer is not a managed symlink."
  fi
  if [ -L "${OSRM_CURRENT_LINK}" ]; then
    old_target="$(readlink -- "${OSRM_CURRENT_LINK}")"
    [[ "${old_target}" =~ ^releases/[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$ ]] \
      || fail "OSRM current dataset pointer is unsafe."
    [ -d "${OSRM_DATA_ROOT}/${old_target}" ] && [ ! -L "${OSRM_DATA_ROOT}/${old_target}" ] \
      || fail "OSRM current dataset pointer is broken."
  fi
  if [ -e "${OSRM_PREVIOUS_LINK}" ] && [ ! -L "${OSRM_PREVIOUS_LINK}" ]; then
    fail "OSRM previous dataset pointer is not a managed symlink."
  fi
  if [ -L "${OSRM_PREVIOUS_LINK}" ]; then
    previous_target="$(readlink -- "${OSRM_PREVIOUS_LINK}")"
    [[ "${previous_target}" =~ ^releases/[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$ ]] \
      || fail "OSRM previous dataset pointer is unsafe."
    [ -d "${OSRM_DATA_ROOT}/${previous_target}" ] && [ ! -L "${OSRM_DATA_ROOT}/${previous_target}" ] \
      || fail "OSRM previous dataset pointer is broken."
  fi

  OSRM_ACTIVATED_FROM="${old_target}"
  OSRM_PREVIOUS_BEFORE_ACTIVATION="${previous_target}"
  OSRM_ACTIVATION_ROLLBACK_TARGET="${old_target}"
  OSRM_ACTIVATION_ROLLBACK_PREVIOUS_TARGET="${previous_target}"

  # Prepare every link needed for activation and rollback before changing the
  # live pointer. The parent is root-controlled, so these private directories
  # cannot be replaced by either OSRM account.
  current_directory="$(mktemp -d "${OSRM_DATA_ROOT}/.current-link.XXXXXX")"
  run_cmd chmod 0700 "${current_directory}"
  current_link="${current_directory}/current"
  run_cmd ln -s "releases/${release_id}" "${current_link}"

  if [ -n "${old_target}" ]; then
    previous_directory="$(mktemp -d "${OSRM_DATA_ROOT}/.previous-link.XXXXXX")"
    run_cmd chmod 0700 "${previous_directory}"
    previous_link="${previous_directory}/previous"
    run_cmd ln -s "${old_target}" "${previous_link}"

  fi

  OSRM_ACTIVATION_PENDING=1
  if ! write_pending_activation \
    "${old_target}" \
    "${previous_target}" \
    "releases/${release_id}"; then
    if restore_dataset_pointers "${old_target}" "${previous_target}" \
      && clear_pending_activation; then
      OSRM_ACTIVATION_PENDING=0
    fi
    cleanup_prepared_link "${current_directory}" "${current_link}" || true
    cleanup_prepared_link "${previous_directory:-}" "${previous_link:-}" || true
    return 1
  fi
  if ! run_cmd mv -fT -- "${current_link}" "${OSRM_CURRENT_LINK}"; then
    if restore_dataset_pointers "${old_target}" "${previous_target}" \
      && clear_pending_activation; then
      OSRM_ACTIVATION_PENDING=0
    fi
    cleanup_prepared_link "${current_directory}" "${current_link}" || true
    cleanup_prepared_link "${previous_directory:-}" "${previous_link:-}" || true
    return 1
  fi

  if [ -n "${old_target}" ] && ! run_cmd mv -fT -- "${previous_link}" "${OSRM_PREVIOUS_LINK}"; then
    if restore_dataset_pointers "${old_target}" "${previous_target}" \
      && clear_pending_activation; then
      OSRM_ACTIVATION_PENDING=0
    fi
    cleanup_prepared_link "${current_directory}" "${current_link}" || true
    cleanup_prepared_link "${previous_directory}" "${previous_link}" || true
    return 1
  fi

  if ! run_cmd sync -f "${OSRM_DATA_ROOT}"; then
    if restore_dataset_pointers "${old_target}" "${previous_target}" \
      && clear_pending_activation; then
      OSRM_ACTIVATION_PENDING=0
    fi
    cleanup_prepared_link "${current_directory}" "${current_link}" || true
    cleanup_prepared_link "${previous_directory:-}" "${previous_link:-}" || true
    return 1
  fi

  cleanup_prepared_link "${current_directory}" "${current_link}" || true
  cleanup_prepared_link "${previous_directory:-}" "${previous_link:-}" || true
}

rollback_release() {
  local old_target="$1"
  local previous_target="${2:-}"

  run_cmd systemctl stop "${OSRM_SERVICE}" >/dev/null 2>&1 || true
  restore_dataset_pointers "${old_target}" "${previous_target}" || return 1
  clear_pending_activation || return 1

  if [ -n "${old_target}" ]; then
    run_cmd systemctl restart "${OSRM_SERVICE}" >/dev/null 2>&1 || true
  fi
}

OSRM_ACTIVATION_PENDING=0
OSRM_ACTIVATION_ROLLBACK_TARGET=""
OSRM_ACTIVATION_ROLLBACK_PREVIOUS_TARGET=""

rollback_pending_activation_on_exit() {
  local status="$?"

  trap - EXIT
  if [ "${OSRM_ACTIVATION_PENDING}" = "1" ]; then
    log "Rolling back an incomplete OSRM dataset activation"
    if rollback_release \
      "${OSRM_ACTIVATION_ROLLBACK_TARGET}" \
      "${OSRM_ACTIVATION_ROLLBACK_PREVIOUS_TARGET}"; then
      OSRM_ACTIVATION_PENDING=0
    fi
  fi
  exit "${status}"
}

canonical_source_set_json() {
  # This exact compact JSON byte sequence is the source-set identity shared
  # with the backend and privileged request worker. Do not append a newline.
  printf '%s' '[{"id":"netherlands","latest_url":"https://download.geofabrik.de/europe/netherlands-latest.osm.pbf"},{"id":"belgium","latest_url":"https://download.geofabrik.de/europe/belgium-latest.osm.pbf"}]'
}

validate_source_manifest_json() {
  local manifest="$1" actual_sha expected_sha snapshot_date snapshot_stamp source_timestamp

  jq -e --argjson max_size "${OSRM_MAX_PBF_BYTES}" '
    type == "object"
    and keys == ["snapshot_date","source_set_sha256","source_timestamp","sources"]
    and (.source_set_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
    and (.snapshot_date | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}$"))
    and (.source_timestamp | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
    and (.sources | type == "array" and length == 2)
    and (.sources[0] | type == "object" and keys == ["filename","id","md5","size_bytes","version_url"])
    and (.sources[0].id == "netherlands")
    and (.sources[0].filename | test("^netherlands-[0-9]{6}[.]osm[.]pbf$"))
    and (.sources[0].version_url == "https://download.geofabrik.de/europe/\(.sources[0].filename)")
    and (.sources[1] | type == "object" and keys == ["filename","id","md5","size_bytes","version_url"])
    and (.sources[1].id == "belgium")
    and (.sources[1].filename | test("^belgium-[0-9]{6}[.]osm[.]pbf$"))
    and (.sources[1].version_url == "https://download.geofabrik.de/europe/\(.sources[1].filename)")
    and (all(.sources[];
      (.md5 | type == "string" and test("^[a-f0-9]{32}$"))
      and (.size_bytes | type == "number" and floor == . and . > 0 and . <= $max_size)
    ))
  ' <<< "${manifest}" >/dev/null || return 1
  snapshot_date="$(jq -er '.snapshot_date' <<< "${manifest}")" || return 1
  source_timestamp="$(jq -er '.source_timestamp' <<< "${manifest}")" || return 1
  python3 -I -S -c '
from datetime import datetime
import sys
try:
    snapshot = datetime.strptime(sys.argv[1], "%Y-%m-%d")
    source_timestamp = datetime.fromisoformat(sys.argv[2].replace("Z", "+00:00"))
except (ValueError, IndexError):
    raise SystemExit(1)
if source_timestamp.date() != snapshot.date():
    raise SystemExit(1)
' "${snapshot_date}" "${source_timestamp}" >/dev/null 2>&1 || return 1
  snapshot_stamp="$(date -u -d "${snapshot_date}" +%y%m%d 2>/dev/null)" || return 1
  jq -e --arg stamp "${snapshot_stamp}" '
    .sources[0].filename == "netherlands-\($stamp).osm.pbf"
    and .sources[1].filename == "belgium-\($stamp).osm.pbf"
  ' <<< "${manifest}" >/dev/null || return 1
  expected_sha="$(jq -er '.source_set_sha256' <<< "${manifest}")" || return 1
  actual_sha="$(canonical_source_set_json | sha256sum | awk '{ print $1 }')" || return 1
  [ "${actual_sha}" = "${expected_sha}" ]
}

validate_source_manifest_file() {
  local manifest_path="$1" actual_sha expected_sha manifest_size snapshot_date snapshot_stamp source_timestamp

  [ -f "${manifest_path}" ] && [ ! -L "${manifest_path}" ] \
    && [ "$(stat -c '%u:%g:%a:%h' -- "${manifest_path}" 2>/dev/null || true)" = '0:0:400:1' ] \
    || return 1
  manifest_size="$(stat -c '%s' -- "${manifest_path}" 2>/dev/null || true)"
  [[ "${manifest_size}" =~ ^[1-9][0-9]*$ ]] && [ "${manifest_size}" -le 16384 ] \
    || return 1
  python3 -I -S "${COMMON_LIB_DIR}/secure-path.py" verify-parent "${manifest_path}" >/dev/null 2>&1 \
    || return 1
  validate_source_manifest_json "$(jq -c '.' "${manifest_path}" 2>/dev/null)" || return 1
  jq -e '
    type == "object"
    and keys == ["snapshot_date","source_set_sha256","source_timestamp","sources"]
    and (.source_set_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
    and (.snapshot_date | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}$"))
    and (.source_timestamp | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
    and (.sources | type == "array" and length == 2)
    and (.sources[0] | type == "object" and keys == ["filename","id","md5","size_bytes","version_url"])
    and (.sources[0].id == "netherlands")
    and (.sources[0].filename | test("^netherlands-[0-9]{6}[.]osm[.]pbf$"))
    and (.sources[0].version_url == "https://download.geofabrik.de/europe/\(.sources[0].filename)")
    and (.sources[1] | type == "object" and keys == ["filename","id","md5","size_bytes","version_url"])
    and (.sources[1].id == "belgium")
    and (.sources[1].filename | test("^belgium-[0-9]{6}[.]osm[.]pbf$"))
    and (.sources[1].version_url == "https://download.geofabrik.de/europe/\(.sources[1].filename)")
    and (all(.sources[];
      (.md5 | type == "string" and test("^[a-f0-9]{32}$"))
      and (.size_bytes | type == "number" and floor == . and . > 0)
    ))
  ' "${manifest_path}" >/dev/null || return 1
  snapshot_date="$(jq -er '.snapshot_date' "${manifest_path}")" || return 1
  source_timestamp="$(jq -er '.source_timestamp' "${manifest_path}")" || return 1
  python3 -I -S -c '
from datetime import datetime
import sys
try:
    snapshot = datetime.strptime(sys.argv[1], "%Y-%m-%d")
    source_timestamp = datetime.fromisoformat(sys.argv[2].replace("Z", "+00:00"))
except (ValueError, IndexError):
    raise SystemExit(1)
if source_timestamp.date() != snapshot.date():
    raise SystemExit(1)
' "${snapshot_date}" "${source_timestamp}" >/dev/null 2>&1 || return 1
  snapshot_stamp="$(date -u -d "${snapshot_date}" +%y%m%d 2>/dev/null)" || return 1
  jq -e --arg stamp "${snapshot_stamp}" '
    .sources[0].filename == "netherlands-\($stamp).osm.pbf"
    and .sources[1].filename == "belgium-\($stamp).osm.pbf"
  ' "${manifest_path}" >/dev/null || return 1
  expected_sha="$(jq -er '.source_set_sha256' "${manifest_path}")" || return 1
  actual_sha="$(canonical_source_set_json | sha256sum | awk '{ print $1 }')" \
    || return 1
  [ "${actual_sha}" = "${expected_sha}" ]
}

import_dataset() {
  local pbf=""
  local expected_sha=""
  local source_manifest_path=""
  local coordinate=""
  local belgium_coordinate=""
  local requested_profile=""
  local profile profile_sha source_before source_after source_size copy_limit actual_sha
  local available_bytes required_bytes filesystem_bytes release_id release_path
  local staging="" token tool_version
  local active_scratch="" old_target previous_target manifest_temp source_manifest_json=null source_parent source_real
  local -a artifacts

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --pbf)
        pbf="${2:-}"
        shift 2
        ;;
      --sha256)
        expected_sha="${2:-}"
        shift 2
        ;;
      --source-manifest)
        source_manifest_path="${2:-}"
        shift 2
        ;;
      --health-coordinate)
        coordinate="${2:-}"
        shift 2
        ;;
      --profile)
        requested_profile="${2:-}"
        shift 2
        ;;
      -h|--help)
        usage
        return 0
        ;;
      *)
        fail "Unknown OSRM import option: $1"
        ;;
    esac
  done

  require_root
  require_ubuntu_2604
  validate_import_limits
  acquire_dis_operation_lock osrm-import
  provision
  recover_pending_activation strict \
    || fail "An interrupted OSRM activation could not be restored before import."

  [ -n "${pbf}" ] || fail "--pbf is required."
  [ -n "${expected_sha}" ] || fail "--sha256 is required."
  [ -n "${coordinate}" ] || fail "--health-coordinate is required."
  if [ -n "${expected_sha}" ]; then
    expected_sha="${expected_sha,,}"
    [[ "${expected_sha}" =~ ^[a-f0-9]{64}$ ]] \
      || fail "--sha256 must contain exactly 64 hexadecimal characters."
  fi
  if [ -n "${source_manifest_path}" ]; then
    [ -n "${expected_sha}" ] \
      || fail "--source-manifest requires the merged --sha256 value."
    validate_source_manifest_file "${source_manifest_path}" \
      || fail "--source-manifest must be a strict root-owned NL+BE source manifest."
    source_manifest_json="$(jq -c '.' "${source_manifest_path}")"
    belgium_coordinate="${OSRM_BELGIUM_HEALTH_COORDINATE}"
    validate_belgium_coordinate "${belgium_coordinate}" \
      || fail "OSRM_BELGIUM_HEALTH_COORDINATE must be a coordinate inside Belgium."
  fi
  validate_coordinate "${coordinate}" || fail "--health-coordinate must be longitude,latitude within valid ranges."
  [[ "${pbf}" == *.osm.pbf ]] || fail "The import source must have the .osm.pbf suffix."
  [ -f "${pbf}" ] && [ ! -L "${pbf}" ] || fail "The import source must be a regular, non-symlink file."
  [ "$(stat -c '%h' -- "${pbf}")" = "1" ] || fail "The import source must not have hard links."

  if [ -n "${OSRM_ACTIVE_SCRATCH_PATH:-}" ]; then
    active_scratch="${OSRM_ACTIVE_SCRATCH_PATH}"
  else
    source_real="$(readlink -f -- "${pbf}")" \
      || fail "The import source could not be resolved safely."
    source_parent="$(dirname -- "${source_real}")"
    if is_managed_scratch_path "${OSRM_DATA_ROOT}/${source_parent##*/}" \
      && [ "${source_parent}" = "$(readlink -f -- "${OSRM_DATA_ROOT}")/${source_parent##*/}" ]; then
      active_scratch="${OSRM_DATA_ROOT}/${source_parent##*/}"
    fi
  fi
  OSRM_ACTIVE_SCRATCH_PATH="${active_scratch}" sweep_stale_scratch
  prune_releases

  if ! osrm_tools_available; then
    fail "Required OSRM tools are unavailable or do not match the protected installation receipt. Run install-package before importing."
  fi
  command -v systemd-run >/dev/null 2>&1 || fail "systemd-run is required for resource-limited OSRM preprocessing."

  profile="$(resolve_profile "${requested_profile}")"
  profile_sha="$(podman_profile_sha)"
  [[ "${profile_sha}" =~ ^[a-f0-9]{64}$ ]] \
    || fail "The pinned OSRM container profile fingerprint is invalid."

  source_before="$(stat -c '%d:%i:%s:%Y:%Z' -- "${pbf}")"
  source_size="$(stat -c '%s' -- "${pbf}")"
  [[ "${source_size}" =~ ^[0-9]+$ ]] && [ "${source_size}" -gt 0 ] || fail "The import source is empty or has an invalid size."
  [ "${source_size}" -le "${OSRM_MAX_PBF_BYTES}" ] || fail "The import source exceeds OSRM_MAX_PBF_BYTES."
  copy_limit=$((source_size + 1))

  read -r filesystem_bytes available_bytes < <(df -PB1 "${OSRM_DATA_ROOT}" | awk 'NR == 2 { print $2, $4 }')
  [[ "${filesystem_bytes}" =~ ^[0-9]+$ ]] && [[ "${available_bytes}" =~ ^[0-9]+$ ]] \
    || fail "OSRM filesystem capacity could not be determined."
  required_bytes=$((source_size * OSRM_IMPORT_DISK_FACTOR + OSRM_IMPORT_DISK_RESERVE_BYTES))
  [ "${available_bytes}" -ge "${required_bytes}" ] \
    || fail "Insufficient free space for bounded OSRM preprocessing."

  staging="$(mktemp -d "${OSRM_DATA_ROOT}/.import.XXXXXX")"
  trap 'safe_cleanup_staging "${staging}"' EXIT
  run_cmd chown "${OSRM_IMPORT_USER}:${OSRM_GROUP}" "${staging}"
  run_cmd chmod 0750 "${staging}"

  log "Snapshotting and verifying the operator-supplied OSM PBF"
  run_cmd dd \
    if="${pbf}" \
    of="${staging}/routing.osm.pbf" \
    bs=4194304 \
    count="${copy_limit}" \
    iflag=nofollow,fullblock,count_bytes \
    oflag=nofollow \
    conv=excl \
    status=none
  source_after="$(stat -c '%d:%i:%s:%Y:%Z' -- "${pbf}")"
  [ "${source_before}" = "${source_after}" ] || fail "The import source changed while it was being snapshotted."
  [ "$(stat -c '%s' -- "${staging}/routing.osm.pbf")" = "${source_size}" ] \
    || fail "The OSM PBF snapshot size does not match the source."
  actual_sha="$(sha256sum -- "${staging}/routing.osm.pbf" | awk '{ print $1 }')"
  if [ -n "${expected_sha}" ] && [ "${actual_sha}" != "${expected_sha}" ]; then
    fail "The OSM PBF SHA-256 does not match the operator-supplied value."
  fi
  run_cmd chown "${OSRM_IMPORT_USER}:${OSRM_GROUP}" "${staging}/routing.osm.pbf"
  run_cmd chmod 0440 "${staging}/routing.osm.pbf"

  token="$(openssl rand -hex 6)"
  run_import_stage extract "${staging}" "${token}" osrm-extract -p "${profile}" /data/routing.osm.pbf
  run_import_stage partition "${staging}" "${token}" osrm-partition /data/routing.osrm
  run_import_stage customize "${staging}" "${token}" osrm-customize /data/routing.osrm
  run_cmd rm -f -- "${staging}/routing.osm.pbf"

  # Every parser stage has exited at this point. Reject links, hard links,
  # special files and mounted subtrees before progressively freezing the
  # complete tree through descriptors. From here on only root can add names.
  validate_plain_tree "${staging}"
  repair_managed_tree "${staging}" root "${OSRM_GROUP}" 0750 0440
  validate_plain_tree "${staging}"

  mapfile -d '' artifacts < <(find "${staging}" -maxdepth 1 -type f -name 'routing.osrm*' -print0 | sort -z)
  [ "${#artifacts[@]}" -ge 4 ] || fail "OSRM preprocessing did not produce a complete MLD dataset."
  [ -s "${staging}/routing.osrm.partition" ] \
    || fail "OSRM preprocessing did not produce the MLD partition artifact."
  [ -s "${staging}/routing.osrm.cells" ] \
    || fail "OSRM preprocessing did not produce the MLD cells artifact."

  log "Hashing the prepared OSRM artifacts"
  (
    cd "${staging}"
    find . -maxdepth 1 -type f -name 'routing.osrm*' -printf '%P\0' \
      | sort -z \
      | xargs -0 sha256sum --
  ) | secure_path_operation write-file \
    "${staging}/ARTIFACTS.SHA256" root "${OSRM_GROUP}" 0440
  (
    cd "${staging}"
    sha256sum --check --strict ARTIFACTS.SHA256
  ) >/dev/null

  tool_version="$("${OSRM_PODMAN_PATH}" "${OSRM_PODMAN_GLOBAL_ARGS[@]}" \
    run --rm --pull=never --network=none --read-only \
    --cap-drop=all --security-opt=no-new-privileges --pids-limit=32 \
    "${OSRM_CONTAINER_IMAGE}" osrm-routed --version 2>&1 | head -n 1 || true)"
  tool_version="${tool_version:0:200}"
  printf '%s\n' "${coordinate}" \
    | secure_path_operation write-file \
      "${staging}/health-coordinate" root "${OSRM_GROUP}" 0440
  if [ "${source_manifest_json}" != "null" ]; then
    printf '%s\n' "${belgium_coordinate}" \
      | secure_path_operation write-file \
        "${staging}/health-coordinate-belgium" root "${OSRM_GROUP}" 0440
  fi
  manifest_temp="${staging}/manifest.json"
  jq -n \
    --arg source_sha256 "${actual_sha}" \
    --arg source_size_bytes "${source_size}" \
    --arg profile_sha256 "${profile_sha}" \
    --arg profile_path "${profile}" \
    --arg osrm_version "${tool_version}" \
    --arg imported_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --argjson source_manifest "${source_manifest_json}" \
    '{
      source_sha256: $source_sha256,
      source_manifest: $source_manifest,
      source_size_bytes: ($source_size_bytes | tonumber),
      profile_sha256: $profile_sha256,
      profile_path: $profile_path,
      osrm_version: $osrm_version,
      algorithm: "mld",
      imported_at: $imported_at
    }' | secure_path_operation write-file \
      "${manifest_temp}" root "${OSRM_GROUP}" 0440

  validate_plain_tree "${staging}"
  secure_path_operation sync-tree "${staging}"

  release_id="$(date -u +%Y%m%dT%H%M%SZ)-${actual_sha:0:12}"
  release_path="${OSRM_RELEASES_ROOT}/${release_id}"
  [ ! -e "${release_path}" ] && [ ! -L "${release_path}" ] || fail "An OSRM release with this identifier already exists."
  run_cmd mv -T -- "${staging}" "${release_path}"
  staging=""
  trap - EXIT
  run_cmd sync -f "${OSRM_RELEASES_ROOT}"

  trap rollback_pending_activation_on_exit EXIT
  if ! activate_release "${release_id}"; then
    fail "The prepared OSRM dataset could not be activated; the prior dataset pointers were restored when possible."
  fi
  old_target="${OSRM_ACTIVATED_FROM}"
  previous_target="${OSRM_PREVIOUS_BEFORE_ACTIVATION}"
  log "Starting the newly prepared OSRM dataset"
  if ! run_cmd systemctl restart "${OSRM_SERVICE}" || ! wait_for_health; then
    if ! rollback_release "${old_target}" "${previous_target}"; then
      fail "The imported OSRM dataset failed readiness and the prior dataset pointers could not be fully restored."
    fi
    OSRM_ACTIVATION_PENDING=0
    trap - EXIT
    record_failed_activation_status "${old_target}"
    fail "The imported OSRM dataset failed its local readiness check; the previous dataset was restored when available."
  fi

  clear_pending_activation \
    || fail "The healthy OSRM dataset could not be committed durably."
  OSRM_ACTIVATION_PENDING=0
  trap - EXIT
  write_status ready "Local road-network routing is available." "${actual_sha}"
  log "OSRM dataset ${release_id} is active and healthy."
  prune_releases_best_effort
}

verify_active() {
  local release mode uid entry

  require_root
  release="$(read_active_release)" || fail "No managed OSRM release is active."
  require_file "${release}/manifest.json"
  release_manifest_is_json_object "${release}" \
    || fail "The active OSRM release manifest is not a valid JSON object."
  require_file "${release}/ARTIFACTS.SHA256"
  require_file "${release}/health-coordinate"
  if jq -e '.source_manifest != null' "${release}/manifest.json" >/dev/null 2>&1; then
    dataset_source_manifest_for_release "${release}" >/dev/null \
      || fail "The active composite OSRM source manifest is invalid."
    require_file "${release}/health-coordinate-belgium"
    read_belgium_probe_coordinate "${release}" >/dev/null \
      || fail "The active composite OSRM Belgian readiness probe is invalid."
  fi
  validate_plain_tree "${release}"

  while IFS= read -r -d '' entry; do
    uid="$(stat -c '%u' -- "${entry}")"
    mode="$(stat -c '%a' -- "${entry}")"
    [ "${uid}" = "0" ] || fail "Active OSRM data is not root-owned: ${entry}"
    if (( (8#${mode} & 8#022) != 0 )); then
      fail "Active OSRM data is writable outside root ownership: ${entry}"
    fi
  done < <(find "${release}" \( -type d -o -type f \) -print0)

  (
    cd "${release}"
    sha256sum --check --strict ARTIFACTS.SHA256
  )
  log "Active OSRM artifact integrity is valid."
}

health() {
  if ! systemctl is-active --quiet "${OSRM_SERVICE}"; then
    fail "OSRM service is not active."
  fi
  health_once || fail "OSRM readiness request failed."
  log "OSRM readiness check passed."
}

serve() {
  local osrm_gid osrm_uid release

  require_root
  recover_pending_activation serve \
    || fail "An interrupted OSRM activation has no safe committed release to serve."
  osrm_tools_available \
    || fail "The pinned OSRM Podman runtime does not match its protected provenance receipt."
  if [ -n "${OSRM_SERVE_RELEASE_OVERRIDE}" ]; then
    release="${OSRM_SERVE_RELEASE_OVERRIDE}"
  else
    release="$(read_active_release)" || fail "No managed OSRM release is active."
  fi
  validate_plain_tree "${release}"
  [ -s "${release}/routing.osrm.partition" ] \
    || fail "No prepared MLD dataset is active."

  osrm_uid="$(id -u "${OSRM_USER}")"
  osrm_gid="$(id -g "${OSRM_USER}")"
  [[ "${osrm_uid}" =~ ^[1-9][0-9]*$ ]] && [[ "${osrm_gid}" =~ ^[1-9][0-9]*$ ]] \
    || fail "The isolated OSRM runtime identity is invalid."

  exec "${OSRM_PODMAN_PATH}" "${OSRM_PODMAN_GLOBAL_ARGS[@]}" run \
    --rm \
    --replace \
    --name dis-osrm \
    --pull=never \
    --network=host \
    --cgroups=disabled \
    --read-only \
    --cap-drop=all \
    --security-opt=no-new-privileges \
    --pids-limit=128 \
    --user "${osrm_uid}:${osrm_gid}" \
    --volume "${release}:/data:ro,rprivate" \
    --workdir /data \
    --tmpfs /tmp:rw,noexec,nosuid,nodev,size=32m \
    "${OSRM_CONTAINER_IMAGE}" osrm-routed \
    --algorithm mld \
    --ip 127.0.0.1 \
    --port 5000 \
    --threads 2 \
    --verbosity WARNING \
    /data/routing.osrm
}

stop() {
  require_root
  "${OSRM_PODMAN_PATH}" "${OSRM_PODMAN_GLOBAL_ARGS[@]}" \
    stop --ignore --time 20 dis-osrm
}

status() {
  local dataset_imported_at="" dataset_sha="" detail health_coordinate="" healthy=false installed=false
  local dataset_identity_valid=true source_manifest=null
  local package_verified_at="" package_version="" provisioned=false release=""
  local release_sha="" service_state="not-installed" state="not_installed"

  if systemd_unit_exists "${OSRM_SERVICE}"; then
    provisioned=true
    service_state="$(systemctl is-active "${OSRM_SERVICE}" 2>/dev/null || true)"
  fi
  if osrm_tools_available; then
    installed=true
    package_version="$(jq -er '.image_version | select(type == "string")' \
      "${OSRM_PACKAGE_PROVENANCE_FILE}" 2>/dev/null || true)"
    package_verified_at="$(jq -er '.verified_at | select(type == "string")' \
      "${OSRM_PACKAGE_PROVENANCE_FILE}" 2>/dev/null || true)"
  fi
  dataset_sha="$(effective_dataset_sha 2>/dev/null || true)"
  if release="$(read_active_release 2>/dev/null)"; then
    release_sha="$(dataset_sha_for_release "${release}" 2>/dev/null || true)"
    if [ -n "${dataset_sha}" ] && [ "${release_sha}" != "${dataset_sha}" ]; then
      # During interrupted activation, effective_dataset_sha deliberately
      # identifies the last committed release instead of the switched candidate.
      if read_pending_activation 2>/dev/null \
        && [ -n "${OSRM_PENDING_CURRENT_TARGET}" ]; then
        release="${OSRM_DATA_ROOT}/${OSRM_PENDING_CURRENT_TARGET}"
        release_sha="$(dataset_sha_for_release "${release}" 2>/dev/null || true)"
      fi
    fi
    [ "${release_sha}" = "${dataset_sha}" ] || release=""
  fi
  if [ -n "${release}" ]; then
    if jq -e '.source_manifest == null' "${release}/manifest.json" >/dev/null 2>&1; then
      source_manifest=null
    elif ! source_manifest="$(dataset_source_manifest_for_release "${release}" 2>/dev/null)"; then
      # A release that declares composite provenance may never silently fall
      # back to the legacy SHA-only identity when that provenance is corrupt.
      source_manifest=null
      dataset_identity_valid=false
    fi
    dataset_imported_at="$(jq -er '.imported_at | select(type == "string")' \
      "${release}/manifest.json" 2>/dev/null || true)"
    health_coordinate="$(read_probe_coordinate "${release}" 2>/dev/null || true)"
  fi
  if [ "${installed}" = true ] && [ "${dataset_identity_valid}" = true ] && [ -n "${dataset_sha}" ] \
    && [ "${service_state}" = "active" ] && health_once; then
    healthy=true
    state="ready"
    detail="Local road-network routing is available."
  elif [ "${installed}" = false ]; then
    state="not_installed"
    detail="The verified Podman runtime and pinned OSRM container are not installed."
  elif [ -z "${dataset_sha}" ]; then
    state="installed_inactive"
    detail="OSRM is installed, but no prepared dataset is active."
  else
    state="degraded"
    detail="An active OSRM dataset exists, but the local readiness check failed."
  fi

  jq -n \
    --arg state "${state}" \
    --arg detail "${detail}" \
    --arg service_state "${service_state}" \
    --arg package_version "${package_version}" \
    --arg package_verified_at "${package_verified_at}" \
    --arg dataset_sha256 "${dataset_sha}" \
    --arg dataset_imported_at "${dataset_imported_at}" \
    --arg health_coordinate "${health_coordinate}" \
    --arg updated_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --argjson installed "${installed}" \
    --argjson provisioned "${provisioned}" \
    --argjson healthy "${healthy}" \
    --argjson dataset_identity_valid "${dataset_identity_valid}" \
    --argjson source_manifest "${source_manifest}" \
    '{
      version: 2,
      state: $state,
      installed: $installed,
      provisioned: $provisioned,
      healthy: $healthy,
      package: (if $installed then {
        version: $package_version,
        verified_at: (if $package_verified_at == "" then null else $package_verified_at end)
      } else null end),
      dataset: (if $dataset_sha256 == "" or ($dataset_identity_valid | not) then null else {
        source_manifest: $source_manifest,
        legacy_sha256: (if $source_manifest == null then $dataset_sha256 else null end),
        imported_at: (if $dataset_imported_at == "" then null else $dataset_imported_at end),
        health_coordinate: (if $health_coordinate == "" then null else $health_coordinate end)
      } end),
      endpoint: "http://127.0.0.1:5000",
      service_state: $service_state,
      detail: $detail,
      updated_at: $updated_at
    }'
}

publish_status() {
  local log_directory="/var/log/dis" status_path="/var/log/dis/osrm-status.json" temporary

  require_root
  ensure_directory "${log_directory}" root "${DIS_GROUP}" 0750
  if id www-data >/dev/null 2>&1; then
    run_cmd setfacl -m "u:www-data:r-x" "${log_directory}"
  fi
  if [ -e "${status_path}" ] || [ -L "${status_path}" ]; then
    [ -f "${status_path}" ] && [ ! -L "${status_path}" ] \
      && [ "$(stat -c '%h' -- "${status_path}")" = "1" ] \
      || fail "The OSRM admin status path is unsafe."
  fi
  temporary="$(mktemp "${log_directory}/.osrm-status.XXXXXX")"
  status | jq '{
    version,
    state,
    installed,
    healthy,
    package,
    dataset: (if .dataset == null then null else {
      source_manifest: .dataset.source_manifest,
      legacy_sha256: .dataset.legacy_sha256,
      imported_at: .dataset.imported_at,
      health_coordinate: .dataset.health_coordinate
    } end),
    service_state,
    detail,
    updated_at
  }' > "${temporary}"
  run_cmd chown root:"${DIS_GROUP}" "${temporary}"
  run_cmd chmod 0640 "${temporary}"
  run_cmd setfacl -m "u:www-data:r--" "${temporary}"
  run_cmd sync -f "${temporary}"
  run_cmd mv -fT -- "${temporary}" "${status_path}"
  run_cmd sync -f "${log_directory}"
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  command_name="${1:-}"
  validate_managed_path APP_ROOT "${APP_ROOT}"
  validate_managed_path DIS_DATA_PATH "${DIS_DATA_PATH}"
  if [ -z "${command_name}" ]; then
    usage
    exit 2
  fi
  shift

  case "${command_name}" in
  install-package)
    [ "$#" -eq 0 ] || fail "install-package does not accept arguments."
    install_package
    ;;
  install-build-tool)
    [ "$#" -eq 0 ] || fail "install-build-tool does not accept arguments."
    install_build_tool
    ;;
  verify-build-tool)
    [ "$#" -eq 0 ] || fail "verify-build-tool does not accept arguments."
    verify_build_tool
    ;;
  provision)
    [ "$#" -eq 0 ] || fail "provision does not accept arguments."
    provision
    ;;
  reconcile)
    [ "$#" -eq 0 ] || fail "reconcile does not accept arguments."
    reconcile
    ;;
  import)
    import_dataset "$@"
    ;;
  health)
    [ "$#" -eq 0 ] || fail "health does not accept arguments."
    health
    ;;
  verify)
    [ "$#" -eq 0 ] || fail "verify does not accept arguments."
    verify_active
    ;;
  status)
    [ "$#" -eq 0 ] || fail "status does not accept arguments."
    status
    ;;
  publish-status)
    [ "$#" -eq 0 ] || fail "publish-status does not accept arguments."
    publish_status
    ;;
  sweep-scratch)
    [ "$#" -eq 0 ] || fail "sweep-scratch does not accept arguments."
    sweep_stale_scratch
    ;;
  prune)
    [ "$#" -eq 0 ] || fail "prune does not accept arguments."
    prune_releases
    ;;
  serve)
    [ "$#" -eq 0 ] || fail "serve does not accept arguments."
    serve
    ;;
  stop)
    [ "$#" -eq 0 ] || fail "stop does not accept arguments."
    stop
    ;;
  -h|--help|help)
    usage
    ;;
    *)
      fail "Unknown OSRM command: ${command_name}"
      ;;
  esac
fi
