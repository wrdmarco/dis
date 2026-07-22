#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
COMMON="${APP_ROOT}/scripts/lib/common.sh"
DEPLOY="${APP_ROOT}/scripts/deploy.sh"
SETUP="${APP_ROOT}/scripts/setup.sh"
SELF_HEAL="${APP_ROOT}/scripts/self-heal-permissions.sh"
RESTORE="${APP_ROOT}/scripts/restore.sh"
UNINSTALL="${APP_ROOT}/scripts/uninstall.sh"
BACKUP="${APP_ROOT}/scripts/backup.sh"
ENGINE_UNIT="${APP_ROOT}/infrastructure/systemd/dis-tts-engine.service"
WORKER_UNIT="${APP_ROOT}/infrastructure/systemd/dis-speech.service"
QUEUE_CONFIG="${APP_ROOT}/webapp/backend/config/queue.php"
INSTALLER_SOURCE="${APP_ROOT}/speech-engine/dis_tts_engine/installer.py"
DOWNLOAD_SOURCE="${APP_ROOT}/speech-engine/dis_tts_engine/download_worker.py"
ENGINE_SOURCE="${APP_ROOT}/speech-engine/dis_tts_engine/engine.py"
ENGINE_INIT_SOURCE="${APP_ROOT}/speech-engine/dis_tts_engine/__init__.py"
ADAPTER_SOURCE="${APP_ROOT}/speech-engine/dis_tts_engine/adapters.py"
SECURE_FILES_SOURCE="${APP_ROOT}/speech-engine/dis_tts_engine/secure_files.py"
SPEECH_PIPELINE_SOURCE="${APP_ROOT}/webapp/backend/app/Services/SpeechAudioPipeline.php"
SPEECH_CONFIG="${APP_ROOT}/webapp/backend/config/dis.php"
EXCLUSIVE_WRITER_SOURCE="${APP_ROOT}/webapp/backend/app/Services/SpeechExclusiveFileWriter.php"
ROOT_ENV_EXAMPLE="${APP_ROOT}/.env.example"
MODEL_PACKAGES="${APP_ROOT}/speech-engine/model-packages.requirements.txt"
PYPROJECT="${APP_ROOT}/speech-engine/pyproject.toml"
UV_LOCK="${APP_ROOT}/speech-engine/uv.lock"
UPDATE_RUNNER="${APP_ROOT}/scripts/web-update-runner.sh"
RECONCILE_COMMAND="${APP_ROOT}/webapp/backend/app/Console/Commands/ReconcileSpeechRuntime.php"
RECONCILE_SERVICE="${APP_ROOT}/webapp/backend/app/Services/SpeechRuntimeReconciliationService.php"

require_text() {
  local file="$1" value="$2"
  grep -Fq -- "${value}" "${file}" || {
    printf 'Missing speech deployment contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  }
}

reject_text() {
  local file="$1" value="$2"
  if grep -Fq -- "${value}" "${file}"; then
    printf 'Forbidden speech deployment contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  fi
}

line_of() {
  local file="$1" value="$2"
  grep -Fnm1 -- "${value}" "${file}" | cut -d: -f1
}

line_of_after() {
  local file="$1" value="$2" after="$3"
  awk -v needle="${value}" -v after="${after}" 'NR > after && index($0, needle) { print NR; exit }' "${file}"
}

require_text "${COMMON}" 'SPEECH_UV_VERSION=0.11.30'
require_text "${COMMON}" 'SPEECH_UV_ARCHIVE_SHA256=04bc7d180d6138bf6dc08387acf507a823f397a98fea55da36b0ccc7fbce3b68'
require_text "${COMMON}" 'uv-x86_64-unknown-linux-gnu.tar.gz'
require_text "${COMMON}" "--proto '=https'"
require_text "${COMMON}" '--max-filesize 67108864'
require_text "${COMMON}" '[ "${checksum}" = "${SPEECH_UV_ARCHIVE_SHA256}" ]'
require_text "${COMMON}" 'version_output="$("${uv_binary}" self version --short)" || return 1'
require_text "${COMMON}" '[ "${version_output}" = "${SPEECH_UV_VERSION}" ]'
reject_text "${COMMON}" '"${uv_binary}" --version'
reject_text "${COMMON}" '"${uv_binary}" -V'
require_text "${COMMON}" 'The speech engine source tree may not contain symbolic links.'
require_text "${COMMON}" '"${uv_binary}" python install 3.11'
require_text "${COMMON}" '"${uv_binary}" sync \'
require_text "${COMMON}" '      --locked \'
require_text "${COMMON}" '"${uv_binary}" pip install \'
require_text "${COMMON}" '      --no-deps \'
require_text "${COMMON}" '      -r "${engine_root}/model-packages.requirements.txt"'
require_text "${COMMON}" 'UV_PROJECT_ENVIRONMENT=${DIS_DATA_PATH}/tts/runtime'
require_text "${COMMON}" 'UV_PYTHON_INSTALL_DIR=${DIS_DATA_PATH}/tts/python'
require_text "${COMMON}" 'PYTHONDONTWRITEBYTECODE=1'
require_text "${COMMON}" 'PYTHONPATH="${engine_root}"'
require_text "${PYPROJECT}" '"torch==2.6.0+cpu"'
require_text "${PYPROJECT}" '"torchaudio==2.6.0+cpu"'
require_text "${UV_LOCK}" 'source = { registry = "https://download.pytorch.org/whl/cpu" }'
reject_text "${UV_LOCK}" 'name = "nvidia-'
reject_text "${UV_LOCK}" 'name = "triton"'
require_text "${COMMON}" 'The speech runtime lock does not pin the CPU-only PyTorch index.'
require_text "${COMMON}" 'The speech runtime lock unexpectedly contains GPU runtime packages.'
require_text "${COMMON}" 'Speech runtime phase 1/3: installing the pinned Python interpreter'
require_text "${COMMON}" 'Speech runtime phase 2/3: installing locked CPU-only speech dependencies'
require_text "${COMMON}" 'Speech runtime phase 3/3: installing the pinned VoxCPM package'
require_text "${MODEL_PACKAGES}" 'voxcpm @ https://files.pythonhosted.org/'
require_text "${MODEL_PACKAGES}" '#sha256='
require_text "${ENGINE_INIT_SOURCE}" 'PROTOCOL_VERSION = 2'
require_text "${ENGINE_INIT_SOURCE}" 'AUDIO_RECIPE_REVISION = "consistent-speaker-loudness-v2"'
require_text "${SPEECH_CONFIG}" "'audio_recipe_revision' => 'consistent-speaker-loudness-v2'"
require_text "${SPEECH_CONFIG}" "'protocol_version' => 2"
require_text "${ADAPTER_SOURCE}" 'options["reference_wav_path"] = str(effective_reference_path)'
require_text "${ADAPTER_SOURCE}" 'options["prompt_wav_path"] = str(effective_reference_path)'
require_text "${SPEECH_PIPELINE_SOURCE}" 'loudnorm=I=-18:TP=-1.5:LRA=7:print_format=json'
require_text "${ROOT_ENV_EXAMPLE}" 'SPEECH_CACHE_HMAC_KEY='
require_text "${SETUP}" 'if [ -z "$(env_value SPEECH_CACHE_HMAC_KEY)" ]; then'
require_text "${SETUP}" 'set_managed_env_secret "${ENV_FILE}" SPEECH_CACHE_HMAC_KEY "base64:$(random_base64 48)"'
require_text "${DEPLOY}" 'ensure_speech_cache_hmac_key() {'
require_text "${DEPLOY}" 'configured="$(env_value SPEECH_CACHE_HMAC_KEY)"'
require_text "${DEPLOY}" '/usr/bin/openssl rand -base64 48'
require_text "${DEPLOY}" 'set_managed_env_secret "${ENV_FILE}" SPEECH_CACHE_HMAC_KEY "base64:${generated}"'
require_text "${DEPLOY}" 'The existing SPEECH_CACHE_HMAC_KEY is shorter than 32 bytes; it was left unchanged.'
require_text "${DEPLOY}" 'ensure_speech_cache_hmac_key'
require_text "${COMMON}" 'set_managed_env_secret() ('
require_text "${COMMON}" 'log "Would set managed environment secret ${key}."'
require_text "${COMMON}" 'mv -fT -- "${temporary}" "${resolved_env}"'
require_text "${COMMON}" '"${COMMON_LIB_DIR}/secure-path.py" "${operation}" -- "$@"'
reject_text "${ROOT_ENV_EXAMPLE}" 'SPEECH_CACHE_HMAC_KEY=change-this'

# Exercise the exact uv version probe with a controlled executable. A valid
# version printed alongside a failed exit status must never pass verification.
# shellcheck disable=SC1090
source "${COMMON}"
speech_uv_version_test_dir="$(mktemp -d)"
speech_uv_version_test_binary="${speech_uv_version_test_dir}/uv"
trap 'rm -rf -- "${speech_uv_version_test_dir}"' EXIT
printf '%s\n' \
  '#!/usr/bin/env bash' \
  '[ "$*" = "self version --short" ] || exit 64' \
  'printf "%s\n" "${FAKE_UV_OUTPUT:-}"' \
  'exit "${FAKE_UV_EXIT_CODE:-0}"' \
  > "${speech_uv_version_test_binary}"
chmod 0755 "${speech_uv_version_test_binary}"

FAKE_UV_OUTPUT="${SPEECH_UV_VERSION}" FAKE_UV_EXIT_CODE=0 \
  speech_uv_executable_version_is_expected "${speech_uv_version_test_binary}" \
  || { printf 'The pinned uv short version should pass verification.\n' >&2; exit 1; }
for rejected_uv_version in '' '0.11.29' '0.11.30 (build metadata)'; do
  if FAKE_UV_OUTPUT="${rejected_uv_version}" FAKE_UV_EXIT_CODE=0 \
    speech_uv_executable_version_is_expected "${speech_uv_version_test_binary}"; then
    printf 'Unexpected uv version output passed verification: %s\n' "${rejected_uv_version}" >&2
    exit 1
  fi
done
if FAKE_UV_OUTPUT="${SPEECH_UV_VERSION}" FAKE_UV_EXIT_CODE=1 \
  speech_uv_executable_version_is_expected "${speech_uv_version_test_binary}"; then
  printf 'A failed uv version command passed verification.\n' >&2
  exit 1
fi

for leaf in models cache runtime staging state uv-cache python; do
  require_text "${COMMON}" 'ensure_managed_directory "${DIS_DATA_PATH}/tts/'"${leaf}"'"'
done
require_text "${COMMON}" 'ensure_managed_directory "${DIS_DATA_PATH}/tts" root "${DIS_GROUP}" 0750'
require_text "${COMMON}" 'repair_managed_tree "${DIS_DATA_PATH}/tts/cache" "${DIS_USER}" "${DIS_GROUP}" 0770 0640'
require_text "${COMMON}" '/usr/bin/find -P "${DIS_DATA_PATH}/tts/cache" -xdev -type f -name '"'"'*.part'"'"' -delete'
require_text "${COMMON}" 'secure_path_operation remove-tree "${DIS_DATA_PATH}/tts/staging"'
require_text "${COMMON}" 'ensure_managed_directory "${DIS_DATA_PATH}/tts/staging" "${DIS_USER}" "${DIS_GROUP}" 0770'
require_text "${COMMON}" 'secure_path_operation acl-tree "${DIS_DATA_PATH}/tts/cache" www-data r-x r--'
require_text "${COMMON}" 'secure_path_operation acl-tree "${DIS_DATA_PATH}/tts/staging" www-data --- ---'
require_text "${EXCLUSIVE_WRITER_SOURCE}" 'public function write(string $path, string $bytes, int $mode = 0600): void'
require_text "${SECURE_FILES_SOURCE}" '0o600,'
reject_text "${COMMON}" 'ensure_managed_directory "${DIS_DATA_PATH}/tts" root "${DIS_GROUP}" 0777'

require_text "${ENGINE_UNIT}" 'User=dis'
require_text "${ENGINE_UNIT}" 'Group=dis'
require_text "${ENGINE_UNIT}" 'RuntimeDirectory=dis-tts'
require_text "${ENGINE_UNIT}" 'RuntimeDirectoryMode=0750'
require_text "${ENGINE_UNIT}" 'ExecStartPre=/usr/bin/setfacl -m u:www-data:--x,d:u:www-data:rw- /run/dis-tts'
require_text "${ENGINE_UNIT}" 'ExecStart=/opt/dis-data/tts/runtime/bin/python -m dis_tts_engine'
require_text "${ENGINE_UNIT}" "ExecStartPost=/usr/bin/timeout --kill-after=2s 30s /bin/sh -c 'until [ -S /run/dis-tts/engine.sock ]; do sleep 1; done'"
require_text "${ENGINE_UNIT}" 'Environment=DIS_TTS_SOCKET_PATH=/run/dis-tts/engine.sock'
require_text "${ENGINE_UNIT}" 'Environment=DIS_TTS_TORCH_THREADS=16'
require_text "${ENGINE_UNIT}" 'Environment=OMP_NUM_THREADS=16'
require_text "${ENGINE_UNIT}" 'CPUQuota=1600%'
require_text "${ENGINE_UNIT}" 'KillMode=control-group'
require_text "${ENGINE_UNIT}" 'TasksMax=512'
require_text "${ENGINE_UNIT}" 'ProtectSystem=strict'
require_text "${ENGINE_UNIT}" 'NoNewPrivileges=true'
require_text "${ENGINE_UNIT}" 'RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6'
require_text "${ENGINE_UNIT}" 'ReadWritePaths=/opt/dis-data/tts/models /opt/dis-data/tts/staging /opt/dis-data/tts/state'
require_text "${ENGINE_UNIT}" 'ReadOnlyPaths=/opt/dis/speech-engine /opt/dis-data/tts/runtime /opt/dis-data/tts/python'
require_text "${INSTALLER_SOURCE}" '"dis_tts_engine.download_worker"'
require_text "${INSTALLER_SOURCE}" 'close_fds=True'
require_text "${INSTALLER_SOURCE}" 'start_new_session=True'
require_text "${INSTALLER_SOURCE}" 'os.killpg(process.pid, signal.SIGTERM)'
require_text "${INSTALLER_SOURCE}" '_INSTALL_STAGING_NAME = re.compile(r"^\.installing-[0-9A-HJKMNP-TV-Z]{26}$"'
require_text "${INSTALLER_SOURCE}" 'for model_id in MODEL_SPECS:'
require_text "${INSTALLER_SOURCE}" 'if not stat.S_ISDIR(metadata.st_mode) or entry.is_symlink():'
require_text "${INSTALLER_SOURCE}" '_remove_install_staging(model_parent / entry.name, model_parent)'
require_text "${ENGINE_SOURCE}" 'self.installer.cancel_all_and_wait(timeout_seconds=10)'
require_text "${COMMON}" '"${engine_python}" -m dis_tts_engine.healthcheck >/dev/null 2>&1'
require_text "${DOWNLOAD_SOURCE}" 'from huggingface_hub import snapshot_download'
reject_text "${INSTALLER_SOURCE}" 'from huggingface_hub import snapshot_download'
reject_text "${ENGINE_SOURCE}" 'from huggingface_hub import snapshot_download'

require_text "${WORKER_UNIT}" 'Requires=dis-tts-engine.service'
require_text "${WORKER_UNIT}" 'ExecStartPre=/usr/bin/test -S /run/dis-tts/engine.sock'
require_text "${WORKER_UNIT}" 'artisan queue:work speech --queue=speech'
require_text "${WORKER_UNIT}" '--tries=1 --timeout=68400'
require_text "${WORKER_UNIT}" 'KillMode=control-group'
require_text "${WORKER_UNIT}" 'ReadWritePaths=/opt/dis-data/tts/cache /opt/dis-data/tts/staging'
require_text "${WORKER_UNIT}" 'InaccessiblePaths=-/opt/dis-data/tts/models -/opt/dis-data/tts/runtime -/opt/dis-data/tts/python -/opt/dis-data/tts/uv-cache -/opt/dis-data/tts/state'
reject_text "${WORKER_UNIT}" '--queue=push'
reject_text "${WORKER_UNIT}" '--queue=default,speech'
[ "$(grep -Fc 'UMask=0027' "${WORKER_UNIT}")" -eq 1 ] || {
  printf 'Speech worker must define its restrictive umask exactly once.\n' >&2
  exit 1
}

for bounded_unit in "${ENGINE_UNIT}" "${WORKER_UNIT}"; do
  stop_seconds="$(sed -nE 's/^TimeoutStopSec=([0-9]+)s$/\1/p' "${bounded_unit}")"
  [[ "${stop_seconds}" =~ ^[0-9]+$ ]] \
    && [ "${stop_seconds}" -ge 45 ] \
    && [ "${stop_seconds}" -le 90 ] || {
    printf 'Speech services must stop within a bounded 45-90 second update window: %s\n' "${bounded_unit}" >&2
    exit 1
  }
done
require_text "${QUEUE_CONFIG}" "'retry_after' => max(68_400"
for retryable_job in \
  PrewarmIncidentSpeech.php \
  RegenerateSpeechCache.php \
  GenerateSpeechPreview.php \
  GenerateDispatchSpeechManifest.php; do
  require_text "${APP_ROOT}/webapp/backend/app/Jobs/${retryable_job}" 'public int $tries = 3;'
done
require_text "${SECURE_FILES_SOURCE}" 'return self.root / f"{validated}.part", self.root / validated'
require_text "${SECURE_FILES_SOURCE}" 'os.fsync(descriptor)'
require_text "${SECURE_FILES_SOURCE}" 'os.link('
require_text "${ENGINE_SOURCE}" 'staging.discard_output(descriptor, part_path)'
require_text "${SPEECH_PIPELINE_SOURCE}" "\$temporary = \$destination.'.'.(string) Str::ulid().'.part';"
require_text "${SPEECH_PIPELINE_SOURCE}" '@unlink($jobPath);'

require_text "${DEPLOY}" 'install_speech_engine_runtime "${APP_ROOT}"'
require_text "${DEPLOY}" 'infrastructure/systemd/dis-tts-engine.service'
require_text "${DEPLOY}" 'infrastructure/systemd/dis-speech.service'
require_text "${DEPLOY}" 'dis-queue dis-media dis-tts-engine dis-speech'
require_text "${COMMON}" 'wait_for_dis_speech_engine_readiness() {'
require_text "${COMMON}" 'for service in dis-media dis-queue'
require_text "${COMMON}" 'dis-frontend dis-tts-engine dis-speech dis-queue'
require_text "${UPDATE_RUNNER}" 'timeout --kill-after=30s "${UPDATE_TIMEOUT_SECONDS}s" /usr/local/bin/update "$@"'
require_text "${DEPLOY}" 'PGOPTIONS="-c lock_timeout=60s -c statement_timeout=15min"'
require_text "${RESTORE}" 'PGOPTIONS="-c lock_timeout=60s -c statement_timeout=15min"'

operational_start="$(line_of "${COMMON}" 'start_dis_operational_services()')"
engine_start="$(line_of_after "${COMMON}" 'run_cmd systemctl start dis-tts-engine' "${operational_start}")"
engine_ready="$(line_of_after "${COMMON}" 'wait_for_dis_speech_engine_readiness 30 2' "${engine_start}")"
speech_start="$(line_of_after "${COMMON}" 'run_cmd systemctl start dis-speech' "${engine_ready}")"
[[ "${operational_start}" -lt "${engine_start}" \
  && "${engine_start}" -lt "${engine_ready}" \
  && "${engine_ready}" -lt "${speech_start}" ]] || {
  printf 'Deployment must wait for the engine socket before starting the speech worker.\n' >&2
  exit 1
}

speech_stop="$(line_of "${COMMON}" 'run_cmd systemctl stop dis-speech')"
engine_stop="$(line_of "${COMMON}" 'run_cmd systemctl stop dis-tts-engine')"
media_stop="$(line_of "${COMMON}" 'for service in dis-media dis-queue')"
[[ "${speech_stop}" -lt "${engine_stop}" && "${engine_stop}" -lt "${media_stop}" ]] || {
  printf 'Deployment must stop speech work before its engine and the other workers.\n' >&2
  exit 1
}

require_text "${SELF_HEAL}" 'dis-tts-engine dis-speech'
require_text "${SELF_HEAL}" 'repair_speech_data_permissions'
require_text "${RESTORE}" 'repair_speech_data_permissions'
require_text "${RECONCILE_COMMAND}" "protected \$signature = 'speech:reconcile-runtime';"
require_text "${RECONCILE_SERVICE}" "'error_code' => 'installed_model_unverified_after_restore'"
require_text "${RECONCILE_SERVICE}" "'error_code' => 'speech_audio_missing_after_restore'"
require_text "${RESTORE}" 'systemd_service_exists dis-tts-engine \'
require_text "${RESTORE}" 'Speech runtime reconciliation requires the speech queue worker to remain stopped.'
require_text "${RESTORE}" 'wait_for_systemd_service_stable dis-tts-engine 30 2 \'
require_text "${RESTORE}" 'The speech engine socket was not ready for post-restore reconciliation.'
require_text "${RESTORE}" '"${APP_ROOT}/webapp/backend/artisan" speech:reconcile-runtime'
reject_text "${RESTORE}" 'speech:reconcile-runtime || true'
reconcile_function="$(line_of "${RESTORE}" 'reconcile_speech_runtime_after_restore()')"
reconcile_engine_start="$(line_of_after "${RESTORE}" 'run_cmd systemctl start dis-tts-engine' "${reconcile_function}")"
reconcile_command_line="$(line_of_after "${RESTORE}" 'speech:reconcile-runtime' "${reconcile_engine_start}")"
reconcile_engine_stop="$(line_of_after "${RESTORE}" 'run_cmd systemctl stop dis-tts-engine' "${reconcile_command_line}")"
[[ "${reconcile_function}" -lt "${reconcile_engine_start}" \
  && "${reconcile_engine_start}" -lt "${reconcile_command_line}" \
  && "${reconcile_command_line}" -lt "${reconcile_engine_stop}" ]] || {
  printf 'Restore speech reconciliation is not fail-closed between controlled engine start/stop.\n' >&2
  exit 1
}
restore_reconcile_call="$(grep -Fn -- 'reconcile_speech_runtime_after_restore' "${RESTORE}" | tail -n 1 | cut -d: -f1)"
restore_migrate="$(line_of "${RESTORE}" 'migrate --force')"
restore_knmi="$(line_of "${RESTORE}" 'dis:reconcile-knmi-after-restore')"
[[ "${restore_migrate}" -lt "${restore_reconcile_call}" \
  && "${restore_reconcile_call}" -lt "${restore_knmi}" ]] || {
  printf 'Speech runtime must reconcile immediately after restored migrations.\n' >&2
  exit 1
}
restore_speech_stop="$(line_of "${RESTORE}" 'run_cmd systemctl stop dis-speech')"
restore_engine_stop="$(line_of "${RESTORE}" 'run_cmd systemctl stop dis-tts-engine')"
[[ "${restore_speech_stop}" -lt "${restore_engine_stop}" ]] || {
  printf 'Restore must stop the speech worker before its engine.\n' >&2
  exit 1
}
require_text "${UNINSTALL}" 'for service in dis-speech dis-tts-engine dis-media'
require_text "${UNINSTALL}" '/etc/systemd/system/dis-speech.service'
require_text "${UNINSTALL}" '/etc/systemd/system/dis-tts-engine.service'
require_text "${UNINSTALL}" 'secure_path_operation remove-tree "${DIS_DATA_PATH}/tts"'

# Voice originals are encrypted backend storage and therefore remain inside the
# established encrypted storage backup; reproducible models/runtime/cache do not.
require_text "${BACKUP}" 'webapp/backend/storage \'
reject_text "${BACKUP}" '  tts \'
reject_text "${BACKUP}" "'tts'"

printf 'Speech production runtime deployment contract passed.\n'
