#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
if ! command -v jq >/dev/null 2>&1; then
  printf 'SKIP: OSRM status identity test requires jq (installed on the Ubuntu target).\n'
  exit 0
fi

TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-osrm-status-identity-test.XXXXXX")"
DIS_DATA_PATH="${TEST_ROOT}/data"
export APP_ROOT DIS_DATA_PATH
trap 'rm -rf -- "${TEST_ROOT}"' EXIT

# shellcheck source=scripts/osrm.sh
source "${APP_ROOT}/scripts/osrm.sh"

mock_release="${DIS_DATA_PATH}/osrm/releases/test"
mock_dataset_sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
mkdir -p "${mock_release}"

systemd_unit_exists() { return 0; }
systemctl() {
  if [ "${1:-}" = 'is-active' ]; then
    printf 'active\n'
    return 0
  fi
  return 1
}
osrm_tools_available() { return 0; }
dpkg-query() {
  if [ "${1:-}" = '-W' ]; then
    printf '5.27.1-test'
    return 0
  fi
  return 1
}
effective_dataset_sha() { printf '%s\n' "${mock_dataset_sha}"; }
read_active_release() { printf '%s\n' "${mock_release}"; }
dataset_sha_for_release() { printf '%s\n' "${mock_dataset_sha}"; }
read_probe_coordinate() { printf '5.1214,52.0907\n'; }
health_once() { return 0; }
require_root() { :; }
require_file() { [ -f "$1" ]; }

printf 'not-json\n' > "${mock_release}/manifest.json"
if (verify_active >/dev/null 2>&1); then
  printf 'OSRM verify accepted a malformed release manifest as legacy.\n' >&2
  exit 1
fi
printf '[]\n' > "${mock_release}/manifest.json"
if (verify_active >/dev/null 2>&1); then
  printf 'OSRM verify accepted a non-object release manifest as legacy.\n' >&2
  exit 1
fi

# A declared but invalid composite identity must fail closed. Even a running
# service and successful mocked health request cannot turn it into ready or
# relabel it as a legitimate legacy release.
jq -n \
  --arg source_sha256 "${mock_dataset_sha}" \
  '{source_sha256:$source_sha256,imported_at:"2026-07-15T03:00:00Z",source_manifest:{broken:true}}' \
  > "${mock_release}/manifest.json"
corrupt_status="$(status)"
jq -e '
  .state == "degraded"
  and .healthy == false
  and .dataset == null
' <<< "${corrupt_status}" >/dev/null

# An explicit null source manifest is the supported legacy shape and retains
# its SHA-only identity until the next deliberate composite map update.
jq -n \
  --arg source_sha256 "${mock_dataset_sha}" \
  '{source_sha256:$source_sha256,imported_at:"2026-07-15T03:00:00Z",source_manifest:null}' \
  > "${mock_release}/manifest.json"
legacy_status="$(status)"
jq -e --arg sha "${mock_dataset_sha}" '
  .state == "ready"
  and .healthy == true
  and .dataset.source_manifest == null
  and .dataset.legacy_sha256 == $sha
' <<< "${legacy_status}" >/dev/null

printf 'OSRM composite identity fail-closed status test passed.\n'
