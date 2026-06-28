import { useMemo, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';
import { formatDateTime } from '../../lib/dateTime';
import type { AuditLogEntry, User } from '../../types/api';

interface AuditFilters {
  userId: string;
  action: string;
  from: string;
  to: string;
}

export function AuditLogPage() {
  const [filters, setFilters] = useState<AuditFilters>({ userId: '', action: '', from: '', to: '' });
  const users = useApiResource<Pick<User, 'id' | 'name' | 'email'>[]>('/admin/audit-users');
  const auditPath = useMemo(() => {
    const params = new URLSearchParams({ per_page: '150' });
    if (filters.userId !== '') {
      params.set('user_id', filters.userId);
    }
    if (filters.action.trim() !== '') {
      params.set('action', filters.action.trim());
    }
    if (filters.from !== '') {
      params.set('from', filters.from);
    }
    if (filters.to !== '') {
      params.set('to', filters.to);
    }

    return `/admin/audit-logs?${params.toString()}`;
  }, [filters]);
  const audit = useApiResource<AuditLogEntry[]>(auditPath);

  return (
    <div className="page-stack">
      <Panel title="Filters">
        <div className="form-grid">
          <label>
            Gebruiker
            <select value={filters.userId} onChange={(event) => setFilters((current) => ({ ...current, userId: event.target.value }))}>
              <option value="">Alle gebruikers</option>
              {users.data?.map((user) => (
                <option key={user.id} value={user.id}>{user.name} ({user.email})</option>
              ))}
            </select>
          </label>
          <label>
            Actie
            <input value={filters.action} placeholder="Bijv. auth.login" onChange={(event) => setFilters((current) => ({ ...current, action: event.target.value }))} />
          </label>
          <label>
            Vanaf
            <input type="date" value={filters.from} onChange={(event) => setFilters((current) => ({ ...current, from: event.target.value }))} />
          </label>
          <label>
            Tot en met
            <input type="date" value={filters.to} onChange={(event) => setFilters((current) => ({ ...current, to: event.target.value }))} />
          </label>
          <div className="form-actions">
            <button className="secondary-button" type="button" onClick={() => setFilters({ userId: '', action: '', from: '', to: '' })}>
              Wissen
            </button>
          </div>
        </div>
      </Panel>

      <Panel
        title="Auditlog"
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
                <th>Actie</th>
                <th>Doel</th>
                <th>IP</th>
                <th>Reden</th>
              </tr>
            </thead>
            <tbody>
              {audit.data?.map((entry) => (
                <tr key={entry.id}>
                  <td>{formatDateTime(entry.created_at)}</td>
                  <td>
                    <strong>{entry.actor?.name ?? 'Systeem'}</strong>
                    <br />
                    <span className="muted-text">{entry.actor?.email ?? ''}</span>
                  </td>
                  <td><code>{entry.action}</code></td>
                  <td>
                    {entry.target_user ? (
                      <>
                        <strong>{entry.target_user.name}</strong>
                        <br />
                        <span className="muted-text">{entry.target_user.email}</span>
                      </>
                    ) : (
                      <>
                        <strong>{entry.target_type}</strong>
                        <br />
                        <span className="muted-text">{shortId(entry.target_id)}</span>
                      </>
                    )}
                  </td>
                  <td>{entry.ip_address ?? '-'}</td>
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

function shortId(value?: string | null): string {
  if (!value) {
    return '-';
  }

  return value.length > 12 ? `${value.slice(0, 12)}...` : value;
}
