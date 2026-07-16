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
mock_id='sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'

podman_mock() {
  [ "${1:-}" = 'image' ] && [ "${2:-}" = 'inspect' ] || return 1
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
podman_image_metadata_is_valid

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
  mock_id='sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
done

printf 'OSRM immutable OCI metadata validation test passed.\n'
