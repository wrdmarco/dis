#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
DIS_DATA_PATH="/opt/dis-data"
export APP_ROOT DIS_DATA_PATH

# shellcheck source=scripts/osrm.sh
source "${APP_ROOT}/scripts/osrm.sh"

fake_extra_group_account=""
fake_primary_group_account=""

getent() {
  case "${1:-}:${2:-}" in
    group:dis-osrm)
      printf 'dis-osrm:x:987:\n'
      ;;
    passwd:dis-osrm)
      printf 'dis-osrm:x:988:987::/opt/dis-data/osrm:/usr/sbin/nologin\n'
      ;;
    passwd:dis-osrm-build)
      printf 'dis-osrm-build:x:989:987::/opt/dis-data/osrm:/usr/sbin/nologin\n'
      ;;
    passwd:)
      printf 'dis-osrm:x:988:987::/opt/dis-data/osrm:/usr/sbin/nologin\n'
      printf 'dis-osrm-build:x:989:987::/opt/dis-data/osrm:/usr/sbin/nologin\n'
      if [ -n "${fake_primary_group_account}" ]; then
        printf '%s:x:990:987::/nonexistent:/usr/sbin/nologin\n' \
          "${fake_primary_group_account}"
      fi
      ;;
    *)
      return 2
      ;;
  esac
}

id() {
  local option="${1:-}"
  local account="${2:-${1:-}}"

  case "${option}" in
    -u)
      [ "${account}" = "dis-osrm" ] && printf '988\n' || printf '989\n'
      ;;
    -gn)
      printf 'dis-osrm\n'
      ;;
    -G)
      if [ "${account}" = "${fake_extra_group_account}" ]; then
        printf '987 1200\n'
      else
        printf '987\n'
      fi
      ;;
    dis-osrm|dis-osrm-build)
      return 0
      ;;
    *)
      return 2
      ;;
  esac
}

awk() {
  printf '1000\n'
}

ensure_osrm_identity

for fake_extra_group_account in dis-osrm dis-osrm-build; do
  if (ensure_osrm_identity >/dev/null 2>&1); then
    printf 'OSRM identity validation accepted supplementary groups for %s.\n' \
      "${fake_extra_group_account}" >&2
    exit 1
  fi
done

fake_extra_group_account=""
fake_primary_group_account="unrelated-system-service"
if (ensure_osrm_identity >/dev/null 2>&1); then
  printf 'OSRM identity validation accepted another account with the primary OSRM gid.\n' >&2
  exit 1
fi

printf 'OSRM identity isolation test passed.\n'
