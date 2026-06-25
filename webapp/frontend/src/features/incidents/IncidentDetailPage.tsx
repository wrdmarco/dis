import { FormEvent, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { DispatchRequest, Incident, User } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';
import { incidentPayload } from './IncidentsPage';

interface IncidentEditFormState {
  title: string;
  description: string;
  priority: Incident['priority'];
  status: Incident['status'];
  statusReason: string;
  locationLabel: string;
  latitude: string;
  longitude: string;
  coordinatorId: string;
}

export function IncidentDetailPage() {
  const { incidentId } = useParams();
  const { api } = useAuth();
  const incident = useApiResource<Incident>(`/incidents/${incidentId}`, Boolean(incidentId));
  const users = useApiResource<User[]>('/users?per_page=200');
  const [editForm, setEditForm] = useState<IncidentEditFormState | null>(null);
  const [savingIncident, setSavingIncident] = useState(false);
  const [incidentError, setIncidentError] = useState<string | null>(null);
  const [message, setMessage] = useState('');
  const [dispatchError, setDispatchError] = useState<string | null>(null);

  useEffect(() => {
    const currentIncident = incident.data;
    if (currentIncident == null) {
      return;
    }

    setEditForm({
      title: currentIncident.title,
      description: currentIncident.description ?? '',
      priority: currentIncident.priority,
      status: currentIncident.status,
      statusReason: '',
      locationLabel: currentIncident.location_label ?? '',
      latitude: currentIncident.latitude ?? '',
      longitude: currentIncident.longitude ?? '',
      coordinatorId: currentIncident.coordinator?.id ?? '',
    });
  }, [incident.data]);

  const saveIncident = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (editForm === null) {
      return;
    }

    setSavingIncident(true);
    setIncidentError(null);
    try {
      await api.patch(`/incidents/${incidentId}`, {
        ...incidentPayload(editForm),
        status_reason: editForm.statusReason.trim() === '' ? null : editForm.statusReason,
      });
      await incident.reload();
    } catch (err) {
      setIncidentError(err instanceof ApiClientError ? err.message : 'Incident kon niet worden opgeslagen.');
    } finally {
      setSavingIncident(false);
    }
  };

  const createDispatch = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setDispatchError(null);
    try {
      await api.post<DispatchRequest>(`/incidents/${incidentId}/dispatches`, { priority: 'high', message, team_code: 'OCP' });
      setMessage('');
    } catch (err) {
      setDispatchError(err instanceof ApiClientError ? err.message : 'Dispatch kon niet worden aangemaakt.');
      return;
    }

    try {
      await incident.reload();
    } catch (err) {
      setDispatchError(err instanceof ApiClientError ? `Dispatch is aangemaakt, maar herladen faalde: ${err.message}` : 'Dispatch is aangemaakt, maar herladen faalde.');
    }
  };

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => void incident.reload()} />
      <Panel title="Incidentdetail">
        <ResourceState loading={incident.loading} error={incident.error} empty={!incident.data}>
          {incident.data && editForm ? (
            <div className="detail-grid">
              <div>
                <h3>{incident.data.reference}</h3>
                <p>{incident.data.title}</p>
                <div className="actions-row">
                  <StatusPill value={incident.data.priority} tone={incident.data.priority === 'critical' ? 'bad' : 'warn'} />
                  <StatusPill value={incident.data.status} />
                </div>
                <dl>
                  <dt>Locatie</dt>
                  <dd>{incident.data.location_label ?? '-'}</dd>
                  <dt>Coördinator</dt>
                  <dd>{incident.data.coordinator?.name ?? '-'}</dd>
                  <dt>Geopend</dt>
                  <dd>{formatDate(incident.data.opened_at)}</dd>
                  <dt>Gesloten</dt>
                  <dd>{formatDate(incident.data.closed_at)}</dd>
                </dl>
              </div>
              <form className="form-grid" onSubmit={saveIncident}>
                <label className="form-grid__wide">
                  Titel
                  <input value={editForm.title} maxLength={180} onChange={(event) => updateEditForm(setEditForm, 'title', event.target.value)} required />
                </label>
                <label className="form-grid__wide">
                  Omschrijving
                  <textarea value={editForm.description} rows={5} onChange={(event) => updateEditForm(setEditForm, 'description', event.target.value)} />
                </label>
                <label>
                  Prioriteit
                  <select value={editForm.priority} onChange={(event) => updateEditForm(setEditForm, 'priority', event.target.value as Incident['priority'])}>
                    <option value="low">Laag</option>
                    <option value="normal">Normaal</option>
                    <option value="high">Hoog</option>
                    <option value="critical">Kritiek</option>
                  </select>
                </label>
                <label>
                  Status
                  <select value={editForm.status} onChange={(event) => updateEditForm(setEditForm, 'status', event.target.value as Incident['status'])}>
                    <option value="draft">Concept</option>
                    <option value="active">Actief</option>
                    <option value="dispatching">Alarmeren</option>
                    <option value="in_progress">In uitvoering</option>
                    <option value="resolved">Afgerond</option>
                    <option value="cancelled">Geannuleerd</option>
                  </select>
                </label>
                <label className="form-grid__wide">
                  Reden statuswijziging
                  <input value={editForm.statusReason} maxLength={1000} onChange={(event) => updateEditForm(setEditForm, 'statusReason', event.target.value)} />
                </label>
                <label className="form-grid__wide">
                  Locatieomschrijving
                  <input value={editForm.locationLabel} maxLength={255} onChange={(event) => updateEditForm(setEditForm, 'locationLabel', event.target.value)} />
                </label>
                <label>
                  Latitude
                  <input type="number" step="0.0000001" min="-90" max="90" value={editForm.latitude} onChange={(event) => updateEditForm(setEditForm, 'latitude', event.target.value)} />
                </label>
                <label>
                  Longitude
                  <input type="number" step="0.0000001" min="-180" max="180" value={editForm.longitude} onChange={(event) => updateEditForm(setEditForm, 'longitude', event.target.value)} />
                </label>
                <label className="form-grid__wide">
                  Coördinator
                  <select value={editForm.coordinatorId} onChange={(event) => updateEditForm(setEditForm, 'coordinatorId', event.target.value)}>
                    <option value="">Niet toegewezen</option>
                    {users.data?.map((user) => <option key={user.id} value={user.id}>{user.name} - {user.email}</option>)}
                  </select>
                </label>
                {users.error ? <p className="form-error form-grid__wide">Coördinatoren laden mislukt: {users.error}</p> : null}
                {incidentError ? <p className="form-error form-grid__wide">{incidentError}</p> : null}
                <div className="actions-row form-grid__wide">
                  <button className="primary-button" type="submit" disabled={savingIncident}>
                    {savingIncident ? 'Opslaan...' : 'Incident opslaan'}
                  </button>
                </div>
              </form>
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      <Panel title="Dispatch aanmaken">
        <form className="form-grid" onSubmit={createDispatch}>
          <label className="form-grid__wide">
            Dispatchbericht
            <textarea value={message} onChange={(event) => setMessage(event.target.value)} required />
          </label>
          {dispatchError ? <p className="form-error form-grid__wide">{dispatchError}</p> : null}
          <div className="actions-row form-grid__wide">
            <button className="primary-button" type="submit">Dispatch aanmaken</button>
          </div>
        </form>
      </Panel>
    </div>
  );
}

function updateEditForm<K extends keyof IncidentEditFormState>(
  setForm: (updater: (current: IncidentEditFormState | null) => IncidentEditFormState | null) => void,
  key: K,
  value: IncidentEditFormState[K],
) {
  setForm((current) => current === null ? current : { ...current, [key]: value });
}

function formatDate(value?: string | null): string {
  return value ? new Date(value).toLocaleString('nl-NL') : '-';
}
