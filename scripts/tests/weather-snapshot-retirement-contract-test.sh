#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
RETIRE="${APP_ROOT}/scripts/retire-weather-snapshots.sh"
DEPLOY="${APP_ROOT}/scripts/deploy.sh"
UPDATE="${APP_ROOT}/scripts/update.sh"
RESTORE="${APP_ROOT}/scripts/restore.sh"
INSTALL="${APP_ROOT}/scripts/install.sh"
COMMON="${APP_ROOT}/scripts/lib/common.sh"
ROOT_ENV="${APP_ROOT}/.env.example"
BACKEND_ENV="${APP_ROOT}/webapp/backend/.env.example"
BACKEND_CONFIG="${APP_ROOT}/webapp/backend/config/dis.php"
QUEUE_CONFIG="${APP_ROOT}/webapp/backend/config/queue.php"
CONSOLE_ROUTES="${APP_ROOT}/webapp/backend/routes/console.php"
API_ROUTES="${APP_ROOT}/webapp/backend/routes/api.php"
BACKUP="${APP_ROOT}/scripts/backup.sh"
PURGE_MIGRATION="${APP_ROOT}/webapp/backend/database/migrations/2026_07_29_000002_purge_retired_weather_snapshot_metadata.php"

require_text() {
  local file="$1" value="$2"
  grep -Fq -- "${value}" "${file}" || {
    printf 'Missing weather-snapshot retirement contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  }
}

reject_text() {
  local file="$1" value="$2"
  if grep -Fq -- "${value}" "${file}"; then
    printf 'Forbidden retired weather-snapshot contract in %s: %s\n' "${file}" "${value}" >&2
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
  printf 'Missing weather-snapshot retirement script.\n' >&2
  exit 1
}
[ ! -f "${APP_ROOT}/infrastructure/systemd/dis-knmi.service" ]
[ ! -f "${APP_ROOT}/infrastructure/systemd/dis-knmi-realtime.service" ]

require_text "${RETIRE}" 'require_root'
require_text "${RETIRE}" '[ "${DIS_INSTALL_PATH}" = "/opt/dis" ]'
require_text "${RETIRE}" '[ "${DIS_DATA_PATH}" = "/opt/dis-data" ]'
require_text "${RETIRE}" 'LEGACY_KNMI_STORAGE="${LEGACY_WEATHER_STORAGE_PARENT}/knmi-forecast"'
require_text "${RETIRE}" 'LEGACY_EUMETSAT_STORAGE="${LEGACY_WEATHER_STORAGE_PARENT}/eumetsat-lightning"'
require_text "${RETIRE}" 'php "${backend}/artisan" queue:clear redis --queue=knmi --force'
require_text "${RETIRE}" 'php "${backend}/artisan" queue:clear redis --queue=knmi-realtime --force'
require_text "${RETIRE}" 'refusing to continue without clearing legacy weather queues'
require_text "${RETIRE}" 'clear_legacy_weather_queues 1'
require_text "${RETIRE}" 'ensure_managed_directory "${LEGACY_WEATHER_STORAGE_PARENT}" root root 0750'
require_text "${RETIRE}" 'secure_path_operation remove-tree "${path}"'
require_text "${RETIRE}" 'remove_exact_managed_tree "${LEGACY_KNMI_STORAGE}"'
require_text "${RETIRE}" 'remove_exact_managed_tree "${LEGACY_EUMETSAT_STORAGE}"'
require_text "${RETIRE}" 'EUMETSAT_LIGHTNING_STORAGE_ROOT'
require_text "${RETIRE}" 'KNMI_FORECAST_[A-Za-z0-9_]*'
require_text "${RETIRE}" 'KNMI_PRECIPITATION_[A-Za-z0-9_]*'
require_text "${RETIRE}" 'KNMI_QUEUE_RETRY_AFTER'
require_text "${RETIRE}" 'KNMI_REALTIME_QUEUE_RETRY_AFTER'
require_text "${RETIRE}" 'WEATHER_DATASET_OPERATION_RETENTION_DAYS'
require_text "${RETIRE}" 'BRIDGE_MARKER="# DIS temporary legacy weather snapshot compatibility bridge"'
require_text "${RETIRE}" '/usr/bin/systemd-run \'
require_text "${RETIRE}" '--finalize-compat "${parent_pid}" "${parent_starttime}"'
require_text "${RETIRE}" '[ "${current_starttime}" = "${expected_starttime}" ] || break'
reject_text "${RETIRE}" 'rm -rf'
reject_text "${RETIRE}" 'redis-cli FLUSH'
reject_text "${RETIRE}" 'queue:clear redis --queue=default'
reject_text "${RETIRE}" 'queue:clear redis --queue=push'
reject_text "${RETIRE}" 'KNMI_EDR_API_KEY'

require_text "${DEPLOY}" 'bash "${SCRIPT_DIR}/retire-weather-snapshots.sh"'
require_text "${DEPLOY}" 'bash "${SCRIPT_DIR}/retire-weather-snapshots.sh" --compat-parent-pid "${PPID}"'
require_text "${DEPLOY}" 'bash "${SCRIPT_DIR}/retire-weather-snapshots.sh" --clear-queues-only'
require_text "${UPDATE}" 'DIS_LEGACY_WEATHER_COMPAT_REQUIRED=0 \'
require_text "${RESTORE}" 'bash "${SCRIPT_DIR}/retire-weather-snapshots.sh"'

reject_text "${DEPLOY}" 'infrastructure/systemd/dis-knmi.service'
reject_text "${DEPLOY}" 'infrastructure/systemd/dis-knmi-realtime.service'
reject_text "${DEPLOY}" 'ensure_knmi_forecast_runtime_dependencies'
reject_text "${COMMON}" 'ensure_knmi_forecast_runtime_dependencies'
reject_text "${INSTALL}" 'hdf5-tools'
reject_text "${INSTALL}" 'libeccodes-tools'
reject_text "${CONSOLE_ROUTES}" 'dis:refresh-knmi-forecast'
reject_text "${CONSOLE_ROUTES}" 'dis:refresh-knmi-precipitation-outlook'
reject_text "${CONSOLE_ROUTES}" 'dis:refresh-eumetsat-lightning'
reject_text "${API_ROUTES}" '/admin/knmi'

for file in "${ROOT_ENV}" "${BACKEND_ENV}" "${BACKEND_CONFIG}"; do
  reject_text "${file}" 'KNMI_OPEN_DATA_API_KEY'
  reject_text "${file}" 'KNMI_FORECAST_'
  reject_text "${file}" 'KNMI_PRECIPITATION_'
  reject_text "${file}" 'KNMI_QUEUE_RETRY_AFTER'
  reject_text "${file}" 'KNMI_REALTIME_QUEUE_RETRY_AFTER'
  reject_text "${file}" 'EUMETSAT_LIGHTNING_STORAGE_ROOT'
  reject_text "${file}" 'WEATHER_DATASET_OPERATION_RETENTION_DAYS'
done
require_text "${ROOT_ENV}" 'KNMI_EDR_API_KEY='
require_text "${BACKEND_ENV}" 'KNMI_EDR_API_KEY='
[ -f "${APP_ROOT}/webapp/backend/app/Services/KnmiCloudBaseObservationService.php" ]

reject_text "${QUEUE_CONFIG}" "'knmi' =>"
reject_text "${QUEUE_CONFIG}" "'knmi_realtime' =>"

for retired_file in \
  webapp/backend/app/Console/Commands/RefreshKnmiForecast.php \
  webapp/backend/app/Console/Commands/RefreshKnmiPrecipitationOutlook.php \
  webapp/backend/app/Console/Commands/ReconcileKnmiAfterRestore.php \
  webapp/backend/app/Jobs/RefreshKnmiForecastDataset.php \
  webapp/backend/app/Jobs/RefreshKnmiPrecipitationOutlookSnapshot.php \
  webapp/backend/app/Jobs/RefreshWeatherDatasetOperation.php \
  webapp/backend/app/Http/Controllers/AdminKnmiController.php \
  webapp/backend/app/Repositories/KnmiForecastSnapshotRepository.php \
  webapp/backend/app/Repositories/KnmiPrecipitationSnapshotRepository.php \
  webapp/backend/app/Services/KnmiForecastImportService.php \
  webapp/backend/app/Services/KnmiPrecipitationImportService.php \
  webapp/backend/app/Services/WeatherDatasetOperationService.php \
  webapp/backend/app/Console/Commands/RefreshEumetsatLightning.php \
  webapp/backend/app/Jobs/RefreshEumetsatLightningSnapshot.php \
  webapp/backend/app/Repositories/EumetsatLightningSnapshotRepository.php; do
  [ ! -e "${APP_ROOT}/${retired_file}" ] || {
    printf 'Retired weather snapshot writer remains reachable: %s\n' "${retired_file}" >&2
    exit 1
  }
done

require_text "${BACKUP}" "--exclude='webapp/backend/storage/app/knmi-forecast'"
require_text "${BACKUP}" "--exclude='webapp/backend/storage/app/eumetsat-lightning'"
require_text "${PURGE_MIGRATION}" "Schema::hasTable('knmi_forecast_operations')"
require_text "${PURGE_MIGRATION}" "Schema::hasTable('knmi_forecast_snapshots')"
require_text "${PURGE_MIGRATION}" "Schema::hasTable('weather_dataset_operations')"
require_text "${PURGE_MIGRATION}" "Schema::hasTable('failed_jobs')"
require_text "${PURGE_MIGRATION}" "['knmi', 'knmi_realtime']"
require_text "${PURGE_MIGRATION}" "['knmi', 'knmi-realtime']"
require_text "${PURGE_MIGRATION}" "'weather.knmi_open_data_api_key'"
require_text "${PURGE_MIGRATION}" "'knmi.manage'"

retirement_call="$(line_of "${DEPLOY}" 'bash "${SCRIPT_DIR}/retire-weather-snapshots.sh"')"
backend_manifest="$(line_of "${DEPLOY}" 'regenerate_backend_package_manifest "${BACKEND_DIR}"')"
[ "${retirement_call}" -lt "${backend_manifest}" ] || {
  printf 'Legacy weather writers and files must be retired before backend activation.\n' >&2
  exit 1
}

restore_install="$(line_of "${RESTORE}" 'replace_managed_tree "${RESTORED_DATA}/webapp/backend/storage"')"
restore_retirement="$(line_of "${RESTORE}" 'bash "${SCRIPT_DIR}/retire-weather-snapshots.sh"')"
restore_repair="$(line_of_exact "${RESTORE}" 'repair_restored_data_permissions')"
[ "${restore_install}" -lt "${restore_retirement}" ] \
  && [ "${restore_retirement}" -lt "${restore_repair}" ] || {
  printf 'Restored weather snapshots must be removed before permission repair.\n' >&2
  exit 1
}

printf 'Weather-snapshot retirement deployment contract passed.\n'
