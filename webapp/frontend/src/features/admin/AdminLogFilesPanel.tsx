import { FileText, Pause, Play, Radio, RefreshCw } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type {
  AdminSystemLogChunk,
  AdminSystemLogFile,
  AdminSystemLogIndex,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  adminSystemLogChunkRequiresReset,
  adminSystemLogPath,
  adminSystemLogShouldFollow,
  formatAdminSystemLogBytes,
  mergeAdminSystemLogLines,
  normalizeAdminSystemLogPollInterval,
  startAdminSystemLogPolling,
} from './adminSystemLogViewer';
import styles from './AdminLogFilesPanel.module.css';

const EMPTY_LOG_FILES: AdminSystemLogFile[] = [];
const DUTCH_INTEGER = new Intl.NumberFormat('nl-NL', { maximumFractionDigits: 0 });

interface CurrentLogFile {
  name: string;
  sizeBytes: number;
  modifiedAt: string | null;
}

export function AdminLogFilesPanel() {
  const { api } = useAuth();
  const inventory = useApiResource<AdminSystemLogIndex>('/admin/system/logs');
  const mutateInventory = inventory.mutate;
  const reloadInventorySilently = inventory.silentReload;
  const logFiles = inventory.data?.logs ?? EMPTY_LOG_FILES;
  const [selectedName, setSelectedName] = useState('');
  const [followLatestFile, setFollowLatestFile] = useState(true);
  const [currentFile, setCurrentFile] = useState<CurrentLogFile | null>(null);
  const [lines, setLines] = useState<string[]>([]);
  const [paused, setPaused] = useState(false);
  const [autoFollow, setAutoFollow] = useState(true);
  const [loadingChunk, setLoadingChunk] = useState(false);
  const [pollError, setPollError] = useState<string | null>(null);
  const [serverTruncated, setServerTruncated] = useState(false);
  const [clientTruncated, setClientTruncated] = useState(false);
  const [lastReceivedAt, setLastReceivedAt] = useState<string | null>(null);
  const cursorRef = useRef(0);
  const generationRef = useRef('');
  const checkpointRef = useRef('');
  const pollAfterMsRef = useRef(2_000);
  const linesRef = useRef<string[]>([]);
  const logRef = useRef<HTMLPreElement | null>(null);
  const latestSelectionRef = useRef(false);
  const refreshRef = useRef<() => void>(() => undefined);

  useEffect(() => {
    if (logFiles.length === 0) {
      setSelectedName('');
      setFollowLatestFile(true);
      return;
    }

    const latestName = logFiles[0]?.name ?? '';
    const selectionExists = logFiles.some((file) => file.name === selectedName);
    if (selectedName === '' || !selectionExists) {
      setFollowLatestFile(true);
      setSelectedName(latestName);
    } else if (followLatestFile && selectedName !== latestName) {
      setSelectedName(latestName);
    }
  }, [followLatestFile, logFiles, selectedName]);

  useEffect(() => {
    if (latestSelectionRef.current) {
      latestSelectionRef.current = false;
      return;
    }

    cursorRef.current = 0;
    generationRef.current = '';
    checkpointRef.current = '';
    pollAfterMsRef.current = 2_000;
    linesRef.current = [];
    setLines([]);
    setCurrentFile(null);
    setPollError(null);
    setServerTruncated(false);
    setClientTruncated(false);
    setLastReceivedAt(null);
    setAutoFollow(true);
    setLoadingChunk(selectedName !== '');
  }, [selectedName]);

  useEffect(() => {
    if (selectedName === '' || paused) {
      refreshRef.current = () => undefined;
      if (paused) {
        setLoadingChunk(false);
      }
      return undefined;
    }

    let active = true;
    const load = async () => {
      const currentGeneration = generationRef.current;
      const response = await api.get<AdminSystemLogChunk>(
        adminSystemLogPath(
          selectedName,
          cursorRef.current,
          currentGeneration,
          checkpointRef.current,
          followLatestFile,
        ),
      );
      if (!active) {
        return;
      }

      const chunk = response.data;
      if (chunk.name !== selectedName && !followLatestFile) {
        throw new Error('De server retourneerde een ander logbestand dan gevraagd.');
      }
      if (chunk.name !== selectedName) {
        latestSelectionRef.current = true;
        mutateInventory((current) => ({
          logs: [
            {
              name: chunk.name,
              size_bytes: chunk.size_bytes,
              modified_at: chunk.modified_at,
            },
            ...(current?.logs ?? []).filter((file) => file.name !== chunk.name),
          ],
        }));
        setSelectedName(chunk.name);
      }

      const reset = adminSystemLogChunkRequiresReset(
        currentGeneration,
        chunk.generation,
        chunk.reset,
      );
      const merged = mergeAdminSystemLogLines(linesRef.current, chunk.lines, reset);
      if (merged.lines !== linesRef.current) {
        linesRef.current = merged.lines;
        setLines(merged.lines);
      }
      cursorRef.current = chunk.cursor;
      generationRef.current = chunk.generation;
      checkpointRef.current = chunk.checkpoint;
      pollAfterMsRef.current = normalizeAdminSystemLogPollInterval(chunk.poll_after_ms);
      setCurrentFile({
        name: chunk.name,
        sizeBytes: chunk.size_bytes,
        modifiedAt: chunk.modified_at,
      });
      setServerTruncated((current) => reset ? chunk.truncated : current || chunk.truncated);
      setClientTruncated((current) => reset ? merged.truncated : current || merged.truncated);
      setLastReceivedAt(new Date().toISOString());
      setPollError(null);
    };

    const polling = startAdminSystemLogPolling({
      load,
      isHidden: () => document.hidden,
      schedule: (callback, delayMs) => window.setTimeout(callback, delayMs),
      cancel: (handle) => window.clearTimeout(handle),
      subscribeVisibility: (listener) => {
        document.addEventListener('visibilitychange', listener);
        return () => document.removeEventListener('visibilitychange', listener);
      },
      onError: (error) => {
        if (active) {
          setPollError(logPollingError(error));
        }
      },
      onSettled: () => {
        if (active) {
          setLoadingChunk(false);
        }
      },
      intervalMs: () => pollAfterMsRef.current,
    });
    refreshRef.current = polling.refresh;

    return () => {
      active = false;
      refreshRef.current = () => undefined;
      polling.stop();
    };
  }, [api, followLatestFile, mutateInventory, paused, selectedName]);

  useEffect(() => {
    if (!autoFollow) {
      return;
    }

    const node = logRef.current;
    if (node !== null) {
      node.scrollTop = node.scrollHeight;
    }
  }, [autoFollow, lines]);

  const selectedInventoryFile = logFiles.find((file) => file.name === selectedName) ?? null;
  const displayedFile = currentFile ?? (selectedInventoryFile === null ? null : {
    name: selectedInventoryFile.name,
    sizeBytes: selectedInventoryFile.size_bytes,
    modifiedAt: selectedInventoryFile.modified_at,
  });
  const logText = useMemo(
    () => lines.length > 0 ? lines.join('\n') : loadingChunk ? 'Logregels laden…' : 'Nog geen logregels beschikbaar.',
    [lines, loadingChunk],
  );
  const connection = logConnectionState(paused, loadingChunk, pollError, selectedName);

  const refreshNow = () => {
    setLoadingChunk(true);
    void reloadInventorySilently();
    refreshRef.current();
  };

  return (
    <Panel
      title="Systeemlogboeken"
      action={<StatusPill value={connection.label} tone={connection.tone} />}
    >
      <ResourceState
        loading={inventory.loading && inventory.data === null}
        error={inventory.data === null ? inventory.error : null}
        empty={inventory.data !== null && logFiles.length === 0}
      >
        <div className={styles.content}>
          <div className={styles.toolbar}>
            <label className={styles.fileField}>
              <span>Logbestand</span>
              <span className={styles.selectShell}>
                <FileText aria-hidden size={17} />
                <select
                  value={selectedName}
                  onChange={(event) => {
                    const nextName = event.target.value;
                    setPaused(false);
                    setFollowLatestFile(nextName === logFiles[0]?.name);
                    setSelectedName(nextName);
                  }}
                >
                  {logFiles.map((file) => (
                    <option key={file.name} value={file.name}>{file.name}</option>
                  ))}
                </select>
              </span>
            </label>

            <div className={styles.actions}>
              <button
                className="secondary-button"
                type="button"
                aria-pressed={paused}
                disabled={selectedName === ''}
                onClick={() => setPaused((current) => !current)}
              >
                {paused ? <Play aria-hidden size={16} /> : <Pause aria-hidden size={16} />}
                {paused ? 'Hervatten' : 'Pauzeren'}
              </button>
              <button
                className="secondary-button"
                type="button"
                disabled={selectedName === '' || paused}
                aria-label={loadingChunk ? 'Logbestand wordt vernieuwd' : 'Logbestand nu vernieuwen'}
                onClick={refreshNow}
              >
                <RefreshCw aria-hidden className={loadingChunk ? 'spin' : undefined} size={16} />
                Vernieuwen
              </button>
            </div>
          </div>

          <div className={styles.connection}>
            <span className={styles.connectionStatus} role="status" aria-live="polite" aria-atomic="true">
              <Radio aria-hidden size={16} />
              <span>{connection.message}</span>
            </span>
            {lastReceivedAt ? (
              <time aria-live="off" dateTime={lastReceivedAt}>
                Laatste controle {formatDateTime(lastReceivedAt)}
              </time>
            ) : null}
          </div>

          {displayedFile ? (
            <dl className={styles.fileMeta}>
              <div>
                <dt>Bestand</dt>
                <dd>{displayedFile.name}</dd>
              </div>
              <div>
                <dt>Grootte</dt>
                <dd>{formatAdminSystemLogBytes(displayedFile.sizeBytes)}</dd>
              </div>
              <div>
                <dt>Gewijzigd</dt>
                <dd>{formatDateTime(displayedFile.modifiedAt)}</dd>
              </div>
              <div>
                <dt>In beeld</dt>
                <dd>{DUTCH_INTEGER.format(lines.length)} regels</dd>
              </div>
            </dl>
          ) : null}

          {pollError ? <p className={styles.error} role="alert">{pollError}</p> : null}
          {inventory.error && inventory.data !== null ? (
            <p className={styles.error} role="alert">{inventory.error}</p>
          ) : null}
          {serverTruncated || clientTruncated ? (
            <p className={styles.notice} role="status">
              Alleen de meest recente logregels worden getoond.
            </p>
          ) : null}

          <div className={styles.viewer}>
            <div className={styles.viewerHeader}>
              <strong>{selectedName || 'Logbestand'}</strong>
              <label className={styles.followControl}>
                <input
                  type="checkbox"
                  checked={autoFollow}
                  onChange={(event) => setAutoFollow(event.target.checked)}
                />
                Automatisch volgen
              </label>
            </div>
            <pre
              ref={logRef}
              className={styles.log}
              tabIndex={0}
              aria-busy={loadingChunk && lines.length === 0}
              aria-label={selectedName ? `Live logbestand ${selectedName}` : 'Live logbestand'}
              onScroll={(event) => {
                const node = event.currentTarget;
                const shouldFollow = adminSystemLogShouldFollow(
                  node.scrollHeight,
                  node.scrollTop,
                  node.clientHeight,
                );
                setAutoFollow((current) => current === shouldFollow ? current : shouldFollow);
              }}
            >
              {logText}
            </pre>
          </div>
        </div>
      </ResourceState>
    </Panel>
  );
}

function logPollingError(error: unknown): string {
  return error instanceof ApiClientError
    ? error.message
    : error instanceof Error && error.message !== ''
      ? error.message
      : 'Nieuwe logregels konden niet worden geladen. DIS probeert het opnieuw.';
}

function logConnectionState(
  paused: boolean,
  loading: boolean,
  error: string | null,
  selectedName: string,
): {
  label: string;
  message: string;
  tone: 'neutral' | 'good' | 'warn' | 'bad';
} {
  if (selectedName === '') {
    return {
      label: 'Geen bestand',
      message: 'Er is geen logbestand geselecteerd.',
      tone: 'neutral',
    };
  }
  if (paused) {
    return {
      label: 'Gepauzeerd',
      message: 'Live volgen is gepauzeerd.',
      tone: 'warn',
    };
  }
  if (error !== null) {
    return {
      label: 'Herstellen',
      message: 'De verbinding wordt automatisch opnieuw geprobeerd.',
      tone: 'bad',
    };
  }
  if (loading) {
    return {
      label: 'Verbinden',
      message: 'De nieuwste logregels worden opgehaald.',
      tone: 'neutral',
    };
  }

  return {
    label: 'Live',
    message: 'Nieuwe logregels worden automatisch gevolgd.',
    tone: 'good',
  };
}
