import { FormEvent, useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Incident } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

export function IncidentsPage() {
  const { api } = useAuth();
  const incidents = useApiResource<Incident[]>('/incidents');
  const [title, setTitle] = useState('');
  const [priority, setPriority] = useState('normal');
  const [error, setError] = useState<string | null>(null);

  const createIncident = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    try {
      await api.post('/incidents', { title, priority, status: 'active' });
      setTitle('');
      await incidents.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Incident kon niet worden aangemaakt.');
    }
  };

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => void incidents.reload()} />
      <Panel title="Incident aanmaken">
        <form className="inline-form" onSubmit={createIncident}>
          <input value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Incidenttitel" required />
          <select value={priority} onChange={(event) => setPriority(event.target.value)}>
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="critical">Critical</option>
          </select>
          <button className="primary-button" type="submit"><Plus size={16} /> Aanmaken</button>
        </form>
        {error && <p className="form-error">{error}</p>}
      </Panel>
      <Panel title="Incidenten">
        <ResourceState loading={incidents.loading} error={incidents.error} empty={(incidents.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Referentie</th>
                <th>Titel</th>
                <th>Prioriteit</th>
                <th>Status</th>
                <th>Locatie</th>
              </tr>
            </thead>
            <tbody>
              {incidents.data?.map((incident) => (
                <tr key={incident.id}>
                  <td><Link to={`/incidents/${incident.id}`}>{incident.reference}</Link></td>
                  <td>{incident.title}</td>
                  <td><StatusPill value={incident.priority} tone={incident.priority === 'critical' ? 'bad' : 'warn'} /></td>
                  <td><StatusPill value={incident.status} /></td>
                  <td>{incident.location_label ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}
