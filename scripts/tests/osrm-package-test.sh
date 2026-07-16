#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-osrm-package-test.XXXXXX")"
DIS_DATA_PATH="${TEST_ROOT}/data"
export APP_ROOT DIS_DATA_PATH
trap 'rm -rf -- "${TEST_ROOT}"' EXIT

# shellcheck source=scripts/osrm.sh
source "${APP_ROOT}/scripts/osrm.sh"

policy_mode='ubuntu'
mock_installed_version=''
mock_fuse_installed_version=''
mock_installed_fingerprint='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
mock_fuse_installed_fingerprint='1111111111111111111111111111111111111111111111111111111111111111'
official_fingerprint='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
official_fuse_fingerprint='2222222222222222222222222222222222222222222222222222222222222222'
mock_image_id='sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc'
mock_profile_sha='dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd'
provenance_valid=0
image_valid=0
package_held=0
fuse_package_held=0
apt_install_count=0
pull_count=0
install_arguments=''
pull_arguments=''
used_source_contents=''
locale_log="${TEST_ROOT}/locales"

require_root() { :; }
require_ubuntu_2604() { :; }
acquire_dis_operation_lock() { :; }
trusted_ubuntu_archive_keyring() { printf '/test/ubuntu-archive-keyring.gpg\n'; }
required_podman_tool_owned_by_package() { return 0; }
required_fuse_overlayfs_tool_owned_by_package() { return 0; }
fuse_device_available() { return 0; }
podman_package_fingerprint() { printf '%s\n' "${mock_installed_fingerprint}"; }
fuse_overlayfs_package_fingerprint() { printf '%s\n' "${mock_fuse_installed_fingerprint}"; }
podman_image_metadata_is_valid() { [ "${image_valid}" = '1' ]; }
podman_image_id() { printf '%s\n' "${mock_image_id}"; }
podman_profile_sha() { [ "${image_valid}" = '1' ] && printf '%s\n' "${mock_profile_sha}"; }
container_provenance_matches() {
  [ "${provenance_valid}" = '1' ] \
    && [ "$1" = "${mock_installed_fingerprint}" ] \
    && [ "$2" = "${mock_installed_version}" ] \
    && [ "$3" = "${mock_fuse_installed_fingerprint}" ] \
    && [ "$4" = "${mock_fuse_installed_version}" ] \
    && [ "$5" = "${mock_image_id}" ] \
    && [ "$6" = "${mock_profile_sha}" ]
}
write_container_provenance() {
  [ "$1" = "${mock_installed_fingerprint}" ]
  [ "$2" = "${mock_installed_version}" ]
  [ "$3" = "${mock_fuse_installed_fingerprint}" ]
  [ "$4" = "${mock_fuse_installed_version}" ]
  [ "$5" = "${mock_image_id}" ]
  [ "$6" = "${mock_profile_sha}" ]
  provenance_valid=1
}

apt-cache() {
  local package_name="${@: -1}" restricted=0

  printf '%s\n' "${LC_ALL:-unset}" >> "${locale_log}"
  [[ "$*" == *'Dir::Etc::sourcelist='* ]] && restricted=1
  if [ "${restricted}" = '1' ]; then
    if [ "${package_name}" = 'fuse-overlayfs' ]; then
      cat <<'POLICY'
fuse-overlayfs:
  Installed: (none)
  Candidate: 1.15-1
  Version table:
     1.15-1 500
        500 http://archive.ubuntu.com/ubuntu resolute/universe amd64 Packages
POLICY
      return
    fi
    case "${policy_mode}" in
      ubuntu|collision)
        cat <<'POLICY'
podman:
  Installed: (none)
  Candidate: 5.4.2+ds1-1ubuntu1
  Version table:
     5.4.2+ds1-1ubuntu1 500
        500 http://archive.ubuntu.com/ubuntu resolute/universe amd64 Packages
POLICY
        ;;
      upgrade)
        cat <<'POLICY'
podman:
  Installed: 5.4.2+ds1-1ubuntu1
  Candidate: 5.5.0+ds1-1ubuntu1
  Version table:
     5.5.0+ds1-1ubuntu1 500
        500 http://archive.ubuntu.com/ubuntu resolute/universe amd64 Packages
POLICY
        ;;
      *)
        printf 'podman:\n  Installed: (none)\n  Candidate: (none)\n'
        ;;
    esac
    return
  fi

  case "${policy_mode}" in
    ubuntu|upgrade)
      printf 'Package files:\n 500 http://archive.ubuntu.com/ubuntu resolute/main amd64 Packages\n 500 http://archive.ubuntu.com/ubuntu resolute/universe amd64 Packages\n'
      ;;
    collision)
      printf 'Package files:\n 900 https://ppa.launchpadcontent.net/example/containers/ubuntu resolute/main amd64 Packages\n 500 http://archive.ubuntu.com/ubuntu resolute/universe amd64 Packages\n'
      ;;
    ppa)
      printf 'Package files:\n 700 https://ppa.launchpadcontent.net/example/containers/ubuntu resolute/main amd64 Packages\n'
      ;;
    foreign-suite)
      printf 'Package files:\n 500 http://archive.ubuntu.com/ubuntu stonking/universe amd64 Packages\n'
      ;;
  esac
}

dpkg-query() {
  local package_name="${@: -1}"

  case "${1:-}" in
    -W)
      case "${package_name}" in
        podman) [ -z "${mock_installed_version}" ] || printf '%s' "${mock_installed_version}" ;;
        fuse-overlayfs) [ -z "${mock_fuse_installed_version}" ] || printf '%s' "${mock_fuse_installed_version}" ;;
        *) return 1 ;;
      esac
      ;;
    *) return 1 ;;
  esac
}

apt-mark() {
  case "${1:-}" in
    showhold)
      [ "${package_held}" = '0' ] || printf 'podman\n'
      [ "${fuse_package_held}" = '0' ] || printf 'fuse-overlayfs\n'
      ;;
    hold) package_held=1; fuse_package_held=1 ;;
    unhold) package_held=0; fuse_package_held=0 ;;
    *) return 1 ;;
  esac
}

run_cmd() {
  local argument selected_fuse_version='' selected_version='' source_list=''

  if [ "${1:-}" = 'apt-get' ]; then
    install_arguments="$*"
    apt_install_count=$((apt_install_count + 1))
    for argument in "$@"; do
      case "${argument}" in
        Dir::Etc::sourcelist=*) source_list="${argument#*=}" ;;
        podman=*) selected_version="${argument#*=}" ;;
        fuse-overlayfs=*) selected_fuse_version="${argument#*=}" ;;
      esac
    done
    [ -n "${source_list}" ] && used_source_contents="$(< "${source_list}")"
    [ -n "${selected_version}" ] || return 1
    [ -n "${selected_fuse_version}" ] || return 1
    mock_installed_version="${selected_version}"
    mock_fuse_installed_version="${selected_fuse_version}"
    mock_installed_fingerprint="${official_fingerprint}"
    mock_fuse_installed_fingerprint="${official_fuse_fingerprint}"
    provenance_valid=0
    return 0
  fi
  if [ "${1:-}" = "${OSRM_PODMAN_PATH}" ] && [[ " $* " == *' pull '* ]]; then
    pull_arguments="$*"
    pull_count=$((pull_count + 1))
    image_valid=1
    return 0
  fi
  "$@"
}

install_package
[ "${apt_install_count}" = '1' ]
[ "${pull_count}" = '1' ]
[[ "${install_arguments}" == *'podman=5.4.2+ds1-1ubuntu1'* ]]
[[ "${install_arguments}" == *'fuse-overlayfs=1.15-1'* ]]
[[ "${install_arguments}" == *'--allow-change-held-packages'* ]]
[[ "${used_source_contents}" == *'http://archive.ubuntu.com/ubuntu resolute universe'* ]]
[[ "${used_source_contents}" == *'signed-by=/test/ubuntu-archive-keyring.gpg'* ]]
[[ "${used_source_contents}" != *'ppa.launchpadcontent.net'* ]]
[[ "${pull_arguments}" == *'--arch amd64 --os linux'* ]]
[[ "${pull_arguments}" == *'--storage-driver=overlay'* ]]
[[ "${pull_arguments}" == *'--storage-opt=overlay.mount_program=/usr/bin/fuse-overlayfs'* ]]
[[ "${pull_arguments}" == *'--storage-opt=overlay.mountopt=nodev'* ]]
[[ "${pull_arguments}" == *'--root=/var/lib/containers/dis-osrm-overlay'* ]]
[[ "${pull_arguments}" == *'--runroot=/run/containers/dis-osrm-overlay'* ]]
[[ "${pull_arguments}" == *"${OSRM_CONTAINER_IMAGE}"* ]]
[[ "${OSRM_CONTAINER_IMAGE}" == *@sha256:* ]]
[[ "${OSRM_CONTAINER_IMAGE}" != *':latest'* ]]
installed_container_runtime_matches_receipt
[ "${package_held}" = '1' ]
[ "${fuse_package_held}" = '1' ]

# An equal-version PPA collision is reinstalled from the restricted Ubuntu
# source list before the immutable image receipt is trusted.
policy_mode='collision'
mock_installed_fingerprint='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'
mock_fuse_installed_fingerprint='3333333333333333333333333333333333333333333333333333333333333333'
provenance_valid=0
install_arguments=''
install_package
[ "${apt_install_count}" = '2' ]
[[ "${install_arguments}" == *'--reinstall'* ]]
[[ "${used_source_contents}" != *'ppa.launchpadcontent.net'* ]]
[ "${mock_installed_fingerprint}" = "${official_fingerprint}" ]
[ "${mock_fuse_installed_fingerprint}" = "${official_fuse_fingerprint}" ]
installed_container_runtime_matches_receipt

# A valid exact receipt avoids both package mutation and image network access,
# even when a newer signed Podman candidate appears later.
policy_mode='upgrade'
install_arguments=''
install_package
[ "${apt_install_count}" = '2' ]
[ "${pull_count}" = '2' ]
[ -z "${install_arguments}" ]
installed_container_runtime_matches_receipt

for policy_mode in ppa foreign-suite; do
  mock_installed_version=''
  provenance_valid=0
  package_held=0
  fuse_package_held=0
  if (install_package >/dev/null 2>&1); then
    printf 'OSRM container test accepted an unapproved %s source.\n' "${policy_mode}" >&2
    exit 1
  fi
done

if grep -vx 'C' "${locale_log}" >/dev/null; then
  printf 'OSRM package parser called apt-cache without LC_ALL=C.\n' >&2
  exit 1
fi

printf 'OSRM Podman origin, digest and provenance test passed.\n'
