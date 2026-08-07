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
  Save,
  Settings2,
  ShieldCheck,
  Undo2,
} from 'lucide-react';
import { useConfirmDialog } from '../../components/ConfirmDialogContext';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type {
  WallboardLiveStreamAdminStatus,
  WallboardLiveStreamConfiguration,
  WallboardLiveStreamConfigurationRequest,
  WallboardLiveStreamConfigurationUpdate,
  WallboardLiveStreamStreamKey,
  WallboardLiveStreamStreamKeyRotation,
  WallboardLiveStreamStatusValue,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import styles from './WallboardLiveStreamPageEditor.module.css';

const STATUS_REFRESH_MILLISECONDS = 5_000;
const HOSTNAME_LABEL_PATTERN = /^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/;
const DECIMAL_INTEGER_PATTERN = /^\d+$/;
const DOTTED_IPV4_PATTERN = /^(?:\d{1,3}\.){3}\d{1,3}$/;
const TLS_PATH_PATTERN = /^\/[A-Za-z0-9._/-]+$/;

interface LiveStreamConfigurationDraft {
  enabled: boolean;
  publicHost: string;
  rtmpsBindAddress: string;
  rtmpsPort: string;
  tlsCertificatePath: string;
  tlsPrivateKeyPath: string;
}

interface ConfigurationNotice {
  configurationChanged: boolean;
  enabled: boolean;
  keyCreated: boolean;
}

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
  const [configurationDraft, setConfigurationDraft] = useState<LiveStreamConfigurationDraft | null>(null);
  const [configurationBaseline, setConfigurationBaseline] = useState<WallboardLiveStreamConfiguration | null>(null);
  const [configurationBaselineRevision, setConfigurationBaselineRevision] = useState<string | null>(null);
  const [configurationSaving, setConfigurationSaving] = useState(false);
  const [configurationError, setConfigurationError] = useState<string | null>(null);
  const [configurationNotice, setConfigurationNotice] = useState<ConfigurationNotice | null>(null);
  const [rotationNotice, setRotationNotice] = useState<{
    obsReconnectRequired: boolean;
    previousKeyRevoked: boolean;
    rotatedAt: string;
  } | null>(null);
  const status = statusResource.data;
  const activeStreamKeyVersion = status?.stream_key_version ?? null;
  const keyManagementReady = status !== null && activeStreamKeyVersion !== null;
  const visibleStreamKey = streamKeyVersion === activeStreamKeyVersion ? streamKey : null;
  const controlsBusy = keyAction !== null || configurationSaving;
  const configurationHasChanges = configurationDraft !== null && configurationBaseline !== null
    && connectionConfigurationChanged(configurationBaseline, configurationPayload(configurationDraft), true);
  const configurationNeedsKey = status?.configuration.enabled === true
    && status.stream_key_configured === false;
  const configurationCanSubmit = configurationHasChanges || configurationNeedsKey;
  const configurationIsStale = configurationHasChanges
    && status !== null
    && configurationBaselineRevision !== status.configuration_revision;

  useEffect(() => {
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible' && !configurationSaving) void silentReload();
    }, STATUS_REFRESH_MILLISECONDS);

    return () => window.clearInterval(timer);
  }, [configurationSaving, silentReload]);

  useEffect(() => {
    if (status === null || configurationHasChanges || configurationSaving) return;

    setConfigurationDraft(configurationDraftFrom(status.configuration));
    setConfigurationBaseline(status.configuration);
    setConfigurationBaselineRevision(status.configuration_revision);
  }, [configurationHasChanges, configurationSaving, status]);

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

  function updateConfiguration<K extends keyof LiveStreamConfigurationDraft>(
    field: K,
    value: LiveStreamConfigurationDraft[K],
  ) {
    setConfigurationDraft((current) => current === null ? current : { ...current, [field]: value });
    setConfigurationError(null);
    setConfigurationNotice(null);
  }

  function resetConfiguration() {
    if (status === null) return;

    setConfigurationDraft(configurationDraftFrom(status.configuration));
    setConfigurationBaseline(status.configuration);
    setConfigurationBaselineRevision(status.configuration_revision);
    setConfigurationError(null);
    setConfigurationNotice(null);
  }

  async function saveConfiguration() {
    if (status === null
      || configurationDraft === null
      || configurationBaseline === null
      || configurationBaselineRevision === null
      || !configurationCanSubmit
      || configurationIsStale
      || controlsBusy) return;

    const validationError = validateConfiguration(configurationDraft);
    if (validationError !== null) {
      setConfigurationError(validationError);
      return;
    }

    const nextConfiguration = configurationPayload(configurationDraft);
    const connectionChanges = connectionConfigurationChanged(configurationBaseline, nextConfiguration);
    if (configurationBaseline.enabled && (!nextConfiguration.enabled || connectionChanges)) {
      const disabling = !nextConfiguration.enabled;
      const confirmed = await confirmAction({
        title: disabling ? 'OBS-ingang uitschakelen?' : 'OBS-ingang opnieuw configureren?',
        message: disabling
          ? 'De actieve OBS-verbinding stopt direct en live-uitzendingen verdwijnen van de wallboards. De Stream Key blijft beveiligd bewaard.'
          : 'De livestreamservices worden opnieuw geladen. Een actieve OBS-verbinding kan kort worden onderbroken en moet bij een gewijzigd serveradres opnieuw verbinden.',
        confirmLabel: disabling ? 'Ja, ingang uitschakelen' : 'Configuratie toepassen',
        intent: disabling ? 'danger' : 'warning',
      });
      if (!confirmed) return;
    }

    setConfigurationSaving(true);
    setConfigurationError(null);
    setConfigurationNotice(null);
    try {
      const response = await api.post<WallboardLiveStreamConfigurationUpdate>(
        '/admin/wallboard-live-stream/configuration',
        configurationRequestPayload(nextConfiguration, configurationBaselineRevision),
      );
      statusResource.mutate(response.data.status);
      setConfigurationDraft(configurationDraftFrom(response.data.status.configuration));
      setConfigurationBaseline(response.data.status.configuration);
      setConfigurationBaselineRevision(response.data.status.configuration_revision);
      setConfigurationNotice({
        configurationChanged: response.data.configuration_changed,
        enabled: response.data.status.configuration.enabled,
        keyCreated: response.data.key_created,
      });
      void silentReload();
    } catch (error) {
      setConfigurationError(error instanceof ApiClientError
        ? error.message
        : 'Livestreamconfiguratie opslaan mislukt.');
      if (error instanceof ApiClientError
        && ['wallboard_live_stream_configuration_changed', 'wallboard_live_stream_configuration_update_failed']
          .includes(error.code)) {
        void silentReload();
      }
    } finally {
      setConfigurationSaving(false);
    }
  }

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
    <fieldset className={styles.editor} aria-busy={statusResource.loading || controlsBusy}>
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

      {configurationDraft === null ? null : (
        <section
          className={styles.configuration}
          aria-labelledby={`${streamKeyInputId}-configuration-title`}
        >
          <header className={styles.configurationHeading}>
            <span className={styles.configurationIcon} aria-hidden><Settings2 size={18} /></span>
            <span>
              <strong id={`${streamKeyInputId}-configuration-title`}>Verbindingsinstellingen</strong>
              <small>Stel hier de beveiligde OBS-ingang in. De server maakt de eerste Stream Key zelf aan.</small>
            </span>
            <label className={styles.enabledControl}>
              <input
                type="checkbox"
                checked={configurationDraft.enabled}
                onChange={(event) => updateConfiguration('enabled', event.currentTarget.checked)}
                disabled={controlsBusy}
              />
              <span>Ingeschakeld</span>
            </label>
          </header>

          <div className={styles.configurationGrid}>
            <label className={styles.configurationField}>
              <span>Publieke hostnaam</span>
              <input
                type="text"
                value={configurationDraft.publicHost}
                onChange={(event) => updateConfiguration('publicHost', event.currentTarget.value)}
                placeholder="ingest.example.nl"
                autoComplete="off"
                autoCapitalize="none"
                spellCheck={false}
                disabled={controlsBusy}
              />
              <small>Zonder <code>rtmps://</code>, poort of pad.</small>
            </label>
            <label className={styles.configurationField}>
              <span>RTMPS-poort</span>
              <input
                type="number"
                min="1024"
                max="65535"
                step="1"
                value={configurationDraft.rtmpsPort}
                onChange={(event) => updateConfiguration('rtmpsPort', event.currentTarget.value)}
                inputMode="numeric"
                disabled={controlsBusy}
              />
              <small>Standaard: <code>1936</code>.</small>
            </label>
            <label className={`${styles.configurationField} ${styles.wideConfigurationField}`}>
              <span>TLS-certificaatpad</span>
              <input
                className={styles.pathInput}
                type="text"
                value={configurationDraft.tlsCertificatePath}
                onChange={(event) => updateConfiguration('tlsCertificatePath', event.currentTarget.value)}
                placeholder="/etc/letsencrypt/live/ingest.example.nl/fullchain.pem"
                autoComplete="off"
                autoCapitalize="none"
                spellCheck={false}
                disabled={controlsBusy}
              />
            </label>
            <label className={`${styles.configurationField} ${styles.wideConfigurationField}`}>
              <span>TLS-private-keypad</span>
              <input
                className={styles.pathInput}
                type="text"
                value={configurationDraft.tlsPrivateKeyPath}
                onChange={(event) => updateConfiguration('tlsPrivateKeyPath', event.currentTarget.value)}
                placeholder="/etc/letsencrypt/live/ingest.example.nl/privkey.pem"
                autoComplete="off"
                autoCapitalize="none"
                spellCheck={false}
                disabled={controlsBusy}
              />
              <small>Vul alleen serverpaden in; certificaatbestanden verlaten de server niet.</small>
            </label>
          </div>

          <details className={styles.advancedConfiguration}>
            <summary>Geavanceerd luisteradres</summary>
            <label className={styles.configurationField}>
              <span>RTMPS-bindadres</span>
              <input
                className={styles.pathInput}
                type="text"
                value={configurationDraft.rtmpsBindAddress}
                onChange={(event) => updateConfiguration('rtmpsBindAddress', event.currentTarget.value)}
                placeholder="0.0.0.0"
                autoComplete="off"
                autoCapitalize="none"
                spellCheck={false}
                disabled={controlsBusy}
              />
              <small>Gebruik normaal <code>0.0.0.0</code>; alleen lokale IPv4-adressen zijn toegestaan.</small>
            </label>
          </details>

          <div className={configurationDraft.enabled ? styles.safetyNote : styles.disabledNote}>
            <ShieldCheck size={18} aria-hidden />
            <span>
              <strong>{configurationDraft.enabled ? 'Controle vóór inschakelen' : 'OBS-ingang blijft uit'}</strong>
              <small>
                {configurationDraft.enabled
                  ? 'De hostnaam moet via DNS naar deze server wijzen en het certificaat moet ervoor geldig zijn. Beperk de ingest-poort in de firewall tot het OBS-adres of vertrouwde VPN.'
                  : 'Je kunt de instellingen alvast bewaren. Een bestaande Stream Key blijft beveiligd bewaard.'}
              </small>
            </span>
          </div>

          <div className={styles.configurationActions}>
            <button
              className="secondary-button"
              type="button"
              onClick={resetConfiguration}
              disabled={!configurationHasChanges || controlsBusy}
            >
              <Undo2 size={15} aria-hidden /> {configurationIsStale ? 'Nieuwste laden' : 'Ongedaan maken'}
            </button>
            <button
              className="primary-button"
              type="button"
              onClick={() => void saveConfiguration()}
              disabled={!configurationCanSubmit || configurationIsStale || controlsBusy}
            >
              {configurationSaving
                ? <><Loader2 className="spin" size={15} aria-hidden /> Opslaan…</>
                : configurationNeedsKey && !configurationHasChanges
                  ? <><KeyRound size={15} aria-hidden /> Eerste Stream Key aanmaken</>
                  : <><Save size={15} aria-hidden /> Instellingen opslaan</>}
            </button>
          </div>

          {configurationError === null || configurationIsStale ? null : (
            <p className={styles.actionError} role="alert">
              <AlertTriangle size={16} aria-hidden /> {configurationError}
            </p>
          )}

          {configurationIsStale ? (
            <p className={styles.actionError} role="alert">
              <AlertTriangle size={16} aria-hidden />
              De configuratie is intussen door een andere beheerder gewijzigd. Laad de nieuwste instellingen voordat je verdergaat.
            </p>
          ) : null}

          {configurationNotice === null ? null : (
            <p className={styles.configurationNotice} role="status" aria-live="polite">
              <CheckCircle2 size={16} aria-hidden /> {configurationNoticeText(configurationNotice)}
            </p>
          )}
        </section>
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
                disabled={controlsBusy || !keyManagementReady || status?.stream_key_configured !== true}
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
              disabled={controlsBusy || !keyManagementReady}
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

function configurationDraftFrom(configuration: WallboardLiveStreamConfiguration): LiveStreamConfigurationDraft {
  return {
    enabled: configuration.enabled,
    publicHost: configuration.public_host,
    rtmpsBindAddress: configuration.rtmps_bind_address,
    rtmpsPort: String(configuration.rtmps_port),
    tlsCertificatePath: configuration.tls_certificate_path,
    tlsPrivateKeyPath: configuration.tls_private_key_path,
  };
}

function configurationPayload(draft: LiveStreamConfigurationDraft): WallboardLiveStreamConfiguration {
  return {
    enabled: draft.enabled,
    public_host: draft.publicHost.trim().toLowerCase(),
    rtmps_bind_address: draft.rtmpsBindAddress.trim(),
    rtmps_port: Number(draft.rtmpsPort.trim()),
    tls_certificate_path: draft.tlsCertificatePath.trim(),
    tls_private_key_path: draft.tlsPrivateKeyPath.trim(),
  };
}

function configurationRequestPayload(
  configuration: WallboardLiveStreamConfiguration,
  revision: string,
): WallboardLiveStreamConfigurationRequest {
  return {
    ...configuration,
    configuration_revision: revision,
  };
}

function connectionConfigurationChanged(
  current: WallboardLiveStreamConfiguration,
  next: WallboardLiveStreamConfiguration,
  includeEnabled = false,
): boolean {
  return (includeEnabled && current.enabled !== next.enabled)
    || current.public_host !== next.public_host
    || current.rtmps_bind_address !== next.rtmps_bind_address
    || current.rtmps_port !== next.rtmps_port
    || current.tls_certificate_path !== next.tls_certificate_path
    || current.tls_private_key_path !== next.tls_private_key_path;
}

function validateConfiguration(draft: LiveStreamConfigurationDraft): string | null {
  const publicHost = draft.publicHost.trim();
  const bindAddress = draft.rtmpsBindAddress.trim();
  const portValue = draft.rtmpsPort.trim();
  const certificatePath = draft.tlsCertificatePath.trim();
  const privateKeyPath = draft.tlsPrivateKeyPath.trim();

  if (!validBindAddress(bindAddress)) {
    return 'Vul een geldig lokaal IPv4-bindadres in, bijvoorbeeld 0.0.0.0.';
  }
  if (!DECIMAL_INTEGER_PATTERN.test(portValue)) {
    return 'Vul een hele RTMPS-poort in.';
  }
  const port = Number(portValue);
  if (!Number.isInteger(port) || port < 1024 || port > 65_535 || port === 19_350 || port === 19_351) {
    return 'Kies een RTMPS-poort van 1024 t/m 65535; 19350 en 19351 zijn intern gereserveerd.';
  }
  if (publicHost !== '' && !validPublicHost(publicHost)) {
    return 'Vul een hostnaam of IPv4-adres in zonder schema, poort of pad.';
  }
  if (certificatePath !== '' && !validPortalTlsPath(certificatePath)) {
    return 'Het certificaatpad moet onder /etc/letsencrypt/live/ of /etc/ssl/ staan.';
  }
  if (privateKeyPath !== '' && !validPortalTlsPath(privateKeyPath)) {
    return 'Het private-keypad moet onder /etc/letsencrypt/live/ of /etc/ssl/ staan.';
  }
  if (draft.enabled && publicHost === '') {
    return 'Vul eerst de publieke hostnaam in.';
  }
  if (draft.enabled && certificatePath === '') {
    return 'Vul eerst het TLS-certificaatpad in.';
  }
  if (draft.enabled && privateKeyPath === '') {
    return 'Vul eerst het TLS-private-keypad in.';
  }

  return null;
}

function validBindAddress(value: string): boolean {
  return value === '0.0.0.0' || validUnicastIpv4(value);
}

function validPublicHost(value: string): boolean {
  if (validIpv4(value)) return validUnicastIpv4(value);
  if (DOTTED_IPV4_PATTERN.test(value)) return false;
  if (value.length === 0 || value.length > 253) return false;

  return value.split('.').every((label) => HOSTNAME_LABEL_PATTERN.test(label));
}

function validUnicastIpv4(value: string): boolean {
  if (!validIpv4(value)) return false;
  const firstOctet = Number(value.split('.')[0]);

  return firstOctet > 0 && firstOctet < 224 && value !== '255.255.255.255';
}

function validIpv4(value: string): boolean {
  const octets = value.split('.');
  return octets.length === 4 && octets.every((octet) => {
    if (!DECIMAL_INTEGER_PATTERN.test(octet) || (octet.length > 1 && octet.startsWith('0'))) return false;
    const number = Number(octet);

    return number >= 0 && number <= 255;
  });
}

function validPortalTlsPath(value: string): boolean {
  if (value.length > 4_096
    || !TLS_PATH_PATTERN.test(value)
    || /[\u0000-\u001F\u007F]/.test(value)
    || value.includes('//')
    || value.endsWith('/')) return false;
  const segments = value.split('/');

  return !segments.includes('.')
    && !segments.includes('..')
    && (value.startsWith('/etc/letsencrypt/live/') || value.startsWith('/etc/ssl/'));
}

function configurationNoticeText(notice: ConfigurationNotice): string {
  if (notice.keyCreated) {
    return 'Configuratie actief en eerste Stream Key aangemaakt. Gebruik Stream Key tonen om hem veilig op te halen.';
  }
  if (!notice.configurationChanged) return 'De opgeslagen configuratie was al actueel.';
  if (notice.enabled) return 'Livestreamconfiguratie actief. Gebruik het getoonde serveradres in OBS.';

  return 'OBS-ingang uitgeschakeld. De Stream Key blijft beveiligd bewaard.';
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
