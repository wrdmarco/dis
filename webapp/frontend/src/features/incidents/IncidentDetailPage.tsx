import { FormEvent, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { DispatchRequest, Incident } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

export function IncidentDetailPage() {
  const { incidentId } = useParams();
  const { api } = useAuth();
  const incident = useApiResource<Incident>(`/incidents/${incidentId}`, Boolean(incidentId));
  const [message, setMessage] = useState('');
  const [dispatchError, setDispatchError] = useState<string | null>(null);

  const createDispatch = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setDispatchError(null);
    try {
      await api.post<DispatchRequest>(`/incidents/${incidentId}/dispatches`, { priority: 'high', message, team_code: 'OCP' });
      setMessage('');
      await incident.reload();
    } catch (err) {
      setDispatchError(err instanceof ApiClientError ? err.message : 'Dispatch kon niet worden aangemaakt.');
    }
  };

  return (
    <Panel title="Incidentdetail">
      <RealtimeBridge onOperationalEvent={() => void incident.reload()} />
      <ResourceState loading={incident.loading} error={incident.error} empty={!incident.data}>
        {incident.data && (
          <div className="detail-grid">
            <div>
              <h3>{incident.data.reference}</h3>
              <p>{incident.data.title}</p>
              <StatusPill value={incident.data.status} />
            </div>
            <form className="form" onSubmit={createDispatch}>
              <label>
                Dispatchbericht
                <textarea value={message} onChange={(event) => setMessage(event.target.value)} required />
              </label>
              {dispatchError && <p className="form-error">{dispatchError}</p>}
              <button className="primary-button" type="submit">Dispatch aanmaken</button>
            </form>
          </div>
        )}
      </ResourceState>
    </Panel>
  );
}
