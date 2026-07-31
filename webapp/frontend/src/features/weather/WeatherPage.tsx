import { AlertTriangle, CloudSun, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import type { OperationalWeatherRadarPageState } from '../../types/api';
import { ForecastLocationControl } from './ForecastLocationControl';
import { ForecastError, ForecastLoading } from './ForecastStates';
import {
  markOperationalWeatherRadarPageStale,
  normalizeOperationalWeatherRadarPage,
} from './forecastNormalization';
import styles from './OperationalForecast.module.css';
import {
  DEFAULT_FORECAST_LOCATION,
  type ForecastLocationQuery,
  useForecastResource,
  WEATHER_REFRESH_INTERVAL_MS,
} from './useForecastResource';
import { WeatherRadarSection } from './WeatherRadarSection';

export function WeatherPage() {
  const [location, setLocation] = useState<ForecastLocationQuery>(DEFAULT_FORECAST_LOCATION);
  const resource = useForecastResource<OperationalWeatherRadarPageState>(
    '/operational-weather/radar',
    location,
    normalizeOperationalWeatherRadarPage,
    WEATHER_REFRESH_INTERVAL_MS,
  );
  const weather = resource.data === null
    ? null
    : resource.stale
      ? markOperationalWeatherRadarPageStale(resource.data)
      : resource.data;

  function applyLocation(next: ForecastLocationQuery) {
    if (next.mode === location.mode && next.label === location.label) {
      void resource.refresh();
      return;
    }
    setLocation(next);
  }

  const locationControl = (
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
          <p>Live buien en twee uur verwachting voor Nederland, België en westelijk Duitsland. Dit kaartbeeld is geen vliegadvies.</p>
        </div>
      </header>

      {resource.stale && weather ? (
        <div className={styles.inlineWarning} role="alert">
          <AlertTriangle aria-hidden size={18} />
          <span>
            De laatst opgehaalde radarstatus is verlopen. Het kaartbeeld wordt daarom niet als actueel aangemerkt.
            {resource.refreshing ? ' Er wordt een nieuwe status opgehaald.' : ''}
            {resource.error ? ` ${resource.error}` : ''}
          </span>
        </div>
      ) : null}

      {!resource.stale && weather && resource.refreshing ? (
        <div className={styles.inlineRefresh} role="status">
          <RefreshCw aria-hidden size={18} />
          <span>Nieuwe radarframes worden gecontroleerd. Het huidige beeld blijft zichtbaar.</span>
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
          <ForecastLoading label="Live radar laden" />
        </>
      ) : weather ? (
        <WeatherRadarSection
          radar={weather.radar}
          location={weather.location}
          locationControl={locationControl}
        />
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
