import { FormEvent, useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Incident, User } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

interface IncidentFormState {
  title: string;
  description: string;
  priority: Incident['priority'];
  status: Incident['status'];
  locationLabel: string;
  latitude: string;
  longitude: string;
  coordinatorId: string;
}

const emptyIncidentForm: IncidentFormState = {
  title: '',
  description: '',
  priority: 'normal',
  status: 'active',
  locationLabel: '',
  latitude: '',
  longitude: '',
  coordinatorId: '',
};

export function IncidentsPage() {
  const { api } = useAuth();
  const incidents = useApiResource<Incident[]>('/incidents');
  const users = useApiResource<User[]>('/users?per_page=200');
  const [form, setForm] = useState<IncidentFormState>(emptyIncidentForm);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const createIncident = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setCreating(true);
    setError(null);
    try {
      await api.post('/incidents', incidentPayload(form));
      setForm(emptyIncidentForm);
      await incidents.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Incident kon niet worden aangemaakt.');
    } finally {
      setCreating(false);
    }
  };

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => void incidents.reload()} />
      <Panel title="Incident aanmaken">
        <form className="form-grid" onSubmit={createIncident}>
          <label className="form-grid__wide">
            Titel
            <input value={form.title} maxLength={180} onChange={(event) => updateForm(setForm, 'title', event.target.value)} required />
          </label>
          <label className="form-grid__wide">
            Omschrijving
            <textarea value={form.description} rows={5} onChange={(event) => updateForm(setForm, 'description', event.target.value)} />
          </label>
          <label>
            Prioriteit
            <select value={form.priority} onChange={(event) => updateForm(setForm, 'priority', event.target.value as Incident['priority'])}>
              <option value="low">Laag</option>
              <option value="normal">Normaal</option>
              <option value="high">Hoog</option>
              <option value="critical">Kritiek</option>
            </select>
          </label>
          <label>
            Status
            <select value={form.status} onChange={(event) => updateForm(setForm, 'status', event.target.value as Incident['status'])}>
              <option value="draft">Concept</option>
              <option value="active">Actief</option>
              <option value="dispatching">Alarmeren</option>
              <option value="in_progress">In uitvoering</option>
            </select>
          </label>
          <label className="form-grid__wide">
            Locatieomschrijving
            <input value={form.locationLabel} maxLength={255} placeholder="Adres, gebied of rendez-vous punt" onChange={(event) => updateForm(setForm, 'locationLabel', event.target.value)} />
          </label>
          <label>
            Latitude
            <input type="number" step="0.0000001" min="-90" max="90" value={form.latitude} onChange={(event) => updateForm(setForm, 'latitude', event.target.value)} />
          </label>
          <label>
            Longitude
            <input type="number" step="0.0000001" min="-180" max="180" value={form.longitude} onChange={(event) => updateForm(setForm, 'longitude', event.target.value)} />
          </label>
          <label className="form-grid__wide">
            Coördinator
            <select value={form.coordinatorId} onChange={(event) => updateForm(setForm, 'coordinatorId', event.target.value)}>
              <option value="">Niet toegewezen</option>
              {users.data?.map((user) => <option key={user.id} value={user.id}>{user.name} - {user.email}</option>)}
            </select>
          </label>
          {users.error ? <p className="form-error form-grid__wide">Coördinatoren laden mislukt: {users.error}</p> : null}
          {error ? <p className="form-error form-grid__wide">{error}</p> : null}
          <div className="actions-row form-grid__wide">
            <button className="primary-button" type="submit" disabled={creating}>
              <Plus size={16} /> {creating ? 'Aanmaken...' : 'Incident aanmaken'}
            </button>
          </div>
        </form>
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
                <th>Coördinator</th>
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
                  <td>{incident.coordinator?.name ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}

export function incidentPayload(form: IncidentFormState): Record<string, unknown> {
  return {
    title: form.title,
    description: form.description.trim() === '' ? null : form.description,
    priority: form.priority,
    status: form.status,
    location_label: form.locationLabel.trim() === '' ? null : form.locationLabel,
    latitude: form.latitude.trim() === '' ? null : Number(form.latitude),
    longitude: form.longitude.trim() === '' ? null : Number(form.longitude),
    coordinator_id: form.coordinatorId === '' ? null : form.coordinatorId,
  };
}

function updateForm<K extends keyof IncidentFormState>(
  setForm: (updater: (current: IncidentFormState) => IncidentFormState) => void,
  key: K,
  value: IncidentFormState[K],
) {
  setForm((current) => ({ ...current, [key]: value }));
}
