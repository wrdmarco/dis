#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/lib/common.sh"

APP_ROOT="${APP_ROOT:-${DIS_INSTALL_PATH}}"
ENV_FILE="${ENV_FILE:-${APP_ROOT}/.env}"

require_root
require_file "${ENV_FILE}"

env_value() {
  local key="$1"
  local value
  value="$(grep -E "^${key}=" "${ENV_FILE}" | tail -n 1 | cut -d '=' -f 2- || true)"
  value="${value%\"}"
  value="${value#\"}"
  value="${value%\'}"
  value="${value#\'}"
  printf '%s' "$value"
}

DB_HOST="$(env_value DB_HOST)"
DB_PORT="$(env_value DB_PORT)"
DB_DATABASE="$(env_value DB_DATABASE)"
DB_USERNAME="$(env_value DB_USERNAME)"
DB_PASSWORD="$(env_value DB_PASSWORD)"

case "${DB_HOST}" in
  127.0.0.1|localhost|"")
    ;;
  *)
    fail "Refusing to provision remote PostgreSQL host '${DB_HOST}'. Create that database manually."
    ;;
esac

if [ -z "${DB_DATABASE}" ] || [ -z "${DB_USERNAME}" ] || [ -z "${DB_PASSWORD}" ]; then
  fail "DB_DATABASE, DB_USERNAME and DB_PASSWORD must be set in ${ENV_FILE}."
fi

log "Provisioning local PostgreSQL database '${DB_DATABASE}' for user '${DB_USERNAME}'"

run_cmd systemctl start postgresql

runuser -u postgres -- psql -v ON_ERROR_STOP=1 \
  -v db_name="${DB_DATABASE}" \
  -v db_user="${DB_USERNAME}" \
  -v db_password="${DB_PASSWORD}" \
  -v db_port="${DB_PORT:-5432}" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'db_user', :'db_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'db_user')\gexec

SELECT format('ALTER ROLE %I WITH LOGIN PASSWORD %L', :'db_user', :'db_password')\gexec

SELECT format('CREATE DATABASE %I OWNER %I ENCODING %L', :'db_name', :'db_user', 'UTF8')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db_name')\gexec

SELECT format('GRANT ALL PRIVILEGES ON DATABASE %I TO %I', :'db_name', :'db_user')\gexec
SQL

log "Database provisioning finished"
