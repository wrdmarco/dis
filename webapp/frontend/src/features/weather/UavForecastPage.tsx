import {
  Activity,
  AlertTriangle,
  Cloud,
  CloudLightning,
  CloudRain,
  CloudSun,
  Eye,
  Gauge,
  Navigation,
  Plane,
  Radar,
  Satellite,
  SatelliteDish,
  Sunrise,
  Thermometer,
  Wind,
  type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';
import { formatDateTime } from '../../lib/dateTime';
import type {
  WallboardForecastBlockKey,
  WallboardForecastMetric,
  WallboardForecastPageState,
  WallboardForecastSource,
} from '../../types/api';
import { ForecastLocationControl } from './ForecastLocationControl';
import { ForecastError, ForecastLoading } from './ForecastStates';
import {
  forecastAdvice,
  forecastAggregationLabel,
  wallboardForecastAllDisplayBlocks,
  type WallboardForecastDisplayBlock,
} from './forecastPresentation';
import {
  markWallboardForecastStale,
  normalizeUavForecastPage,
} from './forecastNormalization';
import styles from './OperationalForecast.module.css';
import {
  DEFAULT_FORECAST_LOCATION,
  type ForecastLocationQuery,
  useForecastResource,
} from './useForecastResource';

interface ForecastMetricSource {
  source: WallboardForecastSource;
  measuredAt: string | null;
  timeLabel: 'Berekend' | 'Bronmoment';
}

const FORECAST_METRIC_KEY_BY_BLOCK: Partial<Record<WallboardForecastBlockKey, WallboardForecastMetric['key']>> = {
  temperature: 'temperature_c',
  wind_speed: 'wind_speed_kmh',
  wind_gust: 'wind_gust_kmh',
  wind_direction: 'wind_direction_degrees',
  precipitation_probability: 'precipitation_probability_pct',
  precipitation_outlook: 'precipitation_outlook',
  thunderstorm_forecast: 'thunderstorm_forecast',
  cloud_cover: 'low_cloud_cover_pct',
  visibility: 'visibility_m',
  kp_index: 'kp_index',
  gnss_visible: 'gnss_satellites',
  gnss_usable: 'gnss_satellites_fix',
};

export function UavForecastPage() {
  const [location, setLocation] = useState<ForecastLocationQuery>(DEFAULT_FORECAST_LOCATION);
  const resource = useForecastResource<WallboardForecastPageState>(
    '/uav-forecast',
    location,
    normalizeUavForecastPage,
  );
  const forecast = resource.data === null
    ? null
    : resource.stale
      ? markWallboardForecastStale(resource.data)
      : resource.data;

  function applyLocation(next: ForecastLocationQuery) {
    if (next.mode === location.mode && next.label === location.label) {
      void resource.refresh();
      return;
    }
    setLocation(next);
  }

  return (
    <div className={`page-stack ${styles.page}`}>
      <header className={`${styles.pageHero} ${styles.uavHero}`}>
        <span className={styles.eyebrow}><Plane aria-hidden size={15} /> Operationele beslisondersteuning</span>
        <div className={styles.heroTitleRow}>
          <div>
            <h1>UAV Forecast</h1>
            <p>Server-beoordeelde weerwaarden, modeldata, daglicht en ruimteweer voor de vluchtvoorbereiding.</p>
          </div>
          <span className={styles.heroIcon} aria-hidden><Navigation size={31} /></span>
        </div>
        <p className={styles.heroNote}>Ontbrekende, ongeldige of verouderde veiligheidsdata worden nooit als groen advies getoond.</p>
      </header>

      <ForecastLocationControl
        busy={resource.busy}
        location={location}
        onApply={applyLocation}
        onRefresh={() => void resource.refresh()}
      />

      {resource.stale && forecast ? (
        <div className={styles.inlineWarning} role="alert">
          <AlertTriangle aria-hidden size={18} />
          <span>
            De laatst opgehaalde forecast is verlopen en daarom als onbekend gemarkeerd.
            {resource.refreshing ? ' Er wordt een nieuwe forecast opgehaald.' : ''}
            {resource.error ? ` ${resource.error}` : ''}
          </span>
        </div>
      ) : null}

      {resource.loading ? (
        <ForecastLoading label="UAV Forecast samenstellen" />
      ) : forecast ? (
        <UavForecastOverview forecast={forecast} />
      ) : (
        <ForecastError message={resource.error} onRetry={() => void resource.refresh()} />
      )}
    </div>
  );
}

function UavForecastOverview({ forecast }: { forecast: WallboardForecastPageState }) {
  const advice = forecastAdvice(forecast.overall_status);
  const blocks = wallboardForecastAllDisplayBlocks(forecast);

  return (
    <>
      <section
        className={`${styles.adviceBanner} ${styles[`adviceBanner_${forecast.overall_status}`]}`}
        aria-labelledby="uav-advice-title"
      >
        <span className={styles.adviceSignal} aria-hidden />
        <div className={styles.adviceCopy}>
          <span className={styles.dataLabel}>Vliegadvies · {forecast.location.label}</span>
          <h2 id="uav-advice-title">{advice.label}</h2>
          <p>{advice.description}</p>
        </div>
        <dl className={styles.adviceFacts}>
          <div><dt>Berekening</dt><dd>{forecastAggregationLabel(forecast)}</dd></div>
          <div><dt>Samengesteld</dt><dd>{formatDateTime(forecast.generated_at)}</dd></div>
        </dl>
      </section>

      <section className={styles.metricSection} aria-labelledby="uav-metrics-title">
        <header className={styles.metricSectionHeader}>
          <div>
            <span className={styles.sectionKicker}>Centrale drempels</span>
            <h2 id="uav-metrics-title">Beoordeelde vluchtwaarden</h2>
          </div>
          <span>Alle {blocks.length} adviesonderdelen</span>
        </header>
        {blocks.length > 0 ? (
          <div className={styles.metricGrid}>
            {blocks.map((block) => (
              <ForecastMetricCard
                block={block}
                key={block.key}
                source={forecastSourceForBlock(block.key, forecast)}
              />
            ))}
          </div>
        ) : (
          <div className={styles.inlineWarning} role="alert">
            <AlertTriangle aria-hidden size={18} />
            <span>De server heeft geen forecastonderdelen geleverd. Het vliegadvies is daarom niet volledig.</span>
          </div>
        )}
      </section>

      <section className={styles.forecastNotes} aria-labelledby="forecast-notes-title">
        <header>
          <h2 id="forecast-notes-title">Reikwijdte en bronnen</h2>
          <span>Bron en meettijd staan bij iedere waarde</span>
        </header>
        <p>{forecast.scope_note}</p>
        <p className={styles.disclaimer}>{forecast.disclaimer}</p>
      </section>
    </>
  );
}

function ForecastMetricCard({
  block,
  source,
}: {
  block: WallboardForecastDisplayBlock;
  source: ForecastMetricSource | null;
}) {
  const Icon = forecastBlockIcon(block.key);
  return (
    <article className={`${styles.metricCard} ${styles[`metricCard_${block.status}`]}`}>
      <header>
        <span className={styles.metricIcon} aria-hidden><Icon size={20} /></span>
        <h3>{block.label}</h3>
        <small>{forecastStatusLabel(block.status)}</small>
      </header>
      <strong className={styles.metricValue}>{block.value}</strong>
      {block.details.length > 0 ? (
        <ul>
          {block.details.map((detail) => <li key={detail}>{detail}</li>)}
        </ul>
      ) : null}
      <p>{block.explanation}</p>
      <footer>
        <span>{source?.source.name || 'Bron niet beschikbaar'}</span>
        <time dateTime={source?.measuredAt ?? undefined}>
          {block.stale ? 'Verouderd · ' : ''}{source?.timeLabel ?? 'Bronmoment'} · {formatDateTime(source?.measuredAt)}
        </time>
      </footer>
    </article>
  );
}

export function forecastSourceForBlock(
  key: WallboardForecastBlockKey,
  forecast: WallboardForecastPageState,
): ForecastMetricSource | null {
  if (key === 'weather') {
    return {
      source: forecast.condition.source,
      measuredAt: forecast.condition.measured_at,
      timeLabel: 'Bronmoment',
    };
  }
  if (key === 'daylight') {
    return { source: forecast.daylight.source, measuredAt: forecast.generated_at, timeLabel: 'Berekend' };
  }

  const metricKey = FORECAST_METRIC_KEY_BY_BLOCK[key];
  let metric = metricKey === undefined
    ? undefined
    : forecast.metrics.find((candidate) => candidate.key === metricKey);
  if (key === 'cloud_cover' && metric === undefined) {
    metric = forecast.metrics.find((candidate) => candidate.key === 'cloud_cover_pct');
  }
  return metric === undefined
    ? null
    : { source: metric.source, measuredAt: metric.measured_at, timeLabel: 'Bronmoment' };
}

export function forecastStatusLabel(status: WallboardForecastMetric['status']): string {
  switch (status) {
    case 'green': return 'Binnen drempel';
    case 'orange': return 'Beoordelen';
    case 'red': return 'Overschrijding';
    case 'unknown': return 'Onbekend';
  }
}

function forecastBlockIcon(key: WallboardForecastBlockKey): LucideIcon {
  switch (key) {
    case 'weather': return CloudSun;
    case 'daylight': return Sunrise;
    case 'temperature': return Thermometer;
    case 'wind_speed': return Wind;
    case 'wind_gust': return Gauge;
    case 'wind_direction': return Navigation;
    case 'precipitation_probability': return CloudRain;
    case 'precipitation_outlook': return Radar;
    case 'thunderstorm_forecast': return CloudLightning;
    case 'cloud_cover': return Cloud;
    case 'visibility': return Eye;
    case 'gnss_visible': return Satellite;
    case 'kp_index': return Activity;
    case 'gnss_usable': return SatelliteDish;
  }
}
