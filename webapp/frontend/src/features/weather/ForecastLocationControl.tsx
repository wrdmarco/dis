import { MapPin, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import styles from './OperationalForecast.module.css';
import type { ForecastLocationQuery } from './useForecastResource';
import { normalizeForecastAddress } from './useForecastResource';

interface ForecastLocationControlProps {
  busy: boolean;
  location: ForecastLocationQuery;
  onApply: (location: ForecastLocationQuery) => void;
  onRefresh: () => void;
}

export function ForecastLocationControl({
  busy,
  location,
  onApply,
  onRefresh,
}: ForecastLocationControlProps) {
  const [mode, setMode] = useState(location.mode);
  const [address, setAddress] = useState(location.label);
  const [validationError, setValidationError] = useState<string | null>(null);

  useEffect(() => {
    setMode(location.mode);
    setAddress(location.label);
  }, [location]);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const normalizedAddress = normalizeForecastAddress(address);
    if (mode === 'address' && normalizedAddress === '') {
      setValidationError('Vul een adres of plaatsnaam in.');
      return;
    }

    setValidationError(null);
    onApply({ mode, label: mode === 'address' ? normalizedAddress : '' });
  }

  return (
    <form className={styles.locationControl} onSubmit={submit} aria-label="Forecastgebied kiezen">
      <div className={styles.locationHeading}>
        <span className={styles.locationIcon} aria-hidden><MapPin size={20} /></span>
        <div>
          <strong>Gebied</strong>
          <span>Landelijk beeld of één server-side gevonden adres</span>
        </div>
      </div>

      <fieldset className={styles.locationModes}>
        <legend className="sr-only">Gebiedstype</legend>
        <label className={mode === 'netherlands' ? styles.locationModeActive : undefined}>
          <input
            checked={mode === 'netherlands'}
            disabled={busy}
            name="forecast-location-mode"
            onChange={() => setMode('netherlands')}
            type="radio"
            value="netherlands"
          />
          UAV Nederland
        </label>
        <label className={mode === 'address' ? styles.locationModeActive : undefined}>
          <input
            checked={mode === 'address'}
            disabled={busy}
            name="forecast-location-mode"
            onChange={() => setMode('address')}
            type="radio"
            value="address"
          />
          Adres
        </label>
      </fieldset>

      <div className={styles.addressField}>
        <label htmlFor="forecast-location-label">Adres of plaatsnaam</label>
        <input
          autoComplete="street-address"
          disabled={busy || mode !== 'address'}
          id="forecast-location-label"
          maxLength={160}
          onChange={(event) => setAddress(event.target.value)}
          placeholder="Bijvoorbeeld Stationsplein 1, Utrecht"
          type="search"
          value={address}
        />
        {validationError ? <span className={styles.locationError} role="alert">{validationError}</span> : null}
      </div>

      <div className={styles.locationActions}>
        <button className="secondary-button" disabled={busy} type="submit">Toepassen</button>
        <button
          className="secondary-button"
          disabled={busy}
          onClick={onRefresh}
          type="button"
        >
          <RefreshCw className={busy ? 'spin' : undefined} aria-hidden size={17} />
          {busy ? 'Bezig…' : 'Verversen'}
        </button>
      </div>
    </form>
  );
}
