import { FormEvent, useEffect, useState } from 'react';
import { BellRing, MessageSquare, Pencil, Send, TrendingUp, X } from 'lucide-react';
import { useParams } from 'react-router-dom';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { DispatchPreview, DispatchRequest, Incident, IncidentLiveLocation, IncidentTimelineItem, Team, User } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';
import { IncidentForm, type IncidentFormState, incidentPayload } from './IncidentsPage';

export function IncidentDetailPage() {
  const { incidentId } = useParams();
  const { api } = useAuth();
  const incident = useApiResource<Incident>(`/incidents/${incidentId}`, Boolean(incidentId));
  const preview = useApiResource<DispatchPreview>(`/incidents/${incidentId}/dispatch-preview`, Boolean(incidentId));
  const dispatches = useApiResource<DispatchRequest[]>(`/incidents/${incidentId}/dispatches`, Boolean(incidentId));
  const liveLocations = useApiResource<IncidentLiveLocation[]>(`/incidents/${incidentId}/live-locations`, Boolean(incidentId));
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
  const [additionalInfo, setAdditionalInfo] = useState('');
  const [additionalInfoSending, setAdditionalInfoSending] = useState(false);
  const [additionalInfoMessage, setAdditionalInfoMessage] = useState<string | null>(null);
  const [dispatchAction, setDispatchAction] = useState<'escalate' | 'realert' | null>(null);
  const [dispatchActionMessage, setDispatchActionMessage] = useState<string | null>(null);

  const latestDispatch = dispatches.data?.[0] ?? null;

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
      await dispatches.reload();
      await liveLocations.reload();
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
      await dispatches.reload();
      await timeline.reload();
    } catch (err) {
      setDispatchError(err instanceof ApiClientError ? err.message : 'Melding kon niet worden verstuurd.');
    } finally {
      setDispatching(false);
    }
  };

  const sendAdditionalInfo = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!latestDispatch || additionalInfo.trim() === '') {
      return;
    }

    setAdditionalInfoSending(true);
    setAdditionalInfoMessage(null);
    try {
      const response = await api.post<{ queued_tokens: number; recipient_users: number }>(`/dispatches/${latestDispatch.id}/message`, {
        message: additionalInfo.trim(),
      });
      setAdditionalInfo('');
      setAdditionalInfoMessage(`Verzonden naar ${response.data.recipient_users} opkomende gebruiker(s), ${response.data.queued_tokens} pushbericht(en) in wachtrij.`);
      await timeline.reload();
      await dispatches.reload();
    } catch (err) {
      setAdditionalInfoMessage(err instanceof ApiClientError ? err.message : 'Nadere info kon niet worden verzonden.');
    } finally {
      setAdditionalInfoSending(false);
    }
  };

  const runDispatchAction = async (action: 'escalate' | 'realert') => {
    if (!latestDispatch) {
      return;
    }

    setDispatchAction(action);
    setDispatchActionMessage(null);
    try {
      await api.post<DispatchRequest>(`/dispatches/${latestDispatch.id}/${action === 'escalate' ? 'escalate' : 're-alert'}`);
      setDispatchActionMessage(action === 'escalate' ? 'Alarmering is opgeschaald.' : 'Heralarmering is verstuurd naar ontvangers zonder reactie.');
      await dispatches.reload();
      await timeline.reload();
    } catch (err) {
      setDispatchActionMessage(err instanceof ApiClientError ? err.message : 'Dispatchactie kon niet worden uitgevoerd.');
    } finally {
      setDispatchAction(null);
    }
  };

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => {
        void incident.reload();
        void preview.reload();
        void dispatches.reload();
        void liveLocations.reload();
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

      <Panel title="Opkomststatus">
        <ResourceState loading={dispatches.loading} error={dispatches.error} empty={(dispatches.data?.length ?? 0) === 0}>
          <div className="panel-body">
            {latestDispatch ? (
              <>
                <div className="actions-row">
                  <button className="secondary-button" type="button" onClick={() => void runDispatchAction('escalate')} disabled={dispatchAction !== null || latestDispatch.status === 'cancelled' || latestDispatch.status === 'escalated'}>
                    <TrendingUp size={16} /> {dispatchAction === 'escalate' ? 'Opschalen...' : 'Opschalen'}
                  </button>
                  <button className="secondary-button" type="button" onClick={() => void runDispatchAction('realert')} disabled={dispatchAction !== null || latestDispatch.status === 'cancelled' || countResponses(latestDispatch, 'pending') === 0}>
                    <BellRing size={16} /> {dispatchAction === 'realert' ? 'Heralarmeren...' : 'Heralarmeren'}
                  </button>
                </div>
                {dispatchActionMessage ? <p className={dispatchActionMessage.includes('kon niet') ? 'form-error' : 'form-note'}>{dispatchActionMessage}</p> : null}
                <div className="summary-grid">
                  <SummaryItem label="Alarmering" value={latestDispatch.status} />
                  <SummaryItem label="Team" value={latestDispatch.target_team?.code ?? '-'} />
                  <SummaryItem label="Verstuurd" value={formatDate(latestDispatch.sent_at)} />
                  <SummaryItem label="Komt" value={String(countResponses(latestDispatch, 'accepted'))} />
                  <SummaryItem label="Komt niet" value={String(countResponses(latestDispatch, 'declined'))} />
                  <SummaryItem label="Nog geen reactie" value={String(countResponses(latestDispatch, 'pending'))} />
                </div>
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>Naam</th>
                      <th>Status</th>
                      <th>Reactietijd</th>
                      <th>Opmerking</th>
                    </tr>
                  </thead>
                  <tbody>
                    {latestDispatch.recipients?.map((recipient) => (
                      <tr key={recipient.id}>
                        <td>{recipient.user?.name ?? recipient.user_id}</td>
                        <td><StatusPill value={responseLabel(recipient.response_status)} tone={recipient.response_status === 'accepted' ? 'good' : recipient.response_status === 'declined' ? 'bad' : undefined} /></td>
                        <td>{formatDate(recipient.responded_at)}</td>
                        <td>{recipient.response_note ?? '-'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <form className="inline-message-form" onSubmit={sendAdditionalInfo}>
                  <label>
                    Nadere info voor opkomende gebruikers
                    <textarea value={additionalInfo} maxLength={2000} onChange={(event) => setAdditionalInfo(event.target.value)} />
                  </label>
                  <button className="primary-button" type="submit" disabled={additionalInfoSending || additionalInfo.trim() === '' || countResponses(latestDispatch, 'accepted') === 0}>
                    <MessageSquare size={16} /> {additionalInfoSending ? 'Versturen...' : 'Info versturen'}
                  </button>
                  {additionalInfoMessage ? <p className={additionalInfoMessage.includes('kon niet') ? 'form-error' : 'form-note'}>{additionalInfoMessage}</p> : null}
                </form>
              </>
            ) : null}
          </div>
        </ResourceState>
      </Panel>

      <Panel title="Live locaties">
        <ResourceState loading={liveLocations.loading} error={liveLocations.error} empty={(liveLocations.data?.length ?? 0) === 0}>
          <LiveLocationMap incident={incident.data} locations={liveLocations.data ?? []} />
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

function SummaryItem({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function LiveLocationMap({ incident, locations }: { incident: Incident | null; locations: IncidentLiveLocation[] }) {
  const points = locations
    .map((location) => ({
      ...location,
      latitude: Number(location.latitude),
      longitude: Number(location.longitude),
    }))
    .filter((location) => Number.isFinite(location.latitude) && Number.isFinite(location.longitude));

  const incidentLatitude = Number(incident?.latitude);
  const incidentLongitude = Number(incident?.longitude);
  const hasIncidentLocation = Number.isFinite(incidentLatitude) && Number.isFinite(incidentLongitude);
  const allPoints = [
    ...(hasIncidentLocation ? [{ latitude: incidentLatitude, longitude: incidentLongitude }] : []),
    ...points,
  ];
  const bounds = boundsFor(allPoints);
  const center = hasIncidentLocation
    ? { latitude: incidentLatitude, longitude: incidentLongitude }
    : points[0] ?? { latitude: 52.1326, longitude: 5.2913 };
  const iframeSource = bounds
    ? `https://www.openstreetmap.org/export/embed.html?bbox=${bounds.minLon},${bounds.minLat},${bounds.maxLon},${bounds.maxLat}&layer=mapnik&marker=${center.latitude},${center.longitude}`
    : `https://www.openstreetmap.org/export/embed.html?bbox=${center.longitude - 0.03},${center.latitude - 0.02},${center.longitude + 0.03},${center.latitude + 0.02}&layer=mapnik&marker=${center.latitude},${center.longitude}`;

  return (
    <div className="live-map">
      <iframe title="Live locaties" src={iframeSource} loading="lazy" referrerPolicy="no-referrer" />
      <div className="live-map__overlay" aria-hidden="true">
        {hasIncidentLocation && bounds ? (
          <span className="live-map__incident-marker" style={markerStyle({ latitude: incidentLatitude, longitude: incidentLongitude }, bounds)} />
        ) : null}
        {bounds ? points.map((point) => (
          <span
            key={point.user_id}
            className="live-map__user-marker"
            style={markerStyle(point, bounds)}
            title={point.user?.name ?? point.user_id}
          />
        )) : null}
      </div>
      <table className="data-table live-map__table">
        <thead>
          <tr>
            <th>Gebruiker</th>
            <th>Laatst gezien</th>
            <th>Nauwkeurigheid</th>
          </tr>
        </thead>
        <tbody>
          {points.map((point) => (
            <tr key={point.user_id}>
              <td>{point.user?.name ?? point.user_id}</td>
              <td>{formatDate(point.recorded_at)}</td>
              <td>{point.accuracy_meters ? `${Number(point.accuracy_meters).toFixed(0)} m` : '-'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function formatDate(value?: string | null): string {
  return value ? new Date(value).toLocaleString('nl-NL') : '-';
}

function responseLabel(value: string): string {
  switch (value) {
    case 'accepted':
      return 'komt';
    case 'declined':
      return 'komt niet';
    case 'no_response':
      return 'geen reactie';
    default:
      return 'wacht op reactie';
  }
}

function countResponses(dispatch: DispatchRequest, status: 'accepted' | 'declined' | 'pending'): number {
  return dispatch.recipients?.filter((recipient) => recipient.response_status === status).length ?? 0;
}

interface MapBounds {
  minLat: number;
  maxLat: number;
  minLon: number;
  maxLon: number;
}

function boundsFor(points: Array<{ latitude: number; longitude: number }>): MapBounds | null {
  if (points.length === 0) {
    return null;
  }

  const latitudes = points.map((point) => point.latitude);
  const longitudes = points.map((point) => point.longitude);
  const minLat = Math.min(...latitudes);
  const maxLat = Math.max(...latitudes);
  const minLon = Math.min(...longitudes);
  const maxLon = Math.max(...longitudes);
  const latPadding = Math.max((maxLat - minLat) * 0.2, 0.01);
  const lonPadding = Math.max((maxLon - minLon) * 0.2, 0.015);

  return {
    minLat: minLat - latPadding,
    maxLat: maxLat + latPadding,
    minLon: minLon - lonPadding,
    maxLon: maxLon + lonPadding,
  };
}

function markerStyle(point: { latitude: number; longitude: number }, bounds: MapBounds): { left: string; top: string } {
  const x = ((point.longitude - bounds.minLon) / (bounds.maxLon - bounds.minLon)) * 100;
  const y = (1 - ((point.latitude - bounds.minLat) / (bounds.maxLat - bounds.minLat))) * 100;

  return {
    left: `${clamp(x, 3, 97)}%`,
    top: `${clamp(y, 3, 97)}%`,
  };
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), max);
}
