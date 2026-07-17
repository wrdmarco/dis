#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
DIS_DATA_PATH="${TMPDIR:-/tmp}/dis-osrm-container-metadata-test"
export APP_ROOT DIS_DATA_PATH

# shellcheck source=scripts/osrm.sh
source "${APP_ROOT}/scripts/osrm.sh"

mock_digest="${OSRM_CONTAINER_IMAGE_DIGEST}"
mock_architecture='amd64'
mock_os='linux'
mock_version="${OSRM_CONTAINER_IMAGE_VERSION}"
mock_source="${OSRM_CONTAINER_SOURCE}"
mock_revision="${OSRM_CONTAINER_REVISION}"
mock_license='BSD-2-Clause'
mock_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
mock_run_log="$(mktemp "${TMPDIR:-/tmp}/dis-osrm-podman-run.XXXXXX")"
mock_worker_lock="$(mktemp "${TMPDIR:-/tmp}/dis-osrm-worker-lock.XXXXXX")"
mock_operation_lock="$(mktemp "${TMPDIR:-/tmp}/dis-osrm-operation-lock.XXXXXX")"
mock_profile_sha='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
trap 'rm -f -- "${mock_run_log}" "${mock_worker_lock}" "${mock_operation_lock}"' EXIT

podman_mock() {
  [ "${CONTAINERS_CONF:-}" = "${OSRM_PODMAN_CONTAINERS_CONF}" ] || return 92
  grep -Fxq 'default_sysctls = []' "${CONTAINERS_CONF}" || return 93
  if [ "${assert_no_inherited_lock_fds:-0}" = '1' ]; then
    [ ! -e "/proc/${BASHPID}/fd/9" ] || return 90
    [ ! -e "/proc/${BASHPID}/fd/${DIS_OPERATION_LOCK_FD}" ] || return 91
  fi
  if [[ " $* " == *' run '* ]]; then
    printf '%s\n' "$*" > "${mock_run_log}"
    printf '%s  %s\n' "${mock_profile_sha}" "${OSRM_CONTAINER_PROFILE}"
    return 0
  fi
  [[ " $* " == *' image inspect '* ]] || return 1
  if [[ " $* " == *' --format {{.Id}} '* ]]; then
    printf '%s\n' "${mock_id}"
    return 0
  fi
  jq -cn \
    --arg digest "${mock_digest}" \
    --arg architecture "${mock_architecture}" \
    --arg os "${mock_os}" \
    --arg version "${mock_version}" \
    --arg source "${mock_source}" \
    --arg revision "${mock_revision}" \
    --arg license "${mock_license}" \
    --arg id "${mock_id}" \
    '[{
      Digest: $digest,
      Architecture: $architecture,
      Os: $os,
      Labels: {
        "org.opencontainers.image.version": $version,
        "org.opencontainers.image.source": $source,
        "org.opencontainers.image.revision": $revision,
        "org.opencontainers.image.licenses": $license
      },
      Id: $id
    }]'
}

OSRM_PODMAN_PATH=podman_mock
exec 9>"${mock_worker_lock}"
exec {DIS_OPERATION_LOCK_FD}>"${mock_operation_lock}"
export DIS_OPERATION_LOCK_FD
assert_no_inherited_lock_fds=1
podman_image_metadata_is_valid
[ "$(podman_image_id)" = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ]
[ "$(podman_profile_sha)" = "${mock_profile_sha}" ]
mock_run_arguments="$(< "${mock_run_log}")"
mock_run_arguments=" ${mock_run_arguments} "
[[ "${mock_run_arguments}" == *' --storage-driver=overlay '* ]]
[[ "${mock_run_arguments}" == *' --storage-opt=overlay.mount_program=/usr/bin/fuse-overlayfs '* ]]
[[ "${mock_run_arguments}" == *' --storage-opt=overlay.ignore_chown_errors=true '* ]]
[[ "${mock_run_arguments}" == *' --storage-opt=overlay.mountopt=nodev '* ]]
[[ "${mock_run_arguments}" == *' --cgroups=disabled '* ]]
[[ "${mock_run_arguments}" == *' --network=none '* ]]
assert_no_inherited_lock_fds=0
exec {DIS_OPERATION_LOCK_FD}>&-
exec 9>&-

for mutation in digest architecture version source revision license id; do
  case "${mutation}" in
    digest) mock_digest='sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ;;
    architecture) mock_architecture='arm64' ;;
    version) mock_version='latest' ;;
    source) mock_source='https://example.invalid/osrm' ;;
    revision) mock_revision='0000000000000000000000000000000000000000' ;;
    license) mock_license='unknown' ;;
    id) mock_id='not-a-content-id' ;;
  esac
  if podman_image_metadata_is_valid; then
    printf 'OSRM container metadata accepted a mismatched %s.\n' "${mutation}" >&2
    exit 1
  fi
  mock_digest="${OSRM_CONTAINER_IMAGE_DIGEST}"
  mock_architecture='amd64'
  mock_version="${OSRM_CONTAINER_IMAGE_VERSION}"
  mock_source="${OSRM_CONTAINER_SOURCE}"
  mock_revision="${OSRM_CONTAINER_REVISION}"
  mock_license='BSD-2-Clause'
  mock_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
done

mock_id='sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
podman_image_metadata_is_valid
[ "$(podman_image_id)" = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ]

printf 'OSRM immutable OCI metadata validation test passed.\n'
