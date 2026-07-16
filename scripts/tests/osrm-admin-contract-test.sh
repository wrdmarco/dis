#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"

assert_contains() {
  local path="$1" value="$2"
  grep -F -- "${value}" "${APP_ROOT}/${path}" >/dev/null \
    || { printf 'Missing OSRM admin contract in %s: %s\n' "${path}" "${value}" >&2; exit 1; }
}

assert_absent() {
  local path="$1" value="$2"
  if grep -F -- "${value}" "${APP_ROOT}/${path}" >/dev/null; then
    printf 'Forbidden OSRM admin contract in %s: %s\n' "${path}" "${value}" >&2
    exit 1
  fi
}

assert_before() {
  local path="$1" first="$2" second="$3" first_line second_line
  first_line="$(grep -nF -- "${first}" "${APP_ROOT}/${path}" | head -n 1 | cut -d: -f1)"
  second_line="$(grep -nF -- "${second}" "${APP_ROOT}/${path}" | head -n 1 | cut -d: -f1)"
  [ -n "${first_line}" ] && [ -n "${second_line}" ] && [ "${first_line}" -lt "${second_line}" ] \
    || { printf 'Expected ordered OSRM contract in %s: %s before %s\n' "${path}" "${first}" "${second}" >&2; exit 1; }
}

worker='scripts/osrm-admin-request-worker.sh'
osrm='scripts/osrm.sh'
common='scripts/lib/common.sh'
backend_service='webapp/backend/app/Services/OsrmOperationService.php'

# The browser can enqueue only a tiny v2 command without URLs or checksums.
# Root accepts the fixed ordered NL+BE set and obtains both supplier checksums.
assert_contains "${worker}" 'NETHERLANDS_LATEST_URL="https://${SOURCE_HOST}/europe/netherlands-latest.osm.pbf"'
assert_contains "${worker}" 'BELGIUM_LATEST_URL="https://${SOURCE_HOST}/europe/belgium-latest.osm.pbf"'
assert_contains "${worker}" 'SOURCE_IDS=(netherlands belgium)'
assert_contains "${worker}" 'MAX_COMBINED_PBF_BYTES="${OSRM_ADMIN_MAX_COMBINED_PBF_BYTES:-5368709120}"'
assert_contains "${worker}" 'latest_target="$(resolve_versioned_source_url "${source_id}" "${latest_url}")"'
assert_contains "${worker}" 'checksum_url="${source_url}.md5"'
assert_contains "${worker}" 'dis:osrm-operation:payload "${OPERATION_ID}"'
assert_contains "${worker}" 'and .version == 2'
assert_contains "${worker}" 'test("^[0-9A-HJKMNP-TV-Z]{26}$"; "i")'
assert_contains "${worker}" '[[ "$1" =~ ^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$ ]]'
assert_contains "${worker}" 'and ((keys_unsorted - ["version","operation_id","action","actor_id","created_at"]) | length == 0)'
assert_contains "${worker}" 'and ((keys_unsorted - ["version","operation_id","action","actor_id","sources","health_coordinate"]) | length == 0)'
assert_contains "${worker}" 'and .sources == ['
assert_contains "${worker}" '{id:"netherlands",latest_url:$netherlands_url},'
assert_contains "${worker}" '{id:"belgium",latest_url:$belgium_url}'
assert_absent "${worker}" '.source_sha256 | type == "string"'
assert_contains "${worker}" 'request_mode="$(stat -c '\''%a'\'' -- "${RUNNING_FILE}")"'
assert_contains "${worker}" 'request_owner="$(stat -c '\''%u'\'' -- "${RUNNING_FILE}")"'
assert_contains "${worker}" 'REQUEST_PUBLISHER_UID="$(id -u www-data 2>/dev/null || true)"'
assert_contains "${worker}" '[ "${request_mode}" != "600" ]'
assert_contains "${worker}" 'validation=${validation_reason}'
assert_contains "${worker}" 'dis:osrm-operation:fail-request "${request_id}" "${reason}"'
assert_contains "${backend_service}" '$previousUmask = umask(0077);'
assert_contains "${backend_service}" '($metadata['\''mode'\''] & 0777) !== 0600'
assert_contains "${backend_service}" '$metadata['\''nlink'\''] !== 1'
assert_contains "${worker}" 'if [ "${ACTION}" = "install_activate" ] \'
assert_contains "${worker}" 'Bestaand geverifieerd OSRM-pakket blijft ongewijzigd tijdens de kaartupdate.'
assert_contains "${worker}" '[ "${age}" -gt 86400 ]'
assert_contains "${worker}" '(.dataset.source_manifest | type == "object")'
assert_contains "${worker}" 'or (.dataset.legacy_sha256 | type == "string" and test("^[a-f0-9]{64}$"))'

# Downloads fail closed on redirects, private/rebound DNS, size, ownership and
# the strictly parsed official Geofabrik sidecar. No client checksum or URL
# reaches curl, while internal prepared artifacts retain SHA-256 manifests.
assert_contains "${worker}" "address.is_global"
assert_contains "${worker}" "--max-redirs 0"
assert_contains "${worker}" "--proto '=https'"
assert_absent "${worker}" '--location'
assert_contains "${worker}" '--resolve "${pin}"'
assert_contains "${worker}" '--resolve "${DOWNLOAD_PIN}"'
assert_contains "${worker}" '[ "${exit_code}" -eq 0 ] && [ "${http_code}" = "302" ]'
assert_contains "${worker}" 'rf"https://download[.]geofabrik[.]de/europe/{source_id}-([0-9]{{6}})[.]osm[.]pbf"'
assert_contains "${worker}" 'versioned_source_url_is_valid "${source_id}" "${target_url}"'
assert_contains "${worker}" '[ "$(stat -c '\''%h'\'' -- "${pbf_file}")" = "1" ]'
assert_contains "${worker}" '[ "$(stat -c '\''%U'\'' -- "${pbf_file}")" = "dis-osrm-build" ]'
assert_contains "${worker}" '--max-filesize 1024'
assert_contains "${worker}" 'source_md5="$(parse_supplier_md5_file "${md5_file}" "${source_filename}")"'
assert_contains "${worker}" 'actual_md5="$(md5sum -- "${pbf_file}"'
assert_contains "${worker}" 'record_resolved_source_manifest "${source_manifest}"'
assert_contains "${worker}" '.dataset.source_manifest == $expected_manifest'
assert_contains "${worker}" 'source_timestamp_for_file "${SOURCE_PBF_FILE[netherlands]}"'
assert_contains "${worker}" 'source_timestamp_for_file "${SOURCE_PBF_FILE[belgium]}"'
assert_contains "${worker}" '[ "${nl_timestamp}" = "${be_timestamp}" ]'
assert_contains "${worker}" 'update_stage merging "Nederlandse en Belgische kaartdata veilig samenvoegen."'
assert_contains "${worker}" '-- /usr/bin/osmium merge --overwrite --output-format=pbf'
assert_contains "${worker}" '--property="PrivateNetwork=yes"'
assert_contains "${worker}" '--property="MemoryMax=${MERGE_MEMORY_MAX}"'
assert_contains "${worker}" 'run_cmd rm -f -- "${SOURCE_PBF_FILE[netherlands]}" "${SOURCE_PBF_FILE[belgium]}"'
assert_contains "${worker}" 'merged_size_is_safe "${merged_size}" "${source_total}"'
assert_contains "${worker}" 'OSRM_IMPORT_PARENT_UNIT=dis-osrm-admin-request.service'
assert_contains "${osrm}" 'sha256sum --check --strict ARTIFACTS.SHA256'
assert_contains "${osrm}" 'release_manifest_is_json_object "${release}" || return 1'
assert_contains "${osrm}" '|| fail "The active OSRM release manifest is not a valid JSON object."'
assert_absent "${worker}" 'curl ${source_url}'

# The privileged broker bootstraps only from the immutable root runtime bundle.
# Its Git checkout is used solely by unprivileged Artisan and root-owned data
# configuration; no root-side OSRM command executes a deployable repo script.
assert_contains "${common}" 'OSRM_ADMIN_RUNTIME_DIR="${OSRM_ADMIN_RUNTIME_PARENT}/osrm-admin"'
assert_contains "${common}" 'install_osrm_admin_runtime_bundle() ('
assert_contains "${common}" 'root_controlled_bundle_source_is_safe "${source_path}"'
assert_contains "${common}" 'root_controlled_bundle_source_is_safe "${COMMON_LIB_DIR}/secure-path.py"'
assert_contains "${common}" '/usr/bin/stat -c '\''%u:%a:%h'\'''
assert_contains "${common}" 'stat -c '\''%u:%g:%a:%h'\'''
assert_contains "${common}" 'verify_osrm_admin_runtime_bundle'
assert_contains "${worker}" 'OSRM_ADMIN_RUNTIME_DIR_FIXED="/usr/local/lib/dis/osrm-admin"'
assert_contains "${worker}" 'bootstrap_require_file "${OSRM_ADMIN_RUNTIME_DIR_FIXED}/${bootstrap_file}" 644'
assert_contains "${worker}" 'RUNTIME_COMMON="${OSRM_ADMIN_RUNTIME_DIR_FIXED}/common.sh"'
assert_contains "${worker}" 'OSRM_SCRIPT="${OSRM_ADMIN_RUNTIME_DIR_FIXED}/osrm.sh"'
assert_before "${worker}" 'bootstrap_require_bundle_directory "${OSRM_ADMIN_RUNTIME_DIR_FIXED}"' 'source "${RUNTIME_COMMON}"'
assert_absent "${worker}" 'APP_ROOT="${APP_ROOT}" bash "${OSRM_SCRIPT}"'
assert_contains "${worker}" 'DIS_DATA_PATH="${DIS_DATA_PATH}" bash "${OSRM_SCRIPT}"'
assert_contains "${worker}" 'runuser -u "${DIS_USER}" -- env -i'
assert_contains "${worker}" 'config_metadata="$(stat -c '\''%u:%g:%a:%h'\'' -- "${config_file}"'
assert_contains "${osrm}" 'OSRM_SERVICE_TEMPLATE="${OSRM_ADMIN_RUNTIME_DIR}/dis-osrm.service"'
assert_contains "${osrm}" '"${OSRM_SERVICE_TEMPLATE}" > "${temporary_unit}"'
assert_absent "${osrm}" '"${APP_ROOT}/infrastructure/systemd/dis-osrm.service"'
assert_contains 'infrastructure/systemd/dis-osrm.service' 'ExecStart=/usr/bin/bash @OSRM_RUNTIME_SCRIPT@ serve'
assert_absent 'infrastructure/systemd/dis-osrm.service' '@APP_ROOT@/scripts/osrm.sh'
for lifecycle in scripts/deploy.sh scripts/update.sh scripts/self-heal-permissions.sh; do
  assert_contains "${lifecycle}" 'install_osrm_admin_runtime_bundle'
done
for lifecycle in scripts/setup.sh scripts/install.sh scripts/deploy.sh scripts/update.sh scripts/self-heal-permissions.sh scripts/uninstall.sh; do
  assert_contains "${lifecycle}" 'bootstrap_root_lifecycle_source "${SCRIPT_DIR}/lib/common.sh"'
  assert_absent "${lifecycle}" '$(basename "${BASH_SOURCE[0]}")'
done
assert_contains 'scripts/self-heal-permissions.sh' 'acquire_dis_operation_lock permission-self-heal'
assert_contains 'scripts/self-heal-permissions.sh' 'dis-osrm-admin-request.timer && systemctl is-active --quiet dis-osrm-admin-request.timer'
assert_contains "${common}" 'acquire_dis_operation_lock osrm-admin-runtime-install'
assert_contains "${common}" 'The OSRM admin Timer unit must be inactive before its runtime bundle is replaced.'
assert_contains 'scripts/uninstall.sh' 'secure_path_operation remove-tree "${OSRM_ADMIN_RUNTIME_DIR}"'
assert_contains "${common}" 'systemctl is-enabled --quiet dis-osrm-admin-request.path'
assert_contains "${common}" 'systemctl is-enabled --quiet dis-osrm-admin-request.timer'
assert_absent 'scripts/update.sh' 'dis-osrm-admin-request.path dis-osrm-admin-request.timer >/dev/null 2>&1 || true'

# A build user never owns the scratch parent and therefore cannot replace
# header/code/error paths before a root redirection. Every path is precreated,
# checked, and sealed root-owned before it is parsed.
assert_contains "${worker}" 'chown root:dis-osrm "${DOWNLOAD_DIRECTORY}"'
assert_absent "${worker}" 'chown dis-osrm-build:dis-osrm "${DOWNLOAD_DIRECTORY}"'
assert_contains "${worker}" 'install -d -m 0750 -o root -g dis-osrm "${control_directory}"'
assert_contains "${worker}" 'prepare_download_control_file "${pbf_file}" "${DOWNLOAD_DIRECTORY}"'
assert_contains "${worker}" 'prepare_download_control_file "${md5_file}" "${control_directory}"'
assert_contains "${worker}" 'seal_download_control_file "${md5_file}" "${control_directory}"'
assert_contains "${worker}" 'seal_download_control_file "${header_file}" "${control_directory}"'
assert_contains "${worker}" 'seal_download_control_file "${code_file}" "${control_directory}"'
assert_before "${worker}" 'prepare_download_control_file "${header_file}"' '--dump-header "${header_file}"'
assert_before "${worker}" 'seal_download_control_file "${header_file}"' 'SOURCE_SIZE[${source_id}]="$(read_content_length "${header_file}")"'
assert_before "${worker}" 'seal_download_control_file "${code_file}"' 'http_code="$(tr -d '\''\r\n'\'' < "${code_file}"'

# Live log/status schemas are bounded and machine-readable.
assert_contains "${worker}" 'progress_percent:$progress_percent'
assert_contains "${worker}" 'LOG_MAX_BYTES='
for stage in validating downloading installing_package provisioning merging extracting partitioning customizing activating verifying configuring completed; do
  assert_contains "${worker}" "${stage}"
done
assert_contains "${osrm}" 'publish-status'
assert_contains "${osrm}" 'apt-mark showhold'
assert_contains "${osrm}" 'apt-mark hold osrm-backend'
assert_contains "${osrm}" 'apt-mark hold osmium-tool'
assert_contains "${osrm}" 'OSRM_RELEASE_RETENTION="${OSRM_RELEASE_RETENTION:-3}"'
assert_contains "${osrm}" 'protected["${target#releases/}"]=1'
assert_contains "${osrm}" 'secure_path_operation remove-tree "${release_path}"'
assert_contains "${osrm}" 'prune_releases_best_effort'
assert_contains "${worker}" 'bash "${OSRM_SCRIPT}" prune'
assert_contains "${worker}" 'safe_cleanup_admin_download "${DOWNLOAD_DIRECTORY}"'
assert_contains 'scripts/uninstall.sh' 'for held_package in osrm-backend osmium-tool; do'
assert_contains 'scripts/uninstall.sh' 'run_cmd apt-mark unhold "${held_package}"'
assert_contains 'scripts/uninstall.sh' 'for osrm_package in osrm-backend osmium-tool; do'
assert_contains "${osrm}" 'dataset: (if .dataset == null then null else {'
assert_contains "${osrm}" '[ "${dataset_identity_valid}" = true ]'
assert_contains "${osrm}" 'source_manifest: .dataset.source_manifest'
assert_contains "${osrm}" 'legacy_sha256: .dataset.legacy_sha256'
assert_contains "${osrm}" 'health_coordinate: .dataset.health_coordinate'
assert_absent "${osrm}" 'dataset: .dataset,'

# Normal DIS installation and update install only the broker. Package install
# and map import remain explicit root-worker operations from Admin.
assert_absent 'scripts/install.sh' 'osrm.sh" install-package'
assert_absent 'scripts/update.sh' 'osrm.sh" install-package'
assert_absent 'scripts/deploy.sh' 'osrm.sh" import'
assert_absent 'scripts/update.sh' 'osrm.sh" import'
assert_contains 'scripts/deploy.sh' 'install_osrm_admin_request_systemd_units'
assert_contains 'scripts/update.sh' 'install_osrm_admin_request_systemd_units'
assert_contains 'scripts/update.sh' 'DEPLOYMENT_SERVICES_STOPPED=1'
assert_contains "${common}" 'did not become idle within 60 seconds'
assert_contains 'scripts/uninstall.sh' 'dis-osrm-admin-request.path'
assert_contains 'infrastructure/systemd/dis-osrm-admin-request.path' 'PathExistsGlob=@DIS_DATA_PATH@/osrm-admin/requests/*.pending'
assert_contains 'infrastructure/systemd/dis-osrm-admin-request.timer' 'OnUnitInactiveSec=1min'

# No web-user sudo entry is introduced; the static root systemd broker is the
# only privileged bridge.
if grep -R -E 'www-data.*osrm|osrm.*www-data' "${APP_ROOT}/infrastructure/sudoers" >/dev/null 2>&1; then
  printf 'OSRM may not be exposed through a web-user sudo rule.\n' >&2
  exit 1
fi

assert_contains "${common}" 'ensure_managed_directory "${DIS_DATA_PATH}/osrm-admin/results" root "${DIS_GROUP}" 0750'
assert_contains "${common}" 'require_user_can_open_file_for_reading "${DIS_USER}" "${status_path}"'
assert_contains "${common}" 'require_user_can_open_file_for_reading www-data "${status_path}"'
assert_contains "${common}" '"if=${path}" of=/dev/null bs=1 count=1 status=none'
assert_contains "${common}" '/usr/bin/find "${path}" \'
assert_contains "${common}" 'if=/dev/null "of=${path}" bs=1 count=0 conv=notrunc oflag=nofollow status=none'
assert_contains "${common}" '.dis-permission-probe.XXXXXXXX'
assert_absent "${common}" 'runuser -u www-data -- test -r "${status_path}"'
assert_absent "${common}" 'runuser -u www-data -- test ! -w "${status_path}"'
printf 'OSRM admin static contract and security test passed.\n'
