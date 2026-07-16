#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
DIS_DATA_PATH="/opt/dis-data"
export APP_ROOT DIS_DATA_PATH

# shellcheck source=scripts/osrm.sh
source "${APP_ROOT}/scripts/osrm.sh"

probe_response=''
requested_url_file="$(mktemp "${TMPDIR:-/tmp}/dis-osrm-health-url.XXXXXX")"
trap 'rm -f -- "${requested_url_file}"' EXIT

read_active_release() {
  printf '/opt/dis-data/osrm/releases/test\n'
}

read_probe_coordinate() {
  printf '5.1214,52.0907\n'
}

curl() {
  printf '%s\n' "${!#}" > "${requested_url_file}"
  printf '%s\n' "${probe_response}"
}

# Keep this shell test self-contained on workstations without jq. Production
# still uses jq; this stub validates the exact fields consumed by health_once.
jq() {
  local input max_snap distance
  max_snap="${4:-0}"
  input="$(cat)"
  [[ "${input}" == *'"code":"Ok"'* ]] || return 1
  [[ "${input}" =~ \"waypoints\":\[\{\"distance\":([0-9]+([.][0-9]+)?) ]] || return 1
  distance="${BASH_REMATCH[1]}"
  awk -v distance="${distance}" -v max_snap="${max_snap}" \
    'BEGIN { exit !(distance >= 0 && distance <= max_snap) }'
}

OSRM_HEALTH_MAX_SNAP_METERS=250
probe_response='{"code":"Ok","waypoints":[{"distance":12.5,"location":[5.1215,52.0908]}]}'
health_once
[[ "$(< "${requested_url_file}")" == *'/nearest/v1/driving/5.1214,52.0907?number=1&radiuses=250' ]]

probe_response='{"code":"Ok","waypoints":[{"distance":250.1,"location":[5.2,52.2]}]}'
if health_once; then
  printf 'OSRM health accepted a snap beyond the configured maximum.\n' >&2
  exit 1
fi

probe_response='{"code":"NoSegment","message":"No segment found"}'
if health_once; then
  printf 'OSRM health accepted a coordinate outside the active road graph.\n' >&2
  exit 1
fi

OSRM_HEALTH_MAX_SNAP_METERS=5001
if health_once; then
  printf 'OSRM health accepted an unsafe snap-radius configuration.\n' >&2
  exit 1
fi

printf 'OSRM bounded nearest-road health test passed.\n'
