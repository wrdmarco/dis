#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
COMMON="${APP_ROOT}/scripts/lib/common.sh"
DEPLOY="${APP_ROOT}/scripts/deploy.sh"

require_text() {
  local file="$1" value="$2"

  grep -Fq -- "${value}" "${file}" || {
    printf 'Missing managed environment mutation contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  }
}

reject_text() {
  local file="$1" value="$2"

  if grep -Fq -- "${value}" "${file}"; then
    printf 'Forbidden managed environment mutation contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  fi
}

require_text "${COMMON}" 'mutate_managed_env_value() ('
require_text "${COMMON}" 'canonical_dis_data_path() {'
require_text "${COMMON}" 'resolved_data_path="$(/usr/bin/readlink -f -- "${DIS_DATA_PATH}" 2>/dev/null || true)"'
require_text "${COMMON}" 'resolved_data_path="$(canonical_dis_data_path)" \'
require_text "${COMMON}" 'expected_env="${resolved_data_path}/.env"'
require_text "${COMMON}" 'resolved_env="$(/usr/bin/readlink -f -- "${env_file}" 2>/dev/null || true)"'
require_text "${COMMON}" '[ "${resolved_env}" = "${expected_env}" ]'
require_text "${COMMON}" '[ -f "${resolved_env}" ] && [ ! -L "${resolved_env}" ]'
require_text "${COMMON}" '[ "$(stat -c '\''%h'\'' -- "${resolved_env}" 2>/dev/null || true)" = "1" ]'
require_text "${COMMON}" 'require_root_controlled_parent "${resolved_env}"'
require_text "${COMMON}" 'if [ "${DRY_RUN:-0}" = "1" ]; then'
require_text "${COMMON}" 'log "Would ${operation} managed environment key ${key}."'
require_text "${COMMON}" 'temporary="$(mktemp "${resolved_env}.rewrite.XXXXXX")"'
require_text "${COMMON}" 'chown --reference="${resolved_env}" "${temporary}"'
require_text "${COMMON}" 'chmod --reference="${resolved_env}" "${temporary}"'
require_text "${COMMON}" '/usr/bin/getfacl -cp -- "${resolved_env}" \'
require_text "${COMMON}" '| /usr/bin/setfacl --set-file=- -- "${temporary}"'
require_text "${COMMON}" 'The managed environment replacement did not preserve ownership and mode.'
require_text "${COMMON}" 'The managed environment replacement did not preserve its ACL.'
require_text "${COMMON}" 'sync -f "${temporary}"'
require_text "${COMMON}" 'mv -fT -- "${temporary}" "${resolved_env}"'
require_text "${COMMON}" 'sync -f "$(dirname "${resolved_env}")"'
require_text "${COMMON}" 'set_managed_env_value() {'
require_text "${COMMON}" 'remove_managed_env_key() {'

require_text "${DEPLOY}" 'set_managed_env_value "${ENV_FILE}" "${key}" "${value}"'
require_text "${DEPLOY}" 'remove_managed_env_key "${ENV_FILE}" "${legacy_key}"'
reject_text "${DEPLOY}" 'sed -i "s/^${key}=.*/${key}='
reject_text "${DEPLOY}" 'sed -i "/^${legacy_key}=/d" "${ENV_FILE}"'
reject_text "${DEPLOY}" '>> "${ENV_FILE}"'

(
  # shellcheck source=scripts/lib/common.sh
  source "${COMMON}"
  temporary_root="$(mktemp -d)"
  temporary_data="${temporary_root}/data"
  mkdir -- "${temporary_data}"
  cleanup_trailing_slash_test() {
    rmdir -- "${temporary_data}" "${temporary_root}"
  }
  trap cleanup_trailing_slash_test EXIT

  DIS_DATA_PATH="${temporary_data}/"
  actual_path="$(canonical_dis_data_path)"
  expected_path="$(/usr/bin/readlink -f -- "${temporary_data}")"
  [ "${actual_path}" = "${expected_path}" ] || {
    printf 'A managed data path with a trailing slash did not canonicalize correctly.\n' >&2
    exit 1
  }
)

printf 'Managed environment atomic mutation contract passed.\n'
