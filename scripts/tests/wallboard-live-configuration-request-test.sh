#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HELPER="${APP_ROOT}/scripts/wallboard-live-configuration-request.sh"
WORKER="${APP_ROOT}/scripts/wallboard-live-key-request-worker.sh"
temporary="$(mktemp -d)"
cleanup() { rm -rf -- "${temporary}"; }
trap cleanup EXIT INT TERM

fail() { printf 'Configuration helper test failed: %s\n' "$1" >&2; exit 1; }
require_text() { grep -Fq -- "$2" "$1" || fail "missing contract: $2"; }
reject_text() { ! grep -Fq -- "$2" "$1" || fail "forbidden contract: $2"; }

bash -n "${HELPER}"
for contract in \
  'readonly ACTIVATION_OFFSET=50' \
  'readonly SUCCESS_OFFSET=75' \
  'readonly ROLLBACK_OFFSET=95' \
  'readonly TERMINAL_OFFSET=100' \
  'write_commit_marker "${key_created}" "${desired_sha}" "${success_deadline}"' \
  'run_by_deadline "${deadline}" /usr/bin/mv -T -- "${temporary}" "${COMMIT_MARKER}"' \
  'DIS_OPERATION_LOCK_HELD' \
  '/proc/$$/fd/${DIS_OPERATION_LOCK_FD}' \
  'operation == "configure"' \
  'configuration-commit' \
  'previous-env' \
  'fail-closed) :' \
  'www-data:root' \
  'key_created:$key_created,config_sha256:$config_sha256'; do
  require_text "${HELPER}" "${contract}"
done
reject_text "${HELPER}" 'fail-closed) remove_claim'

mock_refresh="${temporary}/refresh"
mock_systemctl="${temporary}/systemctl"
trace="${temporary}/trace"
cat > "${mock_refresh}" <<'EOF'
#!/usr/bin/env bash
printf 'refresh\n' >> "${TRACE_PATH}"
EOF
cat > "${mock_systemctl}" <<'EOF'
#!/usr/bin/env bash
case "$1" in
  reload) printf 'reload:%s\n' "$2" >> "${TRACE_PATH}" ;;
  is-active) exit 0 ;;
  stop) printf 'stop\n' >> "${TRACE_PATH}" ;;
  *) exit 1 ;;
esac
EOF
chmod 0700 "${mock_refresh}" "${mock_systemctl}"

# Source a path-adjusted test copy without executing its root-only main entry.
sed \
  -e "s#/usr/local/sbin/dis-wallboard-live-refresh#${mock_refresh//\/\\}#g" \
  -e "s#/usr/bin/systemctl#${mock_systemctl//\/\\}#g" \
  -e 's#/usr/bin/jq#jq#g' \
  -e '/^main "\$@"$/d' \
  "${HELPER}" > "${temporary}/helper-under-test.sh"
# shellcheck disable=SC1090
source "${temporary}/helper-under-test.sh"
export MSYS2_ARG_CONV_EXCL='*'

valid_host stream.example.test || fail 'valid hostname rejected'
valid_host 203.0.113.10 || fail 'valid IPv4 rejected'
! valid_host 224.0.0.1 || fail 'multicast IPv4 accepted'
! valid_host bad..example || fail 'empty DNS label accepted'
valid_bind 0.0.0.0 || fail 'wildcard bind rejected'
valid_bind 192.0.2.20 || fail 'unicast bind rejected'
! valid_bind 01.2.3.4 || fail 'ambiguous IPv4 accepted'
valid_tls_path /etc/letsencrypt/live/stream.example.test/fullchain.pem || fail 'managed certificate rejected'
valid_tls_path /etc/ssl/private/dis-wallboard.key || fail 'managed private key rejected'
! valid_tls_path /tmp/server.key || fail 'unmanaged TLS path accepted'
! valid_tls_path /etc/ssl/../shadow || fail 'traversal TLS path accepted'
valid_stream_key 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcd' || fail 'valid key rejected'
! valid_stream_key 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' || fail 'repeated key accepted'

expected="$(printf '%s' '{"enabled":true,"public_host":"stream.example.test","bind_address":"0.0.0.0","rtmps_port":1936,"tls_certificate_path":"/etc/ssl/cert.pem","tls_private_key_path":"/etc/ssl/key.pem","stream_key_configured":true}' | sha256sum | cut -d' ' -f1)"
actual="$(configuration_digest true stream.example.test 0.0.0.0 1936 /etc/ssl/cert.pem /etc/ssl/key.pem true)"
[ "${actual}" = "${expected}" ] || fail "canonical digest differs: ${actual} != ${expected}"

ENV_FILE="${temporary}/managed.env"
cat > "${ENV_FILE}" <<'EOF'
APP_ENV=testing
WALLBOARD_LIVE_STREAM_ENABLED=true
WALLBOARD_LIVE_STREAM_PUBLIC_HOST=stream.example.test
WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS=0.0.0.0
WALLBOARD_LIVE_STREAM_RTMPS_PORT=1936
WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH=/etc/ssl/cert.pem
WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH=/etc/ssl/key.pem
WALLBOARD_LIVE_STREAM_STREAM_KEY=AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcd
EOF
actual="$(current_digest)"
[ "${actual}" = "${expected}" ] || fail "managed environment digest differs: ${actual} != ${expected}"

export TRACE_PATH="${trace}"
PHP_FPM_SERVICE=php8.5-fpm
DIS_OPERATION_LOCK_FD=9
DIS_OPERATION_LOCK_HELD=1
activate_before "$(($(date +%s) + 5))" || fail 'mock activation failed'
[ "$(cat "${trace}")" = $'refresh\nreload:php8.5-fpm.service' ] || fail 'activation order is not refresh then PHP-FPM reload'
! run_before "$(date +%s)" "${mock_refresh}" || fail 'expired command was executed'
[ "$(wc -l < "${trace}" | tr -d ' ')" = 2 ] || fail 'expired command changed runtime state'

if grep -E 'security_log .*\$\{?(current_key|prior_key|desired_key|stream_key)' "${HELPER}" >/dev/null; then
  fail 'a secret-bearing variable is passed to security logging'
fi

# The worker's outer watchdog is derived from the signed request timestamp and
# remains strictly below the broker's 105-second HTTP wait.
sed -n '/^configuration_request_timeout_seconds()/,/^}/p' "${WORKER}" \
  | sed 's#/usr/bin/jq#jq#g' > "${temporary}/worker-watchdog-under-test.sh"
WORKER_PATH="${temporary}/worker-watchdog-under-test.sh" WATCHDOG_TEMP="${temporary}" bash -c '
  set -euo pipefail
  CONFIGURATION_TERMINAL_OFFSET_SECONDS=100
  CONFIGURATION_RECOVERY_TIMEOUT_SECONDS=15
  WORK_DIR="${WATCHDOG_TEMP}"
  # shellcheck disable=SC1090
  source "${WORKER_PATH}"
  watchdog_request="${WATCHDOG_TEMP}/watchdog.json"
  watchdog_native="$(cygpath -m "${watchdog_request}")"
  created_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  printf "{\"created_at\":\"%s\"}\n" "${created_at}" > "${watchdog_request}"
  budget="$(configuration_request_timeout_seconds "${watchdog_native}")"
  [ "${budget}" -ge 99 ] && [ "${budget}" -le 100 ] \
    || { printf "fresh watchdog budget is %s\n" "${budget}" >&2; exit 1; }
  printf "{\"created_at\":\"invalid\"}\n" > "${watchdog_request}"
  budget="$(configuration_request_timeout_seconds "${watchdog_native}")"
  [ "${budget}" = 5 ] || { printf "invalid watchdog budget is %s\n" "${budget}" >&2; exit 1; }
  created_at="$(date -u -d "200 seconds ago" +%Y-%m-%dT%H:%M:%SZ)"
  printf "{\"created_at\":\"%s\"}\n" "${created_at}" > "${watchdog_request}"
  budget="$(configuration_request_timeout_seconds "${watchdog_native}")"
  [ "${budget}" = 1 ] || { printf "expired watchdog budget is %s\n" "${budget}" >&2; exit 1; }
  recovery_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
  recovery_request="${WATCHDOG_TEMP}/${recovery_id}.json"
  recovery_native="$(cygpath -m "${recovery_request}")"
  printf "{\"created_at\":\"%s\"}\n" "${created_at}" > "${recovery_request}"
  : > "${WATCHDOG_TEMP}/${recovery_id}.previous-env"
  budget="$(configuration_request_timeout_seconds "${recovery_native}")"
  [ "${budget}" = 15 ] || { printf "recovery watchdog budget is %s\n" "${budget}" >&2; exit 1; }
' || fail 'configuration watchdog budget contract failed'

printf 'Wallboard live-stream configuration helper tests passed.\n'
