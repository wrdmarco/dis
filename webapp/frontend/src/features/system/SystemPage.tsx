import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';

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

export function SystemPage() {
  const health = useApiResource<Health>('/admin/health');
  const queues = useApiResource<Record<string, unknown>>('/admin/queues');
  const websocket = useApiResource<Record<string, unknown>>('/admin/websocket-status');
  const services = health.data?.services ?? {};

  return (
    <div className="page-stack">
      <Panel title="Systeemstatus">
        <ResourceState loading={health.loading} error={health.error} empty={!health.data}>
          <>
            <div className="summary-grid">
              <SummaryCard label="Algemene status" value={healthLabel(health.data?.status)} />
              <SummaryCard label="Uptime server" value={formatUptime(services.backend?.uptime_seconds)} />
              <SummaryCard label="Laatste controle" value={health.data?.generated_at ?? health.data?.timestamp ?? '-'} />
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
