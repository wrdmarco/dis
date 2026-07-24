import {
  AlertTriangle,
  BookOpenCheck,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Database,
  Hash,
  Map,
  MapPin,
  MessageSquareText,
  Plus,
  RefreshCw,
  Search,
  ShieldCheck,
  Trash2,
  Volume2,
  X,
} from 'lucide-react';
import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
  type MouseEvent as ReactMouseEvent,
} from 'react';
import { Panel } from '../../components/Panel';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError, apiBaseUrl } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type {
  PaginationMeta,
  SpeechPreparationKind,
  SpeechPreparationStatus,
  SpeechPreparationSummary,
  SpeechPreparedPhrase,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  formatSpeechBytes,
  formatSpeechDuration,
  fixedSpeechPreparationAudioPath,
  normalizeSpeechProgress,
  SPEECH_POLL_INTERVAL_MS,
  speechPreparationContainsTemplateToken,
  speechPreparationValues,
  speechStatusLabel,
  speechStatusTone,
  speechWorkIsActive,
} from './speechPresentation';
import styles from './SpeechPreparationLibrary.module.css';

const PREPARATION_KINDS: Array<{
  kind: SpeechPreparationKind;
  label: string;
  singular: string;
  help: string;
  placeholder: string;
  icon: typeof MapPin;
}> = [
  {
    kind: 'residence',
    label: 'Woonplaatsen',
    singular: 'woonplaats',
    help: 'Eén woonplaats per regel, bijvoorbeeld Utrecht of ’s-Hertogenbosch.',
    placeholder: 'Utrecht\nAmersfoort',
    icon: MapPin,
  },
  {
    kind: 'province',
    label: 'Provincies',
    singular: 'provincie',
    help: 'Houd provincies los van woonplaatsen, zodat ieder segment gericht herbruikbaar blijft.',
    placeholder: 'Utrecht\nGelderland',
    icon: Map,
  },
  {
    kind: 'postcode',
    label: 'Postcodes',
    singular: 'postcode',
    help: 'Gebruik Nederlandse postcodes. De server bewaart 1234 AB en spreekt de tekens afzonderlijk uit.',
    placeholder: '1234 AB\n3581 CP',
    icon: Hash,
  },
  {
    kind: 'fixed_phrase',
    label: 'Vaste template- en pushzinnen',
    singular: 'vaste zin',
    help: 'Voer exact gerenderde regels in. Variabelen zoals {plaats} of {{place}} kunnen niet letterlijk worden voorbereid.',
    placeholder: 'Open de D.I.S.-app en geef je beschikbaarheid door.\nDit is een proefalarmering.',
    icon: MessageSquareText,
  },
];

const EMPTY_SUMMARY: SpeechPreparationSummary = {
  counts: {
    residence: 0,
    province: 0,
    postcode: 0,
    fixed_phrase: 0,
  },
  total_count: 0,
  ready_count: 0,
  pending_count: 0,
  failed_count: 0,
  disk_bytes: 0,
};

const EMPTY_PAGINATION: PaginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 0,
};

const CLEAR_CONFIRMATION = 'VOORBEREIDINGSCACHE LEGEN';
const MODAL_FOCUSABLE_SELECTOR = [
  'audio[controls]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

export function SpeechPreparationLibrary({
  canView,
  canManage,
}: {
  canView: boolean;
  canManage: boolean;
}) {
  const summaryPollInFlightRef = useRef(false);
  const summaryResource = useApiResource<SpeechPreparationSummary>(
    '/admin/speech/preparations/summary',
    canView,
  );
  const reloadSummarySilently = summaryResource.silentReload;
  const reloadSummary = summaryResource.reload;
  const [open, setOpen] = useState(false);
  const summary = summaryResource.data ?? EMPTY_SUMMARY;
  const summaryAvailable = summaryResource.data !== null;
  const summaryLoading = summaryResource.loading && !summaryAvailable;
  const summaryStatus = summaryLoading
    ? { label: 'Status laden', tone: 'neutral' as const }
    : summaryResource.error !== null
      ? {
          label: summaryAvailable ? 'Status verouderd' : 'Status onbekend',
          tone: 'warn' as const,
        }
      : summary.pending_count > 0
        ? { label: `${summary.pending_count} in verwerking`, tone: 'warn' as const }
        : { label: 'Blijvend opgeslagen', tone: 'good' as const };

  useEffect(() => {
    if (!canView || summary.pending_count === 0) return undefined;

    const intervalId = window.setInterval(() => {
      if (document.visibilityState !== 'visible' || summaryPollInFlightRef.current) return;

      summaryPollInFlightRef.current = true;
      void reloadSummarySilently().finally(() => {
        summaryPollInFlightRef.current = false;
      });
    }, SPEECH_POLL_INTERVAL_MS);

    return () => window.clearInterval(intervalId);
  }, [canView, reloadSummarySilently, summary.pending_count]);

  if (!canView) return null;

  return (
    <>
      <Panel
        title="Blijvende voorbereidingsbibliotheek"
        action={(
          <StatusPill
            value={summaryStatus.label}
            tone={summaryStatus.tone}
          />
        )}
      >
        <div className={styles.panelBody}>
          <div className={styles.intro}>
            <span aria-hidden><BookOpenCheck size={23} /></span>
            <div>
              <strong>Veelgebruikte uitspraak één keer voorbereiden</strong>
              <p>
                Woonplaatsen, provincies, postcodes en exacte template- of pushzinnen verlopen niet.
                Bij een andere stem, snelheid of model bouwt de server de audio opnieuw op.
              </p>
            </div>
          </div>

          {summaryResource.error ? (
            <div className={`${styles.inlineError} ${styles.summaryError}`} role="alert">
              <AlertTriangle aria-hidden size={17} />
              <span>
                {summaryAvailable
                  ? 'De actuele voorbereidingsstatus kon niet worden opgehaald. De laatst bekende aantallen blijven zichtbaar.'
                  : 'De voorbereidingsstatus kon niet worden opgehaald.'}
              </span>
              <button
                className="secondary-button"
                type="button"
                disabled={summaryResource.loading}
                onClick={() => void reloadSummary()}
              >
                {summaryResource.loading ? 'Opnieuw laden…' : 'Opnieuw proberen'}
              </button>
            </div>
          ) : null}

          <div className={styles.summaryGrid}>
            {PREPARATION_KINDS.map(({ kind, label, icon: Icon }) => (
              <div key={kind}>
                <Icon aria-hidden size={18} />
                <span>{label}</span>
                <strong>{summaryAvailable ? summary.counts[kind].toLocaleString('nl-NL') : '–'}</strong>
              </div>
            ))}
          </div>

          <dl className={styles.summaryFacts}>
            <div><dt>Gereed</dt><dd>{summaryAvailable ? summary.ready_count.toLocaleString('nl-NL') : '–'}</dd></div>
            <div><dt>In verwerking</dt><dd>{summaryAvailable ? summary.pending_count.toLocaleString('nl-NL') : '–'}</dd></div>
            <div><dt>Mislukt</dt><dd>{summaryAvailable ? summary.failed_count.toLocaleString('nl-NL') : '–'}</dd></div>
            <div><dt>Blijvende audio</dt><dd>{summaryAvailable ? formatSpeechBytes(summary.disk_bytes) : '–'}</dd></div>
          </dl>

          <div className={styles.panelAction}>
            <p>
              Alleen een exacte TTS-regel levert een directe cachetreffer op. Zet plaats, provincie en
              postcode daarom op afzonderlijke sjabloonregels wanneer je losse segmenten wilt hergebruiken.
            </p>
            <button
              className="primary-button"
              type="button"
              aria-haspopup="dialog"
              onClick={() => setOpen(true)}
            >
              <Database aria-hidden size={18} />
              Voorbereidingsbibliotheek {canManage ? 'beheren' : 'bekijken'}
            </button>
          </div>
        </div>
      </Panel>

      {open ? (
        <PreparationLibraryModal
          canManage={canManage}
          onClose={() => setOpen(false)}
          onChanged={reloadSummarySilently}
        />
      ) : null}
    </>
  );
}

function PreparationLibraryModal({
  canManage,
  onClose,
  onChanged,
}: {
  canManage: boolean;
  onClose: () => void;
  onChanged: () => Promise<void>;
}) {
  const { api } = useAuth();
  const dialogRef = useRef<HTMLElement | null>(null);
  const tabListRef = useRef<HTMLDivElement | null>(null);
  const closeButtonRef = useRef<HTMLButtonElement | null>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);
  const requestGenerationRef = useRef(0);
  const activeListRequestsRef = useRef(0);
  const [kind, setKind] = useState<SpeechPreparationKind>('residence');
  const [values, setValues] = useState('');
  const [search, setSearch] = useState('');
  const [deferredSearch, setDeferredSearch] = useState('');
  const [status, setStatus] = useState<'all' | SpeechPreparationStatus>('all');
  const [page, setPage] = useState(1);
  const [entries, setEntries] = useState<SpeechPreparedPhrase[]>([]);
  const [pagination, setPagination] = useState(EMPTY_PAGINATION);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [mutationError, setMutationError] = useState<string | null>(null);
  const [mutationMessage, setMutationMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [reloadGeneration, setReloadGeneration] = useState(0);
  const [deleteConfirmation, setDeleteConfirmation] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const [regeneratingId, setRegeneratingId] = useState<string | null>(null);
  const [clearConfirmation, setClearConfirmation] = useState('');
  const [clearing, setClearing] = useState(false);
  const kindDefinition = PREPARATION_KINDS.find((candidate) => candidate.kind === kind) ?? PREPARATION_KINDS[0];
  const preparedValues = useMemo(() => speechPreparationValues(values), [values]);
  const containsTemplateToken = kind === 'fixed_phrase'
    && speechPreparationContainsTemplateToken(preparedValues);
  const hasActiveEntries = entries.some((entry) => speechWorkIsActive(entry.status));

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setPage(1);
      setDeferredSearch(search.trim());
    }, 250);

    return () => window.clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    const requestGeneration = requestGenerationRef.current + 1;
    requestGenerationRef.current = requestGeneration;
    const payload: {
      kind: SpeechPreparationKind;
      page: number;
      per_page: number;
      status?: SpeechPreparationStatus;
    } = {
      kind,
      page,
      per_page: EMPTY_PAGINATION.per_page,
    };
    if (status !== 'all') payload.status = status;
    const indexParameters = new URLSearchParams({
      kind,
      page: String(page),
      per_page: String(EMPTY_PAGINATION.per_page),
    });
    if (status !== 'all') indexParameters.set('status', status);
    const listRequest = deferredSearch === ''
      ? api.get<SpeechPreparedPhrase[]>(
          `/admin/speech/preparations?${indexParameters.toString()}`,
        )
      : api.post<SpeechPreparedPhrase[]>('/admin/speech/preparations/search', {
          ...payload,
          search: deferredSearch,
        });

    setLoading(true);
    setLoadError(null);
    activeListRequestsRef.current += 1;
    void listRequest
      .then((response) => {
        if (requestGeneration !== requestGenerationRef.current) return;
        const nextPagination = readPagination(response.meta);
        if (response.data.length === 0 && page > nextPagination.last_page) {
          setEntries([]);
          setPagination(nextPagination);
          setPage(nextPagination.last_page);

          return;
        }
        setEntries(response.data);
        setPagination(nextPagination);
      })
      .catch((error) => {
        if (requestGeneration !== requestGenerationRef.current) return;
        setEntries([]);
        setPagination(EMPTY_PAGINATION);
        setLoadError(apiErrorMessage(error, 'De voorbereidingsbibliotheek kon niet worden geladen.'));
      })
      .finally(() => {
        activeListRequestsRef.current = Math.max(0, activeListRequestsRef.current - 1);
        if (requestGeneration === requestGenerationRef.current) setLoading(false);
      });

    return () => {
      if (requestGenerationRef.current === requestGeneration) requestGenerationRef.current += 1;
    };
  }, [api, deferredSearch, kind, page, reloadGeneration, status]);

  useEffect(() => {
    if (!hasActiveEntries) return undefined;

    const intervalId = window.setInterval(() => {
      if (document.visibilityState !== 'visible' || activeListRequestsRef.current > 0) return;

      setReloadGeneration((value) => value + 1);
    }, SPEECH_POLL_INTERVAL_MS);

    return () => window.clearInterval(intervalId);
  }, [hasActiveEntries]);

  useEffect(() => {
    previousFocusRef.current = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    const bodyWasLocked = document.body.classList.contains(styles.modalBodyLock);
    document.body.classList.add(styles.modalBodyLock);
    const frame = window.requestAnimationFrame(() => closeButtonRef.current?.focus());

    return () => {
      window.cancelAnimationFrame(frame);
      if (!bodyWasLocked) document.body.classList.remove(styles.modalBodyLock);
      previousFocusRef.current?.focus();
    };
  }, []);

  function changeKind(nextKind: SpeechPreparationKind) {
    if (nextKind === kind) return;

    setKind(nextKind);
    setValues('');
    setSearch('');
    setDeferredSearch('');
    setStatus('all');
    setPage(1);
    setDeleteConfirmation(null);
    setMutationError(null);
    setMutationMessage(null);
  }

  function handleTabKeyDown(
    event: ReactKeyboardEvent<HTMLButtonElement>,
    currentIndex: number,
  ) {
    let nextIndex: number | null = null;
    switch (event.key) {
      case 'ArrowRight':
      case 'ArrowDown':
        nextIndex = (currentIndex + 1) % PREPARATION_KINDS.length;
        break;
      case 'ArrowLeft':
      case 'ArrowUp':
        nextIndex = (currentIndex - 1 + PREPARATION_KINDS.length) % PREPARATION_KINDS.length;
        break;
      case 'Home':
        nextIndex = 0;
        break;
      case 'End':
        nextIndex = PREPARATION_KINDS.length - 1;
        break;
      default:
        return;
    }

    event.preventDefault();
    const nextTab = tabListRef.current
      ?.querySelectorAll<HTMLButtonElement>('[role="tab"]')
      .item(nextIndex);
    nextTab?.focus();
    changeKind(PREPARATION_KINDS[nextIndex].kind);
  }

  function handleDialogKeyDown(event: ReactKeyboardEvent<HTMLElement>) {
    if (event.key === 'Escape') {
      event.preventDefault();
      onClose();
      return;
    }
    if (event.key !== 'Tab') return;

    const focusable = Array.from(
      dialogRef.current?.querySelectorAll<HTMLElement>(MODAL_FOCUSABLE_SELECTOR) ?? [],
    ).filter((element) => !element.hasAttribute('hidden'));
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (first === undefined || last === undefined) {
      event.preventDefault();
      dialogRef.current?.focus();
    } else if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function handleBackdropMouseDown(event: ReactMouseEvent<HTMLDivElement>) {
    if (event.target === event.currentTarget) onClose();
  }

  async function prepare() {
    if (preparedValues.length === 0 || containsTemplateToken) return;
    setSubmitting(true);
    setMutationError(null);
    setMutationMessage(null);
    try {
      await api.post<unknown>('/admin/speech/preparations', { kind, values: preparedValues });
      setValues('');
      setPage(1);
      setReloadGeneration((value) => value + 1);
      setMutationMessage(
        `${preparedValues.length} ${preparedValues.length === 1 ? kindDefinition.singular : 'items'} in de voorbereidingswachtrij geplaatst.`,
      );
      await onChanged();
    } catch (error) {
      setMutationError(apiErrorMessage(error, 'Voorbereiden is mislukt.'));
    } finally {
      setSubmitting(false);
    }
  }

  async function remove(entry: SpeechPreparedPhrase) {
    setDeletingId(entry.id);
    setMutationError(null);
    setMutationMessage(null);
    try {
      await api.delete<unknown>(`/admin/speech/preparations/${encodeURIComponent(entry.id)}`);
      setDeleteConfirmation(null);
      setReloadGeneration((value) => value + 1);
      setMutationMessage('Het blijvende item en de bijbehorende cachekoppeling zijn verwijderd.');
      await onChanged();
    } catch (error) {
      setMutationError(apiErrorMessage(error, 'Het item kon niet worden verwijderd.'));
    } finally {
      setDeletingId(null);
    }
  }

  async function regenerate(entry: SpeechPreparedPhrase) {
    setRegeneratingId(entry.id);
    setMutationError(null);
    setMutationMessage(null);
    try {
      await api.post<SpeechPreparedPhrase>(
        `/admin/speech/preparations/${encodeURIComponent(entry.id)}/regenerate`,
      );
      setReloadGeneration((value) => value + 1);
      setMutationMessage('Het item is opnieuw in de voorbereidingswachtrij geplaatst.');
      await onChanged();
    } catch (error) {
      setMutationError(apiErrorMessage(error, 'Opnieuw genereren kon niet worden gestart.'));
    } finally {
      setRegeneratingId(null);
    }
  }

  async function clearLibrary() {
    if (clearConfirmation !== CLEAR_CONFIRMATION) return;
    setClearing(true);
    setMutationError(null);
    setMutationMessage(null);
    try {
      await api.post<unknown>('/admin/speech/preparations/clear', { confirmation: clearConfirmation });
      setClearConfirmation('');
      setPage(1);
      setReloadGeneration((value) => value + 1);
      setMutationMessage('De volledige blijvende voorbereidingscache is geleegd. Andere cache-items bleven behouden.');
      await onChanged();
    } catch (error) {
      setMutationError(apiErrorMessage(error, 'De voorbereidingscache kon niet worden geleegd.'));
    } finally {
      setClearing(false);
    }
  }

  const firstResult = pagination.total === 0 ? 0 : ((pagination.current_page - 1) * pagination.per_page) + 1;
  const lastResult = Math.min(pagination.total, pagination.current_page * pagination.per_page);

  return (
    <div className={styles.modalBackdrop} role="presentation" onMouseDown={handleBackdropMouseDown}>
      <section
        ref={dialogRef}
        className={styles.modal}
        role="dialog"
        aria-modal="true"
        aria-labelledby="speech-preparation-title"
        aria-describedby="speech-preparation-description"
        tabIndex={-1}
        onKeyDown={handleDialogKeyDown}
      >
        <header className={styles.modalHeader}>
          <div>
            <span><ShieldCheck aria-hidden size={16} /> Blijvend en lokaal</span>
            <h2 id="speech-preparation-title">
              Voorbereidingsbibliotheek {canManage ? 'beheren' : 'bekijken'}
            </h2>
            <p id="speech-preparation-description">
              {canManage
                ? 'Bereid exacte uitspraak voor, volg de verwerking en verwijder alleen wat je bewust niet meer nodig hebt.'
                : 'Bekijk voorbereide uitspraak, volg de verwerking en luister naar beschikbare audio.'}
            </p>
          </div>
          <button
            ref={closeButtonRef}
            className={styles.closeButton}
            type="button"
            aria-label="Voorbereidingsbibliotheek sluiten"
            onClick={onClose}
          >
            <X aria-hidden size={21} />
          </button>
        </header>

        <div className={styles.modalBody}>
          <div
            ref={tabListRef}
            className={styles.kindTabs}
            role="tablist"
            aria-label="Soort voorbereiding"
          >
            {PREPARATION_KINDS.map(({ kind: candidateKind, label, icon: Icon }, index) => (
              <button
                key={candidateKind}
                id={`speech-preparation-tab-${candidateKind}`}
                type="button"
                role="tab"
                aria-selected={kind === candidateKind}
                aria-controls={`speech-preparation-panel-${candidateKind}`}
                tabIndex={kind === candidateKind ? 0 : -1}
                className={kind === candidateKind ? styles.kindTabActive : styles.kindTab}
                onKeyDown={(event) => handleTabKeyDown(event, index)}
                onClick={() => changeKind(candidateKind)}
              >
                <Icon aria-hidden size={17} />
                {label}
              </button>
            ))}
          </div>

          {PREPARATION_KINDS.map(({ kind: panelKind }) => (
            <div
              key={panelKind}
              id={`speech-preparation-panel-${panelKind}`}
              className={styles.kindPanel}
              role="tabpanel"
              aria-labelledby={`speech-preparation-tab-${panelKind}`}
              tabIndex={0}
              hidden={kind !== panelKind}
            >
              {kind === panelKind ? (
                <>
          {!canManage ? (
            <p className={styles.readOnlyNotice}>
              <ShieldCheck aria-hidden size={17} />
              Je hebt alleen leesrechten. Toevoegen, opnieuw genereren en verwijderen zijn uitgeschakeld.
            </p>
          ) : null}

          {canManage ? (
            <section className={styles.prepareCard} aria-labelledby="speech-prepare-form-title">
            <div>
              <span>Nieuwe voorbereiding</span>
              <h3 id="speech-prepare-form-title">{kindDefinition.label}</h3>
              <p>{kindDefinition.help}</p>
            </div>
            <label>
              Eén waarde of exacte zin per regel
              <textarea
                value={values}
                rows={4}
                maxLength={12_000}
                placeholder={kindDefinition.placeholder}
                disabled={submitting}
                onChange={(event) => {
                  setValues(event.target.value);
                  setMutationError(null);
                  setMutationMessage(null);
                }}
              />
            </label>
            <div className={styles.prepareFooter}>
              <span>{preparedValues.length} van maximaal 50 items</span>
              <button
                className="primary-button"
                type="button"
                disabled={submitting || preparedValues.length === 0 || preparedValues.length > 50 || containsTemplateToken}
                onClick={() => void prepare()}
              >
                {submitting ? <RefreshCw className={styles.spin} aria-hidden size={17} /> : <Plus aria-hidden size={18} />}
                {submitting ? 'In wachtrij plaatsen…' : 'Toevoegen en voorbereiden'}
              </button>
            </div>
            {containsTemplateToken ? (
              <p className={styles.inlineError} role="alert">
                <AlertTriangle aria-hidden size={17} />
                Deze zin bevat een variabele. Vul handmatig de exacte, gerenderde push- of templatezin in.
              </p>
            ) : null}
            </section>
          ) : null}

          {mutationError ? <p className={styles.inlineError} role="alert"><AlertTriangle aria-hidden size={17} />{mutationError}</p> : null}
          {mutationMessage ? <p className={styles.inlineSuccess} role="status"><CheckCircle2 aria-hidden size={17} />{mutationMessage}</p> : null}

          <div className={styles.filters}>
            <label>
              Zoeken
              <span>
                <Search aria-hidden size={17} />
                <input
                  type="search"
                  value={search}
                  placeholder={`Zoek in ${kindDefinition.label.toLocaleLowerCase('nl-NL')}`}
                  onChange={(event) => setSearch(event.target.value)}
                />
              </span>
            </label>
            <label>
              Status
              <select
                value={status}
                onChange={(event) => {
                  setPage(1);
                  setStatus(event.target.value as 'all' | SpeechPreparationStatus);
                }}
              >
                <option value="all">Alle statussen</option>
                <option value="ready">Gereed</option>
                <option value="queued">In wachtrij</option>
                <option value="processing">In verwerking</option>
                <option value="failed">Mislukt</option>
              </select>
            </label>
          </div>

          {loading ? (
            <div className={styles.emptyState} role="status">
              <RefreshCw className={styles.spin} aria-hidden size={22} />
              <strong>Voorbereidingen laden</strong>
            </div>
          ) : loadError ? (
            <div className={styles.emptyState} role="alert">
              <AlertTriangle aria-hidden size={22} />
              <strong>Laden mislukt</strong>
              <span>{loadError}</span>
              <button className="secondary-button" type="button" onClick={() => setReloadGeneration((value) => value + 1)}>
                Opnieuw proberen
              </button>
            </div>
          ) : entries.length === 0 ? (
            <div className={styles.emptyState} role="status">
              <BookOpenCheck aria-hidden size={22} />
              <strong>Nog geen {kindDefinition.label.toLocaleLowerCase('nl-NL')}</strong>
              <span>
                {canManage
                  ? 'Voeg hierboven één of meer items toe om de uitspraak blijvend voor te bereiden.'
                  : 'Er zijn voor dit onderdeel nog geen blijvende voorbereidingen opgeslagen.'}
              </span>
            </div>
          ) : (
            <>
              <p className={styles.resultSummary}>
                <strong>{firstResult}–{lastResult}</strong> van {pagination.total.toLocaleString('nl-NL')}
              </p>
              <ul className={styles.entryList}>
                {entries.map((entry) => (
                  <PreparationEntryCard
                    key={entry.id}
                    entry={entry}
                    canManage={canManage}
                    confirming={deleteConfirmation === entry.id}
                    deleting={deletingId === entry.id}
                    regenerating={regeneratingId === entry.id}
                    onAskDelete={() => setDeleteConfirmation(entry.id)}
                    onCancelDelete={() => setDeleteConfirmation(null)}
                    onDelete={() => void remove(entry)}
                    onRegenerate={() => void regenerate(entry)}
                  />
                ))}
              </ul>
              <nav className={styles.pagination} aria-label="Voorbereidingspagina's">
                <button
                  type="button"
                  aria-label="Vorige pagina"
                  disabled={pagination.current_page <= 1}
                  onClick={() => setPage((value) => Math.max(1, value - 1))}
                >
                  <ChevronLeft aria-hidden size={17} />
                </button>
                <span>Pagina <strong>{pagination.current_page}</strong> van {pagination.last_page}</span>
                <button
                  type="button"
                  aria-label="Volgende pagina"
                  disabled={pagination.current_page >= pagination.last_page}
                  onClick={() => setPage((value) => Math.min(pagination.last_page, value + 1))}
                >
                  <ChevronRight aria-hidden size={17} />
                </button>
              </nav>
            </>
          )}

          {canManage ? (
            <section className={styles.dangerZone} aria-labelledby="speech-preparation-clear-title">
              <div>
                <span>Danger zone</span>
                <h3 id="speech-preparation-clear-title">Volledige voorbereidingscache legen</h3>
                <p>
                  Dit verwijdert alle blijvende woonplaatsen, provincies, postcodes en vaste zinnen inclusief
                  hun cachekoppelingen. Andere cache-items en actieve alarmaudio blijven behouden.
                </p>
              </div>
              <label>
                Typ ter bevestiging <strong>{CLEAR_CONFIRMATION}</strong>
                <input
                  value={clearConfirmation}
                  autoComplete="off"
                  disabled={clearing}
                  onChange={(event) => setClearConfirmation(event.target.value)}
                />
              </label>
              <button
                className={styles.dangerButton}
                type="button"
                disabled={clearing || clearConfirmation !== CLEAR_CONFIRMATION}
                onClick={() => void clearLibrary()}
              >
                <Trash2 aria-hidden size={17} />
                {clearing ? 'Voorbereidingscache legen…' : 'Volledige voorbereidingscache legen'}
              </button>
            </section>
          ) : null}
                </>
              ) : null}
            </div>
          ))}
        </div>
      </section>
    </div>
  );
}

function PreparationEntryCard({
  entry,
  canManage,
  confirming,
  deleting,
  regenerating,
  onAskDelete,
  onCancelDelete,
  onDelete,
  onRegenerate,
}: {
  entry: SpeechPreparedPhrase;
  canManage: boolean;
  confirming: boolean;
  deleting: boolean;
  regenerating: boolean;
  onAskDelete: () => void;
  onCancelDelete: () => void;
  onDelete: () => void;
  onRegenerate: () => void;
}) {
  const [audioError, setAudioError] = useState(false);
  const cancelDeleteButtonRef = useRef<HTMLButtonElement | null>(null);
  const removeButtonRef = useRef<HTMLButtonElement | null>(null);
  const wasConfirmingRef = useRef(false);
  const progress = normalizeSpeechProgress(entry.progress_percent);
  const active = speechWorkIsActive(entry.status);
  const displayValue = entry.value?.trim() || 'Tekst niet beschikbaar';
  const audioSource = entry.audio_url !== null
    ? `${apiBaseUrl.replace(/\/$/, '')}${fixedSpeechPreparationAudioPath(entry.id)}`
    : null;

  useEffect(() => {
    setAudioError(false);
  }, [audioSource]);

  useEffect(() => {
    let frame: number | null = null;
    if (confirming) {
      frame = window.requestAnimationFrame(() => cancelDeleteButtonRef.current?.focus());
    } else if (wasConfirmingRef.current) {
      frame = window.requestAnimationFrame(() => removeButtonRef.current?.focus());
    }
    wasConfirmingRef.current = confirming;

    return () => {
      if (frame !== null) window.cancelAnimationFrame(frame);
    };
  }, [confirming]);

  return (
    <li className={`${styles.entry} ${styles[`entry_${entry.status}`]}`}>
      <header>
        <div>
          <span className={styles.permanentBadge}><ShieldCheck aria-hidden size={14} /> Blijvend</span>
          <p>{displayValue}</p>
        </div>
        <StatusPill value={speechStatusLabel(entry.status)} tone={speechStatusTone(entry.status)} />
      </header>

      {active ? (
        <div className={styles.entryProgress}>
          <div><span>Audio voorbereiden</span><strong>{progress}%</strong></div>
          <progress
            max={100}
            value={progress}
            aria-label={`${displayValue}: audio voor ${progress} procent voorbereid`}
          />
        </div>
      ) : null}

      {entry.status === 'failed' ? (
        <p className={styles.entryError} role="alert">
          <AlertTriangle aria-hidden size={16} />
          Voorbereiden is mislukt{entry.error_code ? ` (${entry.error_code})` : ''}.
        </p>
      ) : null}

      <dl className={styles.entryFacts}>
        <div><dt>Voorbereid</dt><dd>{formatDateTime(entry.prepared_at)}</dd></div>
        <div><dt>Duur</dt><dd>{entry.duration_ms === null ? '–' : formatSpeechDuration(entry.duration_ms)}</dd></div>
        <div><dt>Omvang</dt><dd>{entry.byte_size === null ? '–' : formatSpeechBytes(entry.byte_size)}</dd></div>
        <div><dt>Bijgewerkt</dt><dd>{formatDateTime(entry.updated_at)}</dd></div>
      </dl>

      {audioSource !== null ? (
        <div className={styles.audio}>
          <Volume2 aria-hidden size={17} />
          <audio
            controls
            preload="metadata"
            src={audioSource}
            aria-label={`Voorbereide audio afspelen voor ${displayValue}`}
            onError={() => setAudioError(true)}
            onLoadedMetadata={() => setAudioError(false)}
          >
            Uw browser ondersteunt geen audio-afspelen.
          </audio>
          {audioError ? (
            <p role="alert">
              De voorbereide audio kon niet worden geladen. Genereer dit item opnieuw.
            </p>
          ) : null}
        </div>
      ) : null}

      {canManage ? (
        <div className={styles.entryActions}>
        {confirming ? (
          <div className={styles.inlineConfirmation} role="group" aria-label={`Verwijderen van ${displayValue} bevestigen`}>
            <span>Dit blijvende item verwijderen?</span>
            <button ref={cancelDeleteButtonRef} type="button" disabled={deleting} onClick={onCancelDelete}>Annuleren</button>
            <button type="button" disabled={deleting} onClick={onDelete}>
              <Trash2 aria-hidden size={15} />
              {deleting ? 'Verwijderen…' : 'Definitief verwijderen'}
            </button>
          </div>
        ) : (
          <>
            <button
              className={styles.regenerateButton}
              type="button"
              disabled={active || regenerating}
              onClick={onRegenerate}
            >
              <RefreshCw className={regenerating ? styles.spin : undefined} aria-hidden size={16} />
              {regenerating ? 'Opnieuw starten…' : 'Opnieuw genereren'}
            </button>
            <button ref={removeButtonRef} className={styles.removeButton} type="button" onClick={onAskDelete}>
              <Trash2 aria-hidden size={16} />
              Verwijderen
            </button>
          </>
        )}
        </div>
      ) : null}
    </li>
  );
}

function readPagination(meta: unknown): PaginationMeta {
  if (meta === null || typeof meta !== 'object') return EMPTY_PAGINATION;
  const candidate = meta as Partial<PaginationMeta>;
  const currentPage = Number(candidate.current_page);
  const lastPage = Number(candidate.last_page);
  const perPage = Number(candidate.per_page);
  const total = Number(candidate.total);
  if (![currentPage, lastPage, perPage, total].every(Number.isFinite)) return EMPTY_PAGINATION;

  return {
    current_page: Math.max(1, Math.floor(currentPage)),
    last_page: Math.max(1, Math.floor(lastPage)),
    per_page: Math.max(1, Math.floor(perPage)),
    total: Math.max(0, Math.floor(total)),
  };
}

function apiErrorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiClientError ? error.message : fallback;
}
