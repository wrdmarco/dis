#!/usr/bin/env bash
set -euo pipefail
set +x

readonly CONFIGURE_PATH="/usr/local/libexec/dis-wallboard-live-configure"
readonly CREDENTIAL_DIRECTORY="/etc/dis-wallboard-live"
readonly LOCK_DIRECTORY="/run/lock"
readonly LOCK_PATH="${LOCK_DIRECTORY}/dis-exclusive-operation.lock"
readonly CONFIGURATION_PATH="${CREDENTIAL_DIRECTORY}/mediamtx.yml"
readonly -a CREDENTIAL_FILES=(mediamtx.yml stream-key.sha256 input.url server.crt server.key)

snapshot=""
rollback_required=0
operation_lock_fd=""

fail_closed() {
  printf 'The wallboard live-stream credentials were not refreshed.\n' >&2
  exit 1
}

[ "${EUID}" -eq 0 ] || fail_closed
[ "$#" -eq 0 ] || fail_closed
[ -f "${CONFIGURE_PATH}" ] && [ ! -L "${CONFIGURE_PATH}" ] && [ -x "${CONFIGURE_PATH}" ] \
  && [ "$(/usr/bin/stat -c '%u:%g:%a:%h' -- "${CONFIGURE_PATH}" 2>/dev/null || true)" = "0:0:700:1" ] \
  || fail_closed

credential_is_safe() {
  local path="$1"

  [ -f "${path}" ] && [ ! -L "${path}" ] \
    && [ "$(/usr/bin/stat -c '%u:%g:%a:%h' -- "${path}" 2>/dev/null || true)" = "0:0:600:1" ]
}

remove_snapshot() {
  [ -n "${snapshot:-}" ] || return 0
  [[ "${snapshot}" == "${CREDENTIAL_DIRECTORY}/.refresh-backup."* ]] \
    && [ -d "${snapshot}" ] && [ ! -L "${snapshot}" ] || return 1
  /usr/bin/rm -rf -- "${snapshot}"
  snapshot=""
}

tls_listener_ready() {
  local line endpoint address port

  [ "$(< "${CONFIGURATION_PATH}")" != "disabled" ] || return 0
  line="$(/usr/bin/grep -E '^rtmpsAddress: ([0-9]{1,3}\.){3}[0-9]{1,3}:[0-9]{4,5}$' \
    "${CONFIGURATION_PATH}" 2>/dev/null || true)"
  [ "$(/usr/bin/printf '%s\n' "${line}" | /usr/bin/wc -l)" = "1" ] && [ -n "${line}" ] || return 1
  endpoint="${line#rtmpsAddress: }"
  address="${endpoint%:*}"
  port="${endpoint##*:}"
  [ "${address}" != "0.0.0.0" ] || address="127.0.0.1"
  [[ "${port}" =~ ^[0-9]{4,5}$ ]] || return 1

  /usr/bin/timeout 5 /usr/bin/openssl s_client \
    -connect "${address}:${port}" \
    -brief \
    -verify_return_error \
    -CAfile /etc/ssl/certs/ca-certificates.crt \
    </dev/null >/dev/null 2>&1
}

services_ready() {
  local attempt consecutive=0

  for attempt in $(/usr/bin/seq 1 30); do
    if /usr/bin/systemctl is-active --quiet dis-wallboard-live-ingress.service \
      && /usr/bin/systemctl is-active --quiet dis-wallboard-live.service \
      && tls_listener_ready; then
      consecutive=$((consecutive + 1))
      [ "${consecutive}" -ge 3 ] && return 0
    else
      consecutive=0
    fi
    /usr/bin/sleep 1
  done

  return 1
}

refresh_cleanup() {
  local exit_code="$?" file restore_complete=1

  trap - EXIT INT TERM
  set +e
  if [ "${exit_code}" -ne 0 ] && [ "${rollback_required:-0}" = "1" ] \
    && [ -n "${snapshot:-}" ] && [ -d "${snapshot}" ] && [ ! -L "${snapshot}" ]; then
    /usr/bin/printf 'The wallboard live-stream refresh failed; restoring the previous credentials.\n' >&2
    /usr/bin/systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service >/dev/null 2>&1
    for file in "${CREDENTIAL_FILES[@]}"; do
      if credential_is_safe "${snapshot}/${file}"; then
        /usr/bin/mv -fT -- "${snapshot}/${file}" "${CREDENTIAL_DIRECTORY}/${file}" \
          || restore_complete=0
      else
        restore_complete=0
      fi
    done
    /usr/bin/sync -f "${CREDENTIAL_DIRECTORY}"
    if [ "${restore_complete}" = "1" ]; then
      /usr/bin/systemctl restart dis-wallboard-live-ingress.service dis-wallboard-live.service >/dev/null 2>&1
    fi
    if [ "${restore_complete}" != "1" ] || ! services_ready; then
      /usr/bin/printf 'The previous wallboard live-stream services could not be restored automatically.\n' >&2
    fi
    if [ "${restore_complete}" != "1" ]; then
      /usr/bin/printf 'The remaining root-only credential backup was retained for manual recovery: %s\n' "${snapshot}" >&2
      snapshot=""
    fi
  fi
  remove_snapshot >/dev/null 2>&1 || true
  exit "${exit_code}"
}

/usr/bin/install -d -m 0755 -o root -g root "${LOCK_DIRECTORY}"
if [ -e "${LOCK_PATH}" ] || [ -L "${LOCK_PATH}" ]; then
  [ -f "${LOCK_PATH}" ] && [ ! -L "${LOCK_PATH}" ] \
    && [ "$(/usr/bin/stat -c '%u:%g:%h' -- "${LOCK_PATH}" 2>/dev/null || true)" = "0:0:1" ] \
    || fail_closed
fi
case "${DIS_OPERATION_LOCK_HELD:-0}" in
  1)
    [[ "${DIS_OPERATION_LOCK_FD:-}" =~ ^([3-9]|[1-9][0-9]+)$ ]] || fail_closed
    inherited_fd_path="$(/usr/bin/readlink -f "/proc/$$/fd/${DIS_OPERATION_LOCK_FD}" 2>/dev/null || true)"
    [ "${inherited_fd_path}" = "${LOCK_PATH}" ] || fail_closed
    /usr/bin/flock -n "${DIS_OPERATION_LOCK_FD}" || fail_closed
    operation_lock_fd="${DIS_OPERATION_LOCK_FD}"
    ;;
  0)
    exec 9>"${LOCK_PATH}"
    /usr/bin/chown root:root "${LOCK_PATH}"
    /usr/bin/chmod 0600 "${LOCK_PATH}"
    /usr/bin/flock -n 9 || fail_closed
    operation_lock_fd=9
    ;;
  *)
    fail_closed
    ;;
esac
[ -n "${operation_lock_fd}" ] || fail_closed

snapshot="$(/usr/bin/mktemp -d "${CREDENTIAL_DIRECTORY}/.refresh-backup.XXXXXX")"
trap refresh_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
[[ "${snapshot}" == "${CREDENTIAL_DIRECTORY}/.refresh-backup."* ]] || fail_closed
/usr/bin/chown root:root "${snapshot}"
/usr/bin/chmod 0700 "${snapshot}"
for file in "${CREDENTIAL_FILES[@]}"; do
  credential_is_safe "${CREDENTIAL_DIRECTORY}/${file}" || fail_closed
  /usr/bin/cp --reflink=never -- "${CREDENTIAL_DIRECTORY}/${file}" "${snapshot}/${file}"
  /usr/bin/chown root:root "${snapshot}/${file}"
  /usr/bin/chmod 0600 "${snapshot}/${file}"
  credential_is_safe "${snapshot}/${file}" || fail_closed
  /usr/bin/sync -f "${snapshot}/${file}"
done
/usr/bin/sync -f "${snapshot}"
rollback_required=1

"${CONFIGURE_PATH}"
/usr/bin/systemctl restart dis-wallboard-live-ingress.service
/usr/bin/systemctl restart dis-wallboard-live.service
services_ready || fail_closed

rollback_required=0
remove_snapshot || fail_closed
trap - EXIT INT TERM
printf 'The wallboard live-stream credentials were refreshed.\n'
