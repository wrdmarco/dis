import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { useApiResource } from '../../lib/useApiResource';
import type { StatusAuditEntry } from '../../types/api';

export function StatusAuditPage() {
  const audit = useApiResource<StatusAuditEntry[]>('/status/audit');

  return (
    <div className="page-stack">
      <Panel
        title="Status audit"
        action={(
          <button className="secondary-button" type="button" onClick={() => void audit.reload()}>
            Vernieuwen
          </button>
        )}
      >
        <ResourceState loading={audit.loading} error={audit.error} empty={(audit.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Tijd</th>
                <th>Gebruiker</th>
                <th>Wijziging</th>
                <th>Door</th>
                <th>Reden</th>
              </tr>
            </thead>
            <tbody>
              {audit.data?.map((entry) => (
                <tr key={entry.id}>
                  <td>{formatDateTime(entry.created_at)}</td>
                  <td>
                    <strong>{entry.user?.name ?? '-'}</strong>
                    <br />
                    <span className="muted-text">{entry.user?.email ?? ''}</span>
                  </td>
                  <td>
                    <div className="table-actions">
                      <StatusPill value={statusLabel(entry.from_status)} tone="neutral" />
                      <span>naar</span>
                      <StatusPill value={statusLabel(entry.to_status)} tone={entry.to_status === 'available' ? 'good' : 'neutral'} />
                    </div>
                  </td>
                  <td>{entry.is_system_applied ? 'Systeem' : entry.actor?.name ?? '-'}</td>
                  <td>{entry.reason ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}

function statusLabel(status?: string | null): string {
  switch (status) {
    case 'available':
      return 'Beschikbaar';
    case 'unavailable':
      return 'Niet beschikbaar';
    case 'busy':
      return 'Bezet';
    case 'en_route':
      return 'Onderweg';
    case 'vacation':
      return 'Vakantie';
    case null:
    case undefined:
    case '':
      return '-';
    default:
      return status;
  }
}

function formatDateTime(value?: string | null): string {
  if (!value) {
    return '-';
  }

  return new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date(value));
}
