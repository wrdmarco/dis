import { FormEvent, useEffect, useState } from 'react';
import { Pencil, X } from 'lucide-react';
import { useParams } from 'react-router-dom';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { DispatchRequest, Incident, Team, User } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';
import { IncidentForm, type IncidentFormState, incidentPayload } from './IncidentsPage';

export function IncidentDetailPage() {
  const { incidentId } = useParams();
  const { api } = useAuth();
  const incident = useApiResource<Incident>(`/incidents/${incidentId}`, Boolean(incidentId));
  const users = useApiResource<User[]>('/users?per_page=200');
  const teams = useApiResource<Team[]>('/teams');
  const [editForm, setEditForm] = useState<IncidentFormState | null>(null);
  const [statusReason, setStatusReason] = useState('');
  const [editModalOpen, setEditModalOpen] = useState(false);
  const [savingIncident, setSavingIncident] = useState(false);
  const [incidentError, setIncidentError] = useState<string | null>(null);
  const [message, setMessage] = useState('');
  const [dispatchTeamId, setDispatchTeamId] = useState('');
  const [dispatchError, setDispatchError] = useState<string | null>(null);

  useEffect(() => {
    const currentIncident = incident.data;
    if (currentIncident == null) {
      return;
    }

    setEditForm(formFromIncident(currentIncident));
    setDispatchTeamId(currentIncident.team?.id ?? '');
    setStatusReason('');
  }, [incident.data]);

  function openEditModal() {
    if (incident.data !== undefined && incident.data !== null) {
      setEditForm(formFromIncident(incident.data));
    }
    setStatusReason('');
    setIncidentError(null);
    setEditModalOpen(true);
  }

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
        status_reason: statusReason.trim() === '' ? null : statusReason,
      });
      setEditModalOpen(false);
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
      await api.post<DispatchRequest>(`/incidents/${incidentId}/dispatches`, {
        priority: 'high',
        message,
        target_team_id: dispatchTeamId === '' ? null : dispatchTeamId,
      });
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
      <Panel
        title="Incidentdetail"
        action={incident.data ? (
          <button className="secondary-button" type="button" onClick={openEditModal}>
            <Pencil size={16} /> Aanpassen
          </button>
        ) : null}
      >
        <ResourceState loading={incident.loading} error={incident.error} empty={!incident.data}>
          {incident.data ? (
            <div className="detail-grid">
              <div>
                <h3>{incident.data.reference}</h3>
                <p>{incident.data.title}</p>
                <div className="actions-row">
                  <StatusPill value={incident.data.priority} tone={incident.data.priority === 'critical' ? 'bad' : 'warn'} />
                  <StatusPill value={incident.data.status} />
                </div>
                <dl>
                  <dt>Omschrijving</dt>
                  <dd>{incident.data.description ?? '-'}</dd>
                  <dt>Locatie</dt>
                  <dd>{incident.data.location_label ?? '-'}</dd>
                  <dt>Team</dt>
                  <dd>{incident.data.team?.code ?? '-'}</dd>
                  <dt>Coordinator</dt>
                  <dd>{incident.data.coordinator?.name ?? '-'}</dd>
                  <dt>Geopend</dt>
                  <dd>{formatDate(incident.data.opened_at)}</dd>
                  <dt>Gesloten</dt>
                  <dd>{formatDate(incident.data.closed_at)}</dd>
                </dl>
              </div>
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      <Panel title="Dispatch aanmaken">
        <form className="form-grid" onSubmit={createDispatch}>
          <label className="form-grid__wide">
            Team
            <select value={dispatchTeamId} onChange={(event) => setDispatchTeamId(event.target.value)}>
              <option value="">Basisteam</option>
              {teams.data?.map((team) => <option key={team.id} value={team.id}>{team.code} - {team.name}</option>)}
            </select>
          </label>
          <label className="form-grid__wide">
            Dispatchbericht
            <textarea value={message} onChange={(event) => setMessage(event.target.value)} required />
          </label>
          {teams.error ? <p className="form-error form-grid__wide">Teams laden mislukt: {teams.error}</p> : null}
          {dispatchError ? <p className="form-error form-grid__wide">{dispatchError}</p> : null}
          <div className="actions-row form-grid__wide">
            <button className="primary-button" type="submit">Dispatch aanmaken</button>
          </div>
        </form>
      </Panel>

      {editModalOpen && editForm !== null ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="incident-edit-title">
            <header className="modal__header">
              <h2 id="incident-edit-title">Incident aanpassen</h2>
              <button className="icon-button" type="button" onClick={() => setEditModalOpen(false)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <IncidentForm
              form={editForm}
              users={users.data ?? []}
              teams={teams.data ?? []}
              usersError={users.error}
              teamsError={teams.error}
              saving={savingIncident}
              error={incidentError}
              extraFields={(
                <label className="form-grid__wide">
                  Reden statuswijziging
                  <input value={statusReason} maxLength={1000} onChange={(event) => setStatusReason(event.target.value)} />
                </label>
              )}
              submitLabel="Incident opslaan"
              onCancel={() => setEditModalOpen(false)}
              onSubmit={saveIncident}
              onChange={(updater) => setEditForm((current) => current === null ? current : updater(current))}
            />
          </section>
        </div>
      ) : null}
    </div>
  );
}

function formFromIncident(incident: Incident): IncidentFormState {
  return {
    title: incident.title,
    description: incident.description ?? '',
    priority: incident.priority,
    status: incident.status,
    locationLabel: incident.location_label ?? '',
    latitude: incident.latitude ?? '',
    longitude: incident.longitude ?? '',
    coordinatorId: incident.coordinator?.id ?? '',
    teamId: incident.team?.id ?? '',
  };
}

function formatDate(value?: string | null): string {
  return value ? new Date(value).toLocaleString('nl-NL') : '-';
}
