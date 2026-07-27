import {
  Bug,
  CheckCircle2,
  ChevronRight,
  CircleDot,
  FilePenLine,
  Inbox,
  Lightbulb,
  Plus,
  RotateCcw,
  Search,
  Send,
  Settings2,
  Wrench,
  X,
} from 'lucide-react';
import {
  type FormEvent,
  useEffect,
  useMemo,
  useState,
} from 'react';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type {
  PaginationMeta,
  ProductRequest,
  ProductRequestStatus,
  ProductRequestStatusHistoryEntry,
  ProductRequestType,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  allowedProductRequestTransitions,
  buildProductRequestsPath,
  productRequestStatusLabel,
  productRequestStatusOptions,
  productRequestStatusTone,
  productRequestTypeLabel,
  productRequestTypeOptions,
  type ProductRequestTab,
} from './productRequestPresentation';
import styles from './ProductRequestsPage.module.css';

const EMPTY_REQUESTS: ProductRequest[] = [];

interface RequestContentForm {
  type: ProductRequestType;
  title: string;
  description: string;
}

interface RequestStatusForm {
  status: ProductRequestStatus;
  resolutionNote: string;
}

function emptyRequestContent(): RequestContentForm {
  return {
    type: 'bug',
    title: '',
    description: '',
  };
}

export function ProductRequestsPage() {
  const { api, hasPermission } = useAuth();
  const [tab, setTab] = useState<ProductRequestTab>('all');
  const [typeFilter, setTypeFilter] = useState<ProductRequestType | 'all'>('all');
  const [statusFilter, setStatusFilter] = useState<ProductRequestStatus | 'all'>('all');
  const [query, setQuery] = useState('');
  const debouncedQuery = useDebouncedValue(query, 250);
  const [page, setPage] = useState(1);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [mutationError, setMutationError] = useState<string | null>(null);
  const canCreate = hasPermission('product-requests.create');
  const canResolve = hasPermission('product-requests.resolve');
  const effectiveTab = tab === 'handling' && !canResolve ? 'all' : tab;
  const resourcePath = useMemo(
    () => buildProductRequestsPath({
      tab: effectiveTab,
      type: typeFilter,
      status: statusFilter,
      query: debouncedQuery,
      page,
    }),
    [debouncedQuery, effectiveTab, page, statusFilter, typeFilter],
  );
  const requests = useApiResource<ProductRequest[]>(resourcePath);
  const visibleRequests = requests.data ?? EMPTY_REQUESTS;
  const pagination = paginationMeta(requests.meta);
  const selectedRequest = visibleRequests.find((request) => request.id === selectedId)
    ?? visibleRequests[0]
    ?? null;

  function selectTab(nextTab: ProductRequestTab) {
    setTab(nextTab);
    setStatusFilter('all');
    setPage(1);
    setSelectedId(null);
  }

  function applyRequest(nextRequest: ProductRequest) {
    requests.mutate((current) => {
      if (current === null) {
        return [nextRequest];
      }

      const existingIndex = current.findIndex((request) => request.id === nextRequest.id);
      if (existingIndex < 0) {
        return [nextRequest, ...current];
      }

      return current.map((request) => request.id === nextRequest.id ? nextRequest : request);
    });
    setSelectedId(nextRequest.id);
  }

  async function createRequest(form: RequestContentForm): Promise<void> {
    setMessage(null);
    setMutationError(null);
    try {
      const response = await api.post<ProductRequest>('/product-requests', {
        type: form.type,
        title: form.title.trim(),
        description: form.description.trim(),
      });
      applyRequest(response.data);
      setTab('mine');
      setTypeFilter('all');
      setStatusFilter('all');
      setQuery('');
      setPage(1);
      setCreateOpen(false);
      setMessage('Verzoek ingediend.');
    } catch (error) {
      setMutationError(errorMessage(error, 'Verzoek indienen is niet gelukt.'));
      throw error;
    }
  }

  async function updateRequest(
    request: ProductRequest,
    form: RequestContentForm,
  ): Promise<void> {
    setMessage(null);
    setMutationError(null);
    try {
      const response = await api.patch<ProductRequest>(`/product-requests/${request.id}`, {
        type: form.type,
        title: form.title.trim(),
        description: form.description.trim(),
        lock_version: request.lock_version,
      });
      applyRequest(response.data);
      await requests.silentReload();
      setMessage('Wijzigingen opgeslagen.');
    } catch (error) {
      if (isVersionConflict(error)) {
        await requests.reload();
        setMutationError('Dit verzoek is intussen gewijzigd. De nieuwste versie is geladen; controleer je wijziging opnieuw.');
      } else {
        setMutationError(errorMessage(error, 'Verzoek wijzigen is niet gelukt.'));
      }
      throw error;
    }
  }

  async function updateRequestStatus(
    request: ProductRequest,
    form: RequestStatusForm,
  ): Promise<void> {
    setMessage(null);
    setMutationError(null);
    try {
      const response = await api.patch<ProductRequest>(`/product-requests/${request.id}/status`, {
        status: form.status,
        resolution_note: form.resolutionNote.trim(),
        lock_version: request.lock_version,
      });
      applyRequest(response.data);
      await requests.silentReload();
      setMessage('Afhandeling opgeslagen.');
    } catch (error) {
      if (isVersionConflict(error)) {
        await requests.reload();
        setMutationError('Dit verzoek is intussen gewijzigd. De nieuwste status is geladen; beoordeel het verzoek opnieuw.');
      } else {
        setMutationError(errorMessage(error, 'Afhandeling opslaan is niet gelukt.'));
      }
      throw error;
    }
  }

  return (
    <div className={styles.page}>
      <section className={styles.hero} aria-labelledby="product-requests-heading">
        <div className={styles.heroCopy}>
          <span className={styles.eyebrow}>Centraal meldpunt</span>
          <h2 id="product-requests-heading">Van signaal naar oplossing</h2>
          <p>Meld een bug, vraag een aanpassing aan of stel een nieuwe functie voor. Iedereen met toegang kan de voortgang volgen.</p>
        </div>
        <ol className={styles.workflow} aria-label="Afhandeling van een verzoek">
          <li><CircleDot aria-hidden size={16} /><span>Open</span></li>
          <li><Settings2 aria-hidden size={16} /><span>In behandeling</span></li>
          <li><CheckCircle2 aria-hidden size={16} /><span>Opgelost</span></li>
          <li><X aria-hidden size={16} /><span>Afgewezen</span></li>
        </ol>
        {canCreate ? (
          <button
            className="primary-button"
            type="button"
            onClick={() => {
              setCreateOpen((open) => !open);
              setMutationError(null);
              setMessage(null);
            }}
            aria-expanded={createOpen}
            aria-controls="product-request-create"
          >
            {createOpen ? <X aria-hidden size={17} /> : <Plus aria-hidden size={17} />}
            {createOpen ? 'Sluiten' : 'Nieuw verzoek'}
          </button>
        ) : null}
      </section>

      {createOpen && canCreate ? (
        <CreateRequestForm onCancel={() => setCreateOpen(false)} onSubmit={createRequest} />
      ) : null}

      {message ? <p className={styles.success} role="status">{message}</p> : null}
      {mutationError ? <p className={styles.error} role="alert">{mutationError}</p> : null}

      <section className={styles.workspace} aria-label="Verzoekenoverzicht">
        <div className={styles.listColumn}>
          <div className={styles.tabs} role="tablist" aria-label="Verzoeken selecteren">
            <button
              type="button"
              role="tab"
              aria-selected={effectiveTab === 'all'}
              className={effectiveTab === 'all' ? styles.activeTab : undefined}
              onClick={() => selectTab('all')}
            >
              Alle
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={effectiveTab === 'mine'}
              className={effectiveTab === 'mine' ? styles.activeTab : undefined}
              onClick={() => selectTab('mine')}
            >
              Mijn
            </button>
            {canResolve ? (
              <button
                type="button"
                role="tab"
                aria-selected={effectiveTab === 'handling'}
                className={effectiveTab === 'handling' ? styles.activeTab : undefined}
                onClick={() => selectTab('handling')}
              >
                Te behandelen
              </button>
            ) : null}
          </div>

          <div className={styles.filters}>
            <label className={styles.search}>
              <span className="sr-only">Zoeken in verzoeken</span>
              <Search aria-hidden size={17} />
              <input
                type="search"
                value={query}
                maxLength={120}
                onChange={(event) => {
                  setQuery(event.target.value);
                  setPage(1);
                  setSelectedId(null);
                }}
                placeholder="Zoek op titel, inhoud of indiener"
              />
            </label>
            <label>
              <span>Type</span>
              <select
                value={typeFilter}
                onChange={(event) => {
                  setTypeFilter(event.target.value as ProductRequestType | 'all');
                  setPage(1);
                  setSelectedId(null);
                }}
              >
                <option value="all">Alle typen</option>
                {productRequestTypeOptions.map((option) => (
                  <option value={option.value} key={option.value}>{option.label}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Status</span>
              <select
                value={statusFilter}
                onChange={(event) => {
                  setStatusFilter(event.target.value as ProductRequestStatus | 'all');
                  setPage(1);
                  setSelectedId(null);
                }}
              >
                <option value="all">Alle statussen</option>
                {productRequestStatusOptions
                  .filter((option) => effectiveTab !== 'handling' || ['open', 'in_progress'].includes(option.value))
                  .map((option) => (
                  <option value={option.value} key={option.value}>{option.label}</option>
                  ))}
              </select>
            </label>
          </div>

          <RequestList
            error={requests.error}
            loading={requests.loading}
            requests={visibleRequests}
            selectedId={selectedRequest?.id ?? null}
            hasFilters={query.trim() !== '' || typeFilter !== 'all' || statusFilter !== 'all'}
            onReload={requests.reload}
            onSelect={(requestId) => setSelectedId(requestId)}
          />
          {pagination !== null && pagination.last_page > 1 ? (
            <Pagination
              pagination={pagination}
              onPageChange={(nextPage) => {
                setPage(nextPage);
                setSelectedId(null);
              }}
            />
          ) : null}
        </div>

        <div className={styles.detailColumn}>
          {selectedRequest === null ? (
            <div className={styles.emptyDetail}>
              <Inbox aria-hidden size={28} />
              <h3>Geen verzoek geselecteerd</h3>
              <p>Kies een verzoek in de lijst of pas de filters aan.</p>
            </div>
          ) : (
            <ProductRequestDetailLoader
              key={`${selectedRequest.id}:${selectedRequest.lock_version}`}
              summary={selectedRequest}
              onUpdate={updateRequest}
              onStatusUpdate={updateRequestStatus}
            />
          )}
        </div>
      </section>
    </div>
  );
}

function CreateRequestForm({
  onCancel,
  onSubmit,
}: {
  onCancel: () => void;
  onSubmit: (form: RequestContentForm) => Promise<void>;
}) {
  const [form, setForm] = useState<RequestContentForm>(emptyRequestContent);
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    try {
      await onSubmit(form);
      setForm(emptyRequestContent());
    } catch {
      // The page-level live region explains the API error.
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className={styles.createPanel} id="product-request-create" aria-labelledby="product-request-create-title">
      <header>
        <div>
          <span className={styles.eyebrow}>Nieuwe melding</span>
          <h3 id="product-request-create-title">Wat kunnen we verbeteren?</h3>
        </div>
        <button className="icon-button" type="button" onClick={onCancel} aria-label="Nieuw verzoek sluiten">
          <X aria-hidden size={18} />
        </button>
      </header>
      <form className={styles.form} onSubmit={submit}>
        <fieldset className={styles.typePicker}>
          <legend>Type verzoek</legend>
          {productRequestTypeOptions.map((option) => (
            <label key={option.value}>
              <input
                type="radio"
                name="product-request-type"
                value={option.value}
                checked={form.type === option.value}
                onChange={() => setForm((current) => ({ ...current, type: option.value }))}
              />
              <span>
                <RequestTypeIcon type={option.value} />
                <strong>{option.label}</strong>
              </span>
            </label>
          ))}
        </fieldset>
        <label>
          Titel
          <input
            value={form.title}
            maxLength={180}
            required
            autoFocus
            onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))}
            placeholder="Korte samenvatting van je verzoek"
          />
        </label>
        <label>
          Omschrijving
          <textarea
            value={form.description}
            maxLength={20000}
            rows={6}
            required
            onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
            placeholder="Beschrijf wat er gebeurt, wat je verwacht en wanneer dit merkbaar is."
          />
          <small>Deel geen wachtwoorden, tokens of andere geheime gegevens.</small>
        </label>
        <div className={styles.formActions}>
          <button className="secondary-button" type="button" onClick={onCancel} disabled={saving}>Annuleren</button>
          <button
            className="primary-button"
            type="submit"
            disabled={saving || form.title.trim() === '' || form.description.trim() === ''}
          >
            <Send aria-hidden size={16} />
            {saving ? 'Indienen…' : 'Verzoek indienen'}
          </button>
        </div>
      </form>
    </section>
  );
}

function RequestList({
  requests,
  selectedId,
  loading,
  error,
  hasFilters,
  onReload,
  onSelect,
}: {
  requests: ProductRequest[];
  selectedId: string | null;
  loading: boolean;
  error: string | null;
  hasFilters: boolean;
  onReload: () => Promise<void>;
  onSelect: (requestId: string) => void;
}) {
  if (loading) {
    return (
      <div className={styles.listState} role="status" aria-live="polite">
        <span className={styles.loadingBar} aria-hidden />
        <span className={styles.loadingBar} aria-hidden />
        <span className={styles.loadingBar} aria-hidden />
        <span>Verzoeken laden…</span>
      </div>
    );
  }

  if (error !== null) {
    return (
      <div className={styles.listState} role="alert">
        <Inbox aria-hidden size={24} />
        <strong>Verzoeken konden niet worden geladen</strong>
        <span>{error}</span>
        <button className="secondary-button" type="button" onClick={() => void onReload()}>
          <RotateCcw aria-hidden size={16} /> Opnieuw proberen
        </button>
      </div>
    );
  }

  if (requests.length === 0) {
    return (
      <div className={styles.listState} role="status">
        <Inbox aria-hidden size={24} />
        <strong>{hasFilters ? 'Geen verzoeken gevonden' : 'Nog geen verzoeken'}</strong>
        <span>{hasFilters ? 'Pas de zoekopdracht of filters aan.' : 'Nieuwe verzoeken verschijnen hier.'}</span>
      </div>
    );
  }

  return (
    <div className={styles.requestList} aria-label="Verzoeken">
      {requests.map((request) => (
        <button
          className={`${styles.requestCard} ${selectedId === request.id ? styles.selectedCard : ''}`}
          data-status={request.status}
          type="button"
          key={request.id}
          onClick={() => onSelect(request.id)}
          aria-current={selectedId === request.id ? 'true' : undefined}
        >
          <span className={styles.cardTopline}>
            <span className={styles.typeLabel}>
              <RequestTypeIcon type={request.type} />
              {productRequestTypeLabel(request.type)}
            </span>
            <RequestStatusBadge status={request.status} />
          </span>
          <strong className={styles.cardTitle}>{request.title}</strong>
          <span className={styles.cardDescription}>{request.description}</span>
          <span className={styles.cardMeta}>
            <span>{actorName(request.requester.name)}</span>
            <time dateTime={request.updated_at}>{formatDateTime(request.updated_at)}</time>
            <ChevronRight aria-hidden size={16} />
          </span>
        </button>
      ))}
    </div>
  );
}

function Pagination({
  pagination,
  onPageChange,
}: {
  pagination: PaginationMeta;
  onPageChange: (page: number) => void;
}) {
  return (
    <nav className={styles.pagination} aria-label="Paginering verzoeken">
      <button
        className="secondary-button"
        type="button"
        disabled={pagination.current_page <= 1}
        onClick={() => onPageChange(pagination.current_page - 1)}
      >
        Vorige
      </button>
      <span>
        Pagina {pagination.current_page} van {pagination.last_page}
        <small>{pagination.total} verzoeken</small>
      </span>
      <button
        className="secondary-button"
        type="button"
        disabled={pagination.current_page >= pagination.last_page}
        onClick={() => onPageChange(pagination.current_page + 1)}
      >
        Volgende
      </button>
    </nav>
  );
}

function ProductRequestDetailLoader({
  summary,
  onUpdate,
  onStatusUpdate,
}: {
  summary: ProductRequest;
  onUpdate: (request: ProductRequest, form: RequestContentForm) => Promise<void>;
  onStatusUpdate: (request: ProductRequest, form: RequestStatusForm) => Promise<void>;
}) {
  const detail = useApiResource<ProductRequest>(`/product-requests/${summary.id}`);

  return (
    <>
      {detail.error ? (
        <p className={styles.detailWarning} role="status">
          De volledige geschiedenis kon niet worden geladen. De lijstgegevens blijven zichtbaar.
        </p>
      ) : null}
      <ProductRequestDetail
        request={detail.data ?? summary}
        onUpdate={onUpdate}
        onStatusUpdate={onStatusUpdate}
      />
    </>
  );
}

function ProductRequestDetail({
  request,
  onUpdate,
  onStatusUpdate,
}: {
  request: ProductRequest;
  onUpdate: (request: ProductRequest, form: RequestContentForm) => Promise<void>;
  onStatusUpdate: (request: ProductRequest, form: RequestStatusForm) => Promise<void>;
}) {
  const [editing, setEditing] = useState(false);

  return (
    <article className={styles.detail} aria-labelledby={`product-request-${request.id}`}>
      <header className={styles.detailHeader}>
        <div className={styles.detailLabels}>
          <span className={styles.typeLabel}>
            <RequestTypeIcon type={request.type} />
            {productRequestTypeLabel(request.type)}
          </span>
          <RequestStatusBadge status={request.status} />
        </div>
        <h3 id={`product-request-${request.id}`}>{request.title}</h3>
        <dl className={styles.detailMeta}>
          <div><dt>Ingediend door</dt><dd>{actorName(request.requester.name)}</dd></div>
          <div><dt>Aangemaakt</dt><dd><time dateTime={request.created_at}>{formatDateTime(request.created_at)}</time></dd></div>
          <div><dt>Bijgewerkt</dt><dd><time dateTime={request.updated_at}>{formatDateTime(request.updated_at)}</time></dd></div>
        </dl>
      </header>

      {editing ? (
        <EditRequestForm
          request={request}
          onCancel={() => setEditing(false)}
          onSubmit={async (form) => {
            await onUpdate(request, form);
            setEditing(false);
          }}
        />
      ) : (
        <section className={styles.description} aria-labelledby={`product-request-description-${request.id}`}>
          <header>
            <h4 id={`product-request-description-${request.id}`}>Omschrijving</h4>
            {request.can_update ? (
              <button className="secondary-button" type="button" onClick={() => setEditing(true)}>
                <FilePenLine aria-hidden size={16} /> Aanpassen
              </button>
            ) : null}
          </header>
          <p>{request.description}</p>
        </section>
      )}

      {request.resolution_note ? (
        <section className={styles.resolutionNote} aria-labelledby={`product-request-resolution-${request.id}`}>
          <span className={styles.resolutionIcon} aria-hidden><CheckCircle2 size={19} /></span>
          <div>
            <h4 id={`product-request-resolution-${request.id}`}>Toelichting op de afhandeling</h4>
            <p>{request.resolution_note}</p>
            {request.resolved_by ? <small>Door {actorName(request.resolved_by.name)}</small> : null}
          </div>
        </section>
      ) : null}

      {request.status_history && request.status_history.length > 0 ? (
        <StatusHistory entries={request.status_history} requestId={request.id} />
      ) : null}

      {request.can_resolve ? (
        <StatusUpdateForm request={request} onSubmit={(form) => onStatusUpdate(request, form)} />
      ) : null}
    </article>
  );
}

function StatusHistory({
  entries,
  requestId,
}: {
  entries: ProductRequestStatusHistoryEntry[];
  requestId: string;
}) {
  return (
    <section className={styles.history} aria-labelledby={`product-request-history-${requestId}`}>
      <h4 id={`product-request-history-${requestId}`}>Statusgeschiedenis</h4>
      <ol>
        {entries.map((entry) => (
          <li key={entry.id}>
            <span className={styles.historyMarker} aria-hidden />
            <div>
              <strong>
                {entry.from_status === null
                  ? productRequestStatusLabel(entry.to_status)
                  : `${productRequestStatusLabel(entry.from_status)} → ${productRequestStatusLabel(entry.to_status)}`}
              </strong>
              <span>{actorName(entry.changed_by.name)} · <time dateTime={entry.created_at}>{formatDateTime(entry.created_at)}</time></span>
              {entry.note ? <p>{entry.note}</p> : null}
            </div>
          </li>
        ))}
      </ol>
    </section>
  );
}

function EditRequestForm({
  request,
  onCancel,
  onSubmit,
}: {
  request: ProductRequest;
  onCancel: () => void;
  onSubmit: (form: RequestContentForm) => Promise<void>;
}) {
  const [form, setForm] = useState<RequestContentForm>({
    type: request.type,
    title: request.title,
    description: request.description,
  });
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    try {
      await onSubmit(form);
    } catch {
      // The page-level live region explains the API error.
    } finally {
      setSaving(false);
    }
  }

  return (
    <form className={`${styles.form} ${styles.editForm}`} onSubmit={submit}>
      <h4>Verzoek aanpassen</h4>
      <label>
        Type
        <select value={form.type} onChange={(event) => setForm((current) => ({ ...current, type: event.target.value as ProductRequestType }))}>
          {productRequestTypeOptions.map((option) => <option value={option.value} key={option.value}>{option.label}</option>)}
        </select>
      </label>
      <label>
        Titel
        <input
          value={form.title}
          maxLength={180}
          required
          onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))}
        />
      </label>
      <label>
        Omschrijving
        <textarea
          value={form.description}
          maxLength={20000}
          rows={7}
          required
          onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
        />
      </label>
      <div className={styles.formActions}>
        <button className="secondary-button" type="button" onClick={onCancel} disabled={saving}>Annuleren</button>
        <button
          className="primary-button"
          type="submit"
          disabled={saving || form.title.trim() === '' || form.description.trim() === ''}
        >
          {saving ? 'Opslaan…' : 'Wijzigingen opslaan'}
        </button>
      </div>
    </form>
  );
}

function StatusUpdateForm({
  request,
  onSubmit,
}: {
  request: ProductRequest;
  onSubmit: (form: RequestStatusForm) => Promise<void>;
}) {
  const [form, setForm] = useState<RequestStatusForm>({
    status: request.status,
    resolutionNote: '',
  });
  const [saving, setSaving] = useState(false);
  const allowedStatuses = allowedProductRequestTransitions(request.status);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    try {
      await onSubmit(form);
    } catch {
      // The page-level live region explains the API error.
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className={styles.resolver} aria-labelledby={`product-request-resolver-${request.id}`}>
      <header>
        <span className={styles.resolverIcon} aria-hidden><Wrench size={18} /></span>
        <div>
          <span className={styles.eyebrow}>Behandelaar</span>
          <h4 id={`product-request-resolver-${request.id}`}>Afhandeling vastleggen</h4>
        </div>
      </header>
      <form className={styles.form} onSubmit={submit}>
        <label>
          Nieuwe status
          <select
            value={form.status}
            onChange={(event) => setForm((current) => ({
              ...current,
              status: event.target.value as ProductRequestStatus,
            }))}
          >
            <option value={request.status} disabled>
              Huidig — {productRequestStatusLabel(request.status)}
            </option>
            {productRequestStatusOptions
              .filter((option) => allowedStatuses.includes(option.value))
              .map((option) => (
              <option value={option.value} key={option.value}>{option.label}</option>
              ))}
          </select>
        </label>
        <label>
          Toelichting
          <textarea
            value={form.resolutionNote}
            maxLength={4000}
            rows={5}
            required
            onChange={(event) => setForm((current) => ({ ...current, resolutionNote: event.target.value }))}
            placeholder="Wat is besloten of uitgevoerd?"
          />
          <small>Verplicht bij iedere statuswijziging; zichtbaar voor alle lezers.</small>
        </label>
        <div className={styles.formActions}>
          <button
            className="primary-button"
            type="submit"
            disabled={saving || form.status === request.status || form.resolutionNote.trim() === ''}
          >
            {saving ? 'Opslaan…' : 'Afhandeling opslaan'}
          </button>
        </div>
      </form>
    </section>
  );
}

function RequestStatusBadge({ status }: { status: ProductRequestStatus }) {
  return (
    <span className={styles.statusBadge} data-tone={productRequestStatusTone(status)}>
      <span aria-hidden />
      {productRequestStatusLabel(status)}
    </span>
  );
}

function RequestTypeIcon({ type }: { type: ProductRequestType }) {
  switch (type) {
    case 'bug':
      return <Bug aria-hidden size={15} />;
    case 'change':
      return <Wrench aria-hidden size={15} />;
    case 'feature':
      return <Lightbulb aria-hidden size={15} />;
  }
}

function errorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiClientError ? error.message : fallback;
}

function actorName(name: string | null): string {
  return name?.trim() || 'Onbekende gebruiker';
}

function isVersionConflict(error: unknown): boolean {
  return error instanceof ApiClientError
    && [
      'product_request_version_conflict',
      'version_conflict',
      'stale_version',
    ].includes(error.code);
}

function paginationMeta(meta: unknown): PaginationMeta | null {
  if (meta === null || typeof meta !== 'object') {
    return null;
  }

  const candidate = meta as Partial<PaginationMeta>;
  return Number.isInteger(candidate.current_page)
    && Number.isInteger(candidate.last_page)
    && Number.isInteger(candidate.per_page)
    && Number.isInteger(candidate.total)
    ? candidate as PaginationMeta
    : null;
}

function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debouncedValue, setDebouncedValue] = useState(value);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedValue(value), delayMs);
    return () => window.clearTimeout(timer);
  }, [delayMs, value]);

  return debouncedValue;
}
