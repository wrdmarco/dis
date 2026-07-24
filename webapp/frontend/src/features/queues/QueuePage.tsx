'use client';

import {
  CircleCheck,
  ChevronLeft,
  ChevronRight,
  LoaderCircle,
  Play,
  RefreshCw,
  RotateCcw,
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
  QueueMonitorAction,
  QueueMonitorActionResult,
  QueueMonitorItem,
  QueueMonitorSnapshot,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  boundedQueueProgress,
  formatQueueRuntime,
  formatQueueWait,
  isVisibleQueueItem,
  queueActionForItem,
  queueActionPath,
  queueMonitorPath,
  queueStateLabel,
  queueStateTone,
} from './queuePresentation';
import { startQueuePolling } from './queuePolling';
import styles from './QueuePage.module.css';

const PAGE_SIZE = 50;
const DUTCH_INTEGER = new Intl.NumberFormat('nl-NL', { maximumFractionDigits: 0 });

interface QueuePagination extends PaginationMeta {
  is_truncated: boolean;
}

const EMPTY_PAGINATION: QueuePagination = {
  current_page: 1,
  per_page: PAGE_SIZE,
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
  const { api, hasPermission } = useAuth();
  const [page, setPage] = useState(1);
  const busyItemsRef = useRef<Set<string>>(new Set());
  const actionStatusRef = useRef<HTMLParagraphElement>(null);
  const [busyItems, setBusyItems] = useState<ReadonlySet<string>>(() => new Set());
  const [actionStatus, setActionStatus] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const resource = useQueueMonitor(api, queueMonitorPath('all', 'open', page, PAGE_SIZE));
  const snapshot = resource.data;
  const canManage = hasPermission('system.queues.manage');
  const items = snapshot?.items.filter(isVisibleQueueItem) ?? [];

  useEffect(() => {
    if (snapshot !== null && page > resource.pagination.last_page) {
      setPage(resource.pagination.last_page);
    }
  }, [page, resource.pagination.last_page, snapshot]);

  async function runAction(item: QueueMonitorItem, action: QueueMonitorAction) {
    const itemKey = `${item.queue}-${item.id}`;
    if (busyItemsRef.current.has(itemKey)) {
      return;
    }
    const nextBusyItems = new Set(busyItemsRef.current);
    nextBusyItems.add(itemKey);
    busyItemsRef.current = nextBusyItems;
    setBusyItems(nextBusyItems);
    setActionStatus(null);
    setActionError(null);

    try {
      await api.post<QueueMonitorActionResult>(queueActionPath(item, action));
      await resource.reload();
      setActionStatus(
        action === 'retry'
          ? `${item.label} is opnieuw vrijgegeven.`
          : `${item.label} is vrijgegeven voor directe verwerking.`,
      );
      window.requestAnimationFrame(() => actionStatusRef.current?.focus());
    } catch (error) {
      setActionError(error instanceof ApiClientError ? error.message : 'Actie mislukt.');
      await resource.reload();
    } finally {
      const remainingBusyItems = new Set(busyItemsRef.current);
      remainingBusyItems.delete(itemKey);
      busyItemsRef.current = remainingBusyItems;
      setBusyItems(remainingBusyItems);
    }
  }

  return (
    <div className={`page-stack ${styles.page}`}>
      <Panel
        title="Wachtrij"
        action={(
          <QueueRefresh
            generatedAt={snapshot?.generated_at ?? null}
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
            <div className={styles.content}>
              <QueueStatusCounts snapshot={snapshot} />

              {resource.error ? (
                <p className={styles.notice} role="status">
                  <TriangleAlert aria-hidden size={16} />
                  Status tijdelijk verouderd.
                </p>
              ) : null}

              {actionError ? (
                <p className={`${styles.notice} ${styles.actionError}`} role="alert">
                  <TriangleAlert aria-hidden size={16} />
                  {actionError}
                </p>
              ) : null}

              {actionStatus ? (
                <p
                  className={`${styles.notice} ${styles.actionSuccess}`}
                  role="status"
                  tabIndex={-1}
                  ref={actionStatusRef}
                >
                  <CircleCheck aria-hidden size={16} />
                  {actionStatus}
                </p>
              ) : null}

              {resource.pagination.is_truncated ? (
                <p className={styles.limitNotice} role="status">
                  {DUTCH_INTEGER.format(resource.pagination.total)}+ taken
                </p>
              ) : null}

              {items.length > 0 ? (
                <>
                  <ol className={styles.workList} aria-label="Openstaande wachtrijtaken">
                    {items.map((item) => {
                      const itemKey = `${item.queue}-${item.id}`;
                      return (
                        <QueueWorkItem
                          item={item}
                          generatedAt={snapshot.generated_at}
                          canManage={canManage}
                          busy={busyItems.has(itemKey)}
                          onAction={runAction}
                          key={itemKey}
                        />
                      );
                    })}
                  </ol>
                  <QueuePagination pagination={resource.pagination} onPage={setPage} />
                </>
              ) : (
                <p className={styles.emptyState} role="status">Geen openstaande taken.</p>
              )}
            </div>
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}

function QueueRefresh({
  generatedAt,
  refreshing,
  onRefresh,
}: {
  generatedAt: string | null;
  refreshing: boolean;
  onRefresh: () => Promise<void>;
}) {
  return (
    <div className={styles.refresh}>
      {generatedAt ? (
        <time dateTime={generatedAt}>{formatDateTime(generatedAt)}</time>
      ) : null}
      <button
        className={styles.refreshButton}
        type="button"
        onClick={() => void onRefresh()}
        disabled={refreshing}
        aria-busy={refreshing}
        aria-label={refreshing ? 'Wachtrij wordt ververst' : 'Wachtrij verversen'}
        title="Wachtrij verversen"
      >
        <RefreshCw aria-hidden className={refreshing ? 'spin' : undefined} size={18} />
      </button>
    </div>
  );
}

function QueueStatusCounts({ snapshot }: { snapshot: QueueMonitorSnapshot }) {
  const facts = [
    {
      label: 'Wachtend',
      value: snapshot.summary.pending + snapshot.summary.queued + snapshot.summary.retrying,
      tone: 'waiting',
    },
    { label: 'Bezig', value: snapshot.summary.processing, tone: 'processing' },
    { label: 'Mislukt', value: snapshot.summary.failed, tone: 'failed' },
  ] as const;

  return (
    <dl className={styles.statusCounts} aria-label="Wachtrijstatus">
      {facts.map((fact) => (
        <div className={`${styles.statusCount} ${styles[`statusCount-${fact.tone}`]}`} key={fact.label}>
          <dt>{fact.label}</dt>
          <dd>{fact.value}</dd>
        </div>
      ))}
    </dl>
  );
}

function QueueWorkItem({
  item,
  generatedAt,
  canManage,
  busy,
  onAction,
}: {
  item: QueueMonitorItem;
  generatedAt: string;
  canManage: boolean;
  busy: boolean;
  onAction: (item: QueueMonitorItem, action: QueueMonitorAction) => Promise<void>;
}) {
  const progress = boundedQueueProgress(item.progress_percent);
  const tone = queueStateTone(item.state);
  const action = canManage ? queueActionForItem(item) : null;
  const actionLabel = action === 'retry' ? 'Opnieuw starten' : 'Nu starten';

  return (
    <li>
      <article className={`${styles.workItem} ${styles[`workItem-${tone}`]}`}>
        <div className={styles.identity}>
          <h3>{item.label}</h3>
          <QueueItemMeta item={item} generatedAt={generatedAt} />
        </div>

        {item.state === 'processing' && progress !== null ? (
          <div className={styles.progress}>
            <progress max={100} value={progress} aria-label={`Voortgang ${item.label}`}>
              {progress}%
            </progress>
            <span>{progress}%</span>
          </div>
        ) : null}

        <div className={styles.itemControls}>
          <span className={`${styles.state} ${styles[`state-${tone}`]}`}>
            {queueStateLabel(item.state)}
          </span>
          {action ? (
            <button
              className={styles.taskAction}
              type="button"
              disabled={busy}
              aria-busy={busy}
              aria-label={`${actionLabel}: ${item.label}`}
              onClick={() => void onAction(item, action)}
            >
              {busy ? (
                <LoaderCircle aria-hidden className="spin" size={16} />
              ) : action === 'retry' ? (
                <RotateCcw aria-hidden size={16} />
              ) : (
                <Play aria-hidden size={16} />
              )}
              {actionLabel}
            </button>
          ) : null}
        </div>
      </article>
    </li>
  );
}

function QueueItemMeta({ item, generatedAt }: { item: QueueMonitorItem; generatedAt: string }) {
  const entries: Array<{ label: string; value: React.ReactNode }> = [];

  if (item.state === 'processing') {
    entries.push(
      { label: 'Gestart', value: dateValue(item.started_at) },
      { label: 'Duur', value: formatQueueRuntime(item, generatedAt) },
    );
  } else if (item.state === 'failed') {
    entries.push({ label: 'Mislukt', value: dateValue(item.finished_at) });
  } else {
    entries.push(
      { label: 'Sinds', value: dateValue(item.queued_at) },
      { label: 'Wachttijd', value: formatQueueWait(item, generatedAt) },
    );
  }

  if (typeof item.attempts === 'number' && item.attempts > 0) {
    entries.push({ label: item.attempts === 1 ? 'Poging' : 'Pogingen', value: item.attempts });
  }

  if (item.state === 'failed' && item.error_code) {
    entries.push({ label: 'Fout', value: <code>{item.error_code}</code> });
  }

  return (
    <dl className={styles.itemMeta}>
      {entries.map((entry) => (
        <div key={entry.label}>
          <dt>{entry.label}</dt>
          <dd>{entry.value}</dd>
        </div>
      ))}
    </dl>
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
        className={styles.pageButton}
        type="button"
        disabled={pagination.current_page <= 1}
        onClick={() => onPage(pagination.current_page - 1)}
        aria-label="Vorige pagina"
      >
        <ChevronLeft aria-hidden size={18} />
      </button>
      <span>{pagination.current_page} / {pagination.last_page}</span>
      <button
        className={styles.pageButton}
        type="button"
        disabled={pagination.current_page >= pagination.last_page}
        onClick={() => onPage(pagination.current_page + 1)}
        aria-label="Volgende pagina"
      >
        <ChevronRight aria-hidden size={18} />
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
        if (activeLoad !== null) {
          await activeLoad.catch(() => null);
        }
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
    per_page: positiveInteger(meta.per_page, PAGE_SIZE),
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
    : 'Wachtrij kon niet worden geladen.';
}

function dateValue(value: string | null): React.ReactNode {
  return value
    ? <time dateTime={value}>{formatDateTime(value)}</time>
    : '-';
}
