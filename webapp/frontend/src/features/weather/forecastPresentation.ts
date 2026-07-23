import type {
  WallboardForecastBlockKey,
  WallboardForecastMetric,
  WallboardForecastPageState,
} from '../../types/api';
import { WALLBOARD_FORECAST_BLOCK_KEYS } from '../wallboards/wallboardPresentation';

const FORECAST_TIME_FORMATTER = new Intl.DateTimeFormat('nl-NL', {
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
  timeZone: 'Europe/Amsterdam',
});

export interface WallboardForecastDisplayBlock {
  key: WallboardForecastBlockKey;
  label: string;
  value: string;
  status: WallboardForecastMetric['status'];
  stale: boolean;
  details: string[];
  explanation: string;
}

export interface ForecastAdvice {
  label: string;
  description: string;
}

export function wallboardForecastDisplayBlocks(
  forecast: WallboardForecastPageState,
): WallboardForecastDisplayBlock[] {
  const metrics = new Map(forecast.metrics.map((metric) => [metric.key, metric]));
  const metric = (key: WallboardForecastMetric['key']) => metrics.get(key);
  const simple = (
    key: WallboardForecastBlockKey,
    metricKey: WallboardForecastMetric['key'],
    fallbackLabel: string,
  ): WallboardForecastDisplayBlock => forecastMetricDisplayBlock(key, metric(metricKey), fallbackLabel);
  const temperature = metric('temperature_c');
  const dewPoint = metric('dew_point_c');
  const precipitationProbability = metric('precipitation_probability_pct');
  const precipitation = metric('precipitation_mm');
  const precipitationOutlook = metric('precipitation_outlook');
  const thunderstormForecast = metric('thunderstorm_forecast');
  const windSpeed = metric('wind_speed_kmh');
  const totalCloudCover = metric('cloud_cover_pct');
  const lowCloudCover = metric('low_cloud_cover_pct');
  const daylightComplete = [
    forecast.daylight.sunrise_earliest,
    forecast.daylight.sunrise_latest,
    forecast.daylight.sunset_earliest,
    forecast.daylight.sunset_latest,
  ].every((value) => value !== null);
  const daylightStatus: WallboardForecastMetric['status'] = forecast.daylight.stale || !daylightComplete
    ? 'unknown'
    : 'green';

  const availableBlocks = new Map<WallboardForecastBlockKey, WallboardForecastDisplayBlock>([
    ['weather', {
      key: 'weather',
      label: 'Weer',
      value: forecast.condition.label || 'Onbekend',
      status: forecast.condition.stale ? 'unknown' : forecast.condition.status,
      stale: forecast.condition.stale,
      details: forecast.condition.code === null ? [] : [`WMO-code ${forecast.condition.code}`],
      explanation: metric('weather_code')?.explanation ?? 'De actuele weersituatie kon niet betrouwbaar worden vastgesteld.',
    }],
    ['daylight', {
      key: 'daylight',
      label: 'Daglicht',
      value: `Op ${forecastTimeRange(forecast.daylight.sunrise_earliest, forecast.daylight.sunrise_latest)}`,
      status: daylightStatus,
      stale: forecast.daylight.stale,
      details: [`Onder ${forecastTimeRange(forecast.daylight.sunset_earliest, forecast.daylight.sunset_latest)}`],
      explanation: forecast.daylight.stale
        ? 'De laatst bekende daglichttijden zijn verouderd.'
        : 'Zonsopkomst en zonsondergang voor het gekozen gebied.',
    }],
    ['temperature', {
      ...forecastMetricDisplayBlock('temperature', temperature, 'Temperatuur'),
      status: worstForecastStatus([temperature?.status, dewPoint?.status]),
      stale: temperature?.stale === true || dewPoint?.stale === true,
      details: [`Dauwpunt ${formatForecastMetricValue(dewPoint)}`],
      explanation: dewPoint?.explanation ?? temperature?.explanation ?? 'Temperatuurdata is niet beschikbaar.',
    }],
    ['wind_speed', {
      ...forecastMetricDisplayBlock('wind_speed', windSpeed, 'Windsnelheid'),
      details: [
        forecastWindProfileLabel(forecast),
        forecast.wind_profile.max_non_red_wind_height_agl_m === null
          ? 'Niet-rode vlieghoogte onbekend'
          : `Niet-rood t/m ${forecast.wind_profile.max_non_red_wind_height_agl_m} m AGL`,
      ],
    }],
    ['wind_gust', simple('wind_gust', 'wind_gust_kmh', 'Windstoten')],
    ['wind_direction', simple('wind_direction', 'wind_direction_degrees', 'Windrichting')],
    ['precipitation_probability', {
      ...forecastMetricDisplayBlock('precipitation_probability', precipitationProbability, 'Neerslagkans'),
      status: worstForecastStatus([precipitationProbability?.status, precipitation?.status]),
      stale: precipitationProbability?.stale === true || precipitation?.stale === true,
      details: [`Neerslag ${formatForecastMetricValue(precipitation)}`],
    }],
    ['precipitation_outlook', forecastPrecipitationOutlookDisplayBlock(precipitationOutlook)],
    ['thunderstorm_forecast', forecastThunderstormDisplayBlock(thunderstormForecast)],
    ['cloud_cover', forecastCloudCoverDisplayBlock(lowCloudCover, totalCloudCover)],
    ['visibility', simple('visibility', 'visibility_m', 'Zichtbaarheid')],
    ['gnss_visible', simple('gnss_visible', 'gnss_satellites', 'Zichtbare satellieten')],
    ['kp_index', simple('kp_index', 'kp_index', 'Kp-index')],
    ['gnss_usable', simple('gnss_usable', 'gnss_satellites_fix', 'Bruikbare satellieten')],
  ]);
  const selected = new Set(forecast.visible_blocks);

  return WALLBOARD_FORECAST_BLOCK_KEYS
    .filter((key) => selected.has(key))
    .flatMap((key) => {
      const block = availableBlocks.get(key);
      return block === undefined ? [] : [block];
    });
}

export function wallboardForecastAllDisplayBlocks(
  forecast: WallboardForecastPageState,
): WallboardForecastDisplayBlock[] {
  return wallboardForecastDisplayBlocks({
    ...forecast,
    visible_blocks: [...WALLBOARD_FORECAST_BLOCK_KEYS],
  });
}

function forecastPrecipitationOutlookDisplayBlock(
  metric: WallboardForecastMetric | undefined,
): WallboardForecastDisplayBlock {
  const base = forecastMetricDisplayBlock('precipitation_outlook', metric, 'Buien +3 uur');
  const outlook = metric?.precipitation_outlook;
  if (metric?.stale === true) {
    return {
      ...base,
      label: 'Buien +3 uur',
      value: 'Verouderd',
      details: [],
    };
  }
  if (outlook === null || outlook === undefined) {
    return metric?.status === 'unknown'
      ? { ...base, label: 'Buien +3 uur', value: 'Onbekend', details: [] }
      : base;
  }

  const dryThroughRadar = outlook.radar_peak_mm_h < 0.1;
  const probabilityDetail = outlook.third_hour_probability_pct !== null
      && outlook.third_hour_probability_status !== 'unknown'
    ? `Uur 3 kansmodel: ${formatForecastNumber(outlook.third_hour_probability_pct)}% kans op ≥ 0,1 mm/u`
    : 'Uur 3 kansmodel: niet beschikbaar';
  return {
    ...base,
    label: 'Buien +3 uur',
    value: outlook.radar_first_precipitation_at !== null
      ? `Bui vanaf ${formatWallboardForecastUpdateTime(outlook.radar_first_precipitation_at)}`
      : dryThroughRadar
        ? 'Droog tot +2 uur'
        : 'Lichte neerslag',
    details: [
      `0–2 uur radar: piek ${formatForecastNumber(outlook.radar_peak_mm_h)} mm/u`,
      probabilityDetail,
    ],
  };
}

function forecastThunderstormDisplayBlock(
  metric: WallboardForecastMetric | undefined,
): WallboardForecastDisplayBlock {
  const base = forecastMetricDisplayBlock('thunderstorm_forecast', metric, 'Onweer +3 uur');
  const outlook = metric?.thunderstorm_outlook;
  if (metric?.stale === true || metric?.status === 'unknown') {
    return {
      ...base,
      label: 'Onweer +3 uur',
      value: metric.stale ? 'Verouderd' : 'Onbekend',
      details: ['Geen live bliksemdetectie'],
    };
  }
  if (outlook === null || outlook === undefined) return base;

  return {
    ...base,
    label: 'Onweer +3 uur',
    value: outlook.expected
      ? `Verwacht${outlook.first_expected_at === null ? '' : ` vanaf ${formatWallboardForecastUpdateTime(outlook.first_expected_at)}`}`
      : 'Niet verwacht',
    details: [
      `WMO-modelverwachting t/m ${formatWallboardForecastUpdateTime(outlook.forecast_until)}`,
      'Geen live bliksemdetectie',
    ],
  };
}

function forecastCloudCoverDisplayBlock(
  lowCloudCover: WallboardForecastMetric | undefined,
  totalCloudCover: WallboardForecastMetric | undefined,
): WallboardForecastDisplayBlock {
  const metric = lowCloudCover ?? totalCloudCover;
  const block = forecastMetricDisplayBlock(
    'cloud_cover',
    metric,
    lowCloudCover === undefined ? 'Totale modelbewolking' : 'Lage bewolking',
  );
  if (lowCloudCover === undefined) return block;

  const layers = lowCloudCover.cloud_layers;
  const forecast = lowCloudCover.cloud_base_forecast;
  const observation = lowCloudCover.cloud_base_observation;
  return {
    ...block,
    details: [
      ...forecastCloudBaseForecastDetails(forecast),
      ...forecastCloudBaseObservationDetails(observation),
      ...(forecast !== null && forecast.status !== 'unknown' ? [] : [
        lowCloudCover.source_height_label
          ?? 'KNMI HARMONIE-categorie lage bewolking; KNMI publiceert hiervoor geen vaste hoogteband',
      ]),
      ...(layers === null ? [] : [
        `Modelbewolking: laag ${formatForecastNumber(layers.low_pct)}%; middelbaar ${formatForecastNumber(layers.mid_pct)}%; hoog ${formatForecastNumber(layers.high_pct)}%; totaal ${formatForecastNumber(layers.total_pct)}%`,
      ]),
    ],
  };
}

function forecastCloudBaseForecastDetails(
  forecast: WallboardForecastMetric['cloud_base_forecast'],
): string[] {
  const isDemo = forecast?.attribution === 'DIS_DEMO';
  if (forecast === null || forecast.status === 'unknown') {
    return [isDemo
      ? 'Demo-modelverwachting wolkenbasis niet beschikbaar'
      : 'Modelverwachting wolkenbasis niet beschikbaar'];
  }

  const timing = forecast.model_run_at === null || forecast.valid_at === null
    ? null
    : `Geldig ${formatWallboardForecastUpdateTime(forecast.valid_at)}; modelrun ${formatWallboardForecastUpdateTime(forecast.model_run_at)}${forecastCloudBaseAggregationLabel(forecast)}`;
  if (forecast.status === 'not_calculated') {
    return [
      `${isDemo ? 'Demo-modelverwachting' : 'Modelverwachting'}: geen wolkenbasis berekend voor dit forecastuur`,
      ...(timing === null ? [] : [timing]),
    ];
  }

  return [
    `${isDemo ? 'Demo-modelverwachting wolkenbasis' : 'Modelverwachting wolkenbasis'}: ${formatForecastNumber(forecast.base_height_m ?? 0)} m (hoogtereferentie niet gespecificeerd)`,
    ...(timing === null ? [] : [timing]),
  ];
}

function forecastCloudBaseAggregationLabel(
  forecast: NonNullable<WallboardForecastMetric['cloud_base_forecast']>,
): string {
  if (forecast.sample_count < 1) {
    return '';
  }
  if (forecast.aggregation === 'minimum_of_province_samples') {
    return `; minimum van ${forecast.sample_count} provinciepunten`;
  }
  if (forecast.aggregation === 'single_grid_point') {
    return '; één modelrasterpunt';
  }

  return '';
}

function forecastCloudBaseObservationDetails(
  observation: WallboardForecastMetric['cloud_base_observation'],
): string[] {
  if (observation === null || observation.status === 'unknown') {
    return [observation?.attribution === 'DIS_DEMO'
      ? 'Demo-meting wolkenbasis niet beschikbaar'
      : 'Gemeten wolkenbasis niet beschikbaar'];
  }

  const isDemo = observation.attribution === 'DIS_DEMO';
  const station = observation.station;
  const stationDetail = station === null || observation.observed_at === null
    ? null
    : `${isDemo ? 'Demo-meetpunt' : 'Meetstation'} ${station.name} (${formatForecastNumber(station.distance_km)} km), ${formatWallboardForecastUpdateTime(observation.observed_at)}; kaartkleur volgt model`;
  if (observation.status === 'no_cloud_detected') {
    return [
      `${isDemo ? 'Demo-meting' : 'Gemeten'}: in ${observation.period_minutes} min geen wolkenbasis gedetecteerd`,
      ...(stationDetail === null ? [] : [stationDetail]),
    ];
  }

  const baseLayer = observation.layers.find((layer) => layer.height_m === observation.base_height_m);
  const cover = baseLayer?.cover_okta === null || baseLayer?.cover_okta === undefined
    ? ''
    : ` (${baseLayer.cover_okta}/8 bewolkt)`;
  return [
    `${isDemo ? 'Demo-meting wolkenbasis' : 'Gemeten wolkenbasis'}: ${formatForecastNumber(observation.base_height_m ?? 0)} m boven zeeniveau${cover} (laagste in ${observation.period_minutes} min)`,
    ...(stationDetail === null ? [] : [stationDetail]),
  ];
}

function forecastMetricDisplayBlock(
  key: WallboardForecastBlockKey,
  metric: WallboardForecastMetric | undefined,
  fallbackLabel: string,
): WallboardForecastDisplayBlock {
  if (metric === undefined) {
    return {
      key,
      label: fallbackLabel,
      value: 'Onbekend',
      status: 'unknown',
      stale: false,
      details: [],
      explanation: 'Deze waarde ontbreekt in de actuele serverstate.',
    };
  }

  return {
    key,
    label: metric.label || fallbackLabel,
    value: formatForecastMetricValue(metric),
    status: metric.status,
    stale: metric.stale,
    details: metric.source_height_label === null ? [] : [metric.source_height_label],
    explanation: metric.explanation,
  };
}

export function formatForecastMetricValue(metric: WallboardForecastMetric | undefined): string {
  if (metric === undefined || metric.value === null) return 'Onbekend';
  if (metric.display_value !== null) {
    return `${metric.display_value}${metric.display_unit === null ? '' : ` ${metric.display_unit}`}`;
  }
  if (metric.unit === 'Kp') return `Kp ${formatForecastNumber(metric.value)}`;
  return `${formatForecastNumber(metric.value)}${metric.unit === null ? '' : ` ${metric.unit}`}`;
}

function forecastWindProfileLabel(forecast: WallboardForecastPageState): string {
  if (forecast.wind_profile.samples.length === 0) return 'Windprofiel onbekend';
  return forecast.wind_profile.samples
    .map((sample) => `${sample.height_agl_m} m: ${sample.speed_kmh === null ? 'onbekend' : `${formatForecastNumber(sample.speed_kmh)} km/u`}`)
    .join(' · ');
}

export function forecastTimeRange(earliest: string | null, latest: string | null): string {
  if (earliest === null || latest === null) return 'onbekend';
  const earliestDate = new Date(earliest);
  const latestDate = new Date(latest);
  if (!Number.isFinite(earliestDate.getTime()) || !Number.isFinite(latestDate.getTime())) return 'onbekend';
  const first = FORECAST_TIME_FORMATTER.format(earliestDate);
  const last = FORECAST_TIME_FORMATTER.format(latestDate);
  return first === last ? first : `${first}–${last}`;
}

export function formatWallboardForecastUpdateTime(value: string): string {
  const date = new Date(value);
  return Number.isFinite(date.getTime()) ? FORECAST_TIME_FORMATTER.format(date) : 'onbekend';
}

function worstForecastStatus(
  statuses: Array<WallboardForecastMetric['status'] | undefined>,
): WallboardForecastMetric['status'] {
  if (statuses.includes('red')) return 'red';
  if (statuses.includes('orange')) return 'orange';
  if (statuses.includes('unknown') || statuses.includes(undefined)) return 'unknown';
  return 'green';
}

export function forecastAggregationLabel(forecast: WallboardForecastPageState): string {
  if (forecast.aggregation.type === 'province_average') {
    return `Gemiddelde van ${forecast.aggregation.sample_count}/${forecast.aggregation.expected_sample_count} provincies`;
  }
  return forecast.aggregation.complete ? 'Actuele locatieberekening compleet' : 'Locatieberekening onvolledig';
}

export function forecastAdvice(status: WallboardForecastPageState['overall_status']): ForecastAdvice {
  switch (status) {
    case 'green': return {
      label: 'Binnen standaarddrempels',
      description: 'Alle actuele, beoordeelde waarden vallen binnen de centrale groene drempels.',
    };
    case 'orange': return {
      label: 'Extra beoordeling vereist',
      description: 'Minimaal één waarde vraagt om een expliciete operationele afweging.',
    };
    case 'red': return {
      label: 'Niet vliegen',
      description: 'Minimaal één waarde overschrijdt de centrale rode standaarddrempel.',
    };
    case 'unknown': return {
      label: 'Advies onvolledig',
      description: 'Minimaal één noodzakelijke waarde ontbreekt of is verouderd; dit is nooit een groen advies.',
    };
  }
}

function formatForecastNumber(value: number): string {
  return new Intl.NumberFormat('nl-NL', { maximumFractionDigits: 2 }).format(value);
}
