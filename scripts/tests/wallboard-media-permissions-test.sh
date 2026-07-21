#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
SELF_HEAL="${APP_ROOT}/scripts/self-heal-permissions.sh"
RESTORE="${APP_ROOT}/scripts/restore.sh"
SECURE_PATH="${APP_ROOT}/scripts/lib/secure-path.py"

grep -F 'gpasswd -d www-data "${DIS_GROUP}"' "${SELF_HEAL}" >/dev/null
grep -F '"${DIS_DATA_PATH}/webapp/backend/storage/app"' "${SELF_HEAL}" >/dev/null
grep -F 'repair_managed_tree "${runtime_leaf}" "${DIS_USER}" "${DIS_GROUP}" 0770 0660' "${SELF_HEAL}" >/dev/null
grep -F 'secure_path_operation acl-tree "${DIS_DATA_PATH}/webapp/backend/storage/app" "${DIS_USER}" rwx rw-' "${SELF_HEAL}" >/dev/null
grep -F 'repair_managed_tree "${runtime_leaf}" "${DIS_USER}" "${DIS_GROUP}" 0770 0660' "${RESTORE}" >/dev/null
grep -F 'secure_path_operation acl-tree "${DIS_DATA_PATH}/webapp/backend/storage/app" "${DIS_USER}" rwx rw-' "${RESTORE}" >/dev/null

if [ "$(uname -s)" != "Linux" ] || [ "$(id -u)" -ne 0 ] \
  || ! id dis >/dev/null 2>&1 || ! id www-data >/dev/null 2>&1 \
  || ! command -v getfacl >/dev/null 2>&1 || ! command -v setfacl >/dev/null 2>&1 \
  || ! command -v python3 >/dev/null 2>&1 || ! command -v runuser >/dev/null 2>&1; then
  printf 'SKIP: wallboard media ACL runtime test requires Linux root, dis/www-data identities, getfacl, setfacl and runuser.\n'
  printf 'Wallboard media static permission contract test passed.\n'
  exit 0
fi

if id -nG www-data | tr ' ' '\n' | grep -Fxq dis; then
  printf 'www-data must not be a member of the dis group.\n' >&2
  exit 1
fi

TEST_ROOT="$(mktemp -d /run/dis-wallboard-media-acl-test.XXXXXX)"
cleanup() { rm -rf -- "${TEST_ROOT}"; }
trap cleanup EXIT

install -d -m 0770 -o dis -g dis "${TEST_ROOT}/storage/app"
setfacl -m u:www-data:--x "${TEST_ROOT}" "${TEST_ROOT}/storage"
install -d -m 0770 -o www-data -g www-data \
  "${TEST_ROOT}/storage/app/wallboard-media/objects"
printf legacy > "${TEST_ROOT}/storage/app/wallboard-media/objects/legacy.mp4"
chown www-data:www-data "${TEST_ROOT}/storage/app/wallboard-media/objects/legacy.mp4"
chmod 0640 "${TEST_ROOT}/storage/app/wallboard-media/objects/legacy.mp4"
python3 "${SECURE_PATH}" repair-tree \
  "${TEST_ROOT}/storage/app" dis dis 0770 0660
python3 "${SECURE_PATH}" acl-tree \
  "${TEST_ROOT}/storage/app" www-data rwx rw-
python3 "${SECURE_PATH}" acl-tree \
  "${TEST_ROOT}/storage/app" dis rwx rw-

getfacl -cp "${TEST_ROOT}/storage/app" \
  | grep -Fx 'default:user:dis:rwx' >/dev/null
runuser -u dis -- /usr/bin/dd \
  "if=${TEST_ROOT}/storage/app/wallboard-media/objects/legacy.mp4" \
  of=/dev/null bs=1 count=6 status=none

runuser -u www-data -- mkdir -p \
  "${TEST_ROOT}/storage/app/wallboard-media/objects" \
  "${TEST_ROOT}/storage/app/wallboard-media/staging"
runuser -u www-data -- sh -c \
  'printf source > "$1" && chmod 0640 "$1"' sh \
  "${TEST_ROOT}/storage/app/wallboard-media/objects/source.mp4"

runuser -u dis -- /usr/bin/dd \
  "if=${TEST_ROOT}/storage/app/wallboard-media/objects/source.mp4" \
  of=/dev/null bs=1 count=6 status=none
runuser -u dis -- sh -c \
  'printf transcoded > "$1" && chmod 0640 "$1" && mv -f "$1" "$2"' sh \
  "${TEST_ROOT}/storage/app/wallboard-media/staging/output.mp4" \
  "${TEST_ROOT}/storage/app/wallboard-media/objects/source.mp4"
runuser -u www-data -- /usr/bin/dd \
  "if=${TEST_ROOT}/storage/app/wallboard-media/objects/source.mp4" \
  of=/dev/null bs=1 count=10 status=none

printf 'Wallboard media Linux ACL permission contract test passed.\n'
