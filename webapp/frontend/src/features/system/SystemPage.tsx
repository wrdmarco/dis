import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';

interface Health {
  database: string;
  cache: string;
  queue: string;
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
            <dt>Database</dt><dd>{health.data?.database}</dd>
            <dt>Cache</dt><dd>{health.data?.cache}</dd>
            <dt>Queue</dt><dd>{health.data?.queue}</dd>
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

