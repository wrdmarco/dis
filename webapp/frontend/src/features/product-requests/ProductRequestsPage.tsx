import {
  Bug,
  CheckCircle2,
  ChevronRight,
  FilePenLine,
  Inbox,
  Lightbulb,
  Plus,
  RotateCcw,
  Search,
  Send,
  Wrench,
  X,
} from 'lucide-react';
import {
  type FormEvent,
  type ReactNode,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import { Panel } from '../../components/Panel';
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
  const [tab, setTab] = useState<ProductRequestTab>('handling');
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
  const selectedRequest = visibleRequests.find((request) => request.id === selectedId) ?? null;

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
    <div className="page-stack">
      <Panel
        title="Overzicht"
        action={canCreate ? (
          <button
            className="primary-button"
            type="button"
            aria-haspopup="dialog"
            onClick={() => {
              setCreateOpen(true);
              setSelectedId(null);
              setMutationError(null);
              setMessage(null);
            }}
          >
            <Plus aria-hidden size={17} />
            Nieuw verzoek
          </button>
        ) : undefined}
      >
        <div className={styles.panelBody}>
          <p className={styles.intro}>
            Meld een bug, vraag een aanpassing aan of stel een nieuwe functie voor.
          </p>

          {message && !createOpen && selectedRequest === null ? (
            <p className={styles.success} role="status">{message}</p>
          ) : null}
          {mutationError && !createOpen && selectedRequest === null ? (
            <p className={styles.error} role="alert">{mutationError}</p>
          ) : null}

          <div className={styles.toolbar}>
            <div className={styles.tabs} role="group" aria-label="Verzoeken selecteren">
              {canResolve ? (
                <button
                  type="button"
                  aria-pressed={effectiveTab === 'handling'}
                  className={effectiveTab === 'handling' ? styles.activeTab : undefined}
                  onClick={() => selectTab('handling')}
                >
                  Te behandelen
                </button>
              ) : null}
              <button
                type="button"
                aria-pressed={effectiveTab === 'mine'}
                className={effectiveTab === 'mine' ? styles.activeTab : undefined}
                onClick={() => selectTab('mine')}
              >
                Mijn verzoeken
              </button>
              <button
                type="button"
                aria-pressed={effectiveTab === 'closed'}
                className={effectiveTab === 'closed' ? styles.activeTab : undefined}
                onClick={() => selectTab('closed')}
              >
                Afgesloten verzoeken
              </button>
              <button
                type="button"
                aria-pressed={effectiveTab === 'all'}
                className={effectiveTab === 'all' ? styles.activeTab : undefined}
                onClick={() => selectTab('all')}
              >
                Alle verzoeken
              </button>
            </div>

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
            <label className={styles.compactFilter}>
              <span className="sr-only">Filter op type</span>
              <select
                aria-label="Type"
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
            <label className={styles.compactFilter}>
              <span className="sr-only">Filter op status</span>
              <select
                aria-label="Status"
                value={statusFilter}
                onChange={(event) => {
                  setStatusFilter(event.target.value as ProductRequestStatus | 'all');
                  setPage(1);
                  setSelectedId(null);
                }}
              >
                <option value="all">Alle statussen</option>
                {productRequestStatusOptions
                  .filter((option) => {
                    if (effectiveTab === 'handling') {
                      return ['open', 'in_progress'].includes(option.value);
                    }
                    if (effectiveTab === 'closed') {
                      return ['resolved', 'rejected'].includes(option.value);
                    }

                    return true;
                  })
                  .map((option) => (
                  <option value={option.value} key={option.value}>{option.label}</option>
                  ))}
              </select>
            </label>
          </div>

          <RequestTable
            error={requests.error}
            loading={requests.loading}
            requests={visibleRequests}
            hasFilters={query.trim() !== '' || typeFilter !== 'all' || statusFilter !== 'all'}
            onReload={requests.reload}
            onOpen={(requestId) => {
              setMessage(null);
              setMutationError(null);
              setSelectedId(requestId);
            }}
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
      </Panel>

      {createOpen && canCreate ? (
        <RequestDialog
          title="Nieuw verzoek"
          titleId="product-request-create-title"
          description="Kies het type en beschrijf kort wat er moet veranderen."
          narrow
          onClose={() => {
            setCreateOpen(false);
            setMutationError(null);
          }}
        >
          {mutationError ? <p className={styles.dialogError} role="alert">{mutationError}</p> : null}
          <CreateRequestForm
            onCancel={() => {
              setCreateOpen(false);
              setMutationError(null);
            }}
            onSubmit={createRequest}
          />
        </RequestDialog>
      ) : null}

      {selectedRequest !== null ? (
        <RequestDialog
          title={selectedRequest.title}
          titleId={`product-request-dialog-${selectedRequest.id}`}
          meta={(
            <div className={styles.dialogLabels}>
              <span className={styles.typeLabel} data-type={selectedRequest.type}>
                <RequestTypeIcon type={selectedRequest.type} />
                {productRequestTypeLabel(selectedRequest.type)}
              </span>
              <RequestStatusBadge status={selectedRequest.status} />
            </div>
          )}
          onClose={() => {
            setSelectedId(null);
            setMutationError(null);
          }}
        >
          {message ? <p className={styles.dialogSuccess} role="status">{message}</p> : null}
          {mutationError ? <p className={styles.dialogError} role="alert">{mutationError}</p> : null}
          <ProductRequestDetailLoader
            key={`${selectedRequest.id}:${selectedRequest.lock_version}`}
            summary={selectedRequest}
            onUpdate={updateRequest}
            onStatusUpdate={updateRequestStatus}
          />
        </RequestDialog>
      ) : null}
    </div>
  );
}

function RequestDialog({
  children,
  description,
  meta,
  narrow = false,
  onClose,
  title,
  titleId,
}: {
  children: ReactNode;
  description?: string;
  meta?: ReactNode;
  narrow?: boolean;
  onClose: () => void;
  title: string;
  titleId: string;
}) {
  const dialogRef = useRef<HTMLElement>(null);
  const onCloseRef = useRef(onClose);

  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  useEffect(() => {
    const previouslyFocused = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    const focusFrame = window.requestAnimationFrame(() => {
      const dialog = dialogRef.current;
      const initialTarget = dialog?.querySelector<HTMLElement>('[data-dialog-initial="true"]')
        ?? dialog?.querySelector<HTMLElement>('[data-dialog-close="true"]')
        ?? dialog;
      initialTarget?.focus();
    });

    function keepFocusInDialog(event: KeyboardEvent) {
      const dialog = dialogRef.current;
      if (dialog === null) return;

      if (event.key === 'Escape') {
        event.preventDefault();
        onCloseRef.current();
        return;
      }
      if (event.key !== 'Tab') return;

      const focusable = Array.from(dialog.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
      )).filter((element) => element.tabIndex >= 0);

      if (focusable.length === 0) {
        event.preventDefault();
        dialog.focus();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || !dialog.contains(active))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (active === last || !dialog.contains(active))) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', keepFocusInDialog);

    return () => {
      window.cancelAnimationFrame(focusFrame);
      document.removeEventListener('keydown', keepFocusInDialog);
      document.body.style.overflow = previousOverflow;
      if (previouslyFocused?.isConnected) previouslyFocused.focus();
    };
  }, []);

  const descriptionId = description ? `${titleId}-description` : undefined;

  return (
    <div
      className={`modal-backdrop ${styles.modalBackdrop}`}
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <section
        ref={dialogRef}
        className={`modal ${styles.requestDialog} ${narrow ? styles.requestDialogNarrow : ''}`}
        role="dialog"
        tabIndex={-1}
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={descriptionId}
      >
        <header className={`modal__header ${styles.dialogHeader}`}>
          <div>
            {meta}
            <h2 id={titleId}>{title}</h2>
          </div>
          <button
            className="icon-button"
            type="button"
            onClick={onClose}
            aria-label="Sluiten"
            data-dialog-close="true"
          >
            <X aria-hidden size={18} />
          </button>
        </header>
        {description ? (
          <p className={styles.dialogDescription} id={descriptionId}>{description}</p>
        ) : null}
        {children}
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
    <form className={`${styles.form} ${styles.dialogForm}`} onSubmit={submit}>
      <label>
        Type verzoek
        <select
          value={form.type}
          onChange={(event) => setForm((current) => ({
            ...current,
            type: event.target.value as ProductRequestType,
          }))}
        >
          {productRequestTypeOptions.map((option) => (
            <option value={option.value} key={option.value}>{option.label}</option>
          ))}
        </select>
      </label>
      <label>
        Titel
        <input
          value={form.title}
          maxLength={180}
          required
          data-dialog-initial="true"
          onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))}
          placeholder="Korte samenvatting"
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
          placeholder="Wat gebeurt er nu en wat verwacht je?"
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
  );
}

function RequestTable({
  requests,
  loading,
  error,
  hasFilters,
  onReload,
  onOpen,
}: {
  requests: ProductRequest[];
  loading: boolean;
  error: string | null;
  hasFilters: boolean;
  onReload: () => Promise<void>;
  onOpen: (requestId: string) => void;
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
    <div className={styles.tableWrap}>
      <table className={`data-table ${styles.requestTable}`} aria-label="Verzoekenoverzicht">
        <colgroup>
          <col className={styles.typeColumn} />
          <col />
          <col className={styles.requesterColumn} />
          <col className={styles.statusColumn} />
          <col className={styles.updatedColumn} />
          <col className={styles.actionColumn} />
        </colgroup>
        <thead>
          <tr>
            <th scope="col">Type</th>
            <th scope="col">Verzoek</th>
            <th scope="col">Indiener</th>
            <th scope="col">Status</th>
            <th scope="col">Bijgewerkt</th>
            <th scope="col">Acties</th>
          </tr>
        </thead>
        <tbody>
          {requests.map((request) => (
            <tr key={request.id} data-status={request.status}>
              <td data-label="Type">
                <span className={styles.typeLabel} data-type={request.type}>
                  <RequestTypeIcon type={request.type} />
                  {productRequestTypeLabel(request.type)}
                </span>
              </td>
              <td data-label="Verzoek">
                <div className={styles.requestSummary}>
                  <strong>{request.title}</strong>
                  <span>{request.description}</span>
                </div>
              </td>
              <td data-label="Indiener">
                {request.is_owner ? 'Jij' : actorName(request.requester.name)}
              </td>
              <td data-label="Status">
                <RequestStatusBadge status={request.status} />
              </td>
              <td data-label="Bijgewerkt">
                <time dateTime={request.updated_at}>{formatDateTime(request.updated_at)}</time>
              </td>
              <td data-label="Acties">
                <button
                  className={`secondary-button ${styles.viewButton}`}
                  type="button"
                  onClick={() => onOpen(request.id)}
                  aria-label={`Verzoek bekijken: ${request.title}`}
                >
                  <span>Bekijken</span>
                  <ChevronRight aria-hidden size={16} />
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
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
  const [updatingStatus, setUpdatingStatus] = useState(false);

  return (
    <article className={styles.detail}>
      <dl className={styles.detailMeta}>
        <div><dt>Ingediend door</dt><dd>{actorName(request.requester.name)}</dd></div>
        <div><dt>Aangemaakt</dt><dd><time dateTime={request.created_at}>{formatDateTime(request.created_at)}</time></dd></div>
        <div><dt>Bijgewerkt</dt><dd><time dateTime={request.updated_at}>{formatDateTime(request.updated_at)}</time></dd></div>
      </dl>

      {!editing && !updatingStatus && (request.can_update || request.can_resolve) ? (
        <div className={styles.detailActions}>
          {request.can_update ? (
            <button className="secondary-button" type="button" onClick={() => setEditing(true)}>
              <FilePenLine aria-hidden size={16} /> Aanpassen
            </button>
          ) : null}
          {request.can_resolve ? (
            <button className="secondary-button" type="button" onClick={() => setUpdatingStatus(true)}>
              <Wrench aria-hidden size={16} /> Status wijzigen
            </button>
          ) : null}
        </div>
      ) : null}

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
          <h3 id={`product-request-description-${request.id}`}>Omschrijving</h3>
          <p>{request.description}</p>
        </section>
      )}

      {request.resolution_note ? (
        <section className={styles.resolutionNote} aria-labelledby={`product-request-resolution-${request.id}`}>
          <span className={styles.resolutionIcon} aria-hidden><CheckCircle2 size={19} /></span>
          <div>
            <h3 id={`product-request-resolution-${request.id}`}>Toelichting op de afhandeling</h3>
            <p>{request.resolution_note}</p>
            {request.resolved_by ? <small>Door {actorName(request.resolved_by.name)}</small> : null}
          </div>
        </section>
      ) : null}

      {updatingStatus ? (
        <StatusUpdateForm
          request={request}
          onCancel={() => setUpdatingStatus(false)}
          onSubmit={async (form) => {
            await onStatusUpdate(request, form);
            setUpdatingStatus(false);
          }}
        />
      ) : null}

      {request.status_history && request.status_history.length > 0 ? (
        <StatusHistory entries={request.status_history} />
      ) : null}
    </article>
  );
}

function StatusHistory({
  entries,
}: {
  entries: ProductRequestStatusHistoryEntry[];
}) {
  return (
    <details className={styles.history}>
      <summary>
        <span>Statusgeschiedenis</span>
        <small>{entries.length}</small>
      </summary>
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
    </details>
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
      <h3>Verzoek aanpassen</h3>
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
  onCancel,
  onSubmit,
}: {
  request: ProductRequest;
  onCancel: () => void;
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
      <h3 id={`product-request-resolver-${request.id}`}>Status wijzigen</h3>
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
          <button className="secondary-button" type="button" onClick={onCancel} disabled={saving}>
            Annuleren
          </button>
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
