#!/usr/bin/env bash
set -euo pipefail

LIFECYCLE_SOURCE_PATH="${BASH_SOURCE[0]}"
case "${LIFECYCLE_SOURCE_PATH}" in */*) SCRIPT_DIR="${LIFECYCLE_SOURCE_PATH%/*}" ;; *) SCRIPT_DIR=. ;; esac
LIFECYCLE_SOURCE_NAME="${LIFECYCLE_SOURCE_PATH##*/}"
SCRIPT_DIR="$(cd -- "${SCRIPT_DIR}" && pwd -P)"
bootstrap_root_lifecycle_source() {
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
  IFS='/' read -r -a bootstrap_components <<< "${parent#/}"
  for component in "${bootstrap_components[@]}"; do
    [ -n "${component}" ] || continue
    current="${current}/${component}"
    [ -d "${current}" ] && [ ! -L "${current}" ] || return 1
    metadata="$(/usr/bin/stat -c '%u:%a' -- "${current}" 2>/dev/null || true)"
    [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1
    mode="${BASH_REMATCH[1]}"
    (( (8#${mode} & 8#022) == 0 )) || return 1
  done
}
if [ "${EUID}" -eq 0 ]; then
  [ ! -L "${BASH_SOURCE[0]}" ] \
    && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/${LIFECYCLE_SOURCE_NAME}" \
    && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/lib/common.sh" \
    && bootstrap_root_lifecycle_source "${SCRIPT_DIR}/lib/secure-path.py" \
    || {
      printf '[dis:error] Android APK retirement sources must be root-owned, single-link and non-writable by group/world.\n' >&2
      exit 1
    }
fi
unset -f bootstrap_root_lifecycle_source
# shellcheck source=scripts/lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

LEGACY_ANDROID_APK_STORAGE_PARENT="${DIS_DATA_PATH}/webapp/backend/storage/app"
LEGACY_ANDROID_APK_STORAGE="${LEGACY_ANDROID_APK_STORAGE_PARENT}/android-apks"

require_safe_data_path() {
  local resolved_data_path

  [ "${DIS_INSTALL_PATH}" = "/opt/dis" ] \
    || fail "Android APK retirement is restricted to /opt/dis."
  [ "${DIS_DATA_PATH}" = "/opt/dis-data" ] \
    || fail "Android APK retirement is restricted to /opt/dis-data."
  if [[ ! "${DIS_DATA_PATH}" =~ ^/[A-Za-z0-9._/-]+$ ]] \
    || [[ "/${DIS_DATA_PATH}/" == *"/../"* ]] \
    || [[ "/${DIS_DATA_PATH}/" == *"/./"* ]] \
    || [[ "${DIS_DATA_PATH}" == *"//"* ]] \
    || [ "${DIS_DATA_PATH}" = "/" ]; then
    fail "DIS_DATA_PATH is not safe for Android APK retirement."
  fi

  resolved_data_path="$(canonical_dis_data_path)" \
    || fail "DIS_DATA_PATH must resolve to an existing managed data directory."
  [ "${resolved_data_path}" = "${DIS_DATA_PATH}" ] \
    || fail "DIS_DATA_PATH must be a canonical, non-symbolic-link path for Android APK retirement."
  [ -d "${LEGACY_ANDROID_APK_STORAGE_PARENT}" ] \
    && [ ! -L "${LEGACY_ANDROID_APK_STORAGE_PARENT}" ] \
    || fail "The application storage parent is not a safe directory."
  require_root_controlled_parent "${LEGACY_ANDROID_APK_STORAGE_PARENT}"
}

require_retirement_window() {
  local maintenance_lock="${DIS_INSTALL_PATH}/maintenance/frontend.lock" writer_service

  if [ "${DRY_RUN:-0}" = "1" ]; then
    return 0
  fi
  root_controlled_bundle_source_is_safe "${maintenance_lock}" \
    || fail "Android APK storage can only be retired while frontend maintenance is enabled."
  for writer_service in \
    "${PHP_FPM_SERVICE}" \
    dis-media \
    dis-queue \
    dis-push@1 \
    dis-push@2 \
    dis-push@3 \
    dis-push@4 \
    dis-scheduler \
    dis-websocket \
    dis-frontend \
    dis-deployment-enrichment \
    dis-incident-enrichment; do
    if systemctl is-active --quiet "${writer_service}.service"; then
      fail "Android APK storage can only be retired while ${writer_service}.service is stopped."
    fi
  done
}

remove_legacy_android_apk_storage() (
  set -euo pipefail

  local acl_snapshot="" owner group mode restored=0

  if [ ! -e "${LEGACY_ANDROID_APK_STORAGE}" ] \
    && [ ! -L "${LEGACY_ANDROID_APK_STORAGE}" ]; then
    return 0
  fi
  [ ! -L "${LEGACY_ANDROID_APK_STORAGE}" ] \
    || fail "Refusing to follow an unexpected symbolic link at ${LEGACY_ANDROID_APK_STORAGE}."
  [ -d "${LEGACY_ANDROID_APK_STORAGE}" ] \
    || fail "Refusing to remove an unexpected non-directory object at ${LEGACY_ANDROID_APK_STORAGE}."

  require_retirement_window
  [ -x /usr/bin/getfacl ] && [ -x /usr/bin/setfacl ] \
    || fail "ACL tools are required to preserve application storage permissions."

  owner="$(stat -c '%u' -- "${LEGACY_ANDROID_APK_STORAGE_PARENT}")"
  group="$(stat -c '%g' -- "${LEGACY_ANDROID_APK_STORAGE_PARENT}")"
  mode="$(stat -c '%a' -- "${LEGACY_ANDROID_APK_STORAGE_PARENT}")"
  if [ "${DRY_RUN:-0}" = "1" ]; then
    log "Would freeze ${LEGACY_ANDROID_APK_STORAGE_PARENT}, remove only retired Android APK storage, and restore its metadata."
    return 0
  fi

  acl_snapshot="$(mktemp /var/tmp/dis-android-apk-app-acl.XXXXXX)"
  /usr/bin/getfacl -cp -- "${LEGACY_ANDROID_APK_STORAGE_PARENT}" > "${acl_snapshot}"
  restore_application_storage_parent() {
    local status="$?" restore_status=0

    trap - EXIT INT TERM
    if [ "${restored}" = "0" ]; then
      chown "${owner}:${group}" "${LEGACY_ANDROID_APK_STORAGE_PARENT}" || restore_status=$?
      chmod "${mode}" "${LEGACY_ANDROID_APK_STORAGE_PARENT}" || restore_status=$?
      /usr/bin/setfacl --set-file="${acl_snapshot}" -- "${LEGACY_ANDROID_APK_STORAGE_PARENT}" || restore_status=$?
    fi
    rm -f -- "${acl_snapshot:-}" 2>/dev/null || true
    if [ "${status}" -eq 0 ] && [ "${restore_status}" -ne 0 ]; then
      status="${restore_status}"
    fi
    exit "${status}"
  }
  trap restore_application_storage_parent EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM

  ensure_managed_directory "${LEGACY_ANDROID_APK_STORAGE_PARENT}" root root 0750
  secure_path_operation remove-tree "${LEGACY_ANDROID_APK_STORAGE}"

  chown "${owner}:${group}" "${LEGACY_ANDROID_APK_STORAGE_PARENT}"
  chmod "${mode}" "${LEGACY_ANDROID_APK_STORAGE_PARENT}"
  /usr/bin/setfacl --set-file="${acl_snapshot}" -- "${LEGACY_ANDROID_APK_STORAGE_PARENT}"
  restored=1
  rm -f -- "${acl_snapshot}"
  acl_snapshot=""
  trap - EXIT INT TERM
  log "Removed retired Android APK storage."
)

main() {
  [ "$#" -eq 0 ] || fail "Android APK retirement does not accept arguments."
  require_root
  case "${DIS_RETIRE_ANDROID_APKS_PARENT_OWNS_LOCK:-0}" in
    0)
      acquire_dis_operation_lock android-apk-retirement
      ;;
    1)
      ;;
    *)
      fail "DIS_RETIRE_ANDROID_APKS_PARENT_OWNS_LOCK must be 0 or 1."
      ;;
  esac
  require_safe_data_path
  remove_legacy_android_apk_storage
}

main "$@"
