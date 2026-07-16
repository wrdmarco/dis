#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-osrm-build-tool-test.XXXXXX")"
DIS_DATA_PATH="${TEST_ROOT}/data"
export APP_ROOT DIS_DATA_PATH
trap 'rm -rf -- "${TEST_ROOT}"' EXIT

# shellcheck source=scripts/osrm.sh
source "${APP_ROOT}/scripts/osrm.sh"

candidate_version='1.15.0-1'
mock_installed_version=''
installed_fingerprint='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
official_fingerprint='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
receipt_valid=0
tool_owned=1
package_held=0
apt_install_count=0
install_arguments=''

require_root() { :; }
require_ubuntu_2604() { :; }
acquire_dis_operation_lock() { [ "$1" = 'osrm-build-tool' ]; }
prepare_approved_apt_sources() {
  printf 'deb [signed-by=/test/ubuntu-archive-keyring.gpg] http://archive.ubuntu.com/ubuntu resolute universe\n' > "$1"
}
official_osmium_candidate() { printf '%s\n' "${candidate_version}"; }
required_osmium_tool_owned_by_package() { [ "${tool_owned}" = '1' ]; }
osmium_package_fingerprint() { printf '%s\n' "${installed_fingerprint}"; }
build_tool_provenance_matches() {
  [ "${receipt_valid}" = '1' ] \
    && [ "$1" = "${installed_fingerprint}" ] \
    && [ "$2" = "${mock_installed_version}" ]
}
write_build_tool_provenance() {
  [ "$1" = "${installed_fingerprint}" ]
  [ "$2" = "${mock_installed_version}" ]
  receipt_valid=1
}
installed_osrm_package_matches_receipt() { return 0; }
required_osrm_tools_owned_by_package() { return 0; }

dpkg-query() {
  if [ "${1:-}" = '-W' ]; then
    [ -z "${mock_installed_version}" ] || printf '%s' "${mock_installed_version}"
    return 0
  fi
  return 1
}

apt-mark() {
  case "${1:-}" in
    showhold) [ "${package_held}" = '0' ] || printf 'osmium-tool\n' ;;
    hold) package_held=1 ;;
    unhold) package_held=0 ;;
    *) return 1 ;;
  esac
}

run_cmd() {
  local argument selected_version=''

  if [ "${1:-}" = 'apt-get' ]; then
    install_arguments="$*"
    apt_install_count=$((apt_install_count + 1))
    for argument in "$@"; do
      case "${argument}" in
        osmium-tool=*) selected_version="${argument#*=}" ;;
      esac
    done
    [ "${selected_version}" = "${candidate_version}" ] || return 1
    mock_installed_version="${selected_version}"
    installed_fingerprint="${official_fingerprint}"
    receipt_valid=0
    tool_owned=1
    return 0
  fi
  "$@"
}

# The map-build dependency is installed, attested and held independently.
install_build_tool
[ "${apt_install_count}" = '1' ]
[[ "${install_arguments}" == *'osmium-tool=1.15.0-1'* ]]
[[ "${install_arguments}" == *'--no-install-recommends'* ]]
[[ "${install_arguments}" == *'--allow-change-held-packages'* ]]
[ "${package_held}" = '1' ]
osmium_tool_available

# A valid receipt avoids mutation. A foreign existing install is reinstalled
# through the restricted official source path and gets a new receipt.
install_arguments=''
install_build_tool
[ "${apt_install_count}" = '1' ]
[ -z "${install_arguments}" ]

receipt_valid=0
installed_fingerprint='cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc'
install_build_tool
[ "${apt_install_count}" = '2' ]
[[ "${install_arguments}" == *'--reinstall'* ]]
[ "${installed_fingerprint}" = "${official_fingerprint}" ]
osmium_tool_available

# Runtime readiness is deliberately independent from osmium-tool: deploying
# application code may not degrade an already active OSRM release.
receipt_valid=0
package_held=0
tool_owned=0
if osmium_tool_available; then
  printf 'An invalid osmium-tool installation was unexpectedly accepted.\n' >&2
  exit 1
fi
osrm_tools_available

printf 'OSRM separately attested osmium-tool package test passed.\n'
