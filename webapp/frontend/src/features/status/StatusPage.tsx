import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { AvailabilityStatus } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

export function StatusPage() {
  const statuses = useApiResource<AvailabilityStatus[]>('/status/users?per_page=200');
  const items = statuses.data ?? [];
  const availableCount = items.filter((item) => item.is_available).length;
  const unavailableCount = items.filter((item) => !item.is_available).length;
  const enRouteCount = items.filter((item) => item.status === 'en_route').length;
  const onSceneCount = items.filter((item) => item.status === 'on_scene').length;

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => void statuses.silentReload()} />
      <Panel title="Gebruikersstatussen">
        <ResourceState loading={statuses.loading} error={statuses.error} empty={items.length === 0}>
          <div className="status-overview">
            <div className="summary-grid">
              <SummaryItem label="Gebruikers" value={String(items.length)} />
              <SummaryItem label="Beschikbaar" value={String(availableCount)} />
              <SummaryItem label="Niet beschikbaar" value={String(unavailableCount)} />
              <SummaryItem label="Onderweg" value={String(enRouteCount)} />
              <SummaryItem label="Op locatie" value={String(onSceneCount)} />
            </div>
            <table className="data-table">
              <thead>
                <tr>
                  <th>Gebruiker</th>
                  <th>E-mail</th>
                  <th>Status</th>
                  <th>Beschikbaar</th>
                  <th>Laatst gewijzigd</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.id}>
                    <td>{item.user?.name ?? item.user_id}</td>
                    <td>{item.user?.email ?? '-'}</td>
                    <td><StatusPill value={item.status} tone={statusTone(item)} /></td>
                    <td>{item.is_available ? 'Ja' : 'Nee'} </td>
                    <td>{formatDateTime(item.effective_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </ResourceState>
      </Panel>
    </div>
  );
}

function SummaryItem({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function statusTone(item: AvailabilityStatus): 'neutral' | 'good' | 'warn' | 'bad' {
  if (item.status === 'en_route' || item.status === 'on_scene') {
    return 'good';
  }

  if (item.is_available) {
    return 'good';
  }

  if (item.status === 'unavailable' || item.status === 'suspended') {
    return 'bad';
  }

  return 'neutral';
}
