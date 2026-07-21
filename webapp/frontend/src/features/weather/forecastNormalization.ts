import type {
  OperationalWeatherPageState,
  WallboardForecastBlockKey,
  WallboardForecastMetric,
  WallboardForecastPageState,
  WallboardForecastSource,
  WallboardForecastState,
} from '../../types/api';
import {
  DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS,
  MAX_WALLBOARD_FORECAST_VISIBLE_BLOCKS,
  WALLBOARD_FORECAST_BLOCK_KEYS,
} from '../wallboards/wallboardPresentation';

const WALLBOARD_FORECAST_METRIC_KEYS = [
  'weather_code',
  'temperature_c',
  'dew_point_c',
  'wind_speed_kmh',
  'wind_gust_kmh',
  'wind_direction_degrees',
  'precipitation_probability_pct',
  'precipitation_mm',
  'precipitation_outlook',
  'thunderstorm_forecast',
  'cloud_cover_pct',
  'low_cloud_cover_pct',
  'visibility_m',
  'kp_index',
  'gnss_satellites',
  'gnss_satellites_fix',
] as const satisfies readonly WallboardForecastMetric['key'][];

const WALLBOARD_FORECAST_ADVICE_METRIC_KEYS = WALLBOARD_FORECAST_METRIC_KEYS.filter(
  (key) => key !== 'cloud_cover_pct',
);

export function normalizeUavForecastPage(value: unknown): WallboardForecastPageState | null {
  return normalizeWallboardForecastState({ pages: { forecast: value } }).pages.forecast ?? null;
}

export function normalizeWallboardForecastState(value: unknown): WallboardForecastState {
  if (!isRecord(value) || !isRecord(value.pages)) return { pages: {} };

  const pages = Object.entries(value.pages).slice(0, 20).reduce<Record<string, WallboardForecastPageState>>(
    (normalized, [pageId, rawPage]) => {
      if (!/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/.test(pageId) || !isRecord(rawPage) || !isRecord(rawPage.location)) {
        return normalized;
      }
      const location = rawPage.location;
      if (typeof location.label !== 'string' || !Array.isArray(rawPage.metrics)) return normalized;

      const metrics = rawPage.metrics.flatMap(normalizeWallboardForecastMetric);
      const metricKeys = new Set(metrics.map((metric) => metric.key));
      const requiredKeys = WALLBOARD_FORECAST_METRIC_KEYS.filter((key) => ![
        'low_cloud_cover_pct',
        'precipitation_outlook',
        'thunderstorm_forecast',
      ].includes(key));
      if (metricKeys.size !== metrics.length || requiredKeys.some((key) => !metricKeys.has(key))) {
        return normalized;
      }

      const locationMode = location.mode === 'address' ? 'address' : 'netherlands';
      const locationModeValid = location.mode === 'address' || location.mode === 'netherlands';
      const latitude = normalizeForecastCoordinate(location.latitude, -90, 90);
      const longitude = normalizeForecastCoordinate(location.longitude, -180, 180);
      const locationIsValid = locationModeValid
        && location.label.trim() !== ''
        && latitude !== null
        && longitude !== null;
      const aggregation = normalizeWallboardForecastAggregation(rawPage.aggregation, locationMode);
      const condition = normalizeWallboardForecastCondition(rawPage.condition, metrics);
      const daylight = normalizeWallboardForecastDaylight(rawPage.daylight);
      const windProfile = normalizeWallboardForecastWindProfile(rawPage.wind_profile, metrics);
      const generatedAt = requiredIsoTimestamp(rawPage.generated_at);
      const weatherMetric = metrics.find((metric) => metric.key === 'weather_code');
      const selectedBlocks = Array.isArray(rawPage.visible_blocks)
        ? new Set(rawPage.visible_blocks.filter((candidate): candidate is WallboardForecastBlockKey => (
            typeof candidate === 'string'
            && WALLBOARD_FORECAST_BLOCK_KEYS.includes(candidate as WallboardForecastBlockKey)
          )))
        : new Set(DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS);
      const suppliedOverallStatus = normalizeForecastStatus(rawPage.overall_status);
      const greenAdviceIsComplete = generatedAt !== null
        && locationIsValid
        && aggregation.complete
        && aggregation.fresh
        && condition.status === 'green'
        && !condition.stale
        && condition.source.name !== 'Onbekend'
        && condition.measured_at !== null
        && weatherMetric?.status === 'green'
        && weatherMetric.value === condition.code
        && weatherMetric.measured_at === condition.measured_at
        && weatherMetric.source.name === condition.source.name
        && daylightIsComplete(daylight)
        && !daylight.stale
        && daylight.source.name !== 'Onbekend'
        && !windProfile.stale
        && windProfile.samples.length > 0
        && windProfile.samples.every((sample) => sample.speed_kmh !== null)
        && structuredAdviceContractsAreValid(metrics, aggregation.expected_sample_count)
        && WALLBOARD_FORECAST_ADVICE_METRIC_KEYS.every((key) => {
          const metric = metrics.find((candidate) => candidate.key === key);
          return metric !== undefined
            && metric.status === 'green'
            && !metric.stale
            && metric.measured_at !== null
            && metric.source.name !== 'Onbekend';
        });

      normalized[pageId] = {
        location: {
          mode: locationMode,
          label: location.label.trim().slice(0, 120),
          latitude,
          longitude,
        },
        aggregation,
        visible_blocks: WALLBOARD_FORECAST_BLOCK_KEYS
          .filter((key) => selectedBlocks.has(key))
          .slice(0, MAX_WALLBOARD_FORECAST_VISIBLE_BLOCKS),
        overall_status: suppliedOverallStatus === 'green' && !greenAdviceIsComplete
          ? 'unknown'
          : suppliedOverallStatus,
        generated_at: generatedAt ?? new Date(0).toISOString(),
        condition,
        daylight,
        wind_profile: windProfile,
        metrics,
        scope_note: typeof rawPage.scope_note === 'string' && rawPage.scope_note.trim() !== ''
          ? rawPage.scope_note.trim().slice(0, 600)
          : aggregation.type === 'province_average'
            ? 'Landelijk overzicht op basis van provinciale modelwaarden.'
            : 'Actuele modelwaarden voor de gekozen locatie.',
        disclaimer: typeof rawPage.disclaimer === 'string' && rawPage.disclaimer.trim() !== ''
          ? rawPage.disclaimer.trim().slice(0, 600)
          : 'Indicatieve gegevens; operationele en wettelijke limieten gaan altijd voor.',
      };

      return normalized;
    },
    {},
  );

  return { pages };
}

export function markWallboardForecastStale(
  forecast: WallboardForecastPageState,
): WallboardForecastPageState {
  return {
    ...forecast,
    overall_status: 'unknown',
    aggregation: { ...forecast.aggregation, fresh: false },
    condition: { ...forecast.condition, status: 'unknown', stale: true },
    daylight: { ...forecast.daylight, stale: true },
    wind_profile: { ...forecast.wind_profile, stale: true },
    metrics: forecast.metrics.map((metric) => ({ ...metric, status: 'unknown', stale: true })),
  };
}

export function markOperationalWeatherStale(
  weather: OperationalWeatherPageState,
): OperationalWeatherPageState {
  return {
    ...weather,
    data_status: 'unavailable',
    aggregation: { ...weather.aggregation, fresh: false },
    cloud: { ...weather.cloud, stale: true },
    precipitation: { ...weather.precipitation, stale: true },
  };
}

export function normalizeOperationalWeatherPage(value: unknown): OperationalWeatherPageState | null {
  if (
    !isRecord(value)
    || !isRecord(value.location)
    || !isRecord(value.aggregation)
    || !isRecord(value.cloud)
    || !isRecord(value.precipitation)
    || typeof value.location.label !== 'string'
  ) return null;

  const locationMode = value.location.mode === 'address' ? 'address' : 'netherlands';
  const locationModeValid = value.location.mode === 'address' || value.location.mode === 'netherlands';
  const latitude = normalizeForecastCoordinate(value.location.latitude, -90, 90);
  const longitude = normalizeForecastCoordinate(value.location.longitude, -180, 180);
  const locationIsValid = locationModeValid
    && value.location.label.trim() !== ''
    && latitude !== null
    && longitude !== null;
  const generatedAt = requiredIsoTimestamp(value.generated_at);
  if (generatedAt === null) return null;

  const aggregation = normalizeWallboardForecastAggregation(value.aggregation, locationMode);
  const aggregationContractValid = operationalWeatherAggregationContractIsValid(
    value.aggregation,
    locationMode,
  );
  const cloud = normalizeOperationalWeatherCloud(value.cloud, aggregation.expected_sample_count);
  const precipitation = normalizeOperationalWeatherPrecipitation(
    value.precipitation,
    aggregation.expected_sample_count,
  );
  const cloudCurrent = cloud.complete && !cloud.stale;
  const precipitationCurrent = precipitation.complete && !precipitation.stale;
  const aggregationCurrent = aggregation.complete && aggregation.fresh;
  const dataStatus = !locationIsValid || !aggregationContractValid
    ? 'unavailable'
    : cloudCurrent && precipitationCurrent && aggregationCurrent
      ? 'current'
      : cloudCurrent || precipitationCurrent
        ? 'partial'
        : 'unavailable';

  return {
    location: {
      mode: locationMode,
      label: value.location.label.trim().slice(0, 120),
      latitude,
      longitude,
    },
    aggregation: {
      ...aggregation,
      complete: aggregation.complete && cloud.complete && precipitation.complete,
      fresh: aggregation.fresh && dataStatus === 'current',
    },
    generated_at: generatedAt,
    data_status: dataStatus,
    cloud,
    precipitation,
    scope_note: typeof value.scope_note === 'string' && value.scope_note.trim() !== ''
      ? value.scope_note.trim().slice(0, 600)
      : 'Lokale KNMI-data voor het gekozen gebied.',
    disclaimer: typeof value.disclaimer === 'string' && value.disclaimer.trim() !== ''
      ? value.disclaimer.trim().slice(0, 600)
      : 'Indicatieve gegevens; operationele en wettelijke limieten gaan altijd voor.',
  };
}

function normalizeOperationalWeatherCloud(
  value: Record<string, unknown>,
  aggregationExpectedSampleCount: number,
): OperationalWeatherPageState['cloud'] {
  const cloudCover = boundedNumber(value.cloud_cover_pct, 0, 100);
  const lowCloudCover = boundedNumber(value.cloud_cover_low_pct, 0, 100);
  const midCloudCover = boundedNumber(value.cloud_cover_mid_pct, 0, 100);
  const highCloudCover = boundedNumber(value.cloud_cover_high_pct, 0, 100);
  const cloudBase = value.cloud_base_m === null ? null : boundedNumber(value.cloud_base_m, 0, 60_000);
  const modelRunAt = requiredIsoTimestamp(value.model_run_at);
  const validAt = requiredIsoTimestamp(value.valid_at);
  const measuredAt = requiredIsoTimestamp(value.measured_at);
  const refreshedAt = requiredIsoTimestamp(value.refreshed_at);
  const sampleCount = normalizeBoundedInteger(value.sample_count, 0, 12);
  const expectedSampleCount = normalizeBoundedInteger(value.expected_sample_count, 1, 12);
  const source = normalizeForecastSource(value.source);
  const timestampsValid = modelRunAt !== null
    && validAt !== null
    && measuredAt !== null
    && refreshedAt !== null
    && Date.parse(modelRunAt) <= Date.parse(validAt)
    && Date.parse(measuredAt) === Date.parse(validAt)
    && Date.parse(refreshedAt) >= Date.parse(modelRunAt);
  const structurallyComplete = cloudCover !== null
    && lowCloudCover !== null
    && midCloudCover !== null
    && highCloudCover !== null
    && sampleCount !== null
    && expectedSampleCount !== null
    && expectedSampleCount === aggregationExpectedSampleCount
    && sampleCount === aggregationExpectedSampleCount
    && source.name !== 'Onbekend'
    && timestampsValid;
  const claimedCompleteButInvalid = value.complete === true && !structurallyComplete;
  const stale = value.stale === true || claimedCompleteButInvalid;

  return {
    complete: value.complete === true && structurallyComplete && !stale,
    stale,
    cloud_cover_pct: cloudCover,
    cloud_cover_low_pct: lowCloudCover,
    cloud_cover_mid_pct: midCloudCover,
    cloud_cover_high_pct: highCloudCover,
    cloud_base_m: cloudBase,
    model_run_at: modelRunAt,
    valid_at: validAt,
    measured_at: measuredAt,
    refreshed_at: refreshedAt,
    sample_count: sampleCount,
    expected_sample_count: expectedSampleCount,
    source,
    availability_note: normalizeOptionalText(value.availability_note, 400),
  };
}

function normalizeOperationalWeatherPrecipitation(
  value: Record<string, unknown>,
  aggregationExpectedSampleCount: number,
): OperationalWeatherPageState['precipitation'] {
  const radarPeak = boundedNumber(value.radar_peak_mm_h, 0, 1_000);
  const probability = boundedNumber(value.third_hour_probability_pct, 0, 100);
  const referenceTime = requiredIsoTimestamp(value.reference_time);
  const radarUntil = requiredIsoTimestamp(value.radar_until);
  const thirdHourFrom = requiredIsoTimestamp(value.third_hour_from);
  const forecastUntil = requiredIsoTimestamp(value.forecast_until);
  const measuredAt = requiredIsoTimestamp(value.measured_at);
  const refreshedAt = requiredIsoTimestamp(value.refreshed_at);
  const firstPrecipitationAt = optionalIsoTimestamp(value.radar_first_precipitation_at);
  const sampleCount = normalizeBoundedInteger(value.sample_count, 0, 12);
  const expectedSampleCount = normalizeBoundedInteger(value.expected_sample_count, 1, 12);
  const source = normalizeForecastSource(value.source);
  const referenceMillis = referenceTime === null ? Number.NaN : Date.parse(referenceTime);
  const radarUntilMillis = radarUntil === null ? Number.NaN : Date.parse(radarUntil);
  const thirdHourFromMillis = thirdHourFrom === null ? Number.NaN : Date.parse(thirdHourFrom);
  const forecastUntilMillis = forecastUntil === null ? Number.NaN : Date.parse(forecastUntil);
  const firstPrecipitationMillis = firstPrecipitationAt === null ? null : Date.parse(firstPrecipitationAt);
  const timestampsValid = Number.isFinite(referenceMillis)
    && radarUntilMillis === referenceMillis + 120 * 60 * 1_000
    && thirdHourFromMillis === radarUntilMillis
    && forecastUntilMillis === referenceMillis + 180 * 60 * 1_000
    && measuredAt !== null
    && Date.parse(measuredAt) === referenceMillis
    && refreshedAt !== null
    && Date.parse(refreshedAt) >= referenceMillis
    && (value.radar_first_precipitation_at === null
      ? firstPrecipitationAt === null
      : firstPrecipitationMillis !== null
        && firstPrecipitationMillis >= referenceMillis
        && firstPrecipitationMillis <= radarUntilMillis);
  const structurallyComplete = radarPeak !== null
    && probability !== null
    && sampleCount !== null
    && expectedSampleCount !== null
    && expectedSampleCount === aggregationExpectedSampleCount
    && sampleCount === aggregationExpectedSampleCount
    && source.name !== 'Onbekend'
    && timestampsValid;
  const claimedCompleteButInvalid = value.complete === true && !structurallyComplete;
  const stale = value.stale === true || claimedCompleteButInvalid;

  return {
    complete: value.complete === true && structurallyComplete && !stale,
    stale,
    radar_peak_mm_h: radarPeak,
    radar_first_precipitation_at: firstPrecipitationAt,
    radar_until: radarUntil,
    third_hour_probability_pct: probability,
    third_hour_from: thirdHourFrom,
    forecast_until: forecastUntil,
    reference_time: referenceTime,
    measured_at: measuredAt,
    refreshed_at: refreshedAt,
    sample_count: sampleCount,
    expected_sample_count: expectedSampleCount,
    source,
    availability_note: normalizeOptionalText(value.availability_note, 400),
  };
}

function normalizeWallboardForecastMetric(value: unknown): WallboardForecastMetric[] {
  if (!isRecord(value)) return [];
  if (
    !WALLBOARD_FORECAST_METRIC_KEYS.includes(value.key as WallboardForecastMetric['key'])
    || typeof value.label !== 'string'
    || !isRecord(value.source)
  ) return [];

  const stale = value.stale === true;
  const suppliedStatus = normalizeForecastStatus(value.status);
  const suppliedNumericValue = typeof value.value === 'number' && Number.isFinite(value.value) ? value.value : null;
  const precipitationOutlook = normalizeForecastPrecipitationOutlook(value.precipitation_outlook);
  const thunderstormOutlook = normalizeForecastThunderstormOutlook(value.thunderstorm_outlook);
  const structuredOutlookInvalid = (value.key === 'precipitation_outlook' && precipitationOutlook === null)
    || (value.key === 'thunderstorm_forecast' && thunderstormOutlook === null);
  const numericValue = structuredOutlookInvalid ? null : suppliedNumericValue;
  const measuredAt = requiredIsoTimestamp(value.measured_at);
  const source = normalizeForecastSource(value.source);
  const displayValue = numericValue !== null && (typeof value.display_value === 'string' || typeof value.display_value === 'number')
    ? String(value.display_value).trim().slice(0, 32)
    : null;

  return [{
    key: value.key as WallboardForecastMetric['key'],
    label: value.label.trim().slice(0, 80),
    value: numericValue,
    unit: typeof value.unit === 'string' ? value.unit.trim().slice(0, 16) : null,
    display_value: displayValue === '' ? null : displayValue,
    display_unit: displayValue !== null && typeof value.display_unit === 'string'
      ? value.display_unit.trim().slice(0, 16)
      : null,
    status: stale
      || numericValue === null
      || (suppliedStatus === 'green' && (measuredAt === null || source.name === 'Onbekend'))
      ? 'unknown'
      : suppliedStatus,
    stale,
    source,
    measured_at: measuredAt,
    explanation: typeof value.explanation === 'string'
      ? value.explanation.trim().slice(0, 300)
      : 'Geen gevalideerde toelichting beschikbaar.',
    altitude_m: normalizeBoundedInteger(value.altitude_m, 0, 10_000),
    source_height_label: typeof value.source_height_label === 'string'
      ? value.source_height_label.trim().slice(0, 120)
      : null,
    height_samples_agl_m: normalizeForecastWindSamples(value.height_samples_agl_m),
    max_non_red_wind_height_agl_m: normalizeBoundedInteger(value.max_non_red_wind_height_agl_m, 0, 500),
    cloud_layers: normalizeForecastCloudLayers(value.cloud_layers),
    cloud_base_forecast: normalizeForecastCloudBaseForecast(value.cloud_base_forecast),
    cloud_base_observation: normalizeForecastCloudBaseObservation(value.cloud_base_observation),
    precipitation_outlook: precipitationOutlook,
    thunderstorm_outlook: thunderstormOutlook,
  }];
}

function normalizeForecastPrecipitationOutlook(
  value: unknown,
): WallboardForecastMetric['precipitation_outlook'] {
  if (!isRecord(value) || !['KNMI', 'DIS_DEMO'].includes(String(value.attribution))) return null;
  const radarPeak = boundedNumber(value.radar_peak_mm_h, 0, 500);
  const probability = boundedNumber(value.third_hour_probability_pct, 0, 100);
  const sampleCount = normalizeBoundedInteger(value.sample_count, 1, 12);
  const expectedSampleCount = normalizeBoundedInteger(value.expected_sample_count, 1, 12);
  const radarFirstAt = optionalIsoTimestamp(value.radar_first_precipitation_at);
  const radarUntil = requiredIsoTimestamp(value.radar_until);
  const thirdHourFrom = requiredIsoTimestamp(value.third_hour_from);
  const forecastUntil = requiredIsoTimestamp(value.forecast_until);
  const referenceTime = requiredIsoTimestamp(value.reference_time);
  if (
    radarPeak === null
    || probability === null
    || sampleCount === null
    || expectedSampleCount === null
    || sampleCount !== expectedSampleCount
    || radarUntil === null
    || thirdHourFrom === null
    || forecastUntil === null
    || referenceTime === null
    || (value.radar_first_precipitation_at !== null && radarFirstAt === null)
  ) return null;

  return {
    radar_peak_mm_h: radarPeak,
    radar_first_precipitation_at: radarFirstAt,
    radar_until: radarUntil,
    third_hour_probability_pct: probability,
    third_hour_from: thirdHourFrom,
    forecast_until: forecastUntil,
    reference_time: referenceTime,
    sample_count: sampleCount,
    expected_sample_count: expectedSampleCount,
    attribution: value.attribution as 'KNMI' | 'DIS_DEMO',
  };
}

function normalizeForecastThunderstormOutlook(
  value: unknown,
): WallboardForecastMetric['thunderstorm_outlook'] {
  if (!isRecord(value) || !['OPEN_METEO', 'DIS_DEMO'].includes(String(value.attribution))) return null;
  const sampleCount = normalizeBoundedInteger(value.sample_count, 1, 12);
  const expectedSampleCount = normalizeBoundedInteger(value.expected_sample_count, 1, 12);
  const firstExpectedAt = optionalIsoTimestamp(value.first_expected_at);
  const forecastUntil = requiredIsoTimestamp(value.forecast_until);
  if (
    typeof value.expected !== 'boolean'
    || sampleCount === null
    || expectedSampleCount === null
    || sampleCount !== expectedSampleCount
    || forecastUntil === null
    || (value.first_expected_at !== null && firstExpectedAt === null)
    || (value.expected && firstExpectedAt === null)
  ) return null;

  return {
    expected: value.expected,
    first_expected_at: firstExpectedAt,
    forecast_until: forecastUntil,
    sample_count: sampleCount,
    expected_sample_count: expectedSampleCount,
    attribution: value.attribution as 'OPEN_METEO' | 'DIS_DEMO',
  };
}

function normalizeForecastCloudLayers(value: unknown): WallboardForecastMetric['cloud_layers'] {
  if (!isRecord(value)) return null;
  const low = boundedNumber(value.low_pct, 0, 100);
  const mid = boundedNumber(value.mid_pct, 0, 100);
  const high = boundedNumber(value.high_pct, 0, 100);
  const total = boundedNumber(value.total_pct, 0, 100);
  return low === null || mid === null || high === null || total === null
    ? null
    : { low_pct: low, mid_pct: mid, high_pct: high, total_pct: total };
}

function normalizeForecastCloudBaseForecast(
  value: unknown,
): WallboardForecastMetric['cloud_base_forecast'] {
  if (!isRecord(value)) return null;
  if (
    !['forecast', 'not_calculated', 'unknown'].includes(String(value.status))
    || value.height_reference !== 'model_unspecified'
    || (value.aggregation !== null && !['single_grid_point', 'minimum_of_province_samples'].includes(String(value.aggregation)))
    || !['KNMI_HARMONIE', 'DIS_DEMO'].includes(String(value.attribution))
  ) return null;

  const status = value.status as NonNullable<WallboardForecastMetric['cloud_base_forecast']>['status'];
  const aggregation = value.aggregation as NonNullable<WallboardForecastMetric['cloud_base_forecast']>['aggregation'];
  const attribution = value.attribution as NonNullable<WallboardForecastMetric['cloud_base_forecast']>['attribution'];
  const baseHeight = normalizeBoundedInteger(value.base_height_m, 0, 20_000);
  const sampleCount = normalizeBoundedInteger(value.sample_count, 0, 100);
  const modelRunAt = requiredIsoTimestamp(value.model_run_at);
  const validAt = requiredIsoTimestamp(value.valid_at);

  if (sampleCount === null) return null;
  if (status === 'unknown') {
    return baseHeight === null && sampleCount === 0 && modelRunAt === null && validAt === null
      ? {
          status,
          base_height_m: null,
          height_reference: 'model_unspecified',
          aggregation,
          sample_count: 0,
          model_run_at: null,
          valid_at: null,
          attribution,
        }
      : null;
  }
  if (aggregation === null || modelRunAt === null || validAt === null) return null;
  if (status === 'forecast' && (baseHeight === null || sampleCount < 1)) return null;
  if (status === 'not_calculated' && (baseHeight !== null || sampleCount !== 0)) return null;

  return {
    status,
    base_height_m: baseHeight,
    height_reference: 'model_unspecified',
    aggregation,
    sample_count: sampleCount,
    model_run_at: modelRunAt,
    valid_at: validAt,
    attribution,
  };
}

function normalizeForecastCloudBaseObservation(
  value: unknown,
): WallboardForecastMetric['cloud_base_observation'] {
  if (!isRecord(value)) return null;
  if (
    !['measured', 'no_cloud_detected', 'unknown'].includes(String(value.status))
    || value.period_minutes !== 30
    || value.height_reference !== 'mean_sea_level'
    || !['KNMI', 'DIS_DEMO'].includes(String(value.attribution))
    || !Array.isArray(value.layers)
    || value.layers.length > 3
  ) return null;

  const status = value.status as NonNullable<WallboardForecastMetric['cloud_base_observation']>['status'];
  const attribution = value.attribution as NonNullable<WallboardForecastMetric['cloud_base_observation']>['attribution'];
  const baseHeight = normalizeBoundedInteger(value.base_height_m, 0, 20_000);
  const layers = value.layers.flatMap((candidate) => {
    if (!isRecord(candidate)) return [];
    const height = normalizeBoundedInteger(candidate.height_m, 0, 20_000);
    const cover = candidate.cover_okta === null ? null : normalizeBoundedInteger(candidate.cover_okta, 0, 8);
    return height === null || (candidate.cover_okta !== null && cover === null)
      ? []
      : [{ height_m: height, cover_okta: cover }];
  });
  if (layers.length !== value.layers.length) return null;

  const station = isRecord(value.station)
    && typeof value.station.id === 'string'
    && value.station.id.trim() !== ''
    && typeof value.station.name === 'string'
    && value.station.name.trim() !== ''
    && boundedNumber(value.station.distance_km, 0, 1_000) !== null
    ? {
        id: value.station.id.trim().slice(0, 80),
        name: value.station.name.trim().slice(0, 100),
        distance_km: value.station.distance_km as number,
      }
    : null;
  const observedAt = requiredIsoTimestamp(value.observed_at);

  if (status === 'unknown') {
    return baseHeight === null && layers.length === 0 && station === null && observedAt === null
      ? {
          status,
          base_height_m: null,
          height_reference: 'mean_sea_level',
          layers: [],
          station: null,
          observed_at: null,
          period_minutes: 30,
          attribution,
        }
      : null;
  }
  if (station === null || observedAt === null) return null;
  if (status === 'measured' && baseHeight === null) return null;
  if (status === 'no_cloud_detected' && (baseHeight !== null || layers.length !== 0)) return null;

  return {
    status,
    base_height_m: baseHeight,
    height_reference: 'mean_sea_level',
    layers,
    station,
    observed_at: observedAt,
    period_minutes: 30,
    attribution,
  };
}

function normalizeWallboardForecastAggregation(
  value: unknown,
  locationMode: WallboardForecastPageState['location']['mode'],
): WallboardForecastPageState['aggregation'] {
  const expectedType = locationMode === 'netherlands' ? 'province_average' : 'single_location';
  const expectedSampleCount = locationMode === 'netherlands' ? 12 : 1;
  if (!isRecord(value)) {
    return {
      type: expectedType,
      sample_count: 0,
      expected_sample_count: expectedSampleCount,
      complete: false,
      fresh: false,
    };
  }
  const sampleCount = normalizeBoundedInteger(value.sample_count, 0, expectedSampleCount) ?? 0;
  const contractMatches = value.type === expectedType
    && value.expected_sample_count === expectedSampleCount;
  const complete = contractMatches && value.complete === true && sampleCount === expectedSampleCount;
  return {
    type: expectedType,
    sample_count: sampleCount,
    expected_sample_count: expectedSampleCount,
    complete,
    fresh: complete && value.fresh === true,
  };
}

function operationalWeatherAggregationContractIsValid(
  value: Record<string, unknown>,
  locationMode: WallboardForecastPageState['location']['mode'],
): boolean {
  const expectedType = locationMode === 'netherlands' ? 'province_average' : 'single_location';
  const expectedSampleCount = locationMode === 'netherlands' ? 12 : 1;
  const sampleCount = normalizeBoundedInteger(value.sample_count, 0, expectedSampleCount);
  if (
    value.type !== expectedType
    || value.expected_sample_count !== expectedSampleCount
    || sampleCount === null
  ) return false;

  if (value.complete === true && sampleCount !== expectedSampleCount) return false;
  if (value.fresh === true && value.complete !== true) return false;
  return true;
}

function normalizeWallboardForecastCondition(
  value: unknown,
  metrics: WallboardForecastMetric[],
): WallboardForecastPageState['condition'] {
  const weatherMetric = metrics.find((metric) => metric.key === 'weather_code');
  if (!isRecord(value)) {
    return {
      code: weatherMetric?.value ?? null,
      label: 'Onbekend',
      status: 'unknown',
      stale: weatherMetric?.stale ?? false,
      source: weatherMetric?.source ?? { name: 'Onbekend', url: null },
      measured_at: weatherMetric?.measured_at ?? null,
    };
  }
  const code = normalizeBoundedInteger(value.code, 0, 99);
  const source = normalizeForecastSource(value.source);
  const measuredAt = requiredIsoTimestamp(value.measured_at);
  const suppliedStatus = normalizeForecastStatus(value.status);
  const metricMatches = weatherMetric !== undefined
    && weatherMetric.value === code
    && weatherMetric.status === suppliedStatus
    && !weatherMetric.stale;
  const stale = value.stale === true || weatherMetric?.stale === true;
  return {
    code,
    label: typeof value.label === 'string' && value.label.trim() !== ''
      ? value.label.trim().slice(0, 80)
      : 'Onbekend',
    status: stale || code === null || source.name === 'Onbekend' || measuredAt === null || !metricMatches
      ? 'unknown'
      : suppliedStatus,
    stale,
    source,
    measured_at: measuredAt,
  };
}

function normalizeWallboardForecastDaylight(value: unknown): WallboardForecastPageState['daylight'] {
  const daylightValue = isRecord(value) ? value : {};
  const sunriseEarliest = requiredIsoTimestamp(daylightValue.sunrise_earliest);
  const sunriseLatest = requiredIsoTimestamp(daylightValue.sunrise_latest);
  const sunsetEarliest = requiredIsoTimestamp(daylightValue.sunset_earliest);
  const sunsetLatest = requiredIsoTimestamp(daylightValue.sunset_latest);
  const source = normalizeForecastSource(daylightValue.source);
  return {
    timezone: typeof daylightValue.timezone === 'string' && daylightValue.timezone.trim() !== ''
      ? daylightValue.timezone.trim().slice(0, 64)
      : 'UTC',
    sunrise_earliest: sunriseEarliest,
    sunrise_latest: sunriseLatest,
    sunset_earliest: sunsetEarliest,
    sunset_latest: sunsetLatest,
    stale: daylightValue.stale === true
      || source.name === 'Onbekend'
      || [sunriseEarliest, sunriseLatest, sunsetEarliest, sunsetLatest].some((candidate) => candidate === null),
    source,
  };
}

function normalizeWallboardForecastWindProfile(
  value: unknown,
  metrics: WallboardForecastMetric[],
): WallboardForecastPageState['wind_profile'] {
  const windMetric = metrics.find((metric) => metric.key === 'wind_speed_kmh');
  const profile = isRecord(value) ? value : {};
  const samples = normalizeForecastWindSamples(profile.samples);
  return {
    samples: samples.length > 0 ? samples : (windMetric?.height_samples_agl_m ?? []),
    max_non_red_wind_height_agl_m: normalizeBoundedInteger(profile.max_non_red_wind_height_agl_m, 0, 500)
      ?? windMetric?.max_non_red_wind_height_agl_m
      ?? null,
    stale: profile.stale === true || windMetric?.stale === true,
  };
}

function normalizeForecastWindSamples(value: unknown): WallboardForecastMetric['height_samples_agl_m'] {
  if (!Array.isArray(value)) return [];
  return value.slice(0, 8).flatMap((sample) => {
    if (!isRecord(sample)) return [];
    const height = normalizeBoundedInteger(sample.height_agl_m, 0, 500);
    const speed = typeof sample.speed_kmh === 'number' && Number.isFinite(sample.speed_kmh) && sample.speed_kmh >= 0
      ? sample.speed_kmh
      : null;
    return height === null ? [] : [{ height_agl_m: height, speed_kmh: speed }];
  });
}

function normalizeForecastSource(value: unknown): WallboardForecastSource {
  if (!isRecord(value)) return { name: 'Onbekend', url: null };
  return {
    name: typeof value.name === 'string' && value.name.trim() !== ''
      ? value.name.trim().slice(0, 80)
      : 'Onbekend',
    url: typeof value.url === 'string' ? value.url.slice(0, 2048) : null,
  };
}

function daylightIsComplete(daylight: WallboardForecastPageState['daylight']): boolean {
  return [
    daylight.sunrise_earliest,
    daylight.sunrise_latest,
    daylight.sunset_earliest,
    daylight.sunset_latest,
  ].every((value) => value !== null);
}

function structuredAdviceContractsAreValid(
  metrics: WallboardForecastMetric[],
  expectedSampleCount: number,
): boolean {
  const precipitation = metrics.find((metric) => metric.key === 'precipitation_outlook')?.precipitation_outlook;
  const thunderstorm = metrics.find((metric) => metric.key === 'thunderstorm_forecast')?.thunderstorm_outlook;
  return precipitation !== null
    && precipitation !== undefined
    && precipitation.sample_count === expectedSampleCount
    && precipitation.expected_sample_count === expectedSampleCount
    && thunderstorm !== null
    && thunderstorm !== undefined
    && thunderstorm.sample_count === expectedSampleCount
    && thunderstorm.expected_sample_count === expectedSampleCount;
}

function normalizeForecastStatus(value: unknown): WallboardForecastMetric['status'] {
  return value === 'green' || value === 'orange' || value === 'red' || value === 'unknown'
    ? value
    : 'unknown';
}

function normalizeForecastCoordinate(value: unknown, minimum: number, maximum: number): number | null {
  return boundedNumber(value, minimum, maximum);
}

function normalizeBoundedInteger(value: unknown, minimum: number, maximum: number): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value >= minimum && value <= maximum
    ? value
    : null;
}

function boundedNumber(value: unknown, minimum: number, maximum: number): number | null {
  return typeof value === 'number' && Number.isFinite(value) && value >= minimum && value <= maximum
    ? value
    : null;
}

function optionalIsoTimestamp(value: unknown): string | null {
  return value === null ? null : requiredIsoTimestamp(value);
}

function requiredIsoTimestamp(value: unknown): string | null {
  return typeof value === 'string' && Number.isFinite(Date.parse(value)) ? value : null;
}

function normalizeOptionalText(value: unknown, maximumLength: number): string | null {
  return typeof value === 'string' && value.trim() !== ''
    ? value.trim().slice(0, maximumLength)
    : null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
