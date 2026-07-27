#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
CUTOVER="${APP_ROOT}/scripts/deployment-enrichment-compat.sh"
DEPLOY="${APP_ROOT}/scripts/deploy.sh"
UPDATE="${APP_ROOT}/scripts/update.sh"
CANONICAL_UNIT="${APP_ROOT}/infrastructure/systemd/dis-deployment-enrichment.service"
BRIDGE_UNIT="${APP_ROOT}/infrastructure/systemd/dis-incident-enrichment-compat.service"
RETIRED_UNIT="${APP_ROOT}/infrastructure/systemd/dis-incident-enrichment.service"

require_text() {
  local file="$1" value="$2"

  grep -Fq -- "${value}" "${file}" || {
    printf 'Missing deployment-enrichment compatibility contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  }
}

reject_text() {
  local file="$1" value="$2"

  if grep -Fq -- "${value}" "${file}"; then
    printf 'Forbidden deployment-enrichment compatibility contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  fi
}

line_of() {
  local file="$1" value="$2"

  grep -nF -- "${value}" "${file}" | head -n 1 | cut -d: -f1
}

[ -f "${CUTOVER}" ] || {
  printf 'Missing deployment-enrichment compatibility script.\n' >&2
  exit 1
}
[ -f "${CANONICAL_UNIT}" ] || {
  printf 'Missing canonical deployment-enrichment unit.\n' >&2
  exit 1
}
[ -f "${BRIDGE_UNIT}" ] || {
  printf 'Missing temporary incident-enrichment compatibility template.\n' >&2
  exit 1
}
[ ! -f "${RETIRED_UNIT}" ] || {
  printf 'The retired incident-enrichment worker unit still exists.\n' >&2
  exit 1
}

require_text "${CANONICAL_UNIT}" 'Description=DIS Deployment Location Enrichment Worker'
require_text "${CANONICAL_UNIT}" '--queue=deployment-enrichment,incident-enrichment'

require_text "${BRIDGE_UNIT}" '# DIS temporary legacy deployment-enrichment compatibility bridge'
require_text "${BRIDGE_UNIT}" 'Requires=dis-deployment-enrichment.service'
require_text "${BRIDGE_UNIT}" 'After=dis-deployment-enrichment.service'
require_text "${BRIDGE_UNIT}" 'BindsTo=dis-deployment-enrichment.service'
require_text "${BRIDGE_UNIT}" 'Type=oneshot'
require_text "${BRIDGE_UNIT}" 'ExecStart=/usr/bin/true'
require_text "${BRIDGE_UNIT}" 'RemainAfterExit=yes'
reject_text "${BRIDGE_UNIT}" 'WantedBy='
reject_text "${BRIDGE_UNIT}" 'artisan'
reject_text "${BRIDGE_UNIT}" 'queue:work'

require_text "${CUTOVER}" 'require_root'
require_text "${CUTOVER}" '[ "${DIS_INSTALL_PATH}" = "/opt/dis" ]'
require_text "${CUTOVER}" 'CANONICAL_UNIT="/etc/systemd/system/${CANONICAL_SERVICE}.service"'
require_text "${CUTOVER}" 'LEGACY_UNIT="/etc/systemd/system/${LEGACY_SERVICE}.service"'
require_text "${CUTOVER}" 'LEGACY_WANTS_LINK="/etc/systemd/system/multi-user.target.wants/${LEGACY_SERVICE}.service"'
require_text "${CUTOVER}" 'root_controlled_bundle_source_is_safe "${CANONICAL_SOURCE}"'
require_text "${CUTOVER}" 'root_controlled_bundle_source_is_safe "${BRIDGE_SOURCE}"'
require_text "${CUTOVER}" 'root_owned_runtime_file_is_safe "${CANONICAL_UNIT}" 644'
require_text "${CUTOVER}" 'root_owned_runtime_file_is_safe "${LEGACY_UNIT}" 644'
require_text "${CUTOVER}" 'Refusing to replace an unrecognized ${LEGACY_SERVICE}.service.'
require_text "${CUTOVER}" 'Refusing to shadow ${LEGACY_SERVICE}.service outside the managed unit path.'
require_text "${CUTOVER}" 'parent_starttime="$(process_starttime "${parent_pid}")"'
require_text "${CUTOVER}" '/usr/bin/systemd-run \'
require_text "${CUTOVER}" '--finalize-compat "${parent_pid}" "${parent_starttime}"'
require_text "${CUTOVER}" '[ "${current_starttime}" = "${expected_starttime}" ] || break'
require_text "${CUTOVER}" 'acquire_dis_operation_lock deployment-enrichment-cutover'
require_text "${CUTOVER}" 'bridge_unit_is_safe \'
require_text "${CUTOVER}" 'run_cmd rm -f -- "${LEGACY_WANTS_LINK}" "${LEGACY_UNIT}"'
require_text "${CUTOVER}" '--compat-parent-pid)'
require_text "${CUTOVER}" '--finalize-compat)'
reject_text "${CUTOVER}" 'systemctl stop "${CANONICAL_SERVICE}"'
reject_text "${CUTOVER}" 'systemctl stop dis-deployment-enrichment'
reject_text "${CUTOVER}" 'rm -rf'

require_text "${DEPLOY}" '&& [ -z "${DIS_LEGACY_INCIDENT_ENRICHMENT_COMPAT_REQUIRED+x}" ]; then'
require_text "${DEPLOY}" 'DIS_LEGACY_INCIDENT_ENRICHMENT_COMPAT_REQUIRED must be 0 or 1.'
require_text "${DEPLOY}" 'bash "${SCRIPT_DIR}/deployment-enrichment-compat.sh" --retire'
require_text "${DEPLOY}" 'bash "${SCRIPT_DIR}/deployment-enrichment-compat.sh" --compat-parent-pid "${PPID}"'
require_text "${UPDATE}" 'DIS_LEGACY_INCIDENT_ENRICHMENT_COMPAT_REQUIRED=0 \'

canonical_install="$(line_of "${DEPLOY}" 'infrastructure/systemd/dis-deployment-enrichment.service')"
compatibility_cutover="$(line_of "${DEPLOY}" 'bash "${SCRIPT_DIR}/deployment-enrichment-compat.sh" --retire')"
[ "${canonical_install}" -lt "${compatibility_cutover}" ] || {
  printf 'The canonical worker must be installed before the legacy service bridge is cut over.\n' >&2
  exit 1
}

printf 'Deployment-enrichment first-update compatibility contract passed.\n'
