import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';

interface Health {
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

export function SystemPage() {
  const health = useApiResource<Health>('/admin/health');
  const queues = useApiResource<Record<string, unknown>>('/admin/queues');
  const websocket = useApiResource<Record<string, unknown>>('/admin/websocket-status');

  return (
    <div className="page-stack">
      <Panel title="Health">
        <ResourceState loading={health.loading} error={health.error} empty={!health.data}>
          <dl className="definition-grid">
            <dt>Database</dt><dd>{healthLabel(health.data?.database?.status)} ({health.data?.database?.connection ?? '-'})</dd>
            <dt>Cache</dt><dd>{healthLabel(health.data?.cache?.status)} ({health.data?.cache?.store ?? '-'})</dd>
            <dt>Storage</dt><dd>{healthLabel(health.data?.storage?.status)}</dd>
            <dt>Queue</dt><dd>{health.data?.queue}</dd>
            <dt>Broadcast</dt><dd>{health.data?.websocket?.driver ?? '-'}</dd>
            <dt>Firebase project</dt><dd>{booleanLabel(health.data?.fcm?.project_configured)}</dd>
            <dt>Firebase service account</dt><dd>{booleanLabel(health.data?.fcm?.service_account_configured)}</dd>
            <dt>Timestamp</dt><dd>{health.data?.timestamp}</dd>
          </dl>
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

function healthLabel(value?: string): string {
  if (value === 'ok') {
    return 'OK';
  }

  return value ?? '-';
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
