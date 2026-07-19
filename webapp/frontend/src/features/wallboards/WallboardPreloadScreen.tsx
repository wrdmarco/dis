'use client';

import { forwardRef, type CSSProperties } from 'react';
import styles from './WallboardPreloadScreen.module.css';
import {
  boundedWallboardPreloadCount,
  safeWallboardPreloadText,
  wallboardPreloadPercentage,
  type WallboardPreloadStatus,
} from './wallboardPreloadProgress';

export type { WallboardPreloadStatus } from './wallboardPreloadProgress';

export interface WallboardPreloadScreenProps {
  status: WallboardPreloadStatus;
  completed: number;
  total: number;
  pagesReady: number;
  pagesTotal: number;
  onlineOnlyPages: number;
  currentLabel?: string | null;
  errorText?: string | null;
}

interface WallboardPreloadCopy {
  eyebrow: string;
  title: string;
  detail: string;
}

const STATUS_COPY: Record<WallboardPreloadStatus, WallboardPreloadCopy> = {
  idle: {
    eyebrow: 'Preflight',
    title: 'Wallboard voorbereiden',
    detail: 'De inhoud wordt gecontroleerd voordat de presentatie begint.',
  },
  loading: {
    eyebrow: 'Inhoud lokaal gereedmaken',
    title: 'Wallboard voorbereiden',
    detail: 'Alle pagina\'s en media worden eerst veilig klaargezet.',
  },
  retrying: {
    eyebrow: 'Automatisch herstel',
    title: 'Wallboard voorbereiden',
    detail: 'De verbinding wordt automatisch opnieuw geprobeerd. Laat dit scherm geopend.',
  },
  ready: {
    eyebrow: 'Preflight voltooid',
    title: 'Wallboard gereed',
    detail: 'Alle pagina\'s zijn beschikbaar. De presentatie start automatisch.',
  },
  error: {
    eyebrow: 'Voorbereiding onderbroken',
    title: 'Wallboard voorbereiden',
    detail: 'DIS probeert de voorbereiding automatisch opnieuw. Laat dit scherm geopend.',
  },
};

export const WallboardPreloadScreen = forwardRef<HTMLElement, WallboardPreloadScreenProps>(function WallboardPreloadScreen({
  status,
  completed,
  total,
  pagesReady,
  pagesTotal,
  onlineOnlyPages,
  currentLabel,
  errorText,
}, ref) {
  const percentage = wallboardPreloadPercentage({
    status,
    completed,
    total,
    pagesReady,
    pagesTotal,
  });
  const safeCompleted = Math.min(
    boundedWallboardPreloadCount(completed),
    boundedWallboardPreloadCount(total),
  );
  const safeTotal = boundedWallboardPreloadCount(total);
  const safePagesReady = Math.min(
    boundedWallboardPreloadCount(pagesReady),
    boundedWallboardPreloadCount(pagesTotal),
  );
  const safePagesTotal = boundedWallboardPreloadCount(pagesTotal);
  const safeOnlineOnlyPages = boundedWallboardPreloadCount(onlineOnlyPages);
  const copy = STATUS_COPY[status];
  const currentPage = safeWallboardPreloadText(currentLabel, 'Volgende pagina voorbereiden');
  const errorMessage = safeWallboardPreloadText(errorText, copy.detail);
  const detail = status === 'error' ? errorMessage : copy.detail;
  const progressStyle = {
    '--wallboard-preload-progress': `${percentage * 3.6}deg`,
  } as CSSProperties;

  return (
    <section className={styles.screen} data-status={status} ref={ref}>
      <div className={styles.ambientGrid} aria-hidden="true" />
      <header className={styles.topbar}>
        <span className={styles.systemName}>D.I.S. wallboard</span>
        <span className={styles.preflightState}>{copy.eyebrow}</span>
      </header>

      <section className={styles.content} aria-live="polite" aria-atomic="true">
        <div className={styles.progressStage}>
          <div
            className={styles.progressRing}
            style={progressStyle}
            role="progressbar"
            aria-label="Voortgang wallboard voorbereiden"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={percentage}
            aria-valuetext={`${percentage}% voltooid`}
          >
            <div className={styles.radarSweep} aria-hidden="true" />
            <div className={styles.progressCore}>
              <strong>{percentage}%</strong>
              <span>voorbereid</span>
            </div>
            <i className={`${styles.waypoint} ${styles.waypointOne}`} aria-hidden="true" />
            <i className={`${styles.waypoint} ${styles.waypointTwo}`} aria-hidden="true" />
            <i className={`${styles.waypoint} ${styles.waypointThree}`} aria-hidden="true" />
          </div>
        </div>

        <div className={styles.statusCopy}>
          <p className={styles.eyebrow}>{copy.eyebrow}</p>
          <h1>{copy.title}</h1>
          <p className={styles.detail}>{detail}</p>

          {status === 'loading' || status === 'retrying' ? (
            <p className={styles.currentItem}>
              <span>Nu bezig</span>
              <strong>{currentPage}</strong>
            </p>
          ) : null}

          <div className={styles.metrics} aria-label="Voortgangsdetails">
            <div>
              <span>Lokale pagina&apos;s gereed</span>
              <strong>{safePagesReady}<small> / {safePagesTotal}</small></strong>
            </div>
            <div>
              <span>Onderdelen opgeslagen</span>
              <strong>{safeCompleted}<small> / {safeTotal}</small></strong>
            </div>
          </div>

          {safeOnlineOnlyPages > 0 ? (
            <p className={styles.onlineOnlyNotice}>
              {safeOnlineOnlyPages} externe {safeOnlineOnlyPages === 1 ? 'videopagina wordt' : 'videopagina\'s worden'}
              {' '}online geladen zodra {safeOnlineOnlyPages === 1 ? 'deze wordt getoond' : 'ze worden getoond'}.
            </p>
          ) : null}

          <div className={styles.linearTrack} aria-hidden="true">
            <span style={{ width: `${percentage}%` }} />
          </div>
        </div>
      </section>

      <footer className={styles.footer}>
        <span className={styles.pulse} aria-hidden="true" />
        {status === 'ready'
          ? 'Presentatie wordt gestart'
          : 'Automatische voorbereiding actief'}
      </footer>
    </section>
  );
});
