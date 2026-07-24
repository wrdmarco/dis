#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
RETIRE="${APP_ROOT}/scripts/retire-server-tts.sh"
DEPLOY="${APP_ROOT}/scripts/deploy.sh"
UPDATE="${APP_ROOT}/scripts/update.sh"
RESTORE="${APP_ROOT}/scripts/restore.sh"
COMMON="${APP_ROOT}/scripts/lib/common.sh"
SETUP="${APP_ROOT}/scripts/setup.sh"
SELF_HEAL="${APP_ROOT}/scripts/self-heal-permissions.sh"
UNINSTALL="${APP_ROOT}/scripts/uninstall.sh"
ROOT_ENV_EXAMPLE="${APP_ROOT}/.env.example"

require_text() {
  local file="$1" value="$2"
  grep -Fq -- "${value}" "${file}" || {
    printf 'Missing server-side TTS retirement contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  }
}

require_regex() {
  local file="$1" value="$2"
  grep -Eq -- "${value}" "${file}" || {
    printf 'Missing server-side TTS retirement pattern in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  }
}

reject_text() {
  local file="$1" value="$2"
  if grep -Fq -- "${value}" "${file}"; then
    printf 'Forbidden retired server-side TTS contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  fi
}

line_of() {
  local file="$1" value="$2"
  grep -nF -- "${value}" "${file}" | head -n 1 | cut -d: -f1
}

line_of_exact() {
  local file="$1" value="$2"
  grep -nFx -- "${value}" "${file}" | head -n 1 | cut -d: -f1
}

[ -f "${RETIRE}" ] || {
  printf 'Missing server-side TTS retirement script.\n' >&2
  exit 1
}
[ ! -f "${APP_ROOT}/infrastructure/systemd/dis-speech.service" ]
[ ! -f "${APP_ROOT}/infrastructure/systemd/dis-tts-engine.service" ]
if [ -d "${APP_ROOT}/speech-engine" ] \
  && find "${APP_ROOT}/speech-engine" -type f -print -quit | grep -q .; then
  printf 'The retired speech-engine source tree still contains files.\n' >&2
  exit 1
fi

for file in "${COMMON}" "${SETUP}" "${SELF_HEAL}" "${ROOT_ENV_EXAMPLE}"; do
  reject_text "${file}" 'SPEECH_'
  reject_text "${file}" 'dis-speech'
  reject_text "${file}" 'dis-tts-engine'
  reject_text "${file}" '/opt/dis-data/tts'
done
reject_text "${COMMON}" 'install_speech_engine_runtime'
reject_text "${COMMON}" 'repair_speech_data_permissions'
reject_text "${COMMON}" 'wait_for_dis_speech_engine_readiness'
reject_text "${DEPLOY}" 'infrastructure/systemd/dis-speech.service'
reject_text "${DEPLOY}" 'infrastructure/systemd/dis-tts-engine.service'
reject_text "${DEPLOY}" 'install_speech_engine_runtime'

require_text "${RETIRE}" 'require_root'
require_text "${RETIRE}" '[ "${DIS_INSTALL_PATH}" = "/opt/dis" ]'
require_text "${RETIRE}" '[ "${DIS_DATA_PATH}" = "/opt/dis-data" ]'
require_text "${RETIRE}" 'LEGACY_ENGINE_UNIT="/etc/systemd/system/dis-tts-engine.service"'
require_text "${RETIRE}" 'LEGACY_WORKER_UNIT="/etc/systemd/system/dis-speech.service"'
require_text "${RETIRE}" 'LEGACY_ENGINE_WANTS_LINK="/etc/systemd/system/multi-user.target.wants/dis-tts-engine.service"'
require_text "${RETIRE}" 'LEGACY_WORKER_WANTS_LINK="/etc/systemd/system/multi-user.target.wants/dis-speech.service"'
require_text "${RETIRE}" 'LEGACY_SOCKET_ROOT="/run/dis-tts"'
require_text "${RETIRE}" 'LEGACY_TTS_ROOT="/opt/dis-data/tts"'
require_text "${RETIRE}" 'LEGACY_SPEECH_STORAGE="/opt/dis-data/webapp/backend/storage/app/speech"'
require_text "${RETIRE}" 'LEGACY_SPEECH_SOURCE="/opt/dis/speech-engine"'
require_text "${RETIRE}" 'for service in dis-speech dis-tts-engine; do'
require_text "${RETIRE}" 'run_cmd systemctl stop "${service}"'
require_text "${RETIRE}" 'run_cmd systemctl disable "${service}"'
require_text "${RETIRE}" 'php "${backend}/artisan" queue:clear redis --queue=speech --force'
require_text "${RETIRE}" 'php "${backend}/artisan" config:clear'
reject_text "${RETIRE}" 'redis-cli FLUSH'
reject_text "${RETIRE}" 'queue:clear redis --queue=push'
require_text "${RETIRE}" 'if legacy_queue_clear_is_available; then'
require_text "${RETIRE}" 'clear_legacy_speech_queue'
require_text "${RETIRE}" '--clear-queue-only)'
require_text "${DEPLOY}" 'DIS_RETIRE_TTS_ALLOW_DEFERRED_QUEUE_CLEAR=1 \'
require_text "${DEPLOY}" 'bash "${SCRIPT_DIR}/retire-server-tts.sh" --clear-queue-only'
require_text "${RETIRE}" 'clear_legacy_speech_configuration_cache'
require_text "${RETIRE}" $'      clear_legacy_speech_queue\n      clear_legacy_speech_configuration_cache'

require_regex "${RETIRE}" 'SPEECH_\[A-Za-z0-9_\]\*\[\[:space:\]\]\*='
require_text "${RETIRE}" 'require_root_controlled_parent "${LEGACY_ENV_FILE}"'
require_text "${RETIRE}" '/usr/bin/getfacl -cp -- "${LEGACY_ENV_FILE}"'
require_text "${RETIRE}" '/usr/bin/setfacl --set-file="${acl_snapshot}" -- "${temporary}"'
require_text "${RETIRE}" 'sync -f "${temporary}"'
require_text "${RETIRE}" 'mv -fT -- "${temporary}" "${LEGACY_ENV_FILE}"'

require_text "${RETIRE}" 'ensure_managed_directory "${app_parent}" root root 0750'
require_text "${RETIRE}" 'Encrypted speech storage can only be retired while ${writer_service}.service is stopped.'
require_text "${RETIRE}" 'remove_exact_managed_tree "${LEGACY_SPEECH_STORAGE}"'
require_text "${RETIRE}" 'chown "${owner}:${group}" "${app_parent}"'
require_text "${RETIRE}" '/usr/bin/setfacl --set-file="${acl_snapshot}" -- "${app_parent}"'
require_text "${RETIRE}" 'secure_path_operation remove-tree "${path}"'
require_text "${RETIRE}" 'remove_exact_managed_tree "${LEGACY_SOCKET_ROOT}"'
require_text "${RETIRE}" 'remove_exact_managed_tree "${LEGACY_TTS_ROOT}"'
require_text "${RETIRE}" 'remove_exact_managed_tree "${LEGACY_SPEECH_SOURCE}"'
reject_text "${RETIRE}" 'rm -rf'

require_text "${RETIRE}" 'BRIDGE_MARKER="# DIS temporary legacy TTS compatibility bridge"'
require_text "${RETIRE}" 'ExecStart=/usr/bin/python3 -I -S /opt/dis-data/tts/legacy-bridge/server.py'
require_text "${RETIRE}" 'ExecStart=/usr/bin/true'
require_text "${RETIRE}" '"""Temporary compatibility namespace for one in-progress legacy updater."""'
require_text "${RETIRE}" 'parent_starttime="$(process_starttime "${parent_pid}")"'
require_text "${RETIRE}" '/usr/bin/systemd-run \'
require_text "${RETIRE}" '--finalize-compat "${parent_pid}" "${parent_starttime}"'
require_text "${RETIRE}" '[ "${current_starttime}" = "${expected_starttime}" ] || break'
require_text "${RETIRE}" 'bridge_unit_is_safe "${LEGACY_WORKER_UNIT}"'
require_text "${RETIRE}" 'bridge_unit_is_safe "${LEGACY_ENGINE_UNIT}"'
reject_text "${RETIRE}" 'import torch'
reject_text "${RETIRE}" 'import transformers'
reject_text "${RETIRE}" 'huggingface'
reject_text "${RETIRE}" 'model-packages'

require_text "${DEPLOY}" 'bash "${SCRIPT_DIR}/retire-server-tts.sh"'
require_text "${DEPLOY}" 'bash "${SCRIPT_DIR}/retire-server-tts.sh" --compat-parent-pid "${PPID}"'
require_text "${DEPLOY}" '&& [ -z "${DIS_LEGACY_TTS_COMPAT_REQUIRED+x}" ]; then'
require_text "${DEPLOY}" 'DIS_LEGACY_TTS_COMPAT_REQUIRED must be 0 or 1.'
require_text "${UPDATE}" 'DIS_LEGACY_TTS_COMPAT_REQUIRED=0 \'
require_text "${RESTORE}" 'bash "${SCRIPT_DIR}/retire-server-tts.sh"'
require_text "${UNINSTALL}" 'retire_legacy_server_tts_for_uninstall'
require_text "${UNINSTALL}" 'bash "${SCRIPT_DIR}/retire-server-tts.sh"'
require_text "${UNINSTALL}" 'run_cmd systemctl stop "${PHP_FPM_SERVICE}"'
require_text "${UNINSTALL}" 'run_cmd systemctl start "${PHP_FPM_SERVICE}"'

retirement_call="$(line_of "${DEPLOY}" 'bash "${SCRIPT_DIR}/retire-server-tts.sh"')"
backup_cutover="$(line_of "${DEPLOY}" 'DIS_BACKUP_KEY_CUTOVER_ALLOWED=1 ensure_backup_encryption_key')"
[ "${retirement_call}" -lt "${backup_cutover}" ] || {
  printf 'Server-side TTS data must be retired at the first deployment cutover.\n' >&2
  exit 1
}

restore_install="$(line_of "${RESTORE}" 'replace_managed_tree "${RESTORED_DATA}/secrets"')"
restore_retirement="$(line_of "${RESTORE}" 'bash "${SCRIPT_DIR}/retire-server-tts.sh"')"
restore_repair="$(line_of_exact "${RESTORE}" 'repair_restored_data_permissions')"
[ "${restore_install}" -lt "${restore_retirement}" ] \
  && [ "${restore_retirement}" -lt "${restore_repair}" ] || {
  printf 'Restored legacy speech data must be retired before permission repair.\n' >&2
  exit 1
}

uninstall_stop="$(line_of "${UNINSTALL}" 'for service in dis-media')"
uninstall_retirement="$(line_of_exact "${UNINSTALL}" 'retire_legacy_server_tts_for_uninstall')"
uninstall_units="$(line_of "${UNINSTALL}" 'log "Removing DIS systemd units"')"
[ "${uninstall_stop}" -lt "${uninstall_retirement}" ] \
  && [ "${uninstall_retirement}" -lt "${uninstall_units}" ] || {
  printf 'Uninstall must stop DIS writers before retiring legacy TTS data and units.\n' >&2
  exit 1
}

printf 'Server-side TTS retirement deployment contract passed.\n'
