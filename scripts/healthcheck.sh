#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

HEALTH_URL="${HEALTH_URL:-http://127.0.0.1/health}"
ADMIN_HEALTH_URL="${ADMIN_HEALTH_URL:-}"
CURL_ARGS=(--silent --show-error)

check_url() {
  local label="$1"
  local url="$2"
  shift 2
  local output status

  output="$(mktemp)"
  if ! status="$(curl "${CURL_ARGS[@]}" -o "${output}" -w '%{http_code}' "$@" "${url}")"; then
    status="000"
  fi
  if [ "${status}" -lt 200 ] || [ "${status}" -ge 400 ]; then
    printf '[dis:error] %s failed with HTTP %s: ' "${label}" "${status}" >&2
    head -c 500 "${output}" >&2 || true
    printf '\n' >&2
    rm -f "${output}"
    return 1
  fi
  rm -f "${output}"
}

log "Checking ${HEALTH_URL}"
check_url "Health check" "${HEALTH_URL}"
log "Health check passed"

if [ -n "${ADMIN_HEALTH_URL}" ]; then
  : "${ADMIN_HEALTH_TOKEN:?ADMIN_HEALTH_TOKEN is required when ADMIN_HEALTH_URL is set}"
  check_url "Admin health check" "${ADMIN_HEALTH_URL}" -H "Authorization: Bearer ${ADMIN_HEALTH_TOKEN}"
  log "Admin health check passed"
fi
