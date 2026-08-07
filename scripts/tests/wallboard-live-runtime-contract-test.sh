#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
INGRESS_UNIT="${APP_ROOT}/infrastructure/systemd/dis-wallboard-live-ingress.service"
HLS_UNIT="${APP_ROOT}/infrastructure/systemd/dis-wallboard-live.service"
CONFIGURE="${APP_ROOT}/scripts/wallboard-live-configure.sh"
INGRESS_RUNNER="${APP_ROOT}/scripts/wallboard-live-ingress-runner.sh"
HLS_RUNNER="${APP_ROOT}/scripts/wallboard-live-runner.sh"
AUTH="${APP_ROOT}/scripts/wallboard-live-auth.py"
AUTH_TEST="${APP_ROOT}/scripts/tests/wallboard-live-auth-test.py"
REFRESH="${APP_ROOT}/scripts/wallboard-live-refresh.sh"
KEY_WORKER="${APP_ROOT}/scripts/wallboard-live-key-request-worker.sh"
KEY_SERVICE="${APP_ROOT}/infrastructure/systemd/dis-wallboard-live-key-request.service"
KEY_PATH="${APP_ROOT}/infrastructure/systemd/dis-wallboard-live-key-request.path"
KEY_TIMER="${APP_ROOT}/infrastructure/systemd/dis-wallboard-live-key-request.timer"
COMMON="${APP_ROOT}/scripts/lib/common.sh"
DEPLOY="${APP_ROOT}/scripts/deploy.sh"
UPDATE="${APP_ROOT}/scripts/update.sh"
INSTALL="${APP_ROOT}/scripts/install.sh"
SELF_HEAL="${APP_ROOT}/scripts/self-heal-permissions.sh"
RESTORE="${APP_ROOT}/scripts/restore.sh"
UNINSTALL="${APP_ROOT}/scripts/uninstall.sh"
NGINX="${APP_ROOT}/infrastructure/nginx/dis.conf"

require_text() {
  local file="$1" value="$2"
  grep -Fq -- "${value}" "${file}" || {
    printf 'Missing wallboard live-stream runtime contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  }
}

reject_text() {
  local file="$1" value="$2"
  if grep -Fq -- "${value}" "${file}"; then
    printf 'Forbidden wallboard live-stream runtime contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  fi
}

require_order() {
  local file="$1" first="$2" second="$3" first_line second_line

  first_line="$(grep -nF -- "${first}" "${file}" | head -n 1 | cut -d: -f1 || true)"
  second_line="$(grep -nF -- "${second}" "${file}" | head -n 1 | cut -d: -f1 || true)"
  [ -n "${first_line}" ] && [ -n "${second_line}" ] && [ "${first_line}" -lt "${second_line}" ] || {
    printf 'Wallboard live-stream contract order is invalid in %s: %s -> %s\n' \
      "${file}" "${first}" "${second}" >&2
    exit 1
  }
}

for script in "${CONFIGURE}" "${INGRESS_RUNNER}" "${HLS_RUNNER}" "${REFRESH}" "${KEY_WORKER}" "${COMMON}" \
  "${DEPLOY}" "${UPDATE}" "${INSTALL}" "${SELF_HEAL}" "${RESTORE}" "${UNINSTALL}"; do
  bash -n "${script}"
done
PYTHON_BIN="$(command -v python || command -v python3 || true)"
[ -n "${PYTHON_BIN}" ] || { printf 'Python 3 is required for the auth helper syntax check.\n' >&2; exit 1; }
"${PYTHON_BIN}" -I -S -c 'import ast, pathlib, sys; ast.parse(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))' "${AUTH}"
"${PYTHON_BIN}" -I -S "${AUTH_TEST}"

for contract in \
  'User=dis-wallboard-live-ingress' \
  'Group=dis-wallboard-live-ingress' \
  'LoadCredential=mediamtx.yml:/etc/dis-wallboard-live/mediamtx.yml' \
  'LoadCredential=stream-key.sha256:/etc/dis-wallboard-live/stream-key.sha256' \
  'LoadCredential=server.crt:/etc/dis-wallboard-live/server.crt' \
  'LoadCredential=server.key:/etc/dis-wallboard-live/server.key' \
  'ExecStart=/usr/local/bin/dis-wallboard-live-ingress-runner' \
  'RuntimeDirectory=dis-wallboard-live-ingress' \
  'RuntimeDirectoryMode=0750' \
  'LimitCORE=0' \
  'NoNewPrivileges=true' \
  'ProtectSystem=strict' \
  'ProtectProc=invisible' \
  'CapabilityBoundingSet=' \
  'RestrictAddressFamilies=AF_INET' \
  'MemoryDenyWriteExecute=true' \
  'InaccessiblePaths=-/etc/dis-wallboard-live -/opt/dis/.env -/opt/dis/webapp -/opt/dis-data -/tmp -/var/tmp' \
  'ReadWritePaths=/run/dis-wallboard-live-ingress'; do
  require_text "${INGRESS_UNIT}" "${contract}"
done
for forbidden in 'User=root' 'SupplementaryGroups=' 'ReadWritePaths=/opt/dis' 'CAP_NET_BIND_SERVICE'; do
  reject_text "${INGRESS_UNIT}" "${forbidden}"
done

for contract in \
  'User=dis-wallboard-live' \
  'Group=dis-wallboard-live' \
  'Wants=network-online.target dis-wallboard-live-ingress.service' \
  'After=network-online.target dis-wallboard-live-ingress.service' \
  'LoadCredential=input.url:/etc/dis-wallboard-live/input.url' \
  'ExecStart=/usr/local/bin/dis-wallboard-live-runner' \
  'RuntimeDirectory=dis-wallboard-live' \
  'LimitCORE=0' \
  'LimitFSIZE=6291456' \
  'NoNewPrivileges=true' \
  'ProtectSystem=strict' \
  'MemoryDenyWriteExecute=true' \
  'ReadWritePaths=/run/dis-wallboard-live'; do
  require_text "${HLS_UNIT}" "${contract}"
done

for contract in \
  'WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS' \
  'WALLBOARD_LIVE_STREAM_RTMPS_PORT' \
  'WALLBOARD_LIVE_STREAM_STREAM_KEY' \
  'WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH' \
  'WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH' \
  '/usr/bin/getent ahostsv4 "${public_host}"' \
  '[[ "${stream_key}" =~ ^[A-Za-z0-9._~-]{32,79}$ ]]' \
  'stream_key_hash="$(printf' \
  '/usr/bin/openssl x509' \
  '-noout -checkend 86400' \
  '-noout -checkhost "${public_host}"' \
  '-noout -checkip "${public_host}"' \
  '/usr/bin/openssl pkey' \
  'root_private_file "${resolved_private_key}"' \
  '/usr/bin/openssl verify -purpose sslserver -verify_depth 8' \
  '-CAfile /etc/ssl/certs/ca-certificates.crt' \
  'rtmpEncryption: optional' \
  'rtmpAddress: 127.0.0.1:${LOCAL_RTMP_PORT}' \
  'rtmpsAddress: ${bind_address}:${rtmps_port}' \
  'authHTTPAddress: http://127.0.0.1:${AUTH_HTTP_PORT}/auth' \
  'authHTTPExclude: []' \
  'overridePublisher: false' \
  'maxReaders: 1' \
  'rtsp: false' \
  'hls: false' \
  'webrtc: false' \
  'srt: false' \
  'moq: false'; do
  require_text "${CONFIGURE}" "${contract}"
done
reject_text "${CONFIGURE}" 'passphrase='
reject_text "${CONFIGURE}" 'srt://'
reject_text "${CONFIGURE}" 'WALLBOARD_LIVE_STREAM_SHARED_SECRET'

# The FFmpeg input credential is byte exact and never receives a trailing LF.
# shellcheck disable=SC1090
source "${CONFIGURE}"
private_mode_is_safe 600 && private_mode_is_safe 400 \
  && ! private_mode_is_safe 640 && ! private_mode_is_safe 604 \
  || { printf 'Private-key mode validation is not fail-closed.\n' >&2; exit 1; }
credential_test_file="$(mktemp)"
trap 'rm -f -- "${credential_test_file}"' EXIT
credential_test_value='rtmp://127.0.0.1:19350/live/Abcdefghijklmnopqrstuvwxyz012345'
write_without_newline "${credential_test_value}" "${credential_test_file}"
[ "$(wc -c < "${credential_test_file}" | tr -d '[:space:]')" = "${#credential_test_value}" ] \
  && [ "$(< "${credential_test_file}")" = "${credential_test_value}" ] \
  || { printf 'Credential writer changed the URL bytes.\n' >&2; exit 1; }
rm -f -- "${credential_test_file}"
trap - EXIT

for contract in \
  'EXPECTED_CREDENTIAL_DIRECTORY="/run/credentials/dis-wallboard-live-ingress.service"' \
  '/usr/bin/python3 -I -S "${AUTH_PATH}" >/dev/null &' \
  '"${MEDIAMTX_PATH}" "${configuration}" >/dev/null 2>&1 &' \
  'wait -n "${auth_pid}" "${mediamtx_pid}"' \
  'terminate_children' \
  'auth.ready'; do
  require_text "${INGRESS_RUNNER}" "${contract}"
done

for contract in \
  'LISTEN_ADDRESS = ("127.0.0.1", 19351)' \
  'STREAM_PATH = re.compile' \
  'hashlib.sha256(candidate.encode("ascii")).hexdigest()' \
  'hmac.compare_digest(digest, expected_hash)' \
  'action == "publish"' \
  'local_read = action == "read" and source_ip in {"127.0.0.1", "::1"}' \
  'class AttemptLimiter:' \
  'PUBLIC_AUTH_ATTEMPTS_PER_MINUTE = 30' \
  'def audit_publish_auth' \
  'def log_message' \
  'MAXIMUM_BODY_BYTES = 4096'; do
  require_text "${AUTH}" "${contract}"
done

for contract in \
  'credential_file="${CREDENTIALS_DIRECTORY}/input.url"' \
  'credential_pattern=' \
  '-protocol_whitelist rtmp,tcp' \
  '-f flv' \
  '-/i "${credential_file}"' \
  '-rw_timeout 15000000' \
  '-loglevel quiet' \
  '>/dev/null 2>&1 &' \
  '-bsf:v h264_mp4toannexb' \
  '-c:a aac' \
  '-hls_time 2' \
  '-hls_list_size 6' \
  '-hls_flags delete_segments+omit_endlist+temp_file' \
  'readonly MAX_SEGMENT_BYTES=6291456' \
  'readonly MAX_OUTPUT_BYTES=67108864' \
  'readonly MAX_OPEN_SEGMENT_SECONDS=12' \
  'while true; do' \
  'clean_output_directory' \
  'retry_delay_seconds=$((retry_delay_seconds * 2))'; do
  require_text "${HLS_RUNNER}" "${contract}"
done
reject_text "${HLS_RUNNER}" 'srt,udp'
reject_text "${HLS_RUNNER}" 'passphrase='

for contract in \
  'LOCK_PATH="${LOCK_DIRECTORY}/dis-exclusive-operation.lock"' \
  '/usr/bin/flock -n 9' \
  'DIS_OPERATION_LOCK_HELD' \
  'DIS_OPERATION_LOCK_FD' \
  '/proc/$$/fd/${DIS_OPERATION_LOCK_FD}' \
  '.refresh-backup.XXXXXX' \
  'restoring the previous credentials' \
  'openssl s_client' \
  'services_ready'; do
  require_text "${REFRESH}" "${contract}"
done

for contract in \
  'User=root' \
  'Group=root' \
  'ExecStart=/usr/local/bin/dis-wallboard-live-key-request-worker' \
  'RuntimeDirectory=dis-wallboard-live-key-request' \
  'RuntimeDirectoryMode=0700' \
  'UMask=0077' \
  'LimitCORE=0' \
  'NoNewPrivileges=true' \
  'ProtectSystem=strict' \
  'ProtectProc=invisible' \
  'MemoryDenyWriteExecute=true' \
  'TimeoutStartSec=240s' \
  'ReadWritePaths=@DIS_DATA_PATH@ /etc/dis-wallboard-live /run/dis-wallboard-live-key-request /run/lock'; do
  require_text "${KEY_SERVICE}" "${contract}"
done
require_text "${KEY_PATH}" 'PathExistsGlob=@DIS_DATA_PATH@/wallboard-live-key-requests/*.pending'
for contract in 'OnBootSec=1min' 'OnUnitInactiveSec=1min' 'AccuracySec=1s'; do
  require_text "${KEY_TIMER}" "${contract}"
done

for contract in \
  'CONFIGURATION_HELPER_PATH="/usr/local/libexec/dis-wallboard-live-configuration-request"' \
  'MAX_REQUEST_BYTES=16384' \
  'MAX_RESULT_BYTES=65536' \
  'MAX_REQUEST_LIFETIME_SECONDS=120' \
  'MINIMUM_REMAINING_SECONDS=35' \
  'ACTIVATION_DEADLINE_SECONDS=55' \
  'SUCCESS_RESULT_DEADLINE_SECONDS=70' \
  'ROLLBACK_REFRESH_TIMEOUT_SECONDS=75' \
  "'user:www-data:-wx'" \
  "request_device=\"\$(/usr/bin/stat -c '%d' -- \"\${REQUEST_DIR}\"" \
  '[ -n "${request_device}" ] && [ "${request_device}" = "${work_device}" ]' \
  '^www-data:600:1:' \
  'keys_unsorted - ["operation", "stream_key", "expected_key_sha256", "actor_id", "created_at", "expires_at"]' \
  'test("^[A-Za-z0-9_-]{64}$")' \
  'test("^[a-f0-9]{64}$")' \
  'test("^[0-9A-HJKMNP-TV-Z]{26}$"; "i")' \
  '/usr/bin/mv -T -- "${pending}" "${running}"' \
  'set_managed_env_secret "${env_file}" WALLBOARD_LIVE_STREAM_STREAM_KEY' \
  'DIS_OPERATION_LOCK_HELD=1 DIS_OPERATION_LOCK_FD="${DIS_OPERATION_LOCK_FD}"' \
  'wallboard_live_key_rotation_conflict' \
  'failed 3' \
  'rollback=restored' \
  'Only pre-commit activation/publication failures reach this branch.' \
  'the key remains retrievable through the portal.' \
  'refresh_before_deadline "${activation_deadline}"' \
  'restore_previous_key_and_runtime "${env_file}" "${previous_key}" "${expected_key_sha256}"' \
  'systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service' \
  'write_result "${request_id}" "${state}" "${exit_code}" "${output}" "${result_deadline}"' \
  '/usr/bin/flock -x "${result_fd}"' \
  'result_process_id="${BASHPID}"' \
  'descriptor_path="/proc/${result_process_id}/fd/${result_fd}"' \
  'invalidate_published_result' \
  'Stream-key rotation missed its result publication deadline.' \
  '/usr/bin/sync -f "${REQUEST_DIR}"' \
  "initial_output='Stream-key rotation commit is being finalized.'" \
  'write_commit_marker "${commit_marker}" || return 1' \
  'write_locked_result succeeded 0 "${output}" || return 2' \
  'printf '\''committed\n'\'' > "${temporary}" || return 1' \
  '/usr/bin/sync -f "${WORK_DIR}" || return 1' \
  'if [ "${completed}" != "1" ] && ! safe_commit_marker "${commit_marker}"; then' \
  'safe_commit_marker "${commit_marker}"' \
  'cleanup_committed_request "${running_file}" "${previous_key_file}" "${commit_marker}"' \
  'wallboard_live_key_rotation_committed' \
  'result_publication_incomplete' \
  'if [ "${result_status}" = "2" ] && safe_commit_marker "${commit_marker}"; then' \
  'The durable marker makes rollback forbidden' \
  '{state: $state, exit_code: $exit_code, output: $output, finished_at: $finished_at}' \
  '/usr/bin/chown www-data:root "${temporary}"' \
  'matches=$((matches + 1))' \
  '[ "${matches}" -eq 1 ] && valid_stream_key "${value}" || return 1'; do
  require_text "${KEY_WORKER}" "${contract}"
done
for dispatch_contract in \
  'dispatch_claimed_request()' \
  'CONFIGURATION_TERMINAL_OFFSET_SECONDS=100' \
  'CONFIGURATION_RECOVERY_TIMEOUT_SECONDS=15' \
  'configuration_request_timeout_seconds()' \
  'budget="$((created_epoch + CONFIGURATION_TERMINAL_OFFSET_SECONDS - now))"' \
  '/usr/bin/timeout --signal=TERM --kill-after=1s "${timeout_seconds}s" /usr/bin/env' \
  'for artifact in previous-env configuration-commit recovery-required; do' \
  'budget="${CONFIGURATION_RECOVERY_TIMEOUT_SECONDS}"' \
  'if [ "${operation}" != configure ]; then' \
  'process_claimed_request "${running_file}"' \
  'root_owned_runtime_file_is_safe "${CONFIGURATION_HELPER_PATH}" 700' \
  'APP_ROOT="${APP_ROOT}"' \
  'DIS_DATA_PATH="${DIS_DATA_PATH}"' \
  'PHP_FPM_SERVICE="${PHP_FPM_SERVICE}"' \
  '"${CONFIGURATION_HELPER_PATH}" "${running_file}"' \
  'dispatch_claimed_request "${running}"'; do
  require_text "${KEY_WORKER}" "${dispatch_contract}"
done
[ "$(grep -Fc -- 'dispatch_claimed_request "${running}"' "${KEY_WORKER}")" -eq 2 ] || {
  printf 'Wallboard live-stream worker must dispatch both newly claimed and recovered requests.\n' >&2
  exit 1
}
reject_text "${KEY_WORKER}" 'stream_key=${stream_key}'
reject_text "${KEY_WORKER}" 'expected_key_sha256=${expected_key_sha256}'
require_order "${KEY_WORKER}" \
  '/usr/bin/mv -T -- "${temporary}" "${result_file}" || return 1' \
  'write_commit_marker "${commit_marker}" || return 1'
require_order "${KEY_WORKER}" \
  'write_commit_marker "${commit_marker}" || return 1' \
  'write_locked_result succeeded 0 "${output}" || return 2'
require_order "${KEY_WORKER}" \
  'write_locked_result succeeded 0 "${output}" || return 2' \
  'completed=1'
require_order "${KEY_WORKER}" \
  '/usr/bin/rm -f -- "${previous_key_file}" || return 1' \
  '/usr/bin/rm -f -- "${running_file}" || return 1'
require_order "${KEY_WORKER}" \
  '/usr/bin/rm -f -- "${running_file}" || return 1' \
  '/usr/bin/rm -f -- "${commit_marker}" || return 1'
require_order "${KEY_WORKER}" \
  '# The marker is the irrevocable commit point.' \
  'if [ "${time_invalid}" = "1" ]; then'
(
  # shellcheck disable=SC1090
  source "${KEY_WORKER}"
  valid_stream_key '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_-' \
    && ! valid_stream_key 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' \
    && ! valid_stream_key 'short' \
    || { printf 'Stream-key validation contract failed.\n' >&2; exit 1; }

  managed_env_test="$(mktemp)"
  trap 'rm -f -- "${managed_env_test}"' EXIT
  printf 'APP_ENV=production\nWALLBOARD_LIVE_STREAM_STREAM_KEY=%s\n' \
    '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_-' > "${managed_env_test}"
  [ "$(read_managed_stream_key "${managed_env_test}")" = \
      '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_-' ] \
    || { printf 'Single managed stream-key parsing failed.\n' >&2; exit 1; }
  printf 'WALLBOARD_LIVE_STREAM_STREAM_KEY=%s\nWALLBOARD_LIVE_STREAM_STREAM_KEY=%s\n' \
    '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_-' \
    'abcdefghijklmnopqrstuvwxyz_~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ' > "${managed_env_test}"
  ! read_managed_stream_key "${managed_env_test}" >/dev/null \
    || { printf 'Duplicate managed stream-key definitions were accepted.\n' >&2; exit 1; }
  printf 'WALLBOARD_LIVE_STREAM_STREAM_KEY="%s"\n' \
    '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_-' > "${managed_env_test}"
  ! read_managed_stream_key "${managed_env_test}" >/dev/null \
    || { printf 'Quoted managed stream-key definition was accepted.\n' >&2; exit 1; }
  rm -f -- "${managed_env_test}"
  trap - EXIT

  result_inode_test="$(mktemp)"
  trap 'rm -f -- "${result_inode_test}"' EXIT
  printf 'before' > "${result_inode_test}"
  exec {result_inode_fd}<>"${result_inode_test}"
  result_inode_process_id="${BASHPID}"
  result_inode_descriptor="/proc/${result_inode_process_id}/fd/${result_inode_fd}"
  [ "$(/usr/bin/stat -c '%d:%i' -- "${result_inode_test}")" = \
      "$(/usr/bin/stat -Lc '%d:%i' -- "${result_inode_descriptor}")" ] \
    || { printf 'Result invalidation descriptor did not resolve to the published inode.\n' >&2; exit 1; }
  printf 'invalidated' > "${result_inode_descriptor}"
  [ "$(< "${result_inode_test}")" = 'invalidated' ] \
    || { printf 'Result invalidation did not rewrite the published inode.\n' >&2; exit 1; }
  exec {result_inode_fd}>&-
  rm -f -- "${result_inode_test}"
  trap - EXIT
)

for contract in \
  'WALLBOARD_LIVE_MEDIAMTX_VERSION="1.20.0"' \
  '952d5f7d31d1b448ab4da4509550594c511d42636db9d7bb175d377f4ede81df' \
  '6aa3c03da7b6477f1e110c8e18e819cf9ef121e8981b52b8f8219982dae35f2f' \
  '25947caac403f37ec881c9be213af2cad67e344a6c7098905b0d31c17f40e336' \
  '2da379972ba86627632aa7e3f779c680ba04a5ee26ef2a20dc61cefcc24f73b8' \
  "--proto '=https'" \
  'ensure_wallboard_live_ingress_identity' \
  'ensure_wallboard_live_ingress_dependency' \
  'WALLBOARD_LIVE_CONFIGURATION_REQUEST_HELPER_PATH="/usr/local/libexec/dis-wallboard-live-configuration-request"' \
  '"${app_root}/scripts/wallboard-live-configuration-request.sh"' \
  '"${WALLBOARD_LIVE_CONFIGURATION_REQUEST_HELPER_PATH}"' \
  'root_owned_runtime_file_is_safe "${WALLBOARD_LIVE_CONFIGURATION_REQUEST_HELPER_PATH}" 700' \
  'WALLBOARD_LIVE_STREAM_KEY_HASH_PATH="${WALLBOARD_LIVE_CREDENTIAL_DIRECTORY}/stream-key.sha256"'; do
  require_text "${COMMON}" "${contract}"
done

require_text "${DEPLOY}" 'install_wallboard_live_runtime_bundle "${APP_ROOT}"'
require_text "${DEPLOY}" 'install_wallboard_live_key_request_systemd_units "${APP_ROOT}"'
require_text "${UPDATE}" 'install_wallboard_live_key_request_systemd_units "${DIS_INSTALL_PATH}"'
require_text "${DEPLOY}" 'infrastructure/systemd/dis-wallboard-live-ingress.service'
require_text "${DEPLOY}" 'dis-wallboard-live-ingress dis-wallboard-live'
require_text "${INSTALL}" 'ensure_wallboard_live_ingress_identity'
require_text "${INSTALL}" 'install_wallboard_live_key_request_layout'
require_text "${SELF_HEAL}" 'dis-wallboard-live-ingress'
require_text "${SELF_HEAL}" 'dis-wallboard-live-key-request'
require_text "${RESTORE}" 'dis-wallboard-live-key-request.path'
for contract in \
  '/etc/systemd/system/dis-wallboard-live-ingress.service' \
  '/usr/local/bin/dis-wallboard-live-ingress-runner' \
  '/usr/local/bin/dis-mediamtx' \
  '/usr/local/libexec/dis-wallboard-live-auth' \
  '/usr/local/sbin/dis-wallboard-live-refresh' \
  '/usr/local/bin/dis-wallboard-live-key-request-worker' \
  '/etc/systemd/system/dis-wallboard-live-key-request.service' \
  '/etc/systemd/system/dis-wallboard-live-key-request.path' \
  '/etc/systemd/system/dis-wallboard-live-key-request.timer' \
  'userdel "${WALLBOARD_LIVE_INGRESS_USER}"' \
  'groupdel "${WALLBOARD_LIVE_INGRESS_GROUP}"'; do
  require_text "${UNINSTALL}" "${contract}"
done
require_text "${NGINX}" 'alias /run/dis-wallboard-live/hls/$1;'
require_text "${NGINX}" 'location ~ "^/__dis_wallboard_live/(segment-[0-9]{20}\.ts)$" {'
reject_text "${NGINX}" 'location ~ ^/__dis_wallboard_live/(segment-[0-9]{20}\.ts)$ {'

printf 'Wallboard RTMPS ingress and HLS runtime contract passed.\n'
