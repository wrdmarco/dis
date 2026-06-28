#!/usr/bin/env bash
set -euo pipefail

DIS_INSTALL_PATH="${DIS_INSTALL_PATH:-/opt/dis}"
UPDATE_TIMEOUT_SECONDS="${UPDATE_TIMEOUT_SECONDS:-3300}"
LOG_PATH="${DIS_INSTALL_PATH}/webapp/backend/storage/logs/system-update-runner.log"
BACKEND_DIR="${DIS_INSTALL_PATH}/webapp/backend"

if [ ! -d "${DIS_INSTALL_PATH}" ]; then
  printf '[dis:error] DIS install path not found: %s\n' "${DIS_INSTALL_PATH}" >&2
  exit 1
fi

exec >> "${LOG_PATH}" 2>&1

cd "${DIS_INSTALL_PATH}" || exit 1
echo "[dis] Updatecommando gestart via systemd runner."

set +e
timeout "${UPDATE_TIMEOUT_SECONDS}s" /usr/local/bin/update "$@"
exit_code=$?
set -e

if [ "${exit_code}" -eq 124 ]; then
  echo "[dis] Updateproces duurde te lang en is afgebroken."
fi
echo "[dis] Updatecommando afgerond met exit code ${exit_code}."

if [ -f "${BACKEND_DIR}/artisan" ]; then
  cd "${BACKEND_DIR}" || true
  php artisan dis:finish-update "${exit_code}" || true
fi

exit "${exit_code}"
