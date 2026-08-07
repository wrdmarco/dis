#!/usr/bin/env bash
set -euo pipefail
set +x

readonly SERVICE_USER="dis-wallboard-live-ingress"
readonly SERVICE_GROUP="dis-wallboard-live-ingress"
readonly RUNTIME_DIRECTORY="/run/dis-wallboard-live-ingress"
readonly READY_PATH="${RUNTIME_DIRECTORY}/auth.ready"
readonly EXPECTED_CREDENTIAL_DIRECTORY="/run/credentials/dis-wallboard-live-ingress.service"
readonly MEDIAMTX_PATH="/usr/local/bin/dis-mediamtx"
readonly AUTH_PATH="/usr/local/libexec/dis-wallboard-live-auth"

log_generic() {
  printf 'DIS wallboard live ingress: %s\n' "$1"
}

fail_closed() {
  log_generic 'runtime validation failed; RTMPS ingress was not started.' >&2
  exit 78
}

[ "$#" -eq 0 ] || fail_closed
[ "$(/usr/bin/id -un)" = "${SERVICE_USER}" ] || fail_closed
[ "$(/usr/bin/id -gn)" = "${SERVICE_GROUP}" ] || fail_closed
[ "$(/usr/bin/id -G)" = "$(/usr/bin/id -g)" ] || fail_closed
[ "${CREDENTIALS_DIRECTORY:-}" = "${EXPECTED_CREDENTIAL_DIRECTORY}" ] || fail_closed

configuration="${CREDENTIALS_DIRECTORY}/mediamtx.yml"
for credential in "${configuration}" "${CREDENTIALS_DIRECTORY}/stream-key.sha256" \
  "${CREDENTIALS_DIRECTORY}/server.crt" "${CREDENTIALS_DIRECTORY}/server.key"; do
  [ -f "${credential}" ] && [ ! -L "${credential}" ] && [ -r "${credential}" ] \
    && [ "$(/usr/bin/stat -c '%h' -- "${credential}" 2>/dev/null || true)" = "1" ] || fail_closed
done

if [ "$(< "${configuration}")" = "disabled" ]; then
  unset configuration credential
  log_generic 'ingress is disabled.'
  exec /usr/bin/sleep infinity
fi

[ -x "${MEDIAMTX_PATH}" ] && [ ! -L "${MEDIAMTX_PATH}" ] || fail_closed
[ -x "${AUTH_PATH}" ] && [ ! -L "${AUTH_PATH}" ] || fail_closed
[ -d "${RUNTIME_DIRECTORY}" ] && [ ! -L "${RUNTIME_DIRECTORY}" ] \
  && [ "$(/usr/bin/stat -c '%U:%G:%a' -- "${RUNTIME_DIRECTORY}" 2>/dev/null || true)" = "${SERVICE_USER}:${SERVICE_GROUP}:750" ] \
  || fail_closed
if [ -e "${READY_PATH}" ] || [ -L "${READY_PATH}" ]; then
  [ -f "${READY_PATH}" ] && [ ! -L "${READY_PATH}" ] \
    && [ "$(/usr/bin/stat -c '%U:%G:%a:%h' -- "${READY_PATH}" 2>/dev/null || true)" = "${SERVICE_USER}:${SERVICE_GROUP}:600:1" ] \
    || fail_closed
  /usr/bin/rm -f -- "${READY_PATH}"
fi

/usr/bin/python3 -I -S "${AUTH_PATH}" >/dev/null &
auth_pid=$!
mediamtx_pid=""

terminate_children() {
  local pid attempt
  for pid in "${mediamtx_pid:-}" "${auth_pid:-}"; do
    [ -n "${pid}" ] || continue
    /usr/bin/kill -TERM "${pid}" 2>/dev/null || true
  done
  for attempt in 1 2 3 4 5; do
    if { [ -z "${mediamtx_pid:-}" ] || ! /usr/bin/kill -0 "${mediamtx_pid}" 2>/dev/null; } \
      && { [ -z "${auth_pid:-}" ] || ! /usr/bin/kill -0 "${auth_pid}" 2>/dev/null; }; then
      break
    fi
    /usr/bin/sleep 1
  done
  for pid in "${mediamtx_pid:-}" "${auth_pid:-}"; do
    [ -n "${pid}" ] || continue
    /usr/bin/kill -KILL "${pid}" 2>/dev/null || true
    wait "${pid}" 2>/dev/null || true
  done
}

shutdown() {
  trap - TERM INT HUP
  terminate_children
  exit 0
}
trap shutdown TERM INT HUP

for attempt in $(/usr/bin/seq 1 50); do
  /usr/bin/kill -0 "${auth_pid}" 2>/dev/null || { terminate_children; fail_closed; }
  if [ -f "${READY_PATH}" ] && [ ! -L "${READY_PATH}" ] \
    && [ "$(/usr/bin/stat -c '%U:%G:%a:%h' -- "${READY_PATH}" 2>/dev/null || true)" = "${SERVICE_USER}:${SERVICE_GROUP}:600:1" ]; then
    break
  fi
  /usr/bin/sleep 0.1
done
[ -f "${READY_PATH}" ] || { terminate_children; fail_closed; }

"${MEDIAMTX_PATH}" "${configuration}" >/dev/null 2>&1 &
mediamtx_pid=$!
unset configuration credential

set +e
wait -n "${auth_pid}" "${mediamtx_pid}"
exit_status=$?
set -e
trap - TERM INT HUP
terminate_children
log_generic "ingress process stopped (status ${exit_status}); systemd will retry."
exit "${exit_status}"
