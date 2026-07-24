#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
GENERAL_UNIT="${APP_ROOT}/infrastructure/systemd/dis-queue.service"
PUSH_UNIT="${APP_ROOT}/infrastructure/systemd/dis-push@.service"
SPEECH_UNIT="${APP_ROOT}/infrastructure/systemd/dis-speech.service"
DEPLOY="${APP_ROOT}/scripts/deploy.sh"
COMMON="${APP_ROOT}/scripts/lib/common.sh"
RESTORE="${APP_ROOT}/scripts/restore.sh"
SELF_HEAL="${APP_ROOT}/scripts/self-heal-permissions.sh"
UNINSTALL="${APP_ROOT}/scripts/uninstall.sh"
QUEUE_CONFIG="${APP_ROOT}/webapp/backend/config/queue.php"
DIS_CONFIG="${APP_ROOT}/webapp/backend/config/dis.php"
PUSH_JOB="${APP_ROOT}/webapp/backend/app/Jobs/SendFcmNotification.php"

require_text() {
  local file="$1" value="$2"
  grep -Fq -- "${value}" "${file}" || {
    printf 'Missing push queue contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  }
}

reject_text() {
  local file="$1" value="$2"
  if grep -Fq -- "${value}" "${file}"; then
    printf 'Forbidden push queue contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  fi
}

require_text "${GENERAL_UNIT}" 'artisan queue:work redis --queue=default,broadcasts'
reject_text "${GENERAL_UNIT}" '--queue=push'
require_text "${PUSH_UNIT}" 'artisan queue:work push --queue=push'
require_text "${PUSH_UNIT}" '--tries=4 --timeout=180'
require_text "${PUSH_UNIT}" 'NoNewPrivileges=true'
require_text "${PUSH_UNIT}" 'PrivateDevices=true'
require_text "${PUSH_UNIT}" 'ProtectSystem=strict'
reject_text "${PUSH_UNIT}" '--queue=speech'
reject_text "${SPEECH_UNIT}" '--queue=push'

require_text "${QUEUE_CONFIG}" "'push' => ["
require_text "${QUEUE_CONFIG}" "'queue' => 'push'"
require_text "${QUEUE_CONFIG}" "'retry_after' => max(240"
require_text "${DIS_CONFIG}" "'worker_timeout_seconds' => 180"
require_text "${DIS_CONFIG}" "'max_attempts' => 4"
require_text "${DIS_CONFIG}" "'stale_active_after_seconds' => 7200"
require_text "${PUSH_JOB}" 'implements ShouldBeEncrypted, ShouldQueue'
require_text "${PUSH_JOB}" 'public int $tries = 4;'
require_text "${PUSH_JOB}" "\$this->onConnection('push')->onQueue('push');"

require_text "${DEPLOY}" 'infrastructure/systemd/dis-push@.service'
for instance in dis-push@1 dis-push@2 dis-push@3 dis-push@4; do
  require_text "${DEPLOY}" "${instance}"
  require_text "${COMMON}" "${instance}"
  require_text "${RESTORE}" "${instance}"
  require_text "${SELF_HEAL}" "${instance}"
  require_text "${UNINSTALL}" "${instance}"
done
require_text "${UNINSTALL}" '/etc/systemd/system/dis-push@.service'

printf 'Parallel isolated push queue deployment contract passed.\n'
