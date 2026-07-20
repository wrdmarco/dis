#!/usr/bin/env bash
set -euo pipefail

DIS_INSTALL_PATH="${DIS_INSTALL_PATH:-/opt/dis}"
UPDATE_TIMEOUT_SECONDS="${UPDATE_TIMEOUT_SECONDS:-3300}"
LOG_DIRECTORY="/var/log/dis"
LOG_PATH="${LOG_DIRECTORY}/system-update-runner.log"
BACKEND_DIR="${DIS_INSTALL_PATH}/webapp/backend"
STARTED_EPOCH="$(date +%s)"
INCLUDES_SYSTEM_UPDATES=1

for argument in "$@"; do
  if [ "${argument}" = "--skip-system" ]; then
    INCLUDES_SYSTEM_UPDATES=0
  fi
done

if [ ! -d "${DIS_INSTALL_PATH}" ]; then
  printf '[dis:error] DIS install path not found: %s\n' "${DIS_INSTALL_PATH}" >&2
  exit 1
fi

install -d -m 0750 -o root -g dis "${LOG_DIRECTORY}"
if [ -L "${LOG_PATH}" ]; then
  printf '[dis:error] Refusing symlink update log: %s\n' "${LOG_PATH}" >&2
  exit 1
fi
touch "${LOG_PATH}"
chown root:dis "${LOG_PATH}"
chmod 0640 "${LOG_PATH}"
: > "${LOG_PATH}"
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
  FINISHED_EPOCH="$(date +%s)"
  DURATION_SECONDS="$((FINISHED_EPOCH - STARTED_EPOCH))"
  if [ "${DURATION_SECONDS}" -lt 1 ]; then
    DURATION_SECONDS=1
  fi
  FINISH_ARGUMENTS=(dis:finish-update "${exit_code}" "${DURATION_SECONDS}")
  if [ "${INCLUDES_SYSTEM_UPDATES}" = "1" ]; then
    FINISH_ARGUMENTS+=(--system)
  fi
  runuser -u dis -- php artisan "${FINISH_ARGUMENTS[@]}" || true
fi

exit "${exit_code}"
