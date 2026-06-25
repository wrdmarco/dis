import { FormEvent, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { AvailabilityStatus } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

export function StatusPage() {
  const { api } = useAuth();
  const current = useApiResource<AvailabilityStatus>('/status/me');
  const history = useApiResource<AvailabilityStatus[]>('/status/history');
  const [status, setStatus] = useState('unavailable');
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    try {
      await api.patch('/status/me', { status });
      await current.reload();
      await history.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Status kon niet worden bijgewerkt.');
    }
  };

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => { void current.reload(); void history.reload(); }} />
      <Panel title="Eigen status">
        <form className="inline-form" onSubmit={submit}>
          <select value={status} onChange={(event) => setStatus(event.target.value)}>
            <option value="available">Beschikbaar</option>
            <option value="unavailable">Niet beschikbaar</option>
            <option value="assigned">Toegewezen</option>
            <option value="en_route">Onderweg</option>
            <option value="on_scene">Op locatie</option>
            <option value="resting">Rust</option>
          </select>
          <button className="primary-button" type="submit">Bijwerken</button>
        </form>
        {error && <p className="form-error">{error}</p>}
        {current.data && <p>Huidig: <StatusPill value={current.data.status} tone={current.data.is_available ? 'good' : 'neutral'} /></p>}
      </Panel>
      <Panel title="Statushistorie">
        <ResourceState loading={history.loading} error={history.error} empty={(history.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Status</th><th>Beschikbaar</th><th>Tijd</th></tr></thead>
            <tbody>
              {history.data?.map((item) => (
                <tr key={item.id}>
                  <td><StatusPill value={item.status} /></td>
                  <td>{item.is_available ? 'Ja' : 'Nee'}</td>
                  <td>{formatDateTime(item.effective_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}
