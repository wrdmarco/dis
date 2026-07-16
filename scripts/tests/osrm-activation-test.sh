#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-osrm-test.XXXXXX")"
DIS_DATA_PATH="${TEST_ROOT}/data"
export APP_ROOT DIS_DATA_PATH

cleanup() {
  case "${TEST_ROOT}" in
    "${TMPDIR:-/tmp}"/dis-osrm-test.*)
      rm -rf -- "${TEST_ROOT}"
      ;;
    *)
      printf 'Refusing to clean unexpected OSRM test path: %s\n' "${TEST_ROOT}" >&2
      ;;
  esac
}
trap cleanup EXIT

# shellcheck source=scripts/osrm.sh
source "${APP_ROOT}/scripts/osrm.sh"

fail_previous_move=0
fail_activation_sync=0
activation_data_sync_count=0
run_cmd() {
  local final_argument="${!#}"

  if [ "${1:-}" = 'chown' ]; then
    return 0
  fi
  if [ "${1:-}" = 'systemctl' ]; then
    return 0
  fi
  if [ "${fail_previous_move}" = "1" ] \
    && [ "${1:-}" = "mv" ] \
    && [ "${final_argument}" = "${OSRM_PREVIOUS_LINK}" ]; then
    fail_previous_move=0
    return 1
  fi
  if [ "${fail_activation_sync}" = "1" ] \
    && [ "${1:-}" = "sync" ] \
    && [ "${final_argument}" = "${OSRM_DATA_ROOT}" ]; then
    activation_data_sync_count=$((activation_data_sync_count + 1))
    if [ "${activation_data_sync_count}" = "2" ]; then
      fail_activation_sync=0
      return 1
    fi
  fi
  "$@"
}

# Git Bash maps the Windows owner to a synthetic uid. Production activation is
# root-only; emulate that one marker ownership stat so the recovery contract is
# still exercised on the cross-platform development workstation.
stat() {
  if [ "${1:-}" = '-c' ] \
    && [ "${2:-}" = '%u' ] \
    && [ "${4:-}" = "${OSRM_ACTIVATION_PENDING_FILE}" ]; then
    printf '0\n'
    return 0
  fi
  command stat "$@"
}

mkdir -p "${OSRM_RELEASES_ROOT}"

# The activation contract depends on real POSIX symbolic links. Git Bash on a
# default Windows workstation may emulate `ln -s` as a copied file when native
# symlink creation is unavailable; that cannot exercise the production logic.
symlink_probe_target="${TEST_ROOT}/symlink-probe-target"
symlink_probe="${TEST_ROOT}/symlink-probe"
mkdir -p "${symlink_probe_target}"
if ! ln -s "symlink-probe-target" "${symlink_probe}" 2>/dev/null \
  || [ ! -L "${symlink_probe}" ]; then
  printf 'SKIP: OSRM activation test requires POSIX symbolic-link support.\n'
  exit 0
fi
rm -f -- "${symlink_probe}"
rmdir -- "${symlink_probe_target}"

previous_id="20251231T000000Z-dddddddddddd"
old_id="20260101T000000Z-aaaaaaaaaaaa"
new_id="20260102T000000Z-bbbbbbbbbbbb"
rejected_id="20260103T000000Z-cccccccccccc"
sync_rejected_id="20260104T000000Z-eeeeeeeeeeee"
no_previous_id="20260105T000000Z-ffffffffffff"
crash_id="20260106T000000Z-111111111111"
mkdir -p \
  "${OSRM_RELEASES_ROOT}/${previous_id}" \
  "${OSRM_RELEASES_ROOT}/${old_id}" \
  "${OSRM_RELEASES_ROOT}/${new_id}" \
  "${OSRM_RELEASES_ROOT}/${rejected_id}" \
  "${OSRM_RELEASES_ROOT}/${sync_rejected_id}" \
  "${OSRM_RELEASES_ROOT}/${no_previous_id}" \
  "${OSRM_RELEASES_ROOT}/${crash_id}"
ln -s "releases/${old_id}" "${OSRM_CURRENT_LINK}"
ln -s "releases/${previous_id}" "${OSRM_PREVIOUS_LINK}"

activate_release "${new_id}"
[ "${OSRM_ACTIVATED_FROM}" = "releases/${old_id}" ]
[ "${OSRM_PREVIOUS_BEFORE_ACTIVATION}" = "releases/${previous_id}" ]
[ "$(readlink -- "${OSRM_CURRENT_LINK}")" = "releases/${new_id}" ]
[ "$(readlink -- "${OSRM_PREVIOUS_LINK}")" = "releases/${old_id}" ]
clear_pending_activation
OSRM_ACTIVATION_PENDING=0

# Simulate SIGKILL/reboot: the shell globals disappear while the fsync'ed
# marker and switched pointers remain. Strict startup recovery must restore
# both committed pointers and remove the durable marker before normal serving.
activate_release "${crash_id}"
[ -f "${OSRM_ACTIVATION_PENDING_FILE}" ]
[ "$(readlink -- "${OSRM_CURRENT_LINK}")" = "releases/${crash_id}" ]
recover_pending_activation serve
[ -z "${OSRM_SERVE_RELEASE_OVERRIDE}" ]
[ "$(readlink -- "${OSRM_CURRENT_LINK}")" = "releases/${crash_id}" ]
OSRM_ACTIVATION_PENDING=0
OSRM_ACTIVATION_ROLLBACK_TARGET=''
OSRM_ACTIVATION_ROLLBACK_PREVIOUS_TARGET=''
pending_activation_owner_is_alive() { return 1; }
recover_pending_activation strict
[ "$(readlink -- "${OSRM_CURRENT_LINK}")" = "releases/${new_id}" ]
[ "$(readlink -- "${OSRM_PREVIOUS_LINK}")" = "releases/${old_id}" ]
[ ! -e "${OSRM_ACTIVATION_PENDING_FILE}" ]

# Inject a failure exactly after the current pointer can switch but while the
# prepared previous pointer is being installed. activate_release must restore
# the old current pointer before returning failure.
fail_previous_move=1

if activate_release "${rejected_id}" >/dev/null; then
  printf 'Activation unexpectedly succeeded after injected previous-link failure.\n' >&2
  exit 1
fi
[ "$(readlink -- "${OSRM_CURRENT_LINK}")" = "releases/${new_id}" ]
[ "$(readlink -- "${OSRM_PREVIOUS_LINK}")" = "releases/${old_id}" ]
[ "${OSRM_ACTIVATION_PENDING}" = "0" ]

# Once both live pointers have moved, a durability failure must still restore
# both exact pre-activation targets, not leave `previous` pointing at current.
fail_activation_sync=1
activation_data_sync_count=0
if activate_release "${sync_rejected_id}" >/dev/null; then
  printf 'Activation unexpectedly succeeded after injected sync failure.\n' >&2
  exit 1
fi
[ "$(readlink -- "${OSRM_CURRENT_LINK}")" = "releases/${new_id}" ]
[ "$(readlink -- "${OSRM_PREVIOUS_LINK}")" = "releases/${old_id}" ]
[ "${OSRM_ACTIVATION_PENDING}" = "0" ]

# A later readiness rollback must also restore an exactly absent previous
# pointer rather than retaining the pointer created during activation.
rm -f -- "${OSRM_PREVIOUS_LINK}"
activate_release "${no_previous_id}"
[ "${OSRM_ACTIVATED_FROM}" = "releases/${new_id}" ]
[ -z "${OSRM_PREVIOUS_BEFORE_ACTIVATION}" ]
[ "$(readlink -- "${OSRM_PREVIOUS_LINK}")" = "releases/${new_id}" ]
rollback_release "${OSRM_ACTIVATED_FROM}" "${OSRM_PREVIOUS_BEFORE_ACTIVATION}"
OSRM_ACTIVATION_PENDING=0
[ "$(readlink -- "${OSRM_CURRENT_LINK}")" = "releases/${new_id}" ]
[ ! -e "${OSRM_PREVIOUS_LINK}" ] && [ ! -L "${OSRM_PREVIOUS_LINK}" ]

# A rejected candidate may never be recorded as the active SHA. The status
# helper must use the restored pointer's SHA, or null when no release remains.
restored_sha='2222222222222222222222222222222222222222222222222222222222222222'
captured_state=''
captured_sha=''
health_result=0
active_dataset_sha() { [ -n "${restored_sha}" ] && printf '%s\n' "${restored_sha}"; }
wait_for_health() { return "${health_result}"; }
write_status() {
  captured_state="$1"
  captured_sha="${3:-}"
}
record_failed_activation_status "releases/${new_id}"
[ "${captured_state}" = 'ready' ]
[ "${captured_sha}" = "${restored_sha}" ]

restored_sha=''
health_result=1
record_failed_activation_status ''
[ "${captured_state}" = 'degraded' ]
[ -z "${captured_sha}" ]

if find "${OSRM_DATA_ROOT}" -mindepth 1 -maxdepth 1 -type d -name '.*-link.*' | grep -q .; then
  printf 'Activation left a private link-preparation directory behind.\n' >&2
  exit 1
fi

printf 'OSRM activation rollback test passed.\n'
