import { AlertTriangle, Loader2 } from 'lucide-react';
import styles from './OperationalForecast.module.css';

export function ForecastLoading({ label }: { label: string }) {
  return (
    <div className={styles.stateCard} role="status" aria-live="polite">
      <Loader2 className="spin" aria-hidden size={21} />
      <div><strong>{label}</strong><span>De server stelt het gekozen gebied samen.</span></div>
    </div>
  );
}

export function ForecastError({ message, onRetry }: { message: string | null; onRetry: () => void }) {
  return (
    <div className={`${styles.stateCard} ${styles.stateCardError}`} role="alert">
      <AlertTriangle aria-hidden size={21} />
      <div>
        <strong>Weersinformatie niet beschikbaar</strong>
        <span>{message ?? 'De server kon geen gegevens voor dit gebied leveren.'}</span>
      </div>
      <button className="secondary-button" onClick={onRetry} type="button">Opnieuw proberen</button>
    </div>
  );
}
