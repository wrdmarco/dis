#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-osrm-package-test.XXXXXX")"
DIS_DATA_PATH="${TEST_ROOT}/data"
export APP_ROOT DIS_DATA_PATH

cleanup() {
  rm -rf -- "${TEST_ROOT}"
}
trap cleanup EXIT

# shellcheck source=scripts/osrm.sh
source "${APP_ROOT}/scripts/osrm.sh"

policy_mode='ubuntu'
mock_installed_version=''
mock_installed_fingerprint='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
official_fingerprint='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
provenance_valid=0
tools_valid=1
package_held=0
apt_install_count=0
install_arguments=''
used_source_contents=''
locale_log="${TEST_ROOT}/locales"

require_root() { :; }
require_ubuntu_2604() { :; }
acquire_dis_operation_lock() { :; }
trusted_ubuntu_archive_keyring() { printf '/test/ubuntu-archive-keyring.gpg\n'; }
required_osrm_tools_owned_by_package() { [ "${tools_valid}" = '1' ]; }
osrm_package_fingerprint() { printf '%s\n' "${mock_installed_fingerprint}"; }
package_provenance_matches() {
  [ "${provenance_valid}" = '1' ] \
    && [ "$1" = "${mock_installed_fingerprint}" ] \
    && [ "$2" = "${mock_installed_version}" ]
}
write_package_provenance() {
  [ "$1" = "${mock_installed_fingerprint}" ]
  [ "$2" = "${mock_installed_version}" ]
  provenance_valid=1
}

apt-cache() {
  local restricted=0

  printf '%s\n' "${LC_ALL:-unset}" >> "${locale_log}"
  [[ "$*" == *'Dir::Etc::sourcelist='* ]] && restricted=1
  if [ "${restricted}" = '1' ]; then
    case "${policy_mode}" in
      ubuntu|collision)
        cat <<'POLICY'
osrm-backend:
  Installed: (none)
  Candidate: 5.27.1+ds-1
  Version table:
     5.27.1+ds-1 500
        500 http://archive.ubuntu.com/ubuntu resolute/universe amd64 Packages
POLICY
        ;;
      upgrade)
        cat <<'POLICY'
osrm-backend:
  Installed: 5.27.1+ds-1
  Candidate: 5.27.2+ds-1
  Version table:
     5.27.2+ds-1 500
        500 http://archive.ubuntu.com/ubuntu resolute/universe amd64 Packages
 *** 5.27.1+ds-1 100
        100 /var/lib/dpkg/status
POLICY
        ;;
      *)
        cat <<'POLICY'
osrm-backend:
  Installed: (none)
  Candidate: (none)
  Version table:
POLICY
        ;;
    esac
    return
  fi

  case "${policy_mode}" in
    ubuntu|upgrade)
      cat <<'POLICY'
Package files:
 500 http://archive.ubuntu.com/ubuntu resolute/main amd64 Packages
 500 http://archive.ubuntu.com/ubuntu resolute/universe amd64 Packages
POLICY
      ;;
    collision)
      cat <<'POLICY'
Package files:
 900 https://ppa.launchpadcontent.net/example/osrm/ubuntu resolute/main amd64 Packages
 500 http://archive.ubuntu.com/ubuntu resolute/main amd64 Packages
 500 http://archive.ubuntu.com/ubuntu resolute/universe amd64 Packages
POLICY
      ;;
    ppa)
      cat <<'POLICY'
Package files:
 700 https://ppa.launchpadcontent.net/example/osrm/ubuntu resolute/main amd64 Packages
POLICY
      ;;
    foreign-suite)
      cat <<'POLICY'
Package files:
 500 http://archive.ubuntu.com/ubuntu stonking/universe amd64 Packages
POLICY
      ;;
  esac
}

dpkg-query() {
  case "${1:-}" in
    -W)
      [ -z "${mock_installed_version}" ] || printf '%s' "${mock_installed_version}"
      ;;
    *)
      return 1
      ;;
  esac
  return 0
}

apt-mark() {
  case "${1:-}" in
    showhold) [ "${package_held}" = '0' ] || printf 'osrm-backend\n' ;;
    hold) package_held=1 ;;
    unhold) package_held=0 ;;
    *) return 1 ;;
  esac
}

run_cmd() {
  local argument selected_version='' source_list=''

  if [ "${1:-}" = 'apt-get' ]; then
    install_arguments="$*"
    apt_install_count=$((apt_install_count + 1))
    for argument in "$@"; do
      case "${argument}" in
        Dir::Etc::sourcelist=*) source_list="${argument#*=}" ;;
        osrm-backend=*) selected_version="${argument#*=}" ;;
      esac
    done
    [ -n "${source_list}" ] && used_source_contents="$(< "${source_list}")"
    [ -n "${selected_version}" ] || return 1
    mock_installed_version="${selected_version}"
    mock_installed_fingerprint="${official_fingerprint}"
    provenance_valid=0
    tools_valid=1
    return 0
  fi
  "$@"
}

# A fresh install is selected from a temporary source list containing only
# configured official Ubuntu entries. Every apt parser invocation must use C.
install_package
[ "${apt_install_count}" = '1' ]
[[ "${install_arguments}" == *'osrm-backend=5.27.1+ds-1'* ]]
[[ "${install_arguments}" == *'--allow-change-held-packages'* ]]
[[ "${install_arguments}" != *'--reinstall'* ]]
[[ "${used_source_contents}" == *'http://archive.ubuntu.com/ubuntu resolute main'* ]]
[[ "${used_source_contents}" == *'http://archive.ubuntu.com/ubuntu resolute universe'* ]]
[[ "${used_source_contents}" == *'signed-by=/test/ubuntu-archive-keyring.gpg'* ]]
[[ "${used_source_contents}" != *'ppa.launchpadcontent.net'* ]]
osrm_tools_available
[ "${package_held}" = '1' ]

# A PPA package with the exact same version and a missing/foreign receipt must
# be reinstalled through that restricted source list, not accepted by version.
policy_mode='collision'
mock_installed_version='5.27.1+ds-1'
mock_installed_fingerprint='cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc'
provenance_valid=0
install_arguments=''
used_source_contents=''
install_package
[ "${apt_install_count}" = '2' ]
[[ "${install_arguments}" == *'--reinstall'* ]]
[[ "${used_source_contents}" == *'archive.ubuntu.com/ubuntu resolute/universe'* \
  || "${used_source_contents}" == *'archive.ubuntu.com/ubuntu resolute universe'* ]]
[[ "${used_source_contents}" != *'ppa.launchpadcontent.net'* ]]
[ "${mock_installed_fingerprint}" = "${official_fingerprint}" ]
osrm_tools_available

# A valid durable fingerprint avoids an unnecessary reinstall.
package_held=0
install_package
[ "${apt_install_count}" = '2' ]
[ "${package_held}" = '1' ]

for policy_mode in ppa foreign-suite; do
  mock_installed_version=''
  mock_installed_fingerprint="${official_fingerprint}"
  provenance_valid=0
  install_arguments=''
  install_package
  [ -z "${install_arguments}" ] || {
    printf 'OSRM package test installed an unapproved %s source.\n' "${policy_mode}" >&2
    exit 1
  }
done

# Publishing a newer official candidate must not mutate an already attested
# runtime. The held package remains exact so a map-update rollback keeps binary
# compatibility with the previous dataset.
policy_mode='upgrade'
mock_installed_version='5.27.1+ds-1'
mock_installed_fingerprint="${official_fingerprint}"
provenance_valid=1
tools_valid=1
install_arguments=''
osrm_tools_available
[ "${apt_install_count}" = '2' ]

install_package
[ "${apt_install_count}" = '2' ]
[ "${mock_installed_version}" = '5.27.1+ds-1' ]
[ -z "${install_arguments}" ]
[ "${package_held}" = '1' ]
osrm_tools_available

if grep -vx 'C' "${locale_log}" >/dev/null; then
  printf 'OSRM package parser called apt-cache without LC_ALL=C.\n' >&2
  exit 1
fi

printf 'OSRM Ubuntu package origin and fingerprint test passed.\n'
