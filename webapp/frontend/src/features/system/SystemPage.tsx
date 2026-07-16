import { Cpu, HardDrive, MemoryStick, Radio } from 'lucide-react';
import { type ReactNode, useEffect, useId, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { SystemMetrics } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  appendSystemMetricSample,
  formatSystemBytes,
  formatSystemLoad,
  formatSystemPercent,
  systemMetricChartPaths,
  type SystemMetricHistorySample,
} from './systemMetricsPresentation';
import { startSystemMetricsPolling } from './systemMetricsPolling';

interface Health {
  status?: string;
  generated_at?: string;
  services?: Record<string, ServiceStatus>;
  database?: {
    status?: string;
    connection?: string;
  };
  cache?: {
    status?: string;
    store?: string;
  };
  storage?: {
    status?: string;
  };
  queue: string;
  websocket?: {
    driver?: string;
    host?: string | null;
    port?: string | number | null;
  };
  fcm?: {
    project_configured?: boolean;
    service_account_configured?: boolean;
  };
  timestamp: string;
}

interface ServiceStatus {
  status?: string;
  uptime_seconds?: number | null;
  driver?: string | null;
  connection?: string;
  store?: string;
}

interface LiveSystemMetricsState {
  data: SystemMetrics | null;
  history: SystemMetricHistorySample[];
  loading: boolean;
  error: string | null;
}

export function SystemPage() {
  const health = useApiResource<Health>('/admin/health');
  const queues = useApiResource<Record<string, unknown>>('/admin/queues');
  const websocket = useApiResource<Record<string, unknown>>('/admin/websocket-status');
  const metrics = useLiveSystemMetrics();
  const services = health.data?.services ?? {};
  const uptimeSeconds = metrics.data?.uptime_seconds ?? services.backend?.uptime_seconds;

  return (
    <div className="page-stack system-page">
      <Panel title="Systeemstatus">
        <ResourceState loading={health.loading} error={health.error} empty={!health.data}>
          <>
            <div className="summary-grid">
              <SummaryCard label="Algemene status" value={healthLabel(health.data?.status)} />
              <SummaryCard label="Uptime server" value={formatUptime(uptimeSeconds)} />
              <SummaryCard label="Laatste controle" value={formatDateTime(health.data?.generated_at ?? health.data?.timestamp)} />
            </div>
            <div className="summary-grid">
              {Object.entries(services).map(([name, service]) => (
                <SummaryCard
                  key={name}
                  label={serviceLabel(name)}
                  value={healthLabel(service.status)}
                  note={serviceNote(service)}
                />
              ))}
            </div>
            <dl className="definition-grid">
              <dt>Firebase project</dt><dd>{booleanLabel(health.data?.fcm?.project_configured)}</dd>
              <dt>Firebase service account</dt><dd>{booleanLabel(health.data?.fcm?.service_account_configured)}</dd>
              <dt>Queue driver</dt><dd>{health.data?.queue}</dd>
              <dt>Broadcast driver</dt><dd>{health.data?.websocket?.driver ?? '-'}</dd>
            </dl>
          </>
        </ResourceState>
      </Panel>

      <Panel
        title="Live servercapaciteit"
        action={<LiveMetricStatus data={metrics.data} error={metrics.error} />}
      >
        <ResourceState loading={metrics.loading} error={metrics.data === null ? metrics.error : null} empty={metrics.data === null}>
          {metrics.data ? (
            <div className="system-metrics">
              {metrics.error ? (
                <p className="system-metrics__warning" role="status">
                  Nieuwe meting tijdelijk niet beschikbaar. De laatste geldige waarden blijven zichtbaar.
                </p>
              ) : null}

              <div className="system-metrics__charts">
                <SystemMetricChart
                  label="CPU"
                  icon={<Cpu aria-hidden size={20} />}
                  value={metrics.data.cpu.usage_percent}
                  values={metrics.history.map((sample) => sample.cpuUsagePercent)}
                  detail={`${processorLabel(metrics.data.cpu.logical_processors)} · load 1 min. ${formatSystemLoad(metrics.data.cpu.load_average_1m)}`}
                  tone="cpu"
                />
                <SystemMetricChart
                  label="Geheugen"
                  icon={<MemoryStick aria-hidden size={20} />}
                  value={metrics.data.memory.usage_percent}
                  values={metrics.history.map((sample) => sample.memoryUsagePercent)}
                  detail={`${formatSystemBytes(metrics.data.memory.used_bytes)} van ${formatSystemBytes(metrics.data.memory.total_bytes)} in gebruik`}
                  tone="memory"
                />
              </div>

              <DiskUsage metrics={metrics.data} />

              <p className="system-metrics__updated">
                Laatste geldige meting: <time dateTime={metrics.data.generated_at}>{formatDateTime(metrics.data.generated_at)}</time>
              </p>
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      <div className="two-column">
        <Panel title="Queues">
          <ResourceState loading={queues.loading} error={queues.error} empty={!queues.data}>
            <pre>{JSON.stringify(queues.data, null, 2)}</pre>
          </ResourceState>
        </Panel>
        <Panel title="WebSocket">
          <ResourceState loading={websocket.loading} error={websocket.error} empty={!websocket.data}>
            <pre>{JSON.stringify(websocket.data, null, 2)}</pre>
          </ResourceState>
        </Panel>
      </div>
    </div>
  );
}

function useLiveSystemMetrics(): LiveSystemMetricsState {
  const { api } = useAuth();
  const [data, setData] = useState<SystemMetrics | null>(null);
  const [history, setHistory] = useState<SystemMetricHistorySample[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    const loadMetrics = async () => {
      const response = await api.get<SystemMetrics>('/admin/system/metrics');
      if (!active) {
        return;
      }

      setData(response.data);
      setHistory((current) => appendSystemMetricSample(current, response.data));
      setError(null);
    };

    const stopPolling = startSystemMetricsPolling({
      load: loadMetrics,
      isHidden: () => document.hidden,
      schedule: (callback, delayMs) => window.setTimeout(callback, delayMs),
      cancel: (handle) => window.clearTimeout(handle),
      subscribeVisibility: (listener) => {
        document.addEventListener('visibilitychange', listener);
        return () => document.removeEventListener('visibilitychange', listener);
      },
      onError: (loadError) => {
        if (active) {
          setError(loadError instanceof ApiClientError ? loadError.message : 'Live systeemmetingen konden niet worden geladen.');
        }
      },
      onSettled: () => {
        if (active) {
          setLoading(false);
        }
      },
    });

    return () => {
      active = false;
      stopPolling();
    };
  }, [api]);

  return { data, history, loading, error };
}

function LiveMetricStatus({ data, error }: { data: SystemMetrics | null; error: string | null }) {
  const state = error ? 'stale' : data === null ? 'connecting' : 'live';
  const label = state === 'stale' ? 'Verbinding herstellen' : state === 'connecting' ? 'Verbinden' : 'Live · elke 3 sec.';

  return (
    <span className={`system-live-status system-live-status--${state}`} role="status">
      <Radio aria-hidden size={15} />
      {label}
    </span>
  );
}

function SystemMetricChart({
  label,
  icon,
  value,
  values,
  detail,
  tone,
}: {
  label: string;
  icon: ReactNode;
  value: number | null;
  values: Array<number | null>;
  detail: string;
  tone: 'cpu' | 'memory';
}) {
  const titleId = useId();
  const descriptionId = useId();
  const paths = systemMetricChartPaths(values);
  const normalizedLatest = typeof value === 'number' && Number.isFinite(value)
    ? Math.min(100, Math.max(0, value))
    : null;
  const latestY = normalizedLatest === null
    ? null
    : Math.min(89, Math.max(3, 92 - ((normalizedLatest / 100) * 92)));

  return (
    <article className={`system-metric-card system-metric-card--${tone}`}>
      <header className="system-metric-card__header">
        <span className="system-metric-card__label">{icon}{label}</span>
        <strong>{formatSystemPercent(value)}</strong>
      </header>
      <div className="system-metric-chart">
        <svg viewBox="0 0 300 92" preserveAspectRatio="none" role="img" aria-labelledby={`${titleId} ${descriptionId}`}>
          <title id={titleId}>{label}-gebruik over de laatste drie minuten</title>
          <desc id={descriptionId}>Huidige waarde {formatSystemPercent(value)}. {detail}.</desc>
          <path className="system-metric-chart__grid" d="M0 0 H300 M0 23 H300 M0 46 H300 M0 69 H300 M0 92 H300" />
          {paths.map((path, index) => (
            <path className="system-metric-chart__line" d={path} key={`${path}-${index}`} vectorEffect="non-scaling-stroke" />
          ))}
          {latestY !== null ? (
            <circle className="system-metric-chart__point" cx="297" cy={latestY} r="3" vectorEffect="non-scaling-stroke" />
          ) : null}
        </svg>
        {paths.length === 0 ? <span className="system-metric-chart__empty">Wachten op een geldige meting</span> : null}
      </div>
      <footer>
        <span>{detail}</span>
        <small>Laatste 3 min.</small>
      </footer>
    </article>
  );
}

function DiskUsage({ metrics }: { metrics: SystemMetrics }) {
  const usage = typeof metrics.disk.usage_percent === 'number' && Number.isFinite(metrics.disk.usage_percent)
    ? Math.min(100, Math.max(0, metrics.disk.usage_percent))
    : null;

  return (
    <article className="system-storage-card">
      <header>
        <span className="system-storage-card__label"><HardDrive aria-hidden size={20} /> Opslagruimte</span>
        <div>
          <strong>{formatSystemPercent(usage)}</strong>
          <small>gebruikt</small>
        </div>
      </header>
      <p>{metrics.disk.label}</p>
      {usage === null ? (
        <div className="system-storage-card__unknown">Opslaggebruik niet beschikbaar</div>
      ) : (
        <progress aria-label={`${metrics.disk.label}: gebruikte opslagruimte`} max={100} value={usage}>
          {formatSystemPercent(usage)}
        </progress>
      )}
      <dl>
        <div><dt>Gebruikt</dt><dd>{formatSystemBytes(metrics.disk.used_bytes)}</dd></div>
        <div><dt>Beschikbaar</dt><dd>{formatSystemBytes(metrics.disk.available_bytes)}</dd></div>
        <div><dt>Totaal</dt><dd>{formatSystemBytes(metrics.disk.total_bytes)}</dd></div>
      </dl>
    </article>
  );
}

function SummaryCard({ label, value, note }: { label: string; value: string; note?: string }) {
  return (
    <article className="summary-card">
      <span>{label}</span>
      <strong>{value}</strong>
      {note ? <small>{note}</small> : null}
    </article>
  );
}

function healthLabel(value?: string): string {
  if (value === 'ok') {
    return 'OK';
  }

  return value ?? '-';
}

function serviceLabel(value: string): string {
  const labels: Record<string, string> = {
    backend: 'Backend',
    database: 'Database',
    cache: 'Cache',
    storage: 'Opslag',
    queue: 'Queue',
    websocket: 'WebSocket',
  };

  return labels[value] ?? value;
}

function serviceNote(service: ServiceStatus): string | undefined {
  if (typeof service.uptime_seconds === 'number') {
    return formatUptime(service.uptime_seconds);
  }

  if (service.connection) {
    return service.connection;
  }

  if (service.store) {
    return service.store;
  }

  if (service.driver) {
    return service.driver;
  }

  return undefined;
}

function formatUptime(seconds?: number | null): string {
  if (typeof seconds !== 'number' || !Number.isFinite(seconds) || seconds < 0) {
    return '-';
  }

  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);

  if (days > 0) {
    return `${days}d ${hours}u`;
  }

  if (hours > 0) {
    return `${hours}u ${minutes}m`;
  }

  return `${minutes}m`;
}

function booleanLabel(value?: boolean): string {
  if (value === true) {
    return 'Ingesteld';
  }
  if (value === false) {
    return 'Niet ingesteld';
  }

  return '-';
}

function processorLabel(value: number | null): string {
  if (typeof value !== 'number' || !Number.isInteger(value) || value < 1) {
    return 'Aantal processors onbekend';
  }

  return `${value} ${value === 1 ? 'logische processor' : 'logische processors'}`;
}
