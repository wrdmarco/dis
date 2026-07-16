#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"

grep -F 'root "${DIS_GROUP}" 0750' "${APP_ROOT}/scripts/lib/common.sh" >/dev/null
grep -F 'chown root:"${DIS_GROUP}" "${temporary}"' "${APP_ROOT}/scripts/osrm-admin-request-worker.sh" >/dev/null
grep -F 'u:www-data:r--' "${APP_ROOT}/scripts/osrm-admin-request-worker.sh" >/dev/null
grep -F 'u:${DIS_USER}:--x' "${APP_ROOT}/scripts/lib/common.sh" >/dev/null
grep -F 'u:www-data:r-x" /var/log/dis' "${APP_ROOT}/scripts/lib/common.sh" >/dev/null
grep -F 'require_user_can_open_file_for_reading www-data' "${APP_ROOT}/scripts/lib/common.sh" >/dev/null
grep -F 'require_user_can_open_directory_for_reading www-data' "${APP_ROOT}/scripts/lib/common.sh" >/dev/null

if [ "$(id -u)" -ne 0 ] || ! id dis >/dev/null 2>&1 || ! id www-data >/dev/null 2>&1 \
  || ! command -v setfacl >/dev/null 2>&1 || ! command -v runuser >/dev/null 2>&1; then
  printf 'SKIP: OSRM ACL runtime test requires root, dis/www-data identities, setfacl and runuser on Linux.\n'
  printf 'OSRM admin static permission contract test passed.\n'
  exit 0
fi

TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-osrm-admin-acl-test.XXXXXX")"
cleanup() { rm -rf -- "${TEST_ROOT}"; }
trap cleanup EXIT

install -d -m 0750 -o root -g root "${TEST_ROOT}/osrm-admin"
install -d -m 0750 -o root -g dis "${TEST_ROOT}/osrm-admin/results"
install -m 0640 -o root -g dis /dev/null "${TEST_ROOT}/osrm-admin/results/status.json"
setfacl -m u:dis:--x,u:www-data:--x "${TEST_ROOT}/osrm-admin"
setfacl -m u:dis:--x,u:www-data:--x "${TEST_ROOT}"
setfacl -m u:www-data:r-x "${TEST_ROOT}/osrm-admin/results"
setfacl -m u:www-data:r-- "${TEST_ROOT}/osrm-admin/results/status.json"

runuser -u dis -- /usr/bin/dd \
  "if=${TEST_ROOT}/osrm-admin/results/status.json" of=/dev/null bs=1 count=1 status=none
runuser -u www-data -- /usr/bin/dd \
  "if=${TEST_ROOT}/osrm-admin/results/status.json" of=/dev/null bs=1 count=1 status=none
runuser -u dis -- /usr/bin/find "${TEST_ROOT}/osrm-admin/results" \
  -mindepth 1 -maxdepth 1 -print -quit >/dev/null
runuser -u www-data -- /usr/bin/find "${TEST_ROOT}/osrm-admin/results" \
  -mindepth 1 -maxdepth 1 -print -quit >/dev/null
printf '{"version":1}\n' > "${TEST_ROOT}/osrm-admin/results/status.json"
runuser -u www-data -- /usr/bin/dd \
  "if=${TEST_ROOT}/osrm-admin/results/status.json" of=/dev/null bs=1 count=1 status=none
if runuser -u www-data -- sh -c ': >> "$1"' sh "${TEST_ROOT}/osrm-admin/results/status.json" 2>/dev/null; then
  printf 'www-data unexpectedly obtained write access to an OSRM result.\n' >&2
  exit 1
fi
if runuser -u dis -- sh -c ': >> "$1"' sh "${TEST_ROOT}/osrm-admin/results/status.json" 2>/dev/null; then
  printf 'dis unexpectedly obtained write access to an OSRM result.\n' >&2
  exit 1
fi

printf 'OSRM admin Linux ACL permission contract test passed.\n'
