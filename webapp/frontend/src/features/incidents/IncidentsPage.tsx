import { FormEvent, useState, type ReactNode } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Plus, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Incident, Team, User } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

export interface IncidentFormState {
  title: string;
  description: string;
  priority: Incident['priority'];
  status: Incident['status'];
  locationLabel: string;
  latitude: string;
  longitude: string;
  coordinatorId: string;
  teamId: string;
}

const emptyIncidentForm: IncidentFormState = {
  title: '',
  description: '',
  priority: 'normal',
  status: 'draft',
  locationLabel: '',
  latitude: '',
  longitude: '',
  coordinatorId: '',
  teamId: '',
};

export function IncidentsPage() {
  const { api } = useAuth();
  const navigate = useNavigate();
  const incidents = useApiResource<Incident[]>('/incidents');
  const users = useApiResource<User[]>('/users?per_page=200');
  const teams = useApiResource<Team[]>('/teams');
  const [form, setForm] = useState<IncidentFormState>(emptyIncidentForm);
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const createIncident = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setCreating(true);
    setError(null);
    try {
      const response = await api.post<Incident>('/incidents', incidentPayload(form));
      setForm(emptyIncidentForm);
      setCreateModalOpen(false);
      await incidents.reload();
      navigate(`/incidents/${response.data.id}`);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Incident kon niet worden aangemaakt.');
    } finally {
      setCreating(false);
    }
  };

  function openCreateModal() {
    setForm(emptyIncidentForm);
    setError(null);
    setCreateModalOpen(true);
  }

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => void incidents.reload()} />
      <Panel
        title="Incidenten"
        action={(
          <button className="primary-button" type="button" onClick={openCreateModal}>
            <Plus size={16} /> Incident aanmaken
          </button>
        )}
      >
        <ResourceState loading={incidents.loading} error={incidents.error} empty={(incidents.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Referentie</th>
                <th>Titel</th>
                <th>Prioriteit</th>
                <th>Status</th>
                <th>Locatie</th>
                <th>Team</th>
                <th>Coordinator</th>
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
                  <td>{incident.team?.code ?? '-'}</td>
                  <td>{incident.coordinator?.name ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>

      {createModalOpen ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="incident-create-title">
            <header className="modal__header">
              <h2 id="incident-create-title">Incident aanmaken</h2>
              <button className="icon-button" type="button" onClick={() => setCreateModalOpen(false)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <IncidentForm
              form={form}
              users={users.data ?? []}
              teams={teams.data ?? []}
              usersError={users.error}
              teamsError={teams.error}
              saving={creating}
              error={error}
              submitLabel="Incident aanmaken"
              onCancel={() => setCreateModalOpen(false)}
              onSubmit={createIncident}
              onChange={setForm}
            />
          </section>
        </div>
      ) : null}
    </div>
  );
}

export function IncidentForm(props: {
  form: IncidentFormState;
  users: User[];
  teams: Team[];
  usersError?: string | null;
  teamsError?: string | null;
  saving: boolean;
  error?: string | null;
  extraFields?: ReactNode;
  submitLabel: string;
  onCancel: () => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void;
}) {
  const { form, users, teams, usersError, teamsError, saving, error, extraFields, submitLabel, onCancel, onSubmit, onChange } = props;

  return (
    <form className="form-grid" onSubmit={onSubmit}>
      <label className="form-grid__wide">
        Titel
        <input value={form.title} maxLength={180} onChange={(event) => updateForm(onChange, 'title', event.target.value)} required />
      </label>
      <label className="form-grid__wide">
        Omschrijving
        <textarea value={form.description} rows={5} onChange={(event) => updateForm(onChange, 'description', event.target.value)} />
      </label>
      <label>
        Prioriteit
        <select value={form.priority} onChange={(event) => updateForm(onChange, 'priority', event.target.value as Incident['priority'])}>
          <option value="low">Laag</option>
          <option value="normal">Normaal</option>
          <option value="high">Hoog</option>
          <option value="critical">Kritiek</option>
        </select>
      </label>
      <label>
        Status
        <select value={form.status} onChange={(event) => updateForm(onChange, 'status', event.target.value as Incident['status'])}>
          <option value="draft">Concept</option>
          <option value="active">Actief</option>
          <option value="dispatching">Alarmeren</option>
          <option value="in_progress">In uitvoering</option>
          <option value="resolved">Afgerond</option>
          <option value="cancelled">Geannuleerd</option>
        </select>
      </label>
      <label className="form-grid__wide">
        Team
        <select value={form.teamId} onChange={(event) => updateForm(onChange, 'teamId', event.target.value)}>
          <option value="">Geen team geselecteerd</option>
          {teams.map((team) => <option key={team.id} value={team.id}>{team.code} - {team.name}</option>)}
        </select>
      </label>
      <label className="form-grid__wide">
        Locatieomschrijving
        <input value={form.locationLabel} maxLength={255} placeholder="Adres, gebied of rendez-vous punt" onChange={(event) => updateForm(onChange, 'locationLabel', event.target.value)} onBlur={() => void geocodeAddress(form, onChange)} />
      </label>
      <label>
        Latitude
        <input type="number" step="0.0000001" min="-90" max="90" value={form.latitude} onChange={(event) => updateForm(onChange, 'latitude', event.target.value)} />
      </label>
      <label>
        Longitude
        <input type="number" step="0.0000001" min="-180" max="180" value={form.longitude} onChange={(event) => updateForm(onChange, 'longitude', event.target.value)} />
      </label>
      <label className="form-grid__wide">
        Coordinator
        <select value={form.coordinatorId} onChange={(event) => updateForm(onChange, 'coordinatorId', event.target.value)}>
          <option value="">Niet toegewezen</option>
          {users.map((user) => <option key={user.id} value={user.id}>{user.name} - {user.email}</option>)}
        </select>
      </label>
      {teamsError ? <p className="form-error form-grid__wide">Teams laden mislukt: {teamsError}</p> : null}
      {usersError ? <p className="form-error form-grid__wide">Coordinators laden mislukt: {usersError}</p> : null}
      {extraFields}
      {error ? <p className="form-error form-grid__wide">{error}</p> : null}
      <div className="actions-row form-grid__wide">
        <button className="secondary-button" type="button" onClick={onCancel}>Annuleren</button>
        <button className="primary-button" type="submit" disabled={saving}>
          {saving ? 'Opslaan...' : submitLabel}
        </button>
      </div>
    </form>
  );
}

async function geocodeAddress(form: IncidentFormState, onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void): Promise<void> {
  const query = form.locationLabel.trim();
  if (query.length < 6) {
    return;
  }

  try {
    const params = new URLSearchParams({ q: query, rows: '1' });
    const response = await fetch(`https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?${params.toString()}`, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
      return;
    }

    const payload = await response.json() as { response?: { docs?: Array<{ centroide_ll?: string; weergavenaam?: string }> } };
    const match = payload.response?.docs?.[0];
    const point = match?.centroide_ll?.match(/^POINT\(([-0-9.]+) ([-0-9.]+)\)$/);
    if (!point) {
      return;
    }

    const [, longitude, latitude] = point;
    onChange((current) => ({
      ...current,
      latitude,
      longitude,
      locationLabel: match?.weergavenaam ?? current.locationLabel,
    }));
  } catch {
    // Manual coordinates remain available when the geocoder cannot be reached.
  }
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
    team_id: form.teamId === '' ? null : form.teamId,
  };
}

function updateForm<K extends keyof IncidentFormState>(
  setForm: (updater: (current: IncidentFormState) => IncidentFormState) => void,
  key: K,
  value: IncidentFormState[K],
) {
  setForm((current) => ({ ...current, [key]: value }));
}
