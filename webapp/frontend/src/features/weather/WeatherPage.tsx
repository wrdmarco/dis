import { AlertTriangle, Cloud, CloudRain, Database, RadioTower, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { formatDateTime } from '../../lib/dateTime';
import type {
  OperationalWeatherCloudState,
  OperationalWeatherPageState,
  OperationalWeatherPrecipitationState,
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

  return (
    <div className={`page-stack ${styles.page}`}>
      <header className={styles.pageHero}>
        <span className={styles.eyebrow}><Database aria-hidden size={15} /> Lokaal opgeslagen brondata</span>
        <div className={styles.heroTitleRow}>
          <div>
            <h1>Weer</h1>
            <p>KNMI-bewolking en neerslag, aangevuld met lokaal opgeslagen EUMETSAT-bliksemdetectie.</p>
          </div>
          <span className={styles.heroIcon} aria-hidden><CloudRain size={31} /></span>
        </div>
        <p className={styles.heroNote}>Dit weerbeeld is geen vliegadvies. De browser laadt geen externe weer- of radarkaart.</p>
      </header>

      <ForecastLocationControl
        busy={resource.busy}
        location={location}
        onApply={applyLocation}
        onRefresh={() => void resource.refresh()}
      />

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
        <ForecastLoading label="Lokale KNMI-gegevens laden" />
      ) : weather ? (
        <WeatherOverview weather={weather} />
      ) : (
        <ForecastError message={resource.error} onRetry={() => void resource.refresh()} />
      )}
    </div>
  );
}

function WeatherOverview({ weather }: { weather: OperationalWeatherPageState }) {
  return (
    <>
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

      <WeatherRadarSection radar={weather.radar} />

      <div className={styles.weatherLayout}>
        <CloudSpine cloud={weather.cloud} />
        <PrecipitationTimeline precipitation={weather.precipitation} />
      </div>

      <section className={styles.provenance} aria-labelledby="weather-provenance-title">
        <header>
          <Database aria-hidden size={19} />
          <div>
            <h2 id="weather-provenance-title">Bron en actualiteit</h2>
            <p>Losse KNMI-producten blijven zichtbaar als afzonderlijke datasets.</p>
          </div>
        </header>
        <div className={styles.provenanceGrid}>
          <WeatherSourceCard
            availabilityNote={weather.cloud.availability_note}
            complete={weather.cloud.complete}
            measuredAt={weather.cloud.measured_at ?? weather.cloud.valid_at}
            refreshedAt={weather.cloud.refreshed_at}
            sourceName={weather.cloud.source.name}
            stale={weather.cloud.stale}
            title="Bewolking en wolkenbasis"
          />
          <WeatherSourceCard
            availabilityNote={weather.precipitation.availability_note}
            complete={weather.precipitation.complete}
            measuredAt={weather.precipitation.measured_at ?? weather.precipitation.reference_time}
            refreshedAt={weather.precipitation.refreshed_at}
            sourceName={weather.precipitation.source.name}
            stale={weather.precipitation.stale}
            title="Radar en ensemble uur 3"
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
          <small>Hoogtereferentie niet door het modelproduct gespecificeerd</small>
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
  const radarDry = precipitation.radar_peak_mm_h !== null && precipitation.radar_peak_mm_h < 0.1;
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
              ? precipitation.probability_complete ? 'Compleet' : 'Radar actueel'
              : 'Onvolledig'}
        </span>
      </header>

      <div className={styles.rainHeadline}>
        <span>{precipitation.radar_first_precipitation_at
          ? `Eerste neerslag rond ${formatClock(precipitation.radar_first_precipitation_at)}`
          : radarDry ? 'Radarvenster blijft droog' : 'Start neerslag onbekend'}</span>
        <strong>{formatNumber(precipitation.radar_peak_mm_h, 'mm/u')}</strong>
        <small>hoogste radarpiek in de eerste twee uur</small>
      </div>

      <ol className={styles.rainTimeline} aria-label="Neerslagverwachting voor de komende drie uur">
        <li className={styles.radarWindow}>
          <span className={styles.timelineDot} aria-hidden />
          <small>Nu → {formatClock(precipitation.radar_until)}</small>
          <strong>KNMI radar</strong>
          <span>Lokale radarrasterreeks tot +2 uur</span>
        </li>
        <li className={styles.ensembleWindow}>
          <span className={styles.timelineDot} aria-hidden />
          <small>{precipitation.probability_complete
            ? `${formatClock(precipitation.third_hour_from)} → ${formatClock(precipitation.forecast_until)}`
            : '+2 → +3 uur'}</small>
          <strong>Uur 3 · ensemble</strong>
          <span>{precipitation.probability_complete
            ? `${formatNumber(precipitation.third_hour_probability_pct, '%')} kans op ≥ 0,1 mm/u`
            : 'Kansmodel niet beschikbaar'}</span>
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
  sourceName,
  complete,
  stale,
  measuredAt,
  refreshedAt,
  availabilityNote,
}: {
  title: string;
  sourceName: string;
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
        <span>{sourceName || 'KNMI · bron onbekend'}</span>
      </div>
      <dl>
        <div><dt>Waarde geldig / gemeten</dt><dd>{formatDateTime(measuredAt)}</dd></div>
        <div><dt>Lokaal ververst</dt><dd>{formatDateTime(refreshedAt)}</dd></div>
      </dl>
      <span className={complete && !stale ? styles.freshBadge : styles.unknownBadge}>
        {stale ? 'Verouderd' : complete ? 'Actueel' : 'Niet compleet'}
      </span>
      {availabilityNote ? <p>{availabilityNote}</p> : null}
    </article>
  );
}

export function weatherDataStatusLabel(status: OperationalWeatherPageState['data_status']): string {
  switch (status) {
    case 'current': return 'Lokale datasets actueel';
    case 'partial': return 'Lokale datasets gedeeltelijk beschikbaar';
    case 'unavailable': return 'Lokale datasets niet beschikbaar';
  }
}

function weatherAggregationLabel(weather: OperationalWeatherPageState): string {
  if (weather.aggregation.type === 'province_average') {
    return `${weather.aggregation.sample_count}/${weather.aggregation.expected_sample_count} provinciepunten`;
  }
  return weather.aggregation.complete ? 'Lokaal rasterpunt compleet' : 'Lokaal rasterpunt onvolledig';
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
