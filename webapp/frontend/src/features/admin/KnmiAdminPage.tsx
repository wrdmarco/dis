import { CloudSun, Database, DownloadCloud, RadioTower } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type {
  KnmiAdminStatus,
  KnmiForecastOperation,
  KnmiForecastOperationStarted,
  KnmiForecastSnapshot,
  KnmiPrecipitationRefreshStarted,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import styles from './KnmiAdminPage.module.css';
import {
  buildKnmiKeyPayload,
  formatKnmiBytes,
  KNMI_ACTIVE_POLL_INTERVAL_MS,
  knmiKeySourceLabel,
  knmiOperationIsActive,
  knmiOperationStageLabel,
  knmiOperationStateLabel,
  knmiOperationStateTone,
  normalizeKnmiProgress,
} from './knmiAdminPresentation';

interface KnmiKeyForm {
  openDataApiKey: string;
  edrApiKey: string;
}

const EMPTY_KEY_FORM: KnmiKeyForm = {
  openDataApiKey: '',
  edrApiKey: '',
};

export function KnmiAdminPage() {
  const { api, hasPermission } = useAuth();
  const canManage = hasPermission('settings.manage');
  const status = useApiResource<KnmiAdminStatus>('/admin/knmi', canManage);
  const [keyForm, setKeyForm] = useState<KnmiKeyForm>(EMPTY_KEY_FORM);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saveMessage, setSaveMessage] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);
  const [operationError, setOperationError] = useState<string | null>(null);
  const [startingPrecipitation, setStartingPrecipitation] = useState(false);
  const [precipitationError, setPrecipitationError] = useState<string | null>(null);
  const [precipitationMessage, setPrecipitationMessage] = useState<string | null>(null);
  const operation = status.data?.active_operation ?? status.data?.latest_operation ?? null;
  const operationActive = knmiOperationIsActive(operation?.state);
  const reloadStatusSilently = status.silentReload;

  useEffect(() => {
    if (!operationActive) {
      return undefined;
    }

    const intervalId = window.setInterval(() => {
      void reloadStatusSilently();
    }, KNMI_ACTIVE_POLL_INTERVAL_MS);

    return () => window.clearInterval(intervalId);
  }, [operationActive, reloadStatusSilently]);

  async function saveKeys() {
    const payload = buildKnmiKeyPayload(keyForm);
    if (Object.keys(payload).length === 0) {
      setSaveError('Vul minimaal één nieuwe API-key in. Lege velden blijven ongewijzigd.');
      return;
    }

    setSaving(true);
    setSaveError(null);
    setSaveMessage(null);
    try {
      const response = await api.patch<KnmiAdminStatus>('/admin/knmi', payload);
      status.mutate(response.data);
      setKeyForm(EMPTY_KEY_FORM);
      setSaveMessage('KNMI API-instellingen opgeslagen.');
    } catch (error) {
      setSaveError(error instanceof ApiClientError ? error.message : 'KNMI API-instellingen opslaan mislukt.');
    } finally {
      setSaving(false);
    }
  }

  async function refreshForecast() {
    setStarting(true);
    setOperationError(null);
    try {
      const response = await api.post<KnmiForecastOperationStarted>('/admin/knmi/refresh');
      status.mutate((current) => current === null ? current : {
        ...current,
        active_operation: response.data.operation,
        latest_operation: response.data.operation,
      });
    } catch (error) {
      setOperationError(error instanceof ApiClientError ? error.message : 'KNMI-gegevens bijwerken kon niet worden gestart.');
      if (error instanceof ApiClientError && error.status === 409) {
        await reloadStatusSilently();
      }
    } finally {
      setStarting(false);
    }
  }

  async function refreshPrecipitation() {
    setStartingPrecipitation(true);
    setPrecipitationError(null);
    setPrecipitationMessage(null);
    try {
      await api.post<KnmiPrecipitationRefreshStarted>('/admin/knmi/precipitation/refresh');
      setPrecipitationMessage('De lokale radar- en onweerskansbestanden worden op de server bijgewerkt.');
    } catch (error) {
      setPrecipitationError(error instanceof ApiClientError ? error.message : 'KNMI-neerslagdata bijwerken kon niet worden gestart.');
    } finally {
      setStartingPrecipitation(false);
    }
  }

  return (
    <div className={`page-stack ${styles.page}`}>
      <Panel
        title="KNMI modelgegevens"
        action={<ModelStatus loading={status.loading && status.data === null} snapshot={status.data?.active_snapshot ?? null} operation={operation} />}
      >
        <ResourceState loading={status.loading} error={status.data === null ? status.error : null} empty={status.data === null}>
          {status.data ? (
            <div className={styles.sectionBody}>
              {status.error ? (
                <p className={styles.staleWarning} role="status">
                  De actuele status kon tijdelijk niet worden opgehaald. De laatst bekende gegevens blijven zichtbaar.
                </p>
              ) : null}

              <div className={styles.modelIntro}>
                <span className={styles.sourceIcon} aria-hidden><CloudSun size={23} /></span>
                <div>
                  <strong>HARMONIE-AROME P1</strong>
                  <p>Een modelverwachting voor Nederland, geen meting. D.I.S. bewaart de volledige actuele set en kiest voor de UAV Forecast automatisch het juiste forecastuur.</p>
                </div>
              </div>

              <ForecastWindow snapshot={status.data.active_snapshot} />

              <dl className={styles.snapshotGrid}>
                <SnapshotFact label="Actieve modelrun" value={formatDateTime(status.data.active_snapshot?.model_run_at)} />
                <SnapshotFact label="Forecasturen" value={memberCountLabel(status.data.active_snapshot)} />
                <SnapshotFact label="Bronarchief" value={formatKnmiBytes(status.data.active_snapshot?.source_size_bytes)} />
                <SnapshotFact label="Laatst geactiveerd" value={formatDateTime(status.data.active_snapshot?.activated_at)} />
              </dl>

              <div className={styles.sourceComparison}>
                <article>
                  <span className={styles.sourceIcon} aria-hidden><Database size={20} /></span>
                  <div>
                    <strong>HARMONIE · verwachting</strong>
                    <p>Vooruitblik tot ongeveer 60 uur, inclusief lage bewolking en modelwolkenbasis. Waarden komen uit een rekenmodel.</p>
                  </div>
                </article>
                <article>
                  <span className={`${styles.sourceIcon} ${styles.sourceIconMeasured}`} aria-hidden><RadioTower size={20} /></span>
                  <div>
                    <strong>EDR · gemeten stationdata</strong>
                    <p>Actuele waarnemingen van KNMI-meetstations. D.I.S. toont deze als afzonderlijke meting en mengt ze niet met het modelpercentage.</p>
                  </div>
                </article>
              </div>
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      <Panel title="Import en actualiteit">
        <ResourceState loading={status.loading} error={status.data === null ? status.error : null} empty={status.data === null}>
          {status.data ? (
            <div className={styles.sectionBody}>
              <ImportStatus operation={operation} snapshot={status.data.active_snapshot} />
              {operationError ? <p className="form-error" role="alert">{operationError}</p> : null}
              {precipitationError ? <p className="form-error" role="alert">{precipitationError}</p> : null}
              {precipitationMessage ? <p className="success-text" role="status">{precipitationMessage}</p> : null}
              <div className={styles.actionsRow}>
                <button
                  className="secondary-button"
                  type="button"
                  disabled={!canManage || startingPrecipitation || !status.data.configuration.configured}
                  onClick={() => void refreshPrecipitation()}
                >
                  <DownloadCloud aria-hidden size={18} />
                  {startingPrecipitation ? 'Neerslagdata wordt aangevraagd...' : 'Radar en onweerskans bijwerken'}
                </button>
                <button
                  className="primary-button"
                  type="button"
                  disabled={!canManage || starting || operationActive || !status.data.configuration.configured}
                  onClick={() => void refreshForecast()}
                >
                  <DownloadCloud aria-hidden size={18} />
                  {starting || operationActive ? 'HARMONIE wordt bijgewerkt...' : 'HARMONIE-modelset bijwerken'}
                </button>
              </div>
              {!status.data.configuration.configured ? (
                <p className={styles.actionHint}>Stel eerst de KNMI Open Data API-key in om gegevens te downloaden.</p>
              ) : (
                <p className={styles.actionHint}>Radar en onweerskans worden daarnaast automatisch iedere vijf minuten gecontroleerd.</p>
              )}
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      <Panel title="KNMI API-instellingen">
        <ResourceState loading={status.loading} error={status.data === null ? status.error : null} empty={status.data === null}>
          {status.data ? (
            <form
              className={styles.settingsForm}
              aria-busy={saving}
              onSubmit={(event) => {
                event.preventDefault();
                void saveKeys();
              }}
            >
              <fieldset className={styles.settingsGroup}>
                <legend>Open Data · HARMONIE-model</legend>
                <p>Deze koppeling downloadt het volledige actuele modelarchief. De API-key en tijdelijke downloadlink blijven op de D.I.S.-server.</p>
                <dl className={styles.configurationFacts}>
                  <dt>Open Data-toegang</dt>
                  <dd>{configuredLabel(status.data.configuration.configured)}</dd>
                  <dt>Eigen Open Data-sleutel</dt>
                  <dd>{configuredLabel(status.data.configuration.open_data_api_key_configured)}</dd>
                  <dt>Herkomst sleutel</dt>
                  <dd>{status.data.configuration.open_data_api_key_source
                    ? knmiKeySourceLabel(status.data.configuration.open_data_api_key_source)
                    : status.data.configuration.configured ? 'Bestaande compatibele KNMI-sleutel' : 'Niet ingesteld'}</dd>
                  <dt>Dataset</dt>
                  <dd className="mono">{status.data.configuration.dataset} · {status.data.configuration.dataset_version}</dd>
                  <dt>Automatische controle</dt>
                  <dd>Elke {status.data.configuration.automatic_interval_hours} uur</dd>
                </dl>
                <label>
                  Vast Open Data endpoint
                  <input className="mono" readOnly value={status.data.configuration.open_data_endpoint} />
                </label>
                <label>
                  Nieuwe Open Data API-key
                  <input
                    autoComplete="new-password"
                    type="password"
                    value={keyForm.openDataApiKey}
                    placeholder={secretPlaceholder(status.data.configuration.open_data_api_key_configured)}
                    onChange={(event) => setKeyForm((current) => ({ ...current, openDataApiKey: event.target.value }))}
                  />
                  <small>Laat leeg om de ingestelde sleutel te behouden.</small>
                </label>
              </fieldset>

              <fieldset className={styles.settingsGroup}>
                <legend>EDR · gemeten stationdata</legend>
                <p>Deze aparte koppeling levert recente waarnemingen van meetstations, zoals een gemeten wolkenbasis wanneer die beschikbaar is.</p>
                <dl className={styles.configurationFacts}>
                  <dt>Status</dt>
                  <dd>{configuredLabel(status.data.configuration.edr_api_key_configured)}</dd>
                  <dt>Herkomst sleutel</dt>
                  <dd>{knmiKeySourceLabel(status.data.configuration.edr_api_key_source)}</dd>
                </dl>
                <label>
                  Vaste EDR-collectie
                  <input className="mono" readOnly value={status.data.configuration.edr_collection_endpoint} />
                </label>
                <label>
                  Nieuwe EDR API-key
                  <input
                    autoComplete="new-password"
                    type="password"
                    value={keyForm.edrApiKey}
                    placeholder={secretPlaceholder(status.data.configuration.edr_api_key_configured)}
                    onChange={(event) => setKeyForm((current) => ({ ...current, edrApiKey: event.target.value }))}
                  />
                  <small>Laat leeg om de ingestelde sleutel te behouden.</small>
                </label>
              </fieldset>

              {saveError ? <p className="form-error" role="alert">{saveError}</p> : null}
              {saveMessage ? <p className="success-text" role="status">{saveMessage}</p> : null}
              <div className={styles.actionsRow}>
                <button className="primary-button" type="submit" disabled={saving}>
                  {saving ? 'KNMI-instellingen opslaan...' : 'KNMI-instellingen opslaan'}
                </button>
              </div>
            </form>
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}

function ForecastWindow({ snapshot }: { snapshot: KnmiForecastSnapshot | null }) {
  if (snapshot === null) {
    return (
      <div className={styles.windowEmpty} role="status">
        Nog geen geldige HARMONIE-modelset geactiveerd.
      </div>
    );
  }

  return (
    <section
      className={styles.forecastWindow}
      aria-label={`Verwachtingsvenster van ${formatDateTime(snapshot.forecast_start_at)} tot ${formatDateTime(snapshot.forecast_end_at)}`}
    >
      <div className={styles.windowHeading}>
        <strong>Verwachtingsvenster</strong>
        <span>{snapshot.member_count} forecastmomenten</span>
      </div>
      <div className={styles.windowTrack} aria-hidden>
        <i />
        <i />
        <i />
      </div>
      <div className={styles.windowLabels}>
        <span><b>Modelrun</b><time dateTime={snapshot.forecast_start_at}>{formatDateTime(snapshot.forecast_start_at)}</time></span>
        <span><b>+30 uur</b><small>tussenpunt</small></span>
        <span><b>+60 uur</b><time dateTime={snapshot.forecast_end_at}>{formatDateTime(snapshot.forecast_end_at)}</time></span>
      </div>
    </section>
  );
}

function SnapshotFact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt>{label}</dt>
      <dd>{value}</dd>
    </div>
  );
}

function ModelStatus({
  loading,
  snapshot,
  operation,
}: {
  loading: boolean;
  snapshot: KnmiForecastSnapshot | null;
  operation: KnmiForecastOperation | null;
}) {
  if (loading) {
    return <StatusPill value="Status laden" tone="neutral" />;
  }
  if (operation && knmiOperationIsActive(operation.state)) {
    return <StatusPill value="Import actief" tone="warn" />;
  }
  if (snapshot === null) {
    return <StatusPill value="Niet beschikbaar" tone="bad" />;
  }

  const forecastEnd = new Date(snapshot.forecast_end_at).getTime();
  const expired = Number.isFinite(forecastEnd) && forecastEnd < Date.now();
  return <StatusPill value={expired ? 'Modelset verlopen' : 'Modelset beschikbaar'} tone={expired ? 'warn' : 'good'} />;
}

function ImportStatus({ operation, snapshot }: { operation: KnmiForecastOperation | null; snapshot: KnmiForecastSnapshot | null }) {
  const progress = normalizeKnmiProgress(operation?.progress_percent);
  const active = knmiOperationIsActive(operation?.state);

  if (operation === null) {
    return (
      <div className={styles.operationEmpty} role="status">
        <strong>Nog geen import uitgevoerd</strong>
        <span>{snapshot ? 'De actieve modelset blijft beschikbaar.' : 'Start de eerste download zodra de Open Data API-key is ingesteld.'}</span>
      </div>
    );
  }

  return (
    <div className={styles.operation} aria-live="polite" aria-atomic="true" aria-busy={active}>
      <div className={styles.operationHeader}>
        <div>
          <span>Laatste controle</span>
          <strong>{knmiOperationStageLabel(operation.stage)}</strong>
        </div>
        <StatusPill value={knmiOperationStateLabel(operation.state, operation.unchanged)} tone={knmiOperationStateTone(operation.state)} />
      </div>
      <progress max={100} value={progress ?? undefined} aria-label="Voortgang KNMI-import">
        {progress === null ? 'Voortgang nog niet bekend' : `${progress}%`}
      </progress>
      <div className={styles.progressMeta}>
        <span>{progress === null ? 'Voortgang wordt bepaald' : `${progress}%`}</span>
        <span>{downloadLabel(operation.downloaded_bytes, operation.total_bytes)}</span>
      </div>
      <p>{operation.message}</p>
      <dl className={styles.operationFacts}>
        <dt>Bronbestand</dt><dd className="mono">{operation.source_filename ?? '-'}</dd>
        <dt>Gecontroleerd</dt><dd>{formatDateTime(operation.finished_at ?? operation.started_at ?? operation.created_at)}</dd>
        <dt>Laatste succesvolle set</dt><dd>{formatDateTime(snapshot?.activated_at)}</dd>
      </dl>
    </div>
  );
}

function configuredLabel(configured: boolean): string {
  return configured ? 'Ingesteld' : 'Niet ingesteld';
}

function secretPlaceholder(configured: boolean): string {
  return configured ? 'Ingesteld · ongewijzigd laten' : 'Nog niet ingesteld';
}

function memberCountLabel(snapshot: KnmiForecastSnapshot | null): string {
  return snapshot ? `${snapshot.member_count} momenten (+00 t/m +60)` : '-';
}

function downloadLabel(downloaded?: number | null, total?: number | null): string {
  if (typeof downloaded !== 'number') {
    return typeof total === 'number' ? `${formatKnmiBytes(total)} totaal` : 'Downloadomvang nog niet bekend';
  }

  return typeof total === 'number'
    ? `${formatKnmiBytes(downloaded)} van ${formatKnmiBytes(total)}`
    : `${formatKnmiBytes(downloaded)} ontvangen`;
}
