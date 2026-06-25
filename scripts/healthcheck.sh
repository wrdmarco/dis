#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

HEALTH_URL="${HEALTH_URL:-http://127.0.0.1/health}"
ADMIN_HEALTH_URL="${ADMIN_HEALTH_URL:-}"
CURL_ARGS=(--fail --silent --show-error)

log "Checking ${HEALTH_URL}"
curl "${CURL_ARGS[@]}" "${HEALTH_URL}" >/dev/null
log "Health check passed"

if [ -n "${ADMIN_HEALTH_URL}" ]; then
  : "${ADMIN_HEALTH_TOKEN:?ADMIN_HEALTH_TOKEN is required when ADMIN_HEALTH_URL is set}"
  curl "${CURL_ARGS[@]}" -H "Authorization: Bearer ${ADMIN_HEALTH_TOKEN}" "${ADMIN_HEALTH_URL}" >/dev/null
  log "Admin health check passed"
fi
