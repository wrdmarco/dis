'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import styles from './WallboardVideoInspectionControl.module.css';
import {
  formatWallboardVideoDuration,
  inspectWallboardVideo,
  parseWallboardInspectableVideo,
  type WallboardVideoInspectionResult,
  type WallboardVideoInspectionSuccess,
} from './wallboardVideoInspection';

export interface WallboardVideoInspectionControlProps {
  url: string;
  onInspectionStart?(): void;
  onVerified(result: WallboardVideoInspectionSuccess): void;
  onInspectionComplete?(result: WallboardVideoInspectionResult): void;
  disabled?: boolean;
}

/**
 * Expliciete beheercontrole: externe player-API's worden pas geladen nadat de
 * beheerder op de knop drukt, niet tijdens ieder teken in het URL-veld.
 */
export function WallboardVideoInspectionControl({
  url,
  onInspectionStart,
  onVerified,
  onInspectionComplete,
  disabled = false,
}: WallboardVideoInspectionControlProps) {
  const [result, setResult] = useState<WallboardVideoInspectionResult | null>(null);
  const [checking, setChecking] = useState(false);
  const activeInspection = useRef<AbortController | null>(null);
  const onVerifiedRef = useRef(onVerified);
  onVerifiedRef.current = onVerified;
  const onInspectionCompleteRef = useRef(onInspectionComplete);
  onInspectionCompleteRef.current = onInspectionComplete;
  const onInspectionStartRef = useRef(onInspectionStart);
  onInspectionStartRef.current = onInspectionStart;
  const validUrl = parseWallboardInspectableVideo(url) !== null;

  useEffect(() => {
    activeInspection.current?.abort();
    activeInspection.current = null;
    setChecking(false);
    setResult(null);
  }, [url]);

  useEffect(() => () => activeInspection.current?.abort(), []);

  const inspect = useCallback(async () => {
    activeInspection.current?.abort();
    const controller = new AbortController();
    activeInspection.current = controller;
    setChecking(true);
    setResult(null);
    onInspectionStartRef.current?.();

    const inspection = await inspectWallboardVideo(url, { signal: controller.signal });
    if (activeInspection.current !== controller || controller.signal.aborted) return;

    activeInspection.current = null;
    setChecking(false);
    setResult(inspection);
    onInspectionCompleteRef.current?.(inspection);
    if (inspection.status === 'ready') onVerifiedRef.current(inspection);
  }, [url]);

  return (
    <section className={styles.control} aria-label="Video controleren">
      <div className={styles.actions}>
        <button type="button" onClick={() => void inspect()} disabled={disabled || checking || !validUrl}>
          {checking ? 'Video controleren…' : 'Video controleren'}
        </button>
        {result?.status === 'ready' ? (
          <p className={`${styles.status} ${styles.success}`} role="status">
            Insluiten toegestaan · duur <span className={styles.duration}>{formatWallboardVideoDuration(result.durationSeconds)}</span>
            {' '}· voorgestelde schermtijd <span className={styles.duration}>{formatWallboardVideoDuration(result.recommendedDisplayDurationSeconds)}</span>
          </p>
        ) : null}
      </div>
      {result?.status === 'failed' ? (
        <p className={`${styles.status} ${styles.failure}`} role="alert">{result.message}</p>
      ) : null}
      {result === null && !checking ? (
        <p className={styles.status}>
          DIS controleert bij de videodienst of afspelen op het wallboard is toegestaan en neemt de volledige speelduur over.
        </p>
      ) : null}
    </section>
  );
}
