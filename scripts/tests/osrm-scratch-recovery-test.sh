#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-osrm-scratch-test.XXXXXX")"

cleanup() {
  case "${TEST_ROOT}" in
    "${TMPDIR:-/tmp}"/dis-osrm-scratch-test.*) rm -rf -- "${TEST_ROOT}" ;;
    *) printf 'Refusing to clean unexpected test path: %s\n' "${TEST_ROOT}" >&2 ;;
  esac
}
trap cleanup EXIT

DIS_DATA_PATH="${TEST_ROOT}/data"
# shellcheck source=scripts/osrm.sh
source "${APP_ROOT}/scripts/osrm.sh"

mkdir -p "${OSRM_DATA_ROOT}/releases/20260716T120000Z-aaaaaaaaaaaa"
chmod 0750 "${OSRM_DATA_ROOT}"

REMOVED_SCRATCH=''
require_root() { :; }
acquire_dis_operation_lock() { :; }
log() { :; }
id() {
  if [ "${1:-}" = '-u' ] && [ "${2:-}" = "${OSRM_IMPORT_USER}" ]; then
    printf '123\n'
    return 0
  fi
  command id "$@"
}
stat() {
  local format path name

  if [ "${1:-}" = '-c' ]; then
    format="${2:-}"
    path="${4:-}"
    name="${path##*/}"
    case "${format}" in
      '%u')
        case "${path}" in
          "${OSRM_DATA_ROOT}") printf '0\n' ;;
          */.admin-download.Bad777) printf '999\n' ;;
          */.admin-download.Def456) printf '0\n' ;;
          *) printf '123\n' ;;
        esac
        return 0
        ;;
      '%a')
        case "${name}" in
          .import.Bad666) printf '770\n' ;;
          *) printf '750\n' ;;
        esac
        return 0
        ;;
    esac
  fi
  command stat "$@"
}
secure_path_operation() {
  [ "${1:-}" = 'remove-tree' ] || return 1
  is_managed_scratch_path "${2:-}" || return 1
  REMOVED_SCRATCH="${REMOVED_SCRATCH}${REMOVED_SCRATCH:+ }${2##*/}"
  command rm -rf -- "$2"
}
run_cmd() {
  [ "${1:-}" = 'sync' ] && return 0
  command "$@"
}

mkdir -p \
  "${OSRM_DATA_ROOT}/.import.Abc123" \
  "${OSRM_DATA_ROOT}/.admin-download.Def456" \
  "${OSRM_DATA_ROOT}/.admin-download.Act123" \
  "${OSRM_DATA_ROOT}/.import.Bad666" \
  "${OSRM_DATA_ROOT}/.admin-download.Bad777" \
  "${OSRM_DATA_ROOT}/.import.too-long"
: > "${OSRM_DATA_ROOT}/.import.File01"
: > "${OSRM_DATA_ROOT}/current"
: > "${OSRM_DATA_ROOT}/previous"
if ln -s "${TEST_ROOT}" "${OSRM_DATA_ROOT}/.import.Link01" 2>/dev/null \
  && [ -L "${OSRM_DATA_ROOT}/.import.Link01" ]; then
  CREATED_SYMLINK=1
else
  rm -f -- "${OSRM_DATA_ROOT}/.import.Link01" 2>/dev/null || true
  CREATED_SYMLINK=0
fi

OSRM_ACTIVE_SCRATCH_PATH="${OSRM_DATA_ROOT}/.admin-download.Act123" sweep_stale_scratch

[ ! -e "${OSRM_DATA_ROOT}/.import.Abc123" ]
[ ! -e "${OSRM_DATA_ROOT}/.admin-download.Def456" ]
[ -d "${OSRM_DATA_ROOT}/.admin-download.Act123" ]
[ -d "${OSRM_DATA_ROOT}/.import.Bad666" ]
[ -d "${OSRM_DATA_ROOT}/.admin-download.Bad777" ]
[ -d "${OSRM_DATA_ROOT}/.import.too-long" ]
[ -f "${OSRM_DATA_ROOT}/.import.File01" ]
[ -d "${OSRM_DATA_ROOT}/releases/20260716T120000Z-aaaaaaaaaaaa" ]
[ -f "${OSRM_DATA_ROOT}/current" ]
[ -f "${OSRM_DATA_ROOT}/previous" ]
[[ " ${REMOVED_SCRATCH} " == *' .import.Abc123 '* ]]
[[ " ${REMOVED_SCRATCH} " == *' .admin-download.Def456 '* ]]
[[ " ${REMOVED_SCRATCH} " != *' .admin-download.Act123 '* ]]
if [ "${CREATED_SYMLINK}" = '1' ]; then
  [ -L "${OSRM_DATA_ROOT}/.import.Link01" ]
fi

# A live activation marker belongs to the active manual import. Even unrelated
# exact-name scratch directories are retained until that owner exits.
: > "${OSRM_ACTIVATION_PENDING_FILE}"
mkdir -p "${OSRM_DATA_ROOT}/.import.Live01"
read_pending_activation() { return 0; }
pending_activation_owner_is_alive() { return 0; }
REMOVED_SCRATCH=''
OSRM_ACTIVE_SCRATCH_PATH='' sweep_stale_scratch
[ -d "${OSRM_DATA_ROOT}/.import.Live01" ]
[ -f "${OSRM_ACTIVATION_PENDING_FILE}" ]
[ -z "${REMOVED_SCRATCH}" ]

printf 'OSRM stale scratch ownership, active-target and crash-recovery test passed.\n'
