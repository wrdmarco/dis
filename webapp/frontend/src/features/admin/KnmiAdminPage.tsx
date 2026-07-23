import {
  BookOpen,
  ChevronLeft,
  ChevronRight,
  CloudSun,
  Database,
  DownloadCloud,
  ExternalLink,
  RefreshCw,
  Search,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type {
  KnmiAdminStatus,
  KnmiAdminDatasetStatus,
  KnmiCatalogItem,
  KnmiCatalogResponse,
  KnmiDatasetOperation,
  KnmiDatasetRefreshStarted,
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
  knmiDatasetCategoryLabel,
  knmiDatasetConsumerLabel,
  knmiDatasetStatusLabel,
  knmiDatasetStatusTone,
  knmiDatasetStorageModeLabel,
  knmiKeySourceLabel,
  knmiOperationIsActive,
  knmiOperationStageLabel,
  knmiOperationStateLabel,
  knmiOperationStateTone,
  normalizeKnmiProgress,
  safeKnmiSourceUrl,
} from './knmiAdminPresentation';

interface KnmiKeyForm {
  openDataApiKey: string;
  edrApiKey: string;
}

const EMPTY_KEY_FORM: KnmiKeyForm = {
  openDataApiKey: '',
  edrApiKey: '',
};

const KNMI_CATALOG_PAGE_SIZE = 20;
const KNMI_CATALOG_SEARCH_DELAY_MS = 300;

export function KnmiAdminPage() {
  const { api, hasPermission } = useAuth();
  const canManage = hasPermission('settings.manage');
  const status = useApiResource<KnmiAdminStatus>('/admin/knmi', canManage);
  const [catalogSearchInput, setCatalogSearchInput] = useState('');
  const [catalogQuery, setCatalogQuery] = useState('');
  const [catalogStatus, setCatalogStatus] = useState('');
  const [catalogLicense, setCatalogLicense] = useState('');
  const [catalogPage, setCatalogPage] = useState(1);
  const catalog = useApiResource<KnmiCatalogResponse>(
    knmiCatalogPath(catalogQuery, catalogPage, catalogStatus, catalogLicense),
    canManage,
  );
  const [keyForm, setKeyForm] = useState<KnmiKeyForm>(EMPTY_KEY_FORM);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saveMessage, setSaveMessage] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);
  const [operationError, setOperationError] = useState<string | null>(null);
  const [startingPrecipitation, setStartingPrecipitation] = useState(false);
  const [precipitationError, setPrecipitationError] = useState<string | null>(null);
  const [precipitationMessage, setPrecipitationMessage] = useState<string | null>(null);
  const [refreshingDatasetKey, setRefreshingDatasetKey] = useState<string | null>(null);
  const [datasetRefreshErrors, setDatasetRefreshErrors] = useState<Record<string, string>>({});
  const operation = status.data?.active_operation ?? status.data?.latest_operation ?? null;
  const hasDatasetInventory = Array.isArray(status.data?.datasets);
  const datasets = status.data
    ? status.data.datasets ?? legacyKnmiDatasets(status.data)
    : [];
  const datasetOperationActive = datasets.some((dataset) => knmiOperationIsActive(dataset.operation?.state));
  const operationActive = knmiOperationIsActive(operation?.state);
  const pollingActive = operationActive || datasetOperationActive;
  const reloadStatusSilently = status.silentReload;

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setCatalogQuery(catalogSearchInput.trim());
      setCatalogPage(1);
    }, KNMI_CATALOG_SEARCH_DELAY_MS);

    return () => window.clearTimeout(timeout);
  }, [catalogSearchInput]);

  useEffect(() => {
    if (!pollingActive) {
      return undefined;
    }

    const intervalId = window.setInterval(() => {
      void reloadStatusSilently();
    }, KNMI_ACTIVE_POLL_INTERVAL_MS);

    return () => window.clearInterval(intervalId);
  }, [pollingActive, reloadStatusSilently]);

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
      setPrecipitationMessage('De lokale radar- en neerslagkansbestanden worden op de server bijgewerkt.');
    } catch (error) {
      setPrecipitationError(error instanceof ApiClientError ? error.message : 'KNMI-neerslagdata bijwerken kon niet worden gestart.');
    } finally {
      setStartingPrecipitation(false);
    }
  }

  async function refreshDataset(dataset: KnmiAdminDatasetStatus) {
    if (!dataset.configured || !dataset.refreshable || dataset.category === 'available') return;

    setRefreshingDatasetKey(dataset.key);
    setDatasetRefreshErrors((current) => withoutRecordKey(current, dataset.key));
    try {
      const response = await api.post<KnmiDatasetRefreshStarted>(
        `/admin/knmi/datasets/${encodeURIComponent(dataset.key)}/refresh`,
      );
      const operationDatasetKeys = new Set([
        response.data.dataset_key,
        ...response.data.operation.dataset_keys,
      ]);
      status.mutate((current) => current === null || !Array.isArray(current.datasets)
        ? current
        : {
            ...current,
            datasets: current.datasets.map((candidate) => operationDatasetKeys.has(candidate.key)
              ? { ...candidate, operation: response.data.operation }
              : candidate),
          });
    } catch (error) {
      setDatasetRefreshErrors((current) => ({
        ...current,
        [dataset.key]: error instanceof ApiClientError
          ? error.message
          : 'Deze databron kon niet worden bijgewerkt.',
      }));
    } finally {
      setRefreshingDatasetKey(null);
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
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      <Panel
        title="Operationele databronnen"
        action={status.data ? <DatasetInventoryCount datasets={datasets} /> : undefined}
      >
        <ResourceState loading={status.loading} error={status.data === null ? status.error : null} empty={status.data === null}>
          {status.data ? (
            <DatasetInventory
              datasets={datasets}
              hasNativeInventory={hasDatasetInventory}
              refreshingDatasetKey={refreshingDatasetKey}
              refreshErrors={datasetRefreshErrors}
              onRefresh={(dataset) => void refreshDataset(dataset)}
            />
          ) : null}
        </ResourceState>
      </Panel>

      <Panel
        title="Volledige KNMI-broncatalogus"
        action={<CatalogStatus catalog={catalog.data} loading={catalog.loading} />}
      >
        <KnmiCatalog
          resource={catalog}
          searchInput={catalogSearchInput}
          selectedStatus={catalogStatus}
          selectedLicense={catalogLicense}
          onSearchInput={setCatalogSearchInput}
          onStatus={(value) => {
            setCatalogStatus(value);
            setCatalogPage(1);
          }}
          onLicense={(value) => {
            setCatalogLicense(value);
            setCatalogPage(1);
          }}
          onPage={setCatalogPage}
        />
      </Panel>

      {status.data !== null && !hasDatasetInventory ? <Panel title="Import en actualiteit">
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
                  {startingPrecipitation ? 'Neerslagdata wordt aangevraagd...' : 'Radar en neerslagkans bijwerken'}
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
                <p className={styles.actionHint}>Radar en neerslagkans worden daarnaast automatisch iedere vijf minuten gecontroleerd.</p>
              )}
            </div>
          ) : null}
        </ResourceState>
      </Panel> : null}

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
                <p>Deze aparte koppeling levert recente waarnemingen van meetstations, zoals een gemeten wolkenbasis wanneer die beschikbaar is. D.I.S. mengt ze niet met het modelpercentage.</p>
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

interface KnmiCatalogResource {
  data: KnmiCatalogResponse | null;
  loading: boolean;
  error: string | null;
  reload: () => Promise<void>;
}

function CatalogStatus({
  catalog,
  loading,
}: {
  catalog: KnmiCatalogResponse | null;
  loading: boolean;
}) {
  if (loading && catalog === null) return <StatusPill value="Catalogus laden" tone="neutral" />;
  if (catalog === null || catalog.catalog.cache_state === 'unavailable') {
    return <StatusPill value="Catalogus niet bereikbaar" tone="bad" />;
  }
  if (catalog.catalog.cache_state === 'stale') {
    return <StatusPill value={`${catalog.pagination.total} datasets · cache verouderd`} tone="warn" />;
  }

  return <StatusPill value={`${catalog.pagination.total} datasets`} tone="good" />;
}

function KnmiCatalog({
  resource,
  searchInput,
  selectedStatus,
  selectedLicense,
  onSearchInput,
  onStatus,
  onLicense,
  onPage,
}: {
  resource: KnmiCatalogResource;
  searchInput: string;
  selectedStatus: string;
  selectedLicense: string;
  onSearchInput: (value: string) => void;
  onStatus: (value: string) => void;
  onLicense: (value: string) => void;
  onPage: (page: number) => void;
}) {
  const response = resource.data;
  const sourceUrl = safeKnmiSourceUrl(response?.catalog.source_url ?? '');

  return (
    <div className={styles.catalogWorkbench} aria-busy={resource.loading}>
      <header className={styles.catalogIntro}>
        <span className={styles.catalogMark} aria-hidden><BookOpen size={21} /></span>
        <div>
          <strong>Alle KNMI-datasetrecords, rechtstreeks uit de broncatalogus</strong>
          <p>
            Zoek op product, onderwerp of datasetnaam. Alleen de bronnen in het operationele overzicht
            hierboven zijn al gekoppeld aan D.I.S.; de catalogus zelf downloadt geen datasets.
          </p>
        </div>
        <button
          className="secondary-button"
          type="button"
          disabled={resource.loading}
          onClick={() => void resource.reload()}
        >
          <RefreshCw aria-hidden size={16} />
          {resource.loading ? 'Catalogus laden…' : 'Opnieuw laden'}
        </button>
      </header>

      <div className={styles.catalogToolbar}>
        <label className={styles.catalogSearch}>
          <span>Zoeken</span>
          <span className={styles.catalogSearchField}>
            <Search aria-hidden size={17} />
            <input
              type="search"
              value={searchInput}
              placeholder="Bijvoorbeeld radar, wind of HARMONIE"
              onChange={(event) => onSearchInput(event.currentTarget.value)}
            />
          </span>
        </label>
        <label>
          <span>Datasetstatus</span>
          <select value={selectedStatus} onChange={(event) => onStatus(event.currentTarget.value)}>
            <option value="">Alle statussen</option>
            {response?.filters.statuses.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </label>
        <label>
          <span>Licentie</span>
          <select value={selectedLicense} onChange={(event) => onLicense(event.currentTarget.value)}>
            <option value="">Alle licenties</option>
            {response?.filters.licenses.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label} ({option.count})
              </option>
            ))}
          </select>
        </label>
      </div>

      {resource.error && response !== null ? (
        <p className={styles.catalogWarning} role="status">
          De catalogus kon niet opnieuw worden opgehaald. De laatst bekende resultaten blijven zichtbaar.
        </p>
      ) : null}

      <ResourceState
        loading={resource.loading && response === null}
        error={response === null ? resource.error : null}
        empty={response === null}
      >
        {response ? (
          <>
            <div className={styles.catalogMeta}>
              <p>
                <strong>{catalogResultLabel(response)}</strong>
                <span>
                  Catalogus opgehaald {formatDateTime(response.catalog.fetched_at)}
                  {response.catalog.cache_state === 'stale' ? ' · oudere cache' : ''}
                </span>
              </p>
              {sourceUrl ? (
                <a href={sourceUrl} target="_blank" rel="noreferrer">
                  Open KNMI-catalogus <ExternalLink aria-hidden size={15} />
                </a>
              ) : null}
            </div>

            {response.catalog.warning ? (
              <p className={styles.catalogWarning} role="status">{response.catalog.warning}</p>
            ) : null}

            {response.items.length === 0 ? (
              <div className={styles.catalogEmpty} role="status">
                <Search aria-hidden size={22} />
                <strong>Geen datasets gevonden</strong>
                <span>Pas de zoekterm of filters aan.</span>
              </div>
            ) : (
              <ol className={styles.catalogResults}>
                {response.items.map((item) => <CatalogItem key={item.key} item={item} />)}
              </ol>
            )}

            <CatalogPagination response={response} onPage={onPage} />
          </>
        ) : null}
      </ResourceState>
    </div>
  );
}

function CatalogItem({ item }: { item: KnmiCatalogItem }) {
  const sourceUrl = safeKnmiSourceUrl(item.source_url);
  const license = item.license_title ?? item.license_id ?? 'Licentie niet opgegeven';

  return (
    <li>
      <article className={styles.catalogItem}>
        <header>
          <div>
            <span className={styles.catalogItemKicker}>
              {catalogDatasetStatusLabel(item.status)} · {item.is_open ? 'Open data' : 'Beperkte toegang'}
            </span>
            <h3>{item.title}</h3>
            <code>{item.dataset}{item.version ? ` · versie ${item.version}` : ''}</code>
          </div>
          {sourceUrl ? (
            <a href={sourceUrl} target="_blank" rel="noreferrer" aria-label={`${item.title} in de KNMI-catalogus openen`}>
              Details <ExternalLink aria-hidden size={15} />
            </a>
          ) : null}
        </header>

        {item.description ? <p className={styles.catalogDescription}>{item.description}</p> : null}

        <div className={styles.catalogTags} aria-label="Bestandsformaten en onderwerpen">
          {item.formats.slice(0, 5).map((format) => <span key={`format-${format}`}>{format}</span>)}
          {item.topics.slice(0, 4).map((topic) => <span key={`topic-${topic}`}>{topic}</span>)}
        </div>

        <dl className={styles.catalogFacts}>
          <div><dt>Licentie</dt><dd>{license}</dd></div>
          <div><dt>Publicatie</dt><dd>{formatDateTime(item.publication_at)}</dd></div>
          <div><dt>Metadata bijgewerkt</dt><dd>{formatDateTime(item.metadata_updated_at)}</dd></div>
        </dl>
      </article>
    </li>
  );
}

function CatalogPagination({
  response,
  onPage,
}: {
  response: KnmiCatalogResponse;
  onPage: (page: number) => void;
}) {
  const { page, last_page: lastPage } = response.pagination;

  return (
    <nav className={styles.catalogPagination} aria-label="Cataloguspagina's">
      <button
        className="secondary-button"
        type="button"
        disabled={page <= 1}
        onClick={() => onPage(Math.max(1, page - 1))}
      >
        <ChevronLeft aria-hidden size={17} /> Vorige
      </button>
      <span>Pagina <strong>{page}</strong> van <strong>{Math.max(1, lastPage)}</strong></span>
      <button
        className="secondary-button"
        type="button"
        disabled={page >= lastPage}
        onClick={() => onPage(Math.min(lastPage, page + 1))}
      >
        Volgende <ChevronRight aria-hidden size={17} />
      </button>
    </nav>
  );
}

function DatasetInventoryCount({ datasets }: { datasets: KnmiAdminDatasetStatus[] }) {
  const active = datasets.filter((dataset) => dataset.category === 'active');
  const running = active.some((dataset) => knmiOperationIsActive(dataset.operation?.state));
  const current = active.filter((dataset) => dataset.status === 'current').length;
  const unavailable = active.some((dataset) => (
    dataset.status === 'unavailable' || dataset.status === 'not_configured'
  ));

  if (running) return <StatusPill value="Import actief" tone="warn" />;
  if (active.length === 0) return <StatusPill value="Geen actieve bronnen" tone="neutral" />;
  return (
    <StatusPill
      value={`${current} van ${active.length} actueel`}
      tone={unavailable ? 'bad' : current === active.length ? 'good' : 'warn'}
    />
  );
}

function DatasetInventory({
  datasets,
  hasNativeInventory,
  refreshingDatasetKey,
  refreshErrors,
  onRefresh,
}: {
  datasets: KnmiAdminDatasetStatus[];
  hasNativeInventory: boolean;
  refreshingDatasetKey: string | null;
  refreshErrors: Record<string, string>;
  onRefresh: (dataset: KnmiAdminDatasetStatus) => void;
}) {
  const operational = datasets.filter((dataset) => dataset.category !== 'available');
  const candidates = datasets.filter((dataset) => dataset.category === 'available');

  return (
    <div className={styles.datasetInventory}>
      <header className={styles.datasetInventoryIntro}>
        <span className={styles.dataRailMark} aria-hidden><Database size={20} /></span>
        <div>
          <strong>Van bron naar lokaal weerbeeld</strong>
          <p>
            De rail laat per bron zien of D.I.S. een actuele lokale set heeft, wanneer die is ververst
            en welk onderdeel de gegevens gebruikt.
          </p>
        </div>
      </header>

      {!hasNativeInventory ? (
        <p className={styles.legacyInventoryNote} role="status">
          Deze serverversie rapporteert nog geen volledige datasetinventaris. Alleen de bestaande
          HARMONIE-status is hieronder beschikbaar; gebruik de compatibele importbediening eronder.
        </p>
      ) : null}

      <DatasetRail
        datasets={operational}
        emptyMessage="Er zijn geen operationele databronnen geconfigureerd."
        heading="In gebruik door D.I.S."
        id="knmi-operational-datasets"
        refreshingDatasetKey={refreshingDatasetKey}
        refreshErrors={refreshErrors}
        onRefresh={onRefresh}
      />

      {candidates.length > 0 ? (
        <DatasetRail
          candidate
          datasets={candidates}
          emptyMessage=""
          heading="Beschikbaar in de broncatalogus"
          id="knmi-candidate-datasets"
          refreshingDatasetKey={refreshingDatasetKey}
          refreshErrors={refreshErrors}
          onRefresh={onRefresh}
        />
      ) : null}
    </div>
  );
}

function DatasetRail({
  candidate = false,
  datasets,
  emptyMessage,
  heading,
  id,
  refreshingDatasetKey,
  refreshErrors,
  onRefresh,
}: {
  candidate?: boolean;
  datasets: KnmiAdminDatasetStatus[];
  emptyMessage: string;
  heading: string;
  id: string;
  refreshingDatasetKey: string | null;
  refreshErrors: Record<string, string>;
  onRefresh: (dataset: KnmiAdminDatasetStatus) => void;
}) {
  return (
    <section className={styles.datasetGroup} aria-labelledby={id}>
      <div className={styles.datasetGroupHeading}>
        <h3 id={id}>{heading}</h3>
        <span>{datasets.length} {datasets.length === 1 ? 'bron' : 'bronnen'}</span>
      </div>
      {datasets.length === 0 ? (
        <p className={styles.datasetEmpty}>{emptyMessage}</p>
      ) : (
        <ol className={`${styles.datasetRail} ${candidate ? styles.datasetRailCandidates : ''}`}>
          {datasets.map((dataset) => (
            <DatasetRow
              key={dataset.key}
              candidate={candidate}
              dataset={dataset}
              refreshError={refreshErrors[dataset.key] ?? null}
              refreshing={refreshingDatasetKey === dataset.key}
              onRefresh={onRefresh}
            />
          ))}
        </ol>
      )}
    </section>
  );
}

function DatasetRow({
  candidate,
  dataset,
  refreshError,
  refreshing,
  onRefresh,
}: {
  candidate: boolean;
  dataset: KnmiAdminDatasetStatus;
  refreshError: string | null;
  refreshing: boolean;
  onRefresh: (dataset: KnmiAdminDatasetStatus) => void;
}) {
  const sourceUrl = safeKnmiSourceUrl(dataset.source_url);
  const operationActive = knmiOperationIsActive(dataset.operation?.state);
  const title = dataset.dataset ?? dataset.provider;
  const version = dataset.version ? `versie ${dataset.version}` : 'versie niet opgegeven';

  return (
    <li className={[
      styles.datasetRailItem,
      styles[`datasetRailItem_${dataset.status}`],
      operationActive ? styles.datasetRailItemRunning : '',
    ].filter(Boolean).join(' ')}>
      <span className={styles.datasetRailNode} aria-hidden />
      <article className={styles.datasetRow} aria-label={`${title}, ${knmiDatasetStatusLabel(dataset.status)}`}>
        <header className={styles.datasetRowHeader}>
          <div className={styles.datasetIdentity}>
            <span>{dataset.provider} · {knmiDatasetCategoryLabel(dataset.category)}</span>
            <strong>{title}</strong>
            <code>{dataset.key} · {version}</code>
          </div>
          <StatusPill
            value={operationActive ? 'Wordt bijgewerkt' : knmiDatasetStatusLabel(dataset.status)}
            tone={operationActive ? 'warn' : knmiDatasetStatusTone(dataset.status)}
          />
        </header>

        <div className={styles.datasetUsage}>
          <span>Gebruikt door</span>
          <p>{dataset.consumers.length > 0
            ? dataset.consumers.map(knmiDatasetConsumerLabel).join(' · ')
            : candidate ? 'Nog niet gekoppeld aan D.I.S.' : 'Geen verbruiker gerapporteerd'}</p>
        </div>

        {candidate ? (
          <div className={styles.datasetCandidate}>
            <p>{dataset.availability_note ?? 'Dit bronproduct is beschikbaar, maar wordt nog niet lokaal door D.I.S. verwerkt.'}</p>
            {sourceUrl ? (
              <a href={sourceUrl} target="_blank" rel="noreferrer">
                Bekijk broncatalogus <ExternalLink aria-hidden size={15} />
              </a>
            ) : null}
          </div>
        ) : (
          <>
            <dl className={styles.datasetFacts}>
              <div><dt>Opslag</dt><dd>{knmiDatasetStorageModeLabel(dataset.storage_mode)}</dd></div>
              <div><dt>Laatste bronmoment</dt><dd>{formatDateTime(dataset.reference_at)}</dd></div>
              <div><dt>Lokaal ververst</dt><dd>{formatDateTime(dataset.refreshed_at)}</dd></div>
              <div><dt>Volgende automatische run</dt><dd>{datasetNextUpdateLabel(dataset)}</dd></div>
            </dl>

            {operationActive && dataset.operation ? (
              <DatasetOperationProgress dataset={dataset} operation={dataset.operation} />
            ) : null}

            {dataset.availability_note ? (
              <p className={styles.datasetAvailability}>{dataset.availability_note}</p>
            ) : null}
            {dataset.latest_error ? (
              <p className={styles.datasetError} role="alert">
                <strong>Laatste importfout</strong>
                <span>{dataset.latest_error.message}</span>
                {dataset.latest_error.at ? <time dateTime={dataset.latest_error.at}>{formatDateTime(dataset.latest_error.at)}</time> : null}
              </p>
            ) : null}
            {refreshError ? <p className={styles.datasetError} role="alert">{refreshError}</p> : null}

            <footer className={styles.datasetRowFooter}>
              {sourceUrl ? (
                <a href={sourceUrl} target="_blank" rel="noreferrer">
                  Bron bekijken <ExternalLink aria-hidden size={15} />
                </a>
              ) : <span />}
              {dataset.refreshable ? (
                <button
                  className="secondary-button"
                  type="button"
                  disabled={!dataset.configured || refreshing || operationActive}
                  aria-busy={refreshing || operationActive}
                  onClick={() => onRefresh(dataset)}
                >
                  <RefreshCw aria-hidden size={16} />
                  {!dataset.configured
                    ? 'Configuratie vereist'
                    : refreshing
                      ? 'Bijwerken aanvragen...'
                      : operationActive
                        ? 'Wordt bijgewerkt'
                        : 'Nu bijwerken'}
                </button>
              ) : null}
            </footer>
          </>
        )}
      </article>
    </li>
  );
}

function DatasetOperationProgress({
  dataset,
  operation,
}: {
  dataset: KnmiAdminDatasetStatus;
  operation: KnmiDatasetOperation;
}) {
  const progress = normalizeKnmiProgress(operation.progress_percent);
  return (
    <div className={styles.datasetProgress} aria-live="polite" aria-busy="true">
      <div>
        <span>{knmiOperationStageLabel(operation.stage)}</span>
        <strong>{progress === null ? 'Voortgang wordt bepaald' : `${progress}%`}</strong>
      </div>
      <progress
        aria-label={`Voortgang bijwerken ${dataset.dataset ?? dataset.provider}`}
        max={100}
        value={progress ?? undefined}
      >
        {progress === null ? 'Voortgang onbekend' : `${progress}%`}
      </progress>
      <p>{operation.message}</p>
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

function legacyKnmiDatasets(status: KnmiAdminStatus): KnmiAdminDatasetStatus[] {
  const snapshot = status.active_snapshot;
  const forecastEnd = snapshot ? Date.parse(snapshot.forecast_end_at) : Number.NaN;
  const datasetStatus: KnmiAdminDatasetStatus['status'] = !status.configuration.configured
    ? 'not_configured'
    : snapshot === null
      ? 'unavailable'
      : Number.isFinite(forecastEnd) && forecastEnd < Date.now()
        ? 'stale'
        : 'current';
  const activeOperation = knmiOperationIsActive(status.active_operation?.state)
    ? status.active_operation
    : null;
  const lastFailure = status.latest_operation?.state === 'failed'
    ? status.latest_operation
    : null;

  return [{
    key: 'harmonie_arome_p1',
    provider: 'KNMI',
    dataset: status.configuration.dataset,
    version: status.configuration.dataset_version,
    category: 'active',
    consumers: ['operational_weather', 'uav_forecast'],
    storage_mode: 'local_snapshot',
    status: datasetStatus,
    configured: status.configuration.configured,
    source_url: status.configuration.open_data_endpoint,
    reference_at: snapshot?.model_run_at ?? null,
    refreshed_at: snapshot?.activated_at ?? null,
    next_update_at: null,
    availability_note: 'Beperkte compatibiliteitsweergave uit de bestaande HARMONIE-status.',
    latest_error: lastFailure
      ? {
          code: lastFailure.error_code ?? 'import_failed',
          message: lastFailure.message,
          at: lastFailure.finished_at ?? lastFailure.started_at,
        }
      : null,
    refreshable: false,
    operation: activeOperation ? legacyDatasetOperation(activeOperation) : null,
  }];
}

function legacyDatasetOperation(operation: KnmiForecastOperation): KnmiDatasetOperation {
  return {
    id: operation.id,
    dataset_keys: ['harmonie_arome_p1'],
    state: operation.state,
    stage: operation.stage,
    message: operation.message,
    progress_percent: operation.progress_percent,
    started_at: operation.started_at,
    finished_at: operation.finished_at,
  };
}

function withoutRecordKey(record: Record<string, string>, key: string): Record<string, string> {
  const next = { ...record };
  delete next[key];
  return next;
}

function datasetNextUpdateLabel(dataset: KnmiAdminDatasetStatus): string {
  if (dataset.next_update_at) return formatDateTime(dataset.next_update_at);
  return dataset.category === 'on_demand' ? 'Op aanvraag' : 'Niet gepland';
}

function knmiCatalogPath(
  query: string,
  page: number,
  status: string,
  license: string,
): string {
  const parameters = new URLSearchParams({
    page: String(Math.max(1, page)),
    per_page: String(KNMI_CATALOG_PAGE_SIZE),
  });
  if (query !== '') parameters.set('query', query);
  if (status !== '') parameters.set('status', status);
  if (license !== '') parameters.set('license', license);

  return `/admin/knmi/catalog?${parameters.toString()}`;
}

function catalogResultLabel(response: KnmiCatalogResponse): string {
  const { from, to, total } = response.pagination;
  if (total === 0 || from === null || to === null) return '0 datasets';
  return `${from}–${to} van ${total} datasets`;
}

function catalogDatasetStatusLabel(status: string | null): string {
  switch (status?.toLowerCase()) {
    case 'ongoing':
      return 'Doorlopend';
    case 'completed':
      return 'Afgerond';
    case 'deprecated':
      return 'Vervallen';
    case 'planned':
      return 'Gepland';
    default:
      return status?.trim() || 'Status niet opgegeven';
  }
}
