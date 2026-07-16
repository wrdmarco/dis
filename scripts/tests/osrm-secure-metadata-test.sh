#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
SECURE_PATH="${APP_ROOT}/scripts/lib/secure-path.py"

if [ "${EUID}" -ne 0 ] \
  || [ "$(python3 -I -S -c 'import os; print(os.name)' 2>/dev/null || true)" != 'posix' ]; then
  printf 'SKIP: OSRM descriptor metadata adversarial test requires POSIX root.\n'
  exit 0
fi

TEST_ROOT="$(mktemp -d /root/dis-osrm-metadata-test.XXXXXX)"
cleanup() {
  case "${TEST_ROOT}" in
    /root/dis-osrm-metadata-test.*) rm -rf -- "${TEST_ROOT}" ;;
    *) printf 'Refusing to clean unexpected test path: %s\n' "${TEST_ROOT}" >&2 ;;
  esac
}
trap cleanup EXIT

staging="${TEST_ROOT}/staging"
outside="${TEST_ROOT}/outside"
mkdir -p "${staging}"
printf 'artifact\n' > "${staging}/routing.osrm"
printf 'unchanged\n' > "${outside}"

# Parser-created links and hard links are rejected before root metadata writes.
ln -s "${outside}" "${staging}/manifest.json"
if python3 -I -S "${SECURE_PATH}" validate-tree "${staging}" >/dev/null 2>&1; then
  printf 'Parser-created metadata symlink was unexpectedly accepted.\n' >&2
  exit 1
fi
[ "$(cat "${outside}")" = 'unchanged' ]
rm -f -- "${staging}/manifest.json"
ln "${staging}/routing.osrm" "${staging}/routing.osrm.hardlink"
if python3 -I -S "${SECURE_PATH}" validate-tree "${staging}" >/dev/null 2>&1; then
  printf 'Parser-created hard link was unexpectedly accepted.\n' >&2
  exit 1
fi
rm -f -- "${staging}/routing.osrm.hardlink"

python3 -I -S "${SECURE_PATH}" repair-tree "${staging}" root root 0750 0440
python3 -I -S "${SECURE_PATH}" validate-tree "${staging}"
[ "$(stat -c '%u:%a' -- "${staging}")" = '0:750' ]
[ "$(stat -c '%u:%a:%h' -- "${staging}/routing.osrm")" = '0:440:1' ]

# Even if a hostile final-name symlink is presented, descriptor-relative
# write-file replaces the name atomically and never follows it.
ln -s "${outside}" "${staging}/manifest.json"
printf '{"safe":true}\n' \
  | python3 -I -S "${SECURE_PATH}" write-file "${staging}/manifest.json" root root 0440
[ "$(cat "${outside}")" = 'unchanged' ]
[ -f "${staging}/manifest.json" ] && [ ! -L "${staging}/manifest.json" ]
[ "$(stat -c '%u:%a:%h' -- "${staging}/manifest.json")" = '0:440:1' ]
[ "$(cat "${staging}/manifest.json")" = '{"safe":true}' ]

printf 'OSRM descriptor freeze and no-follow metadata adversarial test passed.\n'
