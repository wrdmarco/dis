import { AlertTriangle, Cloud, CloudSun, Database, RadioTower, RefreshCw } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { formatDateTime } from '../../lib/dateTime';
import type {
  OperationalWeatherCloudState,
  OperationalWeatherPageState,
  OperationalWeatherPrecipitationState,
  WallboardForecastSource,
} from '../../types/api';
import { ForecastLocationControl } from './ForecastLocationControl';
import { ForecastError, ForecastLoading } from './ForecastStates';
import {
  markOperationalWeatherStale,
  normalizeOperationalWeatherPage,
} from './forecastNormalization';
import styles from './OperationalForecast.module.css';
import {
  DEFAULT_FORECAST_LOCATION,
  type ForecastLocationQuery,
  useForecastResource,
  WEATHER_REFRESH_INTERVAL_MS,
} from './useForecastResource';
import { WeatherRadarSection } from './WeatherRadarSection';

const NUMBER_FORMATTER = new Intl.NumberFormat('nl-NL', { maximumFractionDigits: 1 });

export function WeatherPage() {
  const [location, setLocation] = useState<ForecastLocationQuery>(DEFAULT_FORECAST_LOCATION);
  const resource = useForecastResource<OperationalWeatherPageState>(
    '/operational-weather',
    location,
    normalizeOperationalWeatherPage,
    WEATHER_REFRESH_INTERVAL_MS,
  );
  const weather = resource.data === null
    ? null
    : resource.stale
      ? markOperationalWeatherStale(resource.data)
      : resource.data;

  function applyLocation(next: ForecastLocationQuery) {
    if (next.mode === location.mode && next.label === location.label) {
      void resource.refresh();
      return;
    }
    setLocation(next);
  }

  const compactLocationControl = (
    <ForecastLocationControl
      busy={resource.busy}
      compact
      location={location}
      onApply={applyLocation}
      onRefresh={() => void resource.refresh()}
    />
  );

  return (
    <div className={`page-stack ${styles.page}`}>
      <header className={styles.weatherPageHero}>
        <span className={styles.sectionIcon} aria-hidden><CloudSun size={22} /></span>
        <div>
          <span className={styles.sectionKicker}>Operationeel weerbeeld</span>
          <h1>Live weerkaart</h1>
          <p>Regen, bliksem en UAV-weergegevens voor snelle oriëntatie. Dit kaartbeeld is geen vliegadvies.</p>
        </div>
      </header>

      {resource.stale && weather ? (
        <div className={styles.inlineWarning} role="alert">
          <AlertTriangle aria-hidden size={18} />
          <span>
            De laatst opgehaalde weer- en radargegevens zijn verlopen en daarom als niet beschikbaar gemarkeerd.
            {resource.refreshing ? ' Er worden nieuwe gegevens opgehaald.' : ''}
            {resource.error ? ` ${resource.error}` : ''}
          </span>
        </div>
      ) : null}

      {!resource.stale && weather && resource.refreshing ? (
        <div className={styles.inlineRefresh} role="status">
          <RefreshCw aria-hidden size={18} />
          <span>Nieuwe weer- en radargegevens worden gecontroleerd. Het huidige gevalideerde beeld blijft zichtbaar.</span>
        </div>
      ) : null}

      {!resource.stale && weather && !resource.refreshing && resource.error ? (
        <div className={styles.inlineWarning} role="status">
          <AlertTriangle aria-hidden size={18} />
          <span>Bijwerken is niet gelukt. Het huidige gevalideerde beeld blijft zichtbaar; een nieuwe poging volgt automatisch.</span>
        </div>
      ) : null}

      {resource.loading ? (
        <>
          <ForecastLocationControl
            busy={resource.busy}
            location={location}
            onApply={applyLocation}
            onRefresh={() => void resource.refresh()}
          />
          <ForecastLoading label="Live weergegevens laden" />
        </>
      ) : weather ? (
        <WeatherOverview weather={weather} locationControl={compactLocationControl} />
      ) : (
        <>
          <ForecastLocationControl
            busy={resource.busy}
            location={location}
            onApply={applyLocation}
            onRefresh={() => void resource.refresh()}
          />
          <ForecastError message={resource.error} onRetry={() => void resource.refresh()} />
        </>
      )}
    </div>
  );
}

function WeatherOverview({
  weather,
  locationControl,
}: {
  weather: OperationalWeatherPageState;
  locationControl: ReactNode;
}) {
  return (
    <>
      <WeatherRadarSection
        radar={weather.radar}
        location={weather.location}
        locationControl={locationControl}
      />

      <section className={`${styles.dataBanner} ${styles[`dataBanner_${weather.data_status}`]}`} aria-labelledby="weather-data-status">
        <span className={styles.dataPulse} aria-hidden />
        <div>
          <span className={styles.dataLabel}>Datastatus</span>
          <h2 id="weather-data-status">{weatherDataStatusLabel(weather.data_status)}</h2>
          <p>{weather.location.label} · {weatherAggregationLabel(weather)}</p>
        </div>
        <dl>
          <div>
            <dt>Samengesteld</dt>
            <dd>{formatDateTime(weather.generated_at)}</dd>
          </div>
          <div>
            <dt>Gebied</dt>
            <dd>{weather.location.mode === 'netherlands' ? 'UAV Nederland' : 'Adres'}</dd>
          </div>
        </dl>
      </section>

      <div className={styles.weatherLayout}>
        <CloudSpine cloud={weather.cloud} />
        <PrecipitationTimeline precipitation={weather.precipitation} />
      </div>

      <section className={styles.provenance} aria-labelledby="weather-provenance-title">
        <header>
          <Database aria-hidden size={19} />
          <div>
            <h2 id="weather-provenance-title">Bron en actualiteit</h2>
            <p>Iedere weerlaag toont zijn eigen bron en meet- of modeltijd.</p>
          </div>
        </header>
        <div className={styles.provenanceGrid}>
          <WeatherSourceCard
            availabilityNote={weather.cloud.availability_note}
            complete={weather.cloud.complete}
            measuredAt={weather.cloud.measured_at ?? weather.cloud.valid_at}
            refreshedAt={weather.cloud.refreshed_at}
            source={weather.cloud.source}
            stale={weather.cloud.stale}
            title="Bewolking en wolkenbasis"
          />
          <WeatherSourceCard
            availabilityNote={weather.precipitation.availability_note}
            complete={weather.precipitation.complete}
            measuredAt={weather.precipitation.measured_at ?? weather.precipitation.reference_time}
            refreshedAt={weather.precipitation.refreshed_at}
            source={weather.precipitation.source}
            stale={weather.precipitation.stale}
            title="DMI-modelneerslag"
          />
        </div>
        <p className={styles.scopeNote}>{weather.scope_note}</p>
        <p className={styles.disclaimer}>{weather.disclaimer}</p>
      </section>
    </>
  );
}

function CloudSpine({ cloud }: { cloud: OperationalWeatherCloudState }) {
  return (
    <section className={`${styles.atmospherePanel} ${cloud.stale ? styles.providerStale : ''}`} aria-labelledby="cloud-spine-title">
      <header className={styles.sectionHeader}>
        <span className={styles.sectionIcon} aria-hidden><Cloud size={21} /></span>
        <div>
          <span className={styles.sectionKicker}>Atmosferische laagopbouw</span>
          <h2 id="cloud-spine-title">Bewolking en wolkenbasis</h2>
        </div>
        <span className={cloud.complete && !cloud.stale ? styles.freshBadge : styles.unknownBadge}>
          {cloud.stale ? 'Verouderd' : cloud.complete ? 'Compleet' : 'Onvolledig'}
        </span>
      </header>

      <div className={styles.cloudSpine}>
        <CloudLayer label="Hoge bewolking" value={cloud.cloud_cover_high_pct} variant="high" />
        <CloudLayer label="Middelbare bewolking" value={cloud.cloud_cover_mid_pct} variant="mid" />
        <CloudLayer label="Lage bewolking" value={cloud.cloud_cover_low_pct} variant="low" />
        <div className={styles.cloudBaseMarker}>
          <span>Modelwolkenbasis</span>
          <strong>{formatNumber(cloud.cloud_base_m, 'm')}</strong>
          <small>
            {cloud.cloud_base_complete
              ? 'Hoogtereferentie niet door het modelproduct gespecificeerd'
              : `Onbekend · ${sampleCoverage(cloud.cloud_base_sample_count, cloud.cloud_base_expected_sample_count)} geldige punten`}
          </small>
        </div>
      </div>

      <dl className={styles.compactFacts}>
        <div><dt>Totale bedekking</dt><dd>{formatNumber(cloud.cloud_cover_pct, '%')}</dd></div>
        <div><dt>Geldig voor</dt><dd>{formatDateTime(cloud.valid_at)}</dd></div>
        <div><dt>Modelrun</dt><dd>{formatDateTime(cloud.model_run_at)}</dd></div>
        <div><dt>Dekking</dt><dd>{sampleCoverage(cloud.sample_count, cloud.expected_sample_count)}</dd></div>
      </dl>
      {cloud.availability_note ? <p className={styles.availabilityNote}>{cloud.availability_note}</p> : null}
    </section>
  );
}

function CloudLayer({
  label,
  value,
  variant,
}: {
  label: string;
  value: number | null;
  variant: 'high' | 'mid' | 'low';
}) {
  return (
    <div className={`${styles.cloudLayer} ${styles[`cloudLayer_${variant}`]}`}>
      <span>{label}</span>
      <strong>{formatNumber(value, '%')}</strong>
    </div>
  );
}

function PrecipitationTimeline({ precipitation }: { precipitation: OperationalWeatherPrecipitationState }) {
  const modelDry = precipitation.radar_peak_mm_h !== null && precipitation.radar_peak_mm_h < 0.1;
  return (
    <section className={`${styles.precipitationPanel} ${precipitation.stale ? styles.providerStale : ''}`} aria-labelledby="precipitation-title">
      <header className={styles.sectionHeader}>
        <span className={styles.sectionIcon} aria-hidden><RadioTower size={21} /></span>
        <div>
          <span className={styles.sectionKicker}>0–3 uur</span>
          <h2 id="precipitation-title">Neerslagvenster</h2>
        </div>
        <span className={precipitation.complete && !precipitation.stale ? styles.freshBadge : styles.unknownBadge}>
          {precipitation.stale
            ? 'Verouderd'
            : precipitation.complete
              ? 'Model actueel'
              : 'Onvolledig'}
        </span>
      </header>

      <div className={styles.rainHeadline}>
        <span>{precipitation.radar_first_precipitation_at
          ? `Eerste neerslag rond ${formatClock(precipitation.radar_first_precipitation_at)}`
          : modelDry ? 'Modelvenster blijft droog' : 'Start neerslag onbekend'}</span>
        <strong>{formatNumber(precipitation.radar_peak_mm_h, 'mm/u')}</strong>
        <small>hoogste modelpiek in de komende drie uur</small>
      </div>

      <ol className={styles.rainTimeline} aria-label="Neerslagverwachting voor de komende drie uur">
        <li className={styles.radarWindow}>
          <span className={styles.timelineDot} aria-hidden />
          <small>Modeltijd {formatClock(precipitation.reference_time)} → {formatClock(precipitation.radar_until)}</small>
          <strong>Modelneerslag · 0–3 uur</strong>
          <span>{precipitation.source.name} · deterministische verwachting; gewone neerslagkans niet beschikbaar</span>
        </li>
      </ol>

      <dl className={styles.compactFacts}>
        <div><dt>Referentietijd</dt><dd>{formatDateTime(precipitation.reference_time)}</dd></div>
        <div><dt>Datapunten</dt><dd>{sampleCoverage(precipitation.sample_count, precipitation.expected_sample_count)}</dd></div>
      </dl>
      {precipitation.availability_note ? <p className={styles.availabilityNote}>{precipitation.availability_note}</p> : null}
    </section>
  );
}

function WeatherSourceCard({
  title,
  source,
  complete,
  stale,
  measuredAt,
  refreshedAt,
  availabilityNote,
}: {
  title: string;
  source: WallboardForecastSource;
  complete: boolean;
  stale: boolean;
  measuredAt: string | null;
  refreshedAt: string | null;
  availabilityNote: string | null;
}) {
  return (
    <article className={styles.sourceCard}>
      <div>
        <strong>{title}</strong>
        <span className={styles.sourceLinks}>
          {source.url ? (
            <a href={source.url} rel="noreferrer noopener" target="_blank">{source.name || 'Bron onbekend'}</a>
          ) : source.name || 'Bron onbekend'}
          {source.license ? (
            source.license_url
              ? <a href={source.license_url} rel="noreferrer noopener" target="_blank">{source.license}</a>
              : <span>{source.license}</span>
          ) : null}
        </span>
        {forecastSourceProcessingLabel(source) ? (
          <small className={styles.sourceProcessing}>{forecastSourceProcessingLabel(source)}</small>
        ) : null}
      </div>
      <dl>
        <div><dt>Waarde geldig / gemeten</dt><dd>{formatDateTime(measuredAt)}</dd></div>
        <div><dt>Bron vernieuwd</dt><dd>{formatDateTime(refreshedAt)}</dd></div>
      </dl>
      <span className={complete && !stale ? styles.freshBadge : styles.unknownBadge}>
        {stale ? 'Verouderd' : complete ? 'Actueel' : 'Niet compleet'}
      </span>
      {availabilityNote ? <p>{availabilityNote}</p> : null}
    </article>
  );
}

function forecastSourceProcessingLabel(source: WallboardForecastSource): string | null {
  const processing = source.processing_note ?? source.attribution ?? null;
  const processor = source.modified && source.processed_by
    ? `Verwerkt door ${source.processed_by}`
    : null;
  return [processing, processor].filter((part): part is string => part !== null).join(' · ') || null;
}

export function weatherDataStatusLabel(status: OperationalWeatherPageState['data_status']): string {
  switch (status) {
    case 'current': return 'Live weergegevens actueel';
    case 'partial': return 'Weergegevens gedeeltelijk beschikbaar';
    case 'unavailable': return 'Weergegevens niet beschikbaar';
  }
}

function weatherAggregationLabel(weather: OperationalWeatherPageState): string {
  if (weather.aggregation.type === 'province_average') {
    return `${weather.aggregation.sample_count}/${weather.aggregation.expected_sample_count} provinciepunten`;
  }
  return weather.aggregation.complete ? 'Locatiebeeld compleet' : 'Locatiebeeld onvolledig';
}

export function formatNumber(value: number | null, unit: string): string {
  return value === null ? 'Onbekend' : `${NUMBER_FORMATTER.format(value)} ${unit}`;
}

function formatClock(value: string | null): string {
  if (value === null) return 'onbekend';
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) return 'onbekend';
  return new Intl.DateTimeFormat('nl-NL', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: 'Europe/Amsterdam',
  }).format(date);
}

function sampleCoverage(sampleCount: number | null, expectedSampleCount: number | null): string {
  if (sampleCount === null || expectedSampleCount === null) return 'Onbekend';
  return `${sampleCount}/${expectedSampleCount}`;
}
