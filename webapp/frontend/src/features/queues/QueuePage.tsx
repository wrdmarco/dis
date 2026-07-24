'use client';

import {
  BellRing,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Clock3,
  RefreshCw,
  RotateCcw,
  Rows3,
  TriangleAlert,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import type { ApiClient } from '../../lib/apiClient';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import type {
  ApiResponse,
  PaginationMeta,
  QueueMonitorFilter,
  QueueMonitorItem,
  QueueMonitorLane,
  QueueMonitorSnapshot,
  QueueMonitorStateFilter,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  boundedQueueProgress,
  formatQueueRuntime,
  formatQueueWait,
  queueFilterLabel,
  queueLaneDescription,
  queueMonitorPath,
  queueStateFilterLabel,
  queueStateLabel,
  queueStateTone,
} from './queuePresentation';
import { startQueuePolling } from './queuePolling';
import styles from './QueuePage.module.css';

const QUEUE_OPTIONS: QueueMonitorFilter[] = ['all', 'push'];
const STATE_OPTIONS: QueueMonitorStateFilter[] = [
  'all',
  'pending',
  'queued',
  'processing',
  'retrying',
  'failed',
  'completed',
  'cancelled',
];
const PAGE_SIZE_OPTIONS = [25, 50, 100] as const;
const DUTCH_INTEGER = new Intl.NumberFormat('nl-NL', { maximumFractionDigits: 0 });

interface QueuePagination extends PaginationMeta {
  is_truncated: boolean;
}

const EMPTY_PAGINATION: QueuePagination = {
  current_page: 1,
  per_page: 25,
  total: 0,
  last_page: 1,
  is_truncated: false,
};

interface QueueResource {
  data: QueueMonitorSnapshot | null;
  pagination: QueuePagination;
  loading: boolean;
  refreshing: boolean;
  error: string | null;
  reload: () => Promise<void>;
}

export function QueuePage() {
  const { api } = useAuth();
  const [queueFilter, setQueueFilter] = useState<QueueMonitorFilter>('all');
  const [stateFilter, setStateFilter] = useState<QueueMonitorStateFilter>('all');
  const [perPage, setPerPage] = useState(25);
  const [page, setPage] = useState(1);
  const path = queueMonitorPath(queueFilter, stateFilter, page, perPage);
  const resource = useQueueMonitor(api, path);
  const snapshot = resource.data;

  function changeQueue(value: QueueMonitorFilter) {
    setQueueFilter(value);
    setPage(1);
  }

  function changeState(value: QueueMonitorStateFilter) {
    setStateFilter(value);
    setPage(1);
  }

  function changePageSize(value: number) {
    setPerPage(value);
    setPage(1);
  }

  return (
    <div className={`page-stack ${styles.page}`}>
      <Panel
        title="Wachtrijen"
        action={(
          <QueueRefreshStatus
            data={snapshot}
            error={resource.error}
            loading={resource.loading}
            refreshing={resource.refreshing}
            onRefresh={resource.reload}
          />
        )}
      >
        <ResourceState
          loading={resource.loading && snapshot === null}
          error={snapshot === null ? resource.error : null}
          empty={snapshot === null}
        >
          {snapshot ? (
            <div className={styles.overview}>
              {resource.error ? (
                <p className={styles.staleWarning} role="status">
                  De actuele wachtrijstatus kon tijdelijk niet worden opgehaald. De laatste geldige meting blijft zichtbaar.
                </p>
              ) : null}

              <header className={styles.intro}>
                <div>
                  <span className={styles.eyebrow}>Live werkvoorraad</span>
                  <h3>Pushmeldingen direct in beeld</h3>
                  <p>
                    Bekijk welke meldingen wachten, worden verwerkt, opnieuw worden geprobeerd of al zijn afgerond.
                    Parallelle workers houden de operationele alarmering vlot.
                  </p>
                </div>
                <div className={styles.generatedAt}>
                  <Clock3 aria-hidden size={17} />
                  <span>
                    Gemeten
                    <time dateTime={snapshot.generated_at}>{formatDateTime(snapshot.generated_at)}</time>
                  </span>
                </div>
              </header>

              <div className={styles.lanes}>
                {orderedLanes(snapshot.queues).map((lane) => (
                  <QueueLaneCard lane={lane} key={lane.key} />
                ))}
              </div>

              <QueueSummary snapshot={snapshot} />
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      <Panel
        title="Wachtrijtaken"
        action={snapshot ? (
          <span className={styles.resultCount}>
            {DUTCH_INTEGER.format(resource.pagination.total)}{resource.pagination.is_truncated ? '+' : ''} taken
          </span>
        ) : undefined}
      >
        <div className={styles.workbench}>
          <div className={styles.filters} aria-label="Wachtrijfilters">
            <label>
              <span>Wachtrij</span>
              <select
                value={queueFilter}
                onChange={(event) => changeQueue(event.target.value as QueueMonitorFilter)}
              >
                {QUEUE_OPTIONS.map((option) => (
                  <option key={option} value={option}>{queueFilterLabel(option)}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Status</span>
              <select
                value={stateFilter}
                onChange={(event) => changeState(event.target.value as QueueMonitorStateFilter)}
              >
                {STATE_OPTIONS.map((option) => (
                  <option key={option} value={option}>{queueStateFilterLabel(option)}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Per pagina</span>
              <select
                value={perPage}
                onChange={(event) => changePageSize(Number(event.target.value))}
              >
                {PAGE_SIZE_OPTIONS.map((option) => (
                  <option key={option} value={option}>{option}</option>
                ))}
              </select>
            </label>
          </div>

          <ResourceState
            loading={resource.loading && snapshot === null}
            error={snapshot === null ? resource.error : null}
            empty={false}
          >
            {snapshot ? (
              snapshot.items.length > 0 ? (
                <>
                  {resource.pagination.is_truncated ? (
                    <p className={styles.truncationNotice} role="status">
                      <TriangleAlert aria-hidden size={16} />
                      {DUTCH_INTEGER.format(resource.pagination.total)}+ taken · nieuwste {DUTCH_INTEGER.format(resource.pagination.total)} zichtbaar
                    </p>
                  ) : null}
                  <ol className={styles.workList} aria-label="Taken in de wachtrijen">
                    {snapshot.items.map((item) => (
                      <QueueWorkItem item={item} generatedAt={snapshot.generated_at} key={`${item.queue}-${item.id}`} />
                    ))}
                  </ol>
                  <QueuePagination
                    pagination={resource.pagination}
                    onPage={setPage}
                  />
                </>
              ) : (
                <div className={styles.emptyState} role="status">
                  <CheckCircle2 aria-hidden size={25} />
                  <div>
                    <strong>Geen werk voor deze filters</strong>
                    <span>Er staan geen passende taken meer te wachten of te verwerken.</span>
                  </div>
                </div>
              )
            ) : null}
          </ResourceState>
        </div>
      </Panel>
    </div>
  );
}

function QueueRefreshStatus({
  data,
  error,
  loading,
  refreshing,
  onRefresh,
}: {
  data: QueueMonitorSnapshot | null;
  error: string | null;
  loading: boolean;
  refreshing: boolean;
  onRefresh: () => Promise<void>;
}) {
  const seconds = data?.refresh_after_seconds ?? 5;
  const state = error ? 'stale' : data === null || loading ? 'connecting' : 'live';
  const label = state === 'stale'
    ? 'Verbinding herstellen'
    : state === 'connecting'
      ? 'Verbinden'
      : `Live · elke ${seconds} sec.`;

  return (
    <div className={styles.refreshActions}>
      <span className={`${styles.liveStatus} ${styles[`liveStatus-${state}`]}`} role="status" aria-live="polite">
        <span aria-hidden className={styles.liveDot} />
        {label}
      </span>
      <button
        className="secondary-button"
        type="button"
        onClick={() => void onRefresh()}
        disabled={refreshing}
        aria-busy={refreshing}
      >
        <RefreshCw aria-hidden className={refreshing ? 'spin' : undefined} size={16} />
        {refreshing ? 'Verversen' : 'Nu verversen'}
      </button>
    </div>
  );
}

function QueueLaneCard({ lane }: { lane: QueueMonitorLane }) {
  const waiting = lane.states.pending + lane.states.queued + lane.states.retrying;
  const icon = lane.key === 'push'
    ? <BellRing aria-hidden size={23} />
    : <Rows3 aria-hidden size={23} />;

  return (
    <article className={`${styles.lane} ${styles[`lane-${lane.key}`] ?? ''}`}>
      <header>
        <span className={styles.laneIcon}>{icon}</span>
        <div>
          <span className={styles.laneKicker}>Eigen verwerkingsbaan</span>
          <h3>{laneHeading(lane)}</h3>
        </div>
      </header>
      <p>{queueLaneDescription(lane.key)}</p>
      <dl className={styles.laneFacts}>
        <div><dt>Wachtend</dt><dd>{waiting}</dd></div>
        <div><dt>Bezig</dt><dd>{lane.states.processing}</dd></div>
        <div><dt>Totaal in transportwachtrij</dt><dd>{countValue(lane.transport_pending_count)}</dd></div>
        <div className={typeof lane.transport_failed_count === 'number' && lane.transport_failed_count > 0 ? styles.failedFact : undefined}>
          <dt>Transportfouten</dt><dd>{countValue(lane.transport_failed_count)}</dd>
        </div>
      </dl>
      <footer>
        <span className={styles.capacity}>
          Ingesteld: {lane.configured_parallelism} parallelle {lane.configured_parallelism === 1 ? 'worker' : 'workers'}
        </span>
        <small>Dit is de ingestelde capaciteit, geen live workerstatus.</small>
      </footer>
    </article>
  );
}

function QueueSummary({ snapshot }: { snapshot: QueueMonitorSnapshot }) {
  const summary = snapshot.summary;
  const waiting = summary.pending + summary.queued;
  const facts = [
    { label: 'Wachtend', value: waiting, icon: <Clock3 aria-hidden size={18} />, tone: 'neutral' },
    { label: 'In verwerking', value: summary.processing, icon: <Rows3 aria-hidden size={18} />, tone: 'active' },
    { label: 'Nieuwe poging', value: summary.retrying, icon: <RotateCcw aria-hidden size={18} />, tone: 'warning' },
    { label: 'Mislukt', value: summary.failed, icon: <TriangleAlert aria-hidden size={18} />, tone: 'danger' },
    { label: 'Verwerkt', value: summary.completed, icon: <CheckCircle2 aria-hidden size={18} />, tone: 'success' },
  ];

  return (
    <div className={styles.summary} aria-label={`Totaal ${summary.total} geregistreerde taken`}>
      {facts.map((fact) => (
        <article className={`${styles.summaryFact} ${styles[`summaryFact-${fact.tone}`]}`} key={fact.label}>
          <span>{fact.icon}{fact.label}</span>
          <strong>{fact.value}</strong>
        </article>
      ))}
    </div>
  );
}

function QueueWorkItem({ item, generatedAt }: { item: QueueMonitorItem; generatedAt: string }) {
  const progress = boundedQueueProgress(item.progress_percent);
  const tone = queueStateTone(item.state);

  return (
    <li>
      <article className={styles.workItem}>
        <header>
          <div className={styles.workIdentity}>
            <span className={`${styles.queueTag} ${styles[`queueTag-${item.queue}`] ?? ''}`}>
              {item.queue === 'push' ? 'Push' : item.queue}
            </span>
            <h3>{item.label}</h3>
            <small>{workloadTypeLabel(item.workload_type)}</small>
          </div>
          <span className={`${styles.state} ${styles[`state-${tone}`]}`}>
            {queueStateLabel(item.state)}
          </span>
        </header>

        {progress !== null ? (
          <div className={styles.progress}>
            <div>
              <span>Voortgang</span>
              <strong>{progress}%</strong>
            </div>
            <progress max={100} value={progress} aria-label={`Voortgang ${item.label}`}>
              {progress}%
            </progress>
          </div>
        ) : null}

        <dl className={styles.workFacts}>
          <div>
            <dt>In wachtrij sinds</dt>
            <dd>{dateValue(item.queued_at)}</dd>
          </div>
          <div>
            <dt>Wachttijd</dt>
            <dd>{formatQueueWait(item, generatedAt)}</dd>
          </div>
          <div>
            <dt>Verwerkingsduur</dt>
            <dd>{formatQueueRuntime(item, generatedAt)}</dd>
          </div>
          <div>
            <dt>Pogingen</dt>
            <dd>{item.attempts ?? '-'}</dd>
          </div>
          {item.next_attempt_at ? (
            <div>
              <dt>Volgende poging</dt>
              <dd>{dateValue(item.next_attempt_at)}</dd>
            </div>
          ) : null}
          {item.finished_at ? (
            <div>
              <dt>Afgerond</dt>
              <dd>{dateValue(item.finished_at)}</dd>
            </div>
          ) : null}
        </dl>

        {item.error_code ? (
          <p className={styles.errorCode}>
            <TriangleAlert aria-hidden size={16} />
            Foutcode: <code>{item.error_code}</code>
          </p>
        ) : null}
      </article>
    </li>
  );
}

function QueuePagination({
  pagination,
  onPage,
}: {
  pagination: QueuePagination;
  onPage: (page: number) => void;
}) {
  if (pagination.last_page <= 1) {
    return null;
  }

  return (
    <nav className={styles.pagination} aria-label="Pagina's met wachtrijtaken">
      <button
        className="secondary-button"
        type="button"
        disabled={pagination.current_page <= 1}
        onClick={() => onPage(pagination.current_page - 1)}
      >
        <ChevronLeft aria-hidden size={17} />
        Vorige
      </button>
      <span>Pagina {pagination.current_page} van {pagination.last_page}</span>
      <button
        className="secondary-button"
        type="button"
        disabled={pagination.current_page >= pagination.last_page}
        onClick={() => onPage(pagination.current_page + 1)}
      >
        Volgende
        <ChevronRight aria-hidden size={17} />
      </button>
    </nav>
  );
}

function useQueueMonitor(api: ApiClient, path: string): QueueResource {
  const [data, setData] = useState<QueueMonitorSnapshot | null>(null);
  const [pagination, setPagination] = useState<QueuePagination>(EMPTY_PAGINATION);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const manualReloadRef = useRef<() => Promise<void>>(async () => undefined);

  useEffect(() => {
    let active = true;
    let activeLoad: Promise<number | null> | null = null;

    setData(null);
    setPagination(EMPTY_PAGINATION);
    setLoading(true);
    setError(null);

    const load = (): Promise<number | null> => {
      if (activeLoad !== null) {
        return activeLoad;
      }

      activeLoad = api.get<QueueMonitorSnapshot>(path)
        .then((response) => {
          if (active) {
            setData(response.data);
            setPagination(readPagination(response));
            setError(null);
          }

          return response.data.refresh_after_seconds;
        })
        .finally(() => {
          activeLoad = null;
        });

      return activeLoad;
    };

    manualReloadRef.current = async () => {
      if (!active) return;
      setRefreshing(true);
      try {
        await load();
      } catch (loadError) {
        if (active) {
          setError(queueLoadError(loadError));
        }
      } finally {
        if (active) {
          setRefreshing(false);
        }
      }
    };

    const stopPolling = startQueuePolling({
      load,
      isHidden: () => document.hidden,
      schedule: (callback, delayMs) => window.setTimeout(callback, delayMs),
      cancel: (handle) => window.clearTimeout(handle),
      subscribeVisibility: (listener) => {
        document.addEventListener('visibilitychange', listener);
        return () => document.removeEventListener('visibilitychange', listener);
      },
      onError: (loadError) => {
        if (active) setError(queueLoadError(loadError));
      },
      onSettled: () => {
        if (active) setLoading(false);
      },
    });

    return () => {
      active = false;
      manualReloadRef.current = async () => undefined;
      stopPolling();
    };
  }, [api, path]);

  const reload = useCallback(() => manualReloadRef.current(), []);

  return { data, pagination, loading, refreshing, error, reload };
}

function readPagination(response: ApiResponse<QueueMonitorSnapshot>): QueuePagination {
  const meta = response.meta;
  if (!meta || !('current_page' in meta)) {
    return EMPTY_PAGINATION;
  }

  return {
    current_page: positiveInteger(meta.current_page, 1),
    per_page: positiveInteger(meta.per_page, 25),
    total: nonNegativeInteger(meta.total),
    last_page: positiveInteger(meta.last_page, 1),
    is_truncated: 'is_truncated' in meta && meta.is_truncated === true,
  };
}

function positiveInteger(value: unknown, fallback: number): number {
  return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : fallback;
}

function nonNegativeInteger(value: unknown): number {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : 0;
}

function queueLoadError(error: unknown): string {
  return error instanceof ApiClientError
    ? error.message
    : 'De wachtrijstatus kon niet worden geladen.';
}

function orderedLanes(lanes: QueueMonitorLane[]): QueueMonitorLane[] {
  const order = new Map([
    ['push', 0],
  ]);

  return [...lanes].sort((left, right) => (
    (order.get(left.key) ?? 10) - (order.get(right.key) ?? 10)
    || left.label.localeCompare(right.label, 'nl')
  ));
}

function workloadTypeLabel(value: string): string {
  const labels: Record<string, string> = {
    push_notification: 'Pushmelding',
  };

  return labels[value] ?? 'Overige achtergrondtaak';
}

function laneHeading(lane: QueueMonitorLane): string {
  if (lane.key === 'push') return 'Pushmeldingen';
  return lane.label;
}

function dateValue(value: string | null): React.ReactNode {
  return value
    ? <time dateTime={value}>{formatDateTime(value)}</time>
    : '-';
}

function countValue(value: number | null): React.ReactNode {
  return value === null
    ? <span className={styles.unavailableCount}>Niet beschikbaar</span>
    : value;
}
