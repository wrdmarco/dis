#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
DIS_DATA_PATH="/opt/dis-data"
export APP_ROOT DIS_DATA_PATH

# shellcheck source=scripts/osrm.sh
source "${APP_ROOT}/scripts/osrm.sh"

netherlands_response=''
belgium_response=''
composite_release=0
requested_url_file="$(mktemp "${TMPDIR:-/tmp}/dis-osrm-health-url.XXXXXX")"
release_directory="${requested_url_file}.release"
mkdir -p "${release_directory}"
trap 'rm -f -- "${requested_url_file}"; rm -rf -- "${release_directory}"' EXIT

read_active_release() {
  printf '%s\n' "${release_directory}"
}

read_probe_coordinate() {
  printf '5.1214,52.0907\n'
}

read_belgium_probe_coordinate() {
  printf '4.3517,50.8503\n'
}

release_has_composite_source_manifest() {
  [ "${composite_release}" = '1' ]
}

dataset_source_manifest_for_release() {
  printf '{}\n'
}

validate_source_manifest_json() {
  [ "$1" = '{}' ]
}

curl() {
  local url="${!#}"

  printf '%s\n' "${url}" >> "${requested_url_file}"
  if [[ "${url}" == *'/4.3517,50.8503?'* ]]; then
    printf '%s\n' "${belgium_response}"
  else
    printf '%s\n' "${netherlands_response}"
  fi
}

# Keep this shell test self-contained on workstations without jq. Production
# still uses jq; this stub validates the exact fields consumed by health_once.
jq() {
  local input max_snap distance

  if [ "$#" -ge 3 ] && [ -f "${!#}" ]; then
    input="$(< "${!#}")"
    [[ "${input}" =~ ^[[:space:]]*\{.*\}[[:space:]]*$ ]]
    return
  fi
  max_snap="${4:-0}"
  input="$(cat)"
  [[ "${input}" == *'"code":"Ok"'* ]] || return 1
  [[ "${input}" =~ \"waypoints\":\[\{\"distance\":([0-9]+([.][0-9]+)?) ]] || return 1
  distance="${BASH_REMATCH[1]}"
  awk -v distance="${distance}" -v max_snap="${max_snap}" \
    'BEGIN { exit !(distance >= 0 && distance <= max_snap) }'
}

OSRM_HEALTH_MAX_SNAP_METERS=250
netherlands_response='{"code":"Ok","waypoints":[{"distance":12.5,"location":[5.1215,52.0908]}]}'
belgium_response='{"code":"Ok","waypoints":[{"distance":8.0,"location":[4.3518,50.8504]}]}'
composite_release=0

printf 'not-json\n' > "${release_directory}/manifest.json"
if health_once; then
  printf 'OSRM health accepted a malformed release manifest as legacy.\n' >&2
  exit 1
fi
printf '[]\n' > "${release_directory}/manifest.json"
if health_once; then
  printf 'OSRM health accepted a non-object release manifest as legacy.\n' >&2
  exit 1
fi
# A valid legacy manifest may omit the later source_manifest key.
printf '{}\n' > "${release_directory}/manifest.json"
: > "${requested_url_file}"
health_once
grep -F '/nearest/v1/driving/5.1214,52.0907?number=1&radiuses=250' "${requested_url_file}" >/dev/null
[ "$(wc -l < "${requested_url_file}" | tr -d ' ')" = '1' ]

composite_release=1
: > "${requested_url_file}"
health_once
grep -F '/nearest/v1/driving/5.1214,52.0907?number=1&radiuses=250' "${requested_url_file}" >/dev/null
grep -F '/nearest/v1/driving/4.3517,50.8503?number=1&radiuses=250' "${requested_url_file}" >/dev/null
[ "$(wc -l < "${requested_url_file}" | tr -d ' ')" = '2' ]

belgium_response='{"code":"Ok","waypoints":[{"distance":250.1,"location":[4.4,50.9]}]}'
if health_once; then
  printf 'Composite OSRM health accepted a Belgian probe beyond the maximum.\n' >&2
  exit 1
fi
belgium_response='{"code":"Ok","waypoints":[{"distance":8.0,"location":[4.3518,50.8504]}]}'

netherlands_response='{"code":"Ok","waypoints":[{"distance":250.1,"location":[5.2,52.2]}]}'
if health_once; then
  printf 'OSRM health accepted a snap beyond the configured maximum.\n' >&2
  exit 1
fi

netherlands_response='{"code":"NoSegment","message":"No segment found"}'
if health_once; then
  printf 'OSRM health accepted a coordinate outside the active road graph.\n' >&2
  exit 1
fi

OSRM_HEALTH_MAX_SNAP_METERS=5001
if health_once; then
  printf 'OSRM health accepted an unsafe snap-radius configuration.\n' >&2
  exit 1
fi

validate_belgium_coordinate '4.3517,50.8503'
if validate_belgium_coordinate '5.1214,52.0907'; then
  printf 'A Dutch coordinate was unexpectedly accepted as the Belgian probe.\n' >&2
  exit 1
fi

printf 'OSRM bounded Dutch/Belgian nearest-road health test passed.\n'
