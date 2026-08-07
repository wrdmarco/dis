'use client';

import { useEffect, useId, useState } from 'react';
import {
  AlertTriangle,
  CheckCircle2,
  Copy,
  Eye,
  EyeOff,
  KeyRound,
  Loader2,
  RadioTower,
  RefreshCw,
  RotateCw,
} from 'lucide-react';
import { useConfirmDialog } from '../../components/ConfirmDialogContext';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type {
  WallboardLiveStreamAdminStatus,
  WallboardLiveStreamStreamKey,
  WallboardLiveStreamStreamKeyRotation,
  WallboardLiveStreamStatusValue,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import styles from './WallboardLiveStreamPageEditor.module.css';

const STATUS_REFRESH_MILLISECONDS = 5_000;

const STATUS_PRESENTATION: Record<WallboardLiveStreamStatusValue, {
  label: string;
  tone: 'neutral' | 'good' | 'warn' | 'bad';
}> = {
  live: { label: 'Live signaal', tone: 'good' },
  waiting: { label: 'Wachten op OBS', tone: 'neutral' },
  offline: { label: 'Streamservice offline', tone: 'warn' },
  error: { label: 'Streamfout', tone: 'bad' },
};

export function WallboardLiveStreamPageEditor() {
  const { api } = useAuth();
  const confirmAction = useConfirmDialog();
  const streamKeyInputId = `wallboard-live-stream-key-${useId().replaceAll(':', '')}`;
  const statusResource = useApiResource<WallboardLiveStreamAdminStatus>(
    '/admin/wallboard-live-stream/status',
  );
  const { silentReload } = statusResource;
  const [streamKey, setStreamKey] = useState<string | null>(null);
  const [streamKeyVersion, setStreamKeyVersion] = useState<string | null>(null);
  const [keyAction, setKeyAction] = useState<'reveal' | 'rotate' | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [copyStatus, setCopyStatus] = useState<string | null>(null);
  const [rotationNotice, setRotationNotice] = useState<{
    obsReconnectRequired: boolean;
    previousKeyRevoked: boolean;
    rotatedAt: string;
  } | null>(null);
  const status = statusResource.data;
  const activeStreamKeyVersion = status?.stream_key_version ?? null;
  const keyManagementReady = status !== null && activeStreamKeyVersion !== null;
  const visibleStreamKey = streamKeyVersion === activeStreamKeyVersion ? streamKey : null;

  useEffect(() => {
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') void silentReload();
    }, STATUS_REFRESH_MILLISECONDS);

    return () => window.clearInterval(timer);
  }, [silentReload]);

  useEffect(() => {
    if (status === null || streamKey === null || streamKeyVersion === null
      || activeStreamKeyVersion === streamKeyVersion) return;

    setStreamKey(null);
    setStreamKeyVersion(null);
    setCopyStatus(null);
    setRotationNotice(null);
    setActionError('De Stream Key is intussen gewijzigd. Haal voor gebruik de actuele Stream Key opnieuw op.');
  }, [activeStreamKeyVersion, status, streamKey, streamKeyVersion]);

  const presentation = status === null ? null : STATUS_PRESENTATION[status.status];
  const keyActionBusy = keyAction !== null;

  async function revealStreamKey() {
    setKeyAction('reveal');
    setActionError(null);
    setCopyStatus(null);
    try {
      const response = await api.post<WallboardLiveStreamStreamKey>(
        '/admin/wallboard-live-stream/stream-key/reveal',
      );
      setStreamKey(response.data.stream_key);
      setStreamKeyVersion(response.data.stream_key_version);
    } catch (error) {
      setActionError(error instanceof ApiClientError
        ? error.message
        : 'Stream Key ophalen mislukt.');
    } finally {
      setKeyAction(null);
    }
  }

  async function rotateStreamKey() {
    const confirmed = await confirmAction({
      title: 'Stream Key wisselen?',
      message: 'De oude Stream Key stopt direct met werken en een actieve OBS-stream wordt onderbroken. Werk de nieuwe Stream Key daarna in OBS bij en start de stream opnieuw.',
      confirmLabel: 'Ja, Stream Key wisselen',
      intent: 'danger',
    });
    if (!confirmed) return;

    setStreamKey(null);
    setStreamKeyVersion(null);
    setKeyAction('rotate');
    setActionError(null);
    setCopyStatus(null);
    setRotationNotice(null);
    try {
      const response = await api.post<WallboardLiveStreamStreamKeyRotation>(
        '/admin/wallboard-live-stream/stream-key/rotate',
        { confirmation: 'WISSELEN' },
      );
      statusResource.mutate((current) => current === null ? current : {
        ...current,
        stream_key_version: response.data.stream_key_version,
      });
      setStreamKey(response.data.stream_key);
      setStreamKeyVersion(response.data.stream_key_version);
      setRotationNotice({
        obsReconnectRequired: response.data.obs_reconnect_required,
        previousKeyRevoked: response.data.previous_key_revoked,
        rotatedAt: response.data.rotated_at,
      });
      void silentReload();
    } catch (error) {
      const message = error instanceof ApiClientError
        ? error.message
        : 'Stream Key wisselen mislukt.';
      setActionError(`${message} Haal voor gebruik de actuele Stream Key opnieuw op.`);
    } finally {
      setKeyAction(null);
    }
  }

  async function copyStreamKey() {
    if (visibleStreamKey === null) return;

    setActionError(null);
    setCopyStatus(null);
    if (typeof navigator.clipboard?.writeText !== 'function') {
      setActionError('Automatisch kopiëren is niet beschikbaar. Selecteer de Stream Key en kopieer deze handmatig.');
      return;
    }

    try {
      await navigator.clipboard.writeText(visibleStreamKey);
      setCopyStatus('Stream Key gekopieerd.');
    } catch {
      setActionError('Stream Key kopiëren mislukt. Selecteer de key en kopieer deze handmatig.');
    }
  }

  function hideStreamKey() {
    setStreamKey(null);
    setStreamKeyVersion(null);
    setCopyStatus(null);
    setActionError(null);
  }

  return (
    <fieldset className={styles.editor} aria-busy={statusResource.loading || keyActionBusy}>
      <legend>OBS live-uitzending</legend>
      <header className={styles.heading}>
        <span className={styles.signal} aria-hidden><RadioTower size={20} /></span>
        <span>
          <strong>OBS RTMPS-ingang</strong>
          <small>De wallboards verbinden pas met de browserstream wanneer deze pagina actief is.</small>
        </span>
        {presentation === null ? null : (
          <StatusPill value={presentation.label} tone={presentation.tone} />
        )}
      </header>

      {statusResource.loading && status === null ? (
        <p className={styles.feedback} role="status">
          <Loader2 className="spin" size={17} aria-hidden /> Streamstatus laden…
        </p>
      ) : null}

      {statusResource.error !== null ? (
        <div className={styles.error} role="alert">
          <AlertTriangle size={18} aria-hidden />
          <span>
            <strong>Streamstatus kon niet worden geladen</strong>
            <small>{statusResource.error}</small>
          </span>
          <button className="secondary-button" type="button" onClick={() => void statusResource.reload()}>
            <RefreshCw size={15} aria-hidden /> Opnieuw proberen
          </button>
        </div>
      ) : null}

      {status === null ? null : (
        <>
          <dl className={styles.details}>
            <div className={styles.address}>
              <dt>Server</dt>
              <dd><code>{status.server_url ?? 'Niet geconfigureerd'}</code></dd>
            </div>
            <div>
              <dt>Stream Key ingesteld</dt>
              <dd>{status.stream_key_configured ? 'Ja' : 'Nee'}</dd>
            </div>
            <div>
              <dt>Laatste streamactiviteit</dt>
              <dd>{formatLastPacket(status.last_packet_at)}</dd>
            </div>
          </dl>
          {status.message === null ? null : <p className={styles.message}>{status.message}</p>}
        </>
      )}

      <section className={styles.keyManagement} aria-labelledby={`${streamKeyInputId}-title`}>
        <header className={styles.keyHeading}>
          <span className={styles.keyIcon} aria-hidden><KeyRound size={18} /></span>
          <span>
            <strong id={`${streamKeyInputId}-title`}>Stream Key beheren</strong>
            <small>De key wordt alleen na een expliciete beheeractie zichtbaar en niet met de automatische statuscontrole opgehaald.</small>
          </span>
          <span className={styles.keyActions}>
            {visibleStreamKey === null ? (
              <button
                className="secondary-button"
                type="button"
                onClick={() => void revealStreamKey()}
                disabled={keyActionBusy || !keyManagementReady || status?.stream_key_configured !== true}
              >
                {keyAction === 'reveal'
                  ? <><Loader2 className="spin" size={15} aria-hidden /> Ophalen…</>
                  : <><Eye size={15} aria-hidden /> Stream Key tonen</>}
              </button>
            ) : (
              <button
                className="secondary-button"
                type="button"
                onClick={hideStreamKey}
                disabled={keyActionBusy}
              >
                <EyeOff size={15} aria-hidden /> Verbergen
              </button>
            )}
            <button
              className="danger-button"
              type="button"
              onClick={() => void rotateStreamKey()}
              disabled={keyActionBusy || !keyManagementReady}
            >
              {keyAction === 'rotate'
                ? <><Loader2 className="spin" size={15} aria-hidden /> Wisselen…</>
                : <><RotateCw size={15} aria-hidden /> Stream Key wisselen</>}
            </button>
          </span>
        </header>

        {visibleStreamKey === null ? null : (
          <div className={styles.revealedKey}>
            <label htmlFor={streamKeyInputId}>Stream Key</label>
            <div className={styles.keyValueRow}>
              <input
                id={streamKeyInputId}
                className={styles.keyValue}
                type="text"
                value={visibleStreamKey}
                readOnly
                autoComplete="off"
                autoCapitalize="none"
                spellCheck={false}
                onFocus={(event) => event.currentTarget.select()}
              />
              <button
                className="secondary-button"
                type="button"
                onClick={() => void copyStreamKey()}
                disabled={keyActionBusy}
              >
                <Copy size={15} aria-hidden /> Kopiëren
              </button>
            </div>
            <small>Bewaar deze key als wachtwoord en deel hem alleen met de beheerder van OBS.</small>
          </div>
        )}

        {copyStatus === null ? null : (
          <p className={styles.copyStatus} role="status" aria-live="polite">
            <CheckCircle2 size={16} aria-hidden /> {copyStatus}
          </p>
        )}

        {actionError === null ? null : (
          <p className={styles.actionError} role="alert">
            <AlertTriangle size={16} aria-hidden /> {actionError}
          </p>
        )}

        {rotationNotice === null ? null : (
          <div className={styles.rotationAlert} role="alert" aria-atomic="true">
            <AlertTriangle size={19} aria-hidden />
            <span>
              <strong>Nieuwe Stream Key actief</strong>
              <small>
                De oude Stream Key werkt niet meer. Werk de nieuwe key nu bij in OBS en start de stream opnieuw.
              </small>
              <small>
                Gewisseld op {formatRotationTime(rotationNotice.rotatedAt)}
                {rotationNotice.previousKeyRevoked && rotationNotice.obsReconnectRequired
                  ? ' · oude key ingetrokken · OBS moet opnieuw verbinden'
                  : ''}
              </small>
            </span>
          </div>
        )}
      </section>

      <section className={styles.instructions} aria-label="OBS uitvoerinstellingen">
        <strong>OBS-uitvoer</strong>
        <p>
          Kies in OBS bij Stream de service Custom..., neem de server hierboven over en vul de apart
          beheerde code in bij Stream Key. Stel video in op H.264, audio op AAC en het
          keyframe-interval op 2 seconden.
        </p>
        <small>Haal de Stream Key hierboven beveiligd op. De live-uitzending speelt op wallboards automatisch en zonder geluid af.</small>
      </section>
    </fieldset>
  );
}

function formatRotationTime(value: string): string {
  const timestamp = Date.parse(value);
  if (!Number.isFinite(timestamp)) return 'onbekend tijdstip';

  return new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'short',
    timeStyle: 'medium',
    timeZone: 'Europe/Amsterdam',
  }).format(new Date(timestamp));
}

function formatLastPacket(value: string | null): string {
  if (value === null) return 'Nog geen signaal ontvangen';
  const timestamp = Date.parse(value);
  if (!Number.isFinite(timestamp)) return 'Onbekend';

  return new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'short',
    timeStyle: 'medium',
    timeZone: 'Europe/Amsterdam',
  }).format(new Date(timestamp));
}
