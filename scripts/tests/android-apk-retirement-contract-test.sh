#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
RETIRE="${APP_ROOT}/scripts/retire-android-apks.sh"
DEPLOY="${APP_ROOT}/scripts/deploy.sh"
RESTORE="${APP_ROOT}/scripts/restore.sh"
BACKUP="${APP_ROOT}/scripts/backup.sh"
SETUP="${APP_ROOT}/scripts/setup.sh"
UPDATE="${APP_ROOT}/scripts/update.sh"

require_text() {
  local file="$1" value="$2"
  grep -Fq -- "${value}" "${file}" || {
    printf 'Missing Android APK retirement contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  }
}

reject_text() {
  local file="$1" value="$2"
  if grep -Fq -- "${value}" "${file}"; then
    printf 'Forbidden Android APK retirement contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  fi
}

line_of() {
  local file="$1" value="$2"
  grep -nF -- "${value}" "${file}" | head -n 1 | cut -d: -f1
}

line_of_exact() {
  local file="$1" value="$2"
  grep -nFx -- "${value}" "${file}" | head -n 1 | cut -d: -f1
}

[ -f "${RETIRE}" ] || {
  printf 'Missing Android APK retirement script.\n' >&2
  exit 1
}

require_text "${RETIRE}" 'LEGACY_ANDROID_APK_STORAGE_PARENT="${DIS_DATA_PATH}/webapp/backend/storage/app"'
require_text "${RETIRE}" 'LEGACY_ANDROID_APK_STORAGE="${LEGACY_ANDROID_APK_STORAGE_PARENT}/android-apks"'
require_text "${RETIRE}" 'require_root'
require_text "${RETIRE}" '[ "${DIS_INSTALL_PATH}" = "/opt/dis" ]'
require_text "${RETIRE}" '[ "${DIS_DATA_PATH}" = "/opt/dis-data" ]'
require_text "${RETIRE}" 'acquire_dis_operation_lock android-apk-retirement'
require_text "${RETIRE}" 'DIS_RETIRE_ANDROID_APKS_PARENT_OWNS_LOCK must be 0 or 1.'
require_text "${RETIRE}" 'require_root_controlled_parent "${LEGACY_ANDROID_APK_STORAGE_PARENT}"'
require_text "${RETIRE}" '[ ! -L "${LEGACY_ANDROID_APK_STORAGE}" ]'
require_text "${RETIRE}" '[ -d "${LEGACY_ANDROID_APK_STORAGE}" ]'
require_text "${RETIRE}" 'frontend maintenance is enabled'
require_text "${RETIRE}" 'root_controlled_bundle_source_is_safe "${maintenance_lock}"'
require_text "${RETIRE}" 'systemctl is-active --quiet "${writer_service}.service"'
require_text "${RETIRE}" '/usr/bin/getfacl -cp -- "${LEGACY_ANDROID_APK_STORAGE_PARENT}"'
require_text "${RETIRE}" 'trap restore_application_storage_parent EXIT'
require_text "${RETIRE}" 'ensure_managed_directory "${LEGACY_ANDROID_APK_STORAGE_PARENT}" root root 0750'
require_text "${RETIRE}" 'secure_path_operation remove-tree "${LEGACY_ANDROID_APK_STORAGE}"'
require_text "${RETIRE}" '/usr/bin/setfacl --set-file="${acl_snapshot}" -- "${LEGACY_ANDROID_APK_STORAGE_PARENT}"'
reject_text "${RETIRE}" 'secure_path_operation remove-tree "${LEGACY_ANDROID_APK_STORAGE_PARENT}"'
reject_text "${RETIRE}" 'rm -rf'
reject_text "${RETIRE}" 'Phone'
reject_text "${RETIRE}" 'Source/Android'
reject_text "${RETIRE}" 'Releases/Android'
reject_text "${RETIRE}" 'Gradle'
reject_text "${RETIRE}" 'app/build'

require_text "${DEPLOY}" 'DIS_RETIRE_ANDROID_APKS_PARENT_OWNS_LOCK=1 \'
require_text "${DEPLOY}" 'bash "${SCRIPT_DIR}/retire-android-apks.sh"'
require_text "${RESTORE}" 'DIS_RETIRE_ANDROID_APKS_PARENT_OWNS_LOCK=1 \'
require_text "${RESTORE}" 'bash "${SCRIPT_DIR}/retire-android-apks.sh"'
require_text "${BACKUP}" "--exclude='webapp/backend/storage/app/android-apks'"
require_text "${SETUP}" 'bash "${SCRIPT_DIR}/deploy.sh"'
require_text "${UPDATE}" 'bash "${SCRIPT_DIR}/deploy.sh"'
reject_text "${SETUP}" 'retire-android-apks.sh'
reject_text "${UPDATE}" 'retire-android-apks.sh'

deploy_stop="$(line_of_exact "${DEPLOY}" 'stop_dis_deployment_services')"
deploy_retirement="$(line_of "${DEPLOY}" 'bash "${SCRIPT_DIR}/retire-android-apks.sh"')"
deploy_restart="$(line_of_exact "${DEPLOY}" 'restart_dis_web_services_for_verification')"
[ "${deploy_stop}" -lt "${deploy_retirement}" ] \
  && [ "${deploy_retirement}" -lt "${deploy_restart}" ] || {
  printf 'Android APK storage must be retired after service stop and before web restart.\n' >&2
  exit 1
}

restore_install="$(line_of "${RESTORE}" 'replace_managed_tree "${RESTORED_DATA}/webapp/backend/storage"')"
restore_retirement="$(line_of "${RESTORE}" 'bash "${SCRIPT_DIR}/retire-android-apks.sh"')"
restore_repair="$(line_of_exact "${RESTORE}" 'repair_restored_data_permissions')"
[ "${restore_install}" -lt "${restore_retirement}" ] \
  && [ "${restore_retirement}" -lt "${restore_repair}" ] || {
  printf 'Restored Android APK storage must be removed before permission repair.\n' >&2
  exit 1
}

[ "$(grep -Fc -- 'bash "${SCRIPT_DIR}/retire-android-apks.sh"' "${DEPLOY}")" -eq 1 ]
[ "$(grep -Fc -- 'bash "${SCRIPT_DIR}/retire-android-apks.sh"' "${RESTORE}")" -eq 1 ]

printf 'Android APK retirement deployment contract passed.\n'
