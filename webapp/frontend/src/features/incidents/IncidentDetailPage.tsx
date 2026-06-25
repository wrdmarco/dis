import { type FormEvent, type ReactNode, useEffect, useState } from 'react';
import { BellRing, Clock, MapPin, MessageSquare, Pencil, RadioTower, Send, TrendingUp, Users, X } from 'lucide-react';
import { useParams } from 'react-router-dom';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
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
  const [recipientUpdatingId, setRecipientUpdatingId] = useState<string | null>(null);
  const [recipientUpdateMessage, setRecipientUpdateMessage] = useState<string | null>(null);

  const latestDispatch = dispatches.data?.[0] ?? null;
  const showDraftPanel = incident.data?.status === 'draft';
  const recipientCount = latestDispatch?.recipients?.length ?? preview.data?.recipients.length ?? 0;
  const liveSharedCount = liveLocations.data?.filter((location) => location.sharing_status === 'shared').length ?? 0;

  useEffect(() => {
    const currentIncident = incident.data;
    if (currentIncident == null || editModalOpen) {
      return;
    }

    setEditForm(formFromIncident(currentIncident));
    setStatusReason('');
  }, [editModalOpen, incident.data]);

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

  const updateRecipientResponse = async (recipientId: string, response: 'pending' | 'accepted' | 'declined' | 'no_response') => {
    if (!latestDispatch) {
      return;
    }

    setRecipientUpdatingId(recipientId);
    setRecipientUpdateMessage(null);
    try {
      await api.patch(`/dispatches/${latestDispatch.id}/recipients/${recipientId}/response`, {
        response,
        note: 'Handmatig aangepast vanuit incidentdetail.',
      });
      setRecipientUpdateMessage('Opkomststatus aangepast.');
      await dispatches.reload();
      await liveLocations.reload();
      await timeline.reload();
    } catch (err) {
      setRecipientUpdateMessage(err instanceof ApiClientError ? err.message : 'Opkomststatus kon niet worden aangepast.');
    } finally {
      setRecipientUpdatingId(null);
    }
  };

  return (
    <div className="page-stack incident-detail-page">
      <RealtimeBridge onOperationalEvent={() => {
        void incident.silentReload();
        void preview.silentReload();
        void dispatches.silentReload();
        void liveLocations.silentReload();
        void timeline.silentReload();
      }} />
      <Panel
        title="Melding"
        action={incident.data ? (
          <button className="secondary-button" type="button" onClick={openEditModal}>
            <Pencil size={16} /> Aanpassen
          </button>
        ) : null}
      >
        <ResourceState loading={incident.loading} error={incident.error} empty={!incident.data}>
          {incident.data ? (
            <div className="incident-detail">
              <div className="incident-hero">
                <div className="incident-hero__main">
                  <span className="incident-reference">{incident.data.reference}</span>
                  <h3>{incident.data.title}</h3>
                  <div className="incident-hero__badges">
                    <StatusPill value={incident.data.priority} tone={incident.data.priority === 'critical' ? 'bad' : 'warn'} />
                    <StatusPill value={incident.data.status} />
                  </div>
                  <p>{incident.data.description ?? 'Geen omschrijving vastgelegd.'}</p>
                </div>
                <dl className="incident-meta">
                  <MetaItem icon={<MapPin size={16} />} label="Locatie" value={incident.data.location_label ?? '-'} />
                  <MetaItem icon={<Users size={16} />} label="Team" value={incident.data.team?.code ? `${incident.data.team.code} - ${incident.data.team.name}` : '-'} />
                  <MetaItem icon={<RadioTower size={16} />} label="Coordinator" value={incident.data.coordinator?.name ?? '-'} />
                  <MetaItem icon={<Clock size={16} />} label="Geopend" value={formatDate(incident.data.opened_at)} />
                  <MetaItem icon={<Clock size={16} />} label="Gesloten" value={formatDate(incident.data.closed_at)} />
                </dl>
              </div>
              <div className="incident-overview">
                <SummaryItem label="Incidentstatus" value={incidentStatusLabel(incident.data.status)} />
                <SummaryItem label="Prioriteit" value={priorityLabel(incident.data.priority)} />
                <SummaryItem label="Ontvangers" value={String(recipientCount)} />
                <SummaryItem label="Komt" value={latestDispatch ? String(countResponses(latestDispatch, 'accepted')) : '-'} />
                <SummaryItem label="Onderweg" value={latestDispatch ? String(countOperatorStatuses(latestDispatch, 'en_route')) : '-'} />
                <SummaryItem label="Live locaties" value={String(liveSharedCount)} />
              </div>
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      {showDraftPanel ? (
        <Panel
          title="Alarmeringsconcept"
          action={(
            <button className="primary-button" type="button" onClick={activateIncident} disabled={dispatching || preview.loading || (preview.data?.recipients.length ?? 0) === 0}>
              <Send size={16} /> {dispatching ? 'Versturen...' : 'Melding versturen'}
            </button>
          )}
        >
          <ResourceState loading={preview.loading} error={preview.error} empty={false}>
            <div className="panel-body">
              <div className="draft-dispatch">
                <div>
                  <span>Team</span>
                  <strong>{preview.data?.team ? `${preview.data.team.code} - ${preview.data.team.name}` : '-'}</strong>
                </div>
                <div>
                  <span>Te alarmeren</span>
                  <strong>{preview.data?.recipients.length ?? 0}</strong>
                </div>
              </div>
              {preview.data?.blocked_reason ? <p className="form-error">{preview.data.blocked_reason}</p> : null}
              {dispatchError ? <p className="form-error">{dispatchError}</p> : null}
              {(preview.data?.recipients.length ?? 0) > 0 ? (
                <div className="draft-recipient-grid">
                  {preview.data?.recipients.map((recipient) => (
                    <article key={recipient.id}>
                      <strong>{recipient.name}</strong>
                      <span>{recipient.email}</span>
                      <small>{recipient.teams?.map((team) => team.code).join(', ') || '-'}</small>
                    </article>
                  ))}
                </div>
              ) : null}
            </div>
          </ResourceState>
        </Panel>
      ) : null}

      <Panel title="Opkomst en alarmering">
        <ResourceState loading={dispatches.loading} error={dispatches.error} empty={(dispatches.data?.length ?? 0) === 0}>
          <div className="panel-body">
            {latestDispatch ? (
              <>
                <div className="dispatch-toolbar">
                  <div>
                    <span>Laatste alarmering</span>
                    <strong>{dispatchStatusLabel(latestDispatch.status)}</strong>
                  </div>
                  <div className="dispatch-toolbar__actions">
                    <button className="secondary-button" type="button" onClick={() => void runDispatchAction('escalate')} disabled={dispatchAction !== null || latestDispatch.status === 'cancelled' || latestDispatch.status === 'escalated'}>
                      <TrendingUp size={16} /> {dispatchAction === 'escalate' ? 'Opschalen...' : 'Opschalen'}
                    </button>
                    <button className="secondary-button" type="button" onClick={() => void runDispatchAction('realert')} disabled={dispatchAction !== null || latestDispatch.status === 'cancelled' || countResponses(latestDispatch, 'pending') === 0}>
                      <BellRing size={16} /> {dispatchAction === 'realert' ? 'Heralarmeren...' : 'Heralarmeren'}
                    </button>
                  </div>
                </div>
                {dispatchActionMessage ? <p className={dispatchActionMessage.includes('kon niet') ? 'form-error' : 'form-note'}>{dispatchActionMessage}</p> : null}
                <div className="summary-grid">
                  <SummaryItem label="Alarmering" value={dispatchStatusLabel(latestDispatch.status)} />
                  <SummaryItem label="Team" value={latestDispatch.target_team?.code ?? '-'} />
                  <SummaryItem label="Verstuurd" value={formatDate(latestDispatch.sent_at)} />
                  <SummaryItem label="Komt" value={String(countResponses(latestDispatch, 'accepted'))} />
                  <SummaryItem label="Komt niet" value={String(countResponses(latestDispatch, 'declined'))} />
                  <SummaryItem label="Nog geen reactie" value={String(countResponses(latestDispatch, 'pending'))} />
                  <SummaryItem label="Onderweg" value={String(countOperatorStatuses(latestDispatch, 'en_route'))} />
                  <SummaryItem label="Op locatie" value={String(countOperatorStatuses(latestDispatch, 'on_scene'))} />
                </div>
                <div className="recipient-list">
                  {latestDispatch.recipients?.map((recipient) => (
                    <article className={`recipient-row recipient-row--${recipient.response_status}`} key={recipient.id}>
                      <div className="recipient-row__identity">
                        <strong>{recipient.user?.name ?? recipient.user_id}</strong>
                        <span>{recipient.user?.email ?? '-'}</span>
                      </div>
                      <div className="recipient-row__states">
                        <StatusPill value={responseLabel(recipient.response_status)} tone={recipient.response_status === 'accepted' ? 'good' : recipient.response_status === 'declined' ? 'bad' : undefined} />
                        <StatusPill value={operatorStatusLabel(recipient.user?.statuses?.[0]?.status)} tone={operatorStatusTone(recipient.user?.statuses?.[0]?.status)} />
                      </div>
                      <div className="recipient-row__time">
                        <span>Reactie</span>
                        <strong>{formatDate(recipient.responded_at)}</strong>
                      </div>
                      <select
                        value={recipient.response_status}
                        disabled={recipientUpdatingId === recipient.id || latestDispatch.status === 'cancelled'}
                        onChange={(event) => void updateRecipientResponse(recipient.id, event.target.value as 'pending' | 'accepted' | 'declined' | 'no_response')}
                        aria-label={`Opkomststatus aanpassen voor ${recipient.user?.name ?? recipient.user_id}`}
                      >
                        <option value="pending">Wacht op reactie</option>
                        <option value="accepted">Komt</option>
                        <option value="declined">Komt niet</option>
                        <option value="no_response">Geen reactie</option>
                      </select>
                      {recipient.response_note ? <p className="recipient-row__note">{recipient.response_note}</p> : null}
                    </article>
                  ))}
                </div>
                {recipientUpdateMessage ? <p className={recipientUpdateMessage.includes('kon niet') ? 'form-error' : 'form-note'}>{recipientUpdateMessage}</p> : null}
                <form className="inline-message-form" onSubmit={sendAdditionalInfo}>
                  <label>
                    Nadere info voor opkomende gebruikers
                    <textarea value={additionalInfo} maxLength={2000} onChange={(event) => setAdditionalInfo(event.target.value)} />
                  </label>
                  <button className="primary-button" type="submit" disabled={additionalInfoSending || additionalInfo.trim() === '' || additionalInfoRecipientCount(latestDispatch) === 0}>
                    <MessageSquare size={16} /> {additionalInfoSending ? 'Versturen...' : 'Info versturen'}
                  </button>
                  {additionalInfoMessage ? <p className={additionalInfoMessage.includes('kon niet') ? 'form-error' : 'form-note'}>{additionalInfoMessage}</p> : null}
                </form>
              </>
            ) : null}
          </div>
        </ResourceState>
      </Panel>

      <Panel title="Kaart en live locaties">
        <ResourceState loading={liveLocations.loading} error={liveLocations.error} empty={false}>
          <LiveLocationMap incident={incident.data} locations={liveLocations.data ?? []} />
        </ResourceState>
      </Panel>

      <Panel title="Tijdlijn">
        <ResourceState loading={timeline.loading} error={timeline.error} empty={(timeline.data?.length ?? 0) === 0}>
          <div className="incident-timeline">
            {timeline.data?.map((item) => (
              <article className={`incident-timeline__item incident-timeline__item--${item.type}`} key={`${item.type}-${item.id}`}>
                <time>{formatDate(item.created_at)}</time>
                <div>
                  <span>{timelineTypeLabel(item.type)}</span>
                  <strong>{item.label}</strong>
                  {item.message ? <p>{item.message}</p> : null}
                </div>
              </article>
            ))}
          </div>
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

function MetaItem({ icon, label, value }: { icon: ReactNode; label: string; value: string }) {
  return (
    <>
      <dt>{icon}<span>{label}</span></dt>
      <dd>{value}</dd>
    </>
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
    .filter((location) => location.latitude !== null && location.latitude !== undefined && location.longitude !== null && location.longitude !== undefined)
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
  const mapPoints = allPoints.length > 0 ? allPoints : [{ latitude: 52.1326, longitude: 5.2913 }];
  const center = centerFor(mapPoints);
  const viewport = mapViewport(mapPoints);
  const centerWorld = latLonToWorld(center.latitude, center.longitude, viewport.zoom);
  const tiles = visibleTiles(centerWorld, viewport.zoom, viewport.width, viewport.height);

  return (
    <div className="live-map">
      <div className="live-map__canvas" role="img" aria-label="Live locaties kaart">
        {tiles.map((tile) => (
          <img
            key={`${tile.x}-${tile.y}-${tile.z}`}
            alt=""
            className="live-map__tile"
            src={`https://tile.openstreetmap.org/${tile.z}/${tile.x}/${tile.y}.png`}
            style={{ left: tile.left, top: tile.top }}
            loading="lazy"
            referrerPolicy="no-referrer"
          />
        ))}
        {hasIncidentLocation ? (
          <span className="live-map__incident-marker" style={worldMarkerStyle({ latitude: incidentLatitude, longitude: incidentLongitude }, centerWorld, viewport)} />
        ) : null}
        {points.map((point) => (
          <span
            key={point.user_id}
            className="live-map__user-marker"
            style={worldMarkerStyle(point, centerWorld, viewport)}
            title={point.user?.name ?? point.user_id}
          >
            <span className="live-map__user-label">{point.user?.name ?? point.user_id}</span>
          </span>
        ))}
      </div>
      <table className="data-table live-map__table">
        <thead>
          <tr>
            <th>Gebruiker</th>
            <th>Locatie</th>
            <th>ETA</th>
            <th>Laatst gezien</th>
            <th>Nauwkeurigheid</th>
          </tr>
        </thead>
        <tbody>
          {locations.map((location) => (
            <tr key={location.user_id}>
              <td>{location.user?.name ?? location.user_id}</td>
              <td>{locationStatusLabel(location)}</td>
              <td>{location.eta_minutes ? `${location.eta_minutes} min` : '-'}</td>
              <td>{formatDate(location.recorded_at)}</td>
              <td>{location.accuracy_meters ? `${Number(location.accuracy_meters).toFixed(0)} m` : '-'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function formatDate(value?: string | null): string {
  return formatDateTime(value);
}

function incidentStatusLabel(status: string): string {
  switch (status) {
    case 'draft':
      return 'Concept';
    case 'active':
      return 'Actief';
    case 'dispatching':
      return 'Alarmeren';
    case 'in_progress':
      return 'In behandeling';
    case 'resolved':
      return 'Afgerond';
    case 'cancelled':
      return 'Geannuleerd';
    default:
      return status;
  }
}

function priorityLabel(priority: string): string {
  switch (priority) {
    case 'critical':
      return 'Kritiek';
    case 'high':
      return 'Hoog';
    case 'normal':
      return 'Normaal';
    case 'low':
      return 'Laag';
    default:
      return priority;
  }
}

function dispatchStatusLabel(status: string): string {
  switch (status) {
    case 'draft':
      return 'Concept';
    case 'sent':
      return 'Verstuurd';
    case 'escalated':
      return 'Opgeschaald';
    case 'cancelled':
      return 'Geannuleerd';
    default:
      return status;
  }
}

function timelineTypeLabel(type: IncidentTimelineItem['type']): string {
  switch (type) {
    case 'status':
      return 'Incidentstatus';
    case 'dispatch':
      return 'Alarmering';
    case 'dispatch_response':
      return 'Opkomst';
    case 'dispatch_message':
      return 'Nadere info';
    case 'operator_status':
      return 'Operationele status';
    default:
      return type;
  }
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

function locationStatusLabel(location: IncidentLiveLocation): string {
  switch (location.sharing_status) {
    case 'shared':
      return 'gedeeld';
    case 'pending':
      return 'wacht op locatie';
    case 'declined':
      return location.refusal_reason ? `geweigerd (${location.refusal_reason})` : 'geweigerd';
    default:
      return 'niet gevraagd';
  }
}

function operatorStatusLabel(status?: string | null): string {
  switch (status) {
    case 'en_route':
      return 'Onderweg';
    case 'on_scene':
      return 'Op locatie';
    case 'available':
      return 'Beschikbaar';
    case 'unavailable':
      return 'Niet beschikbaar';
    case 'assigned':
      return 'Toegewezen';
    case 'resting':
      return 'Rust';
    case 'suspended':
      return 'Geblokkeerd';
    default:
      return 'Onbekend';
  }
}

function operatorStatusTone(status?: string | null): 'neutral' | 'good' | 'warn' | 'bad' {
  switch (status) {
    case 'en_route':
    case 'on_scene':
      return 'good';
    case 'unavailable':
    case 'suspended':
      return 'bad';
    case 'assigned':
      return 'warn';
    default:
      return 'neutral';
  }
}

function countResponses(dispatch: DispatchRequest, status: 'accepted' | 'declined' | 'pending'): number {
  return dispatch.recipients?.filter((recipient) => recipient.response_status === status).length ?? 0;
}

function countOperatorStatuses(dispatch: DispatchRequest, status: 'en_route' | 'on_scene'): number {
  return dispatch.recipients?.filter((recipient) => recipient.user?.statuses?.[0]?.status === status).length ?? 0;
}

function additionalInfoRecipientCount(dispatch: DispatchRequest): number {
  return dispatch.recipients?.filter((recipient) => recipient.response_status === 'accepted'
    || ['en_route', 'on_scene'].includes(recipient.user?.statuses?.[0]?.status ?? '')).length ?? 0;
}

interface MapBounds {
  minLat: number;
  maxLat: number;
  minLon: number;
  maxLon: number;
}

function boundsFor(points: Array<{ latitude: number; longitude: number }>): MapBounds {
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

function centerFor(points: Array<{ latitude: number; longitude: number }>): { latitude: number; longitude: number } {
  const bounds = boundsFor(points);

  return {
    latitude: (bounds.minLat + bounds.maxLat) / 2,
    longitude: (bounds.minLon + bounds.maxLon) / 2,
  };
}

interface MapViewport {
  width: number;
  height: number;
  zoom: number;
}

interface WorldPoint {
  x: number;
  y: number;
}

interface TilePosition {
  x: number;
  y: number;
  z: number;
  left: string;
  top: string;
}

function mapViewport(points: Array<{ latitude: number; longitude: number }>): MapViewport {
  const width = 960;
  const height = 380;
  const bounds = boundsFor(points);

  for (let zoom = 16; zoom >= 5; zoom -= 1) {
    const northWest = latLonToWorld(bounds.maxLat, bounds.minLon, zoom);
    const southEast = latLonToWorld(bounds.minLat, bounds.maxLon, zoom);
    if (Math.abs(southEast.x - northWest.x) <= width - 96 && Math.abs(southEast.y - northWest.y) <= height - 96) {
      return { width, height, zoom };
    }
  }

  return { width, height, zoom: 5 };
}

function latLonToWorld(latitude: number, longitude: number, zoom: number): WorldPoint {
  const sinLatitude = Math.sin((clamp(latitude, -85.05112878, 85.05112878) * Math.PI) / 180);
  const scale = 256 * 2 ** zoom;

  return {
    x: ((longitude + 180) / 360) * scale,
    y: (0.5 - Math.log((1 + sinLatitude) / (1 - sinLatitude)) / (4 * Math.PI)) * scale,
  };
}

function visibleTiles(center: WorldPoint, zoom: number, width: number, height: number): TilePosition[] {
  const tileSize = 256;
  const minTileX = Math.floor((center.x - width / 2) / tileSize);
  const maxTileX = Math.floor((center.x + width / 2) / tileSize);
  const minTileY = Math.floor((center.y - height / 2) / tileSize);
  const maxTileY = Math.floor((center.y + height / 2) / tileSize);
  const tileCount = 2 ** zoom;
  const tiles: TilePosition[] = [];

  for (let x = minTileX; x <= maxTileX; x += 1) {
    for (let y = minTileY; y <= maxTileY; y += 1) {
      if (y < 0 || y >= tileCount) {
        continue;
      }

      const wrappedX = ((x % tileCount) + tileCount) % tileCount;
      tiles.push({
        x: wrappedX,
        y,
        z: zoom,
        left: `${Math.round(x * tileSize - (center.x - width / 2))}px`,
        top: `${Math.round(y * tileSize - (center.y - height / 2))}px`,
      });
    }
  }

  return tiles;
}

function worldMarkerStyle(point: { latitude: number; longitude: number }, center: WorldPoint, viewport: MapViewport): { left: string; top: string } {
  const world = latLonToWorld(point.latitude, point.longitude, viewport.zoom);
  return {
    left: `${Math.round(world.x - center.x + viewport.width / 2)}px`,
    top: `${Math.round(world.y - center.y + viewport.height / 2)}px`,
  };
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), max);
}
