import { FormEvent, useEffect, useState } from 'react';
import { Pencil, Send, X } from 'lucide-react';
import { useParams } from 'react-router-dom';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { DispatchPreview, Incident, IncidentTimelineItem, Team, User } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';
import { IncidentForm, type IncidentFormState, incidentPayload } from './IncidentsPage';

export function IncidentDetailPage() {
  const { incidentId } = useParams();
  const { api } = useAuth();
  const incident = useApiResource<Incident>(`/incidents/${incidentId}`, Boolean(incidentId));
  const preview = useApiResource<DispatchPreview>(`/incidents/${incidentId}/dispatch-preview`, Boolean(incidentId));
  const timeline = useApiResource<IncidentTimelineItem[]>(`/incidents/${incidentId}/timeline`, Boolean(incidentId));
  const users = useApiResource<User[]>('/users?per_page=200');
  const teams = useApiResource<Team[]>('/teams');
  const [editForm, setEditForm] = useState<IncidentFormState | null>(null);
  const [statusReason, setStatusReason] = useState('');
  const [editModalOpen, setEditModalOpen] = useState(false);
  const [savingIncident, setSavingIncident] = useState(false);
  const [incidentError, setIncidentError] = useState<string | null>(null);
  const [dispatching, setDispatching] = useState(false);
  const [dispatchError, setDispatchError] = useState<string | null>(null);

  useEffect(() => {
    const currentIncident = incident.data;
    if (currentIncident == null) {
      return;
    }

    setEditForm(formFromIncident(currentIncident));
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
      await preview.reload();
      await timeline.reload();
    } catch (err) {
      setIncidentError(err instanceof ApiClientError ? err.message : 'Incident kon niet worden opgeslagen.');
    } finally {
      setSavingIncident(false);
    }
  };

  const activateIncident = async () => {
    if (!incidentId) {
      return;
    }

    setDispatchError(null);
    setDispatching(true);
    try {
      await api.patch(`/incidents/${incidentId}`, {
        status: 'active',
        status_reason: 'Melding geactiveerd en alarmering verstuurd.',
      });
      await incident.reload();
      await preview.reload();
      await timeline.reload();
    } catch (err) {
      setDispatchError(err instanceof ApiClientError ? err.message : 'Melding kon niet worden verstuurd.');
    } finally {
      setDispatching(false);
    }
  };

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => {
        void incident.reload();
        void preview.reload();
        void timeline.reload();
      }} />
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

      <Panel
        title="Alarmeringsconcept"
        action={incident.data?.status === 'draft' ? (
          <button className="primary-button" type="button" onClick={activateIncident} disabled={dispatching || preview.loading || (preview.data?.recipients.length ?? 0) === 0}>
            <Send size={16} /> {dispatching ? 'Versturen...' : 'Melding versturen'}
          </button>
        ) : null}
      >
        <ResourceState loading={preview.loading} error={preview.error} empty={false}>
          <div className="panel-body">
            <dl>
              <dt>Team</dt>
              <dd>{preview.data?.team ? `${preview.data.team.code} - ${preview.data.team.name}` : '-'}</dd>
              <dt>Ontvangers</dt>
              <dd>{preview.data?.recipients.length ?? 0}</dd>
            </dl>
            {preview.data?.blocked_reason ? <p className="form-error">{preview.data.blocked_reason}</p> : null}
            {dispatchError ? <p className="form-error">{dispatchError}</p> : null}
            {(preview.data?.recipients.length ?? 0) > 0 ? (
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Naam</th>
                    <th>E-mail</th>
                    <th>Teams</th>
                  </tr>
                </thead>
                <tbody>
                  {preview.data?.recipients.map((recipient) => (
                    <tr key={recipient.id}>
                      <td>{recipient.name}</td>
                      <td>{recipient.email}</td>
                      <td>{recipient.teams?.map((team) => team.code).join(', ') || '-'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : null}
          </div>
        </ResourceState>
      </Panel>

      <Panel title="Incidentlog">
        <ResourceState loading={timeline.loading} error={timeline.error} empty={(timeline.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Tijd</th>
                <th>Type</th>
                <th>Gebeurtenis</th>
                <th>Toelichting</th>
              </tr>
            </thead>
            <tbody>
              {timeline.data?.map((item) => (
                <tr key={`${item.type}-${item.id}`}>
                  <td>{formatDate(item.created_at)}</td>
                  <td>{item.type}</td>
                  <td>{item.label}</td>
                  <td>{item.message ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
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
