#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-osrm-retention-test.XXXXXX")"
DIS_DATA_PATH="${TEST_ROOT}/data"
export APP_ROOT DIS_DATA_PATH

cleanup() { rm -rf -- "${TEST_ROOT}"; }
trap cleanup EXIT

# shellcheck source=scripts/osrm.sh
source "${APP_ROOT}/scripts/osrm.sh"
require_root() { :; }
acquire_dis_operation_lock() { :; }
run_cmd() {
  [ "${1:-}" != 'sync' ] || return 0
  command "$@"
}
stat() {
  if [ "${1:-}" = '-c' ] && [ "${2:-}" = '%u' ] \
    && [[ "${4:-}" == "${OSRM_RELEASES_ROOT}"/* ]]; then
    printf '0\n'
    return 0
  fi
  command stat "$@"
}
removed=''
secure_path_operation() {
  [ "$1" = 'remove-tree' ] || return 1
  removed="${removed}${removed:+ }$(basename "$2")"
  rm -rf -- "$2"
}
read_pending_activation() {
  OSRM_PENDING_CURRENT_TARGET='releases/20260101T000000Z-aaaaaaaaaaaa'
  OSRM_PENDING_PREVIOUS_TARGET=''
  OSRM_PENDING_CANDIDATE_TARGET=''
}

mkdir -p "${OSRM_RELEASES_ROOT}"
ids=(
  20260101T000000Z-aaaaaaaaaaaa
  20260102T000000Z-bbbbbbbbbbbb
  20260103T000000Z-cccccccccccc
  20260104T000000Z-dddddddddddd
  20260105T000000Z-eeeeeeeeeeee
)
for release_id in "${ids[@]}"; do
  mkdir -p "${OSRM_RELEASES_ROOT}/${release_id}"
done

if ! ln -s 'releases/20260105T000000Z-eeeeeeeeeeee' "${OSRM_CURRENT_LINK}" 2>/dev/null \
  || [ ! -L "${OSRM_CURRENT_LINK}" ]; then
  printf 'SKIP: OSRM retention runtime test requires POSIX symbolic-link support.\n'
  exit 0
fi
ln -s 'releases/20260104T000000Z-dddddddddddd' "${OSRM_PREVIOUS_LINK}"
printf 'pending\n' > "${OSRM_ACTIVATION_PENDING_FILE}"

OSRM_RELEASE_RETENTION=3
prune_releases
[ -d "${OSRM_RELEASES_ROOT}/20260101T000000Z-aaaaaaaaaaaa" ]
[ -d "${OSRM_RELEASES_ROOT}/20260104T000000Z-dddddddddddd" ]
[ -d "${OSRM_RELEASES_ROOT}/20260105T000000Z-eeeeeeeeeeee" ]
[ ! -e "${OSRM_RELEASES_ROOT}/20260102T000000Z-bbbbbbbbbbbb" ]
[ ! -e "${OSRM_RELEASES_ROOT}/20260103T000000Z-cccccccccccc" ]
[ -L "${OSRM_CURRENT_LINK}" ]
[ -L "${OSRM_PREVIOUS_LINK}" ]
[ -f "${OSRM_ACTIVATION_PENDING_FILE}" ]
[[ " ${removed} " == *' 20260102T000000Z-bbbbbbbbbbbb '* ]]
[[ " ${removed} " == *' 20260103T000000Z-cccccccccccc '* ]]

if (OSRM_RELEASE_RETENTION=2; prune_releases >/dev/null 2>&1); then
  printf 'Unsafe OSRM retention below three was unexpectedly accepted.\n' >&2
  exit 1
fi

prune_releases() { return 1; }
prune_releases_best_effort >/dev/null

printf 'OSRM protected release-retention test passed.\n'
