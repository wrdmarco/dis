#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
osrm="${APP_ROOT}/scripts/osrm.sh"
worker="${APP_ROOT}/scripts/osrm-admin-request-worker.sh"
secure_path="${APP_ROOT}/scripts/lib/secure-path.py"

assert_contains() {
  local path="$1" value="$2"
  grep -F -- "${value}" "${path}" >/dev/null \
    || { printf 'Missing crash-security contract in %s: %s\n' "${path}" "${value}" >&2; exit 1; }
}

assert_absent() {
  local path="$1" value="$2"
  if grep -F -- "${value}" "${path}" >/dev/null; then
    printf 'Forbidden crash-security contract in %s: %s\n' "${path}" "${value}" >&2
    exit 1
  fi
}

assert_count_at_least() {
  local path="$1" value="$2" minimum="$3" count
  count="$(grep -Fc -- "${value}" "${path}" || true)"
  [ "${count}" -ge "${minimum}" ] \
    || { printf 'Expected at least %s occurrences of %s in %s, found %s\n' "${minimum}" "${value}" "${path}" "${count}" >&2; exit 1; }
}

line_of() {
  grep -nF -- "$2" "$1" | head -n 1 | cut -d: -f1
}

assert_before() {
  local path="$1" first="$2" second="$3" first_line second_line
  first_line="$(line_of "${path}" "${first}")"
  second_line="$(line_of "${path}" "${second}")"
  [ -n "${first_line}" ] && [ -n "${second_line}" ] && [ "${first_line}" -lt "${second_line}" ] \
    || { printf 'Expected %s before %s in %s\n' "${first}" "${second}" "${path}" >&2; exit 1; }
}

# A transient parser is coupled to parent failure/stop, but never ordered
# after the Type=oneshot parent that is synchronously waiting for it.
assert_contains "${osrm}" '"--property=PartOf=${OSRM_IMPORT_PARENT_UNIT}"'
assert_contains "${osrm}" '"--property=BindsTo=${OSRM_IMPORT_PARENT_UNIT}"'
assert_absent "${osrm}" '"--property=After=${OSRM_IMPORT_PARENT_UNIT}"'

# Stale scratch recovery is exact-name, direct-child, owner/mode constrained,
# descriptor-deleted and runs before a fresh download directory is allocated.
assert_contains "${osrm}" '[[ "${name}" =~ ^\.(import|admin-download)\.[A-Za-z0-9]{6}$ ]]'
assert_contains "${osrm}" 'local active_scratch="${OSRM_ACTIVE_SCRATCH_PATH:-}"'
assert_contains "${osrm}" '[ "${uid}" != "0" ] && [ "${uid}" != "${build_uid}" ]'
assert_contains "${osrm}" '(( (8#${mode} & 8#022) != 0 ))'
assert_contains "${osrm}" 'secure_path_operation remove-tree "${candidate}"'
assert_contains "${osrm}" 'pending_activation_owner_is_alive'
assert_contains "${osrm}" 'OSRM_IMPORT_STAGING_ON_EXIT=""'
assert_contains "${osrm}" 'OSRM_IMPORT_STAGING_ON_EXIT="${staging}"'
assert_contains "${osrm}" 'trap '\''safe_cleanup_staging "${OSRM_IMPORT_STAGING_ON_EXIT:-}"'\'' EXIT'
assert_absent "${osrm}" 'trap '\''safe_cleanup_staging "${staging}"'\'' EXIT'
assert_contains "${worker}" 'bash "${OSRM_SCRIPT}" sweep-scratch'
assert_contains "${worker}" 'OSRM_ACTIVE_SCRATCH_PATH="${DOWNLOAD_DIRECTORY}"'
assert_contains "${worker}" '--property="PartOf=dis-osrm-admin-request.service"'
assert_contains "${worker}" '--property="BindsTo=dis-osrm-admin-request.service"'
assert_absent "${worker}" '--property="After=dis-osrm-admin-request.service"'
assert_count_at_least "${worker}" 'bash "${OSRM_SCRIPT}" sweep-scratch' 2
assert_before "${worker}" 'bash "${OSRM_SCRIPT}" sweep-scratch' 'DOWNLOAD_DIRECTORY="$(mktemp -d'
assert_before "${worker}" 'bash "${OSRM_SCRIPT}" prune' 'DOWNLOAD_DIRECTORY="$(mktemp -d'

# Abandoned recovery can report success only after reloading the immutable
# payload and matching plus actively verifying the committed runtime.
assert_contains "${worker}" 'payload="$(artisan_callback dis:osrm-operation:payload "${OPERATION_ID}")" \'
assert_contains "${worker}" 'operation_payload_contract_is_valid "${payload}" || return 2'
assert_contains "${worker}" 'expected_manifest="$(resolved_source_manifest_from_marker)" || return 1'
assert_contains "${worker}" '.dataset.source_manifest == $expected_manifest'
assert_contains "${worker}" '.dataset.health_coordinate == $coordinate'
assert_contains "${worker}" 'bash "${OSRM_SCRIPT}" verify >/dev/null 2>&1'
assert_contains "${worker}" 'bash "${OSRM_SCRIPT}" health >/dev/null 2>&1'
assert_contains "${worker}" 'recover_committed_operation || recovery_result=$?'
assert_contains "${worker}" '[ "${recovery_result}" = "2" ]'
assert_contains "${worker}" 'RECOVERY_RETRY_PENDING=1'
assert_contains "${worker}" '[ "${RECOVERY_RETRY_PENDING}" = "0" ] || break'
assert_before "${worker}" 'if [ -e "${DIS_DATA_PATH}/osrm" ] || [ -L "${DIS_DATA_PATH}/osrm" ]; then' 'for running_file in "${WORK_DIR}"/*.json'

# A corrupt declared composite manifest is never relabelled as a genuine
# legacy SHA-only release in the public status contract.
assert_contains "${osrm}" 'local dataset_identity_valid=true source_manifest=null'
assert_contains "${osrm}" 'dataset_identity_valid=false'
assert_contains "${osrm}" '($dataset_identity_valid | not)'

# Root metadata is written only after descriptor validation/freeze, through an
# exclusive no-follow temporary file, fsync and atomic descriptor-relative move.
assert_contains "${osrm}" 'repair_managed_tree "${staging}" root "${OSRM_GROUP}" 0750 0440'
assert_contains "${osrm}" 'secure_path_operation write-file \'
assert_absent "${osrm}" '> "${staging}/ARTIFACTS.SHA256"'
assert_absent "${osrm}" '> "${staging}/health-coordinate"'
assert_absent "${osrm}" '> "${manifest_temp}"'
assert_contains "${secure_path}" 'os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC'
assert_contains "${secure_path}" 'os.replace('
assert_contains "${secure_path}" 'os.fsync(parent_fd)'
assert_before "${osrm}" 'repair_managed_tree "${staging}" root "${OSRM_GROUP}" 0750 0440' '"${staging}/ARTIFACTS.SHA256" root'

printf 'OSRM crash recovery, transient ordering and staging security contract test passed.\n'
