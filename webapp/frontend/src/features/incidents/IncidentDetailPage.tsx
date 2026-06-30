import { type FormEvent, type ReactNode, useEffect, useState } from 'react';
import { BellRing, Clock, CloudSun, Download, MapPin, MessageSquare, Pencil, Plane, RadioTower, RefreshCw, Send, Trash2, TrendingUp, Users, X } from 'lucide-react';
import { useNavigate, useParams } from 'react-router-dom';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { DispatchPreview, DispatchRequest, DroneFlightContext, Incident, IncidentLiveLocation, IncidentTimelineItem, Team, User } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';
import { IncidentForm, type IncidentFormState, incidentPayload } from './IncidentsPage';

const LIVE_LOCATION_STALE_MS = 5 * 60 * 1000;

export function IncidentDetailPage() {
  const { incidentId } = useParams();
  const navigate = useNavigate();
  const { api, hasPermission } = useAuth();
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
  const [escalationModalOpen, setEscalationModalOpen] = useState(false);
  const [escalationTeamIds, setEscalationTeamIds] = useState<string[]>([]);
  const [escalationError, setEscalationError] = useState<string | null>(null);
  const [recipientUpdatingId, setRecipientUpdatingId] = useState<string | null>(null);
  const [recipientUpdateMessage, setRecipientUpdateMessage] = useState<string | null>(null);
  const [operatorStatusUpdatingUserId, setOperatorStatusUpdatingUserId] = useState<string | null>(null);
  const [locationRequestingUserId, setLocationRequestingUserId] = useState<string | null>(null);
  const [reportDownloading, setReportDownloading] = useState(false);
  const [reportError, setReportError] = useState<string | null>(null);
  const [flightRefreshLoading, setFlightRefreshLoading] = useState(false);
  const [flightRefreshMessage, setFlightRefreshMessage] = useState<string | null>(null);
  const [deletingIncident, setDeletingIncident] = useState(false);

  const latestDispatch = dispatches.data?.[0] ?? null;
  const showDraftPanel = incident.data?.status === 'draft';
  const reportAvailable = incident.data?.status === 'resolved' || incident.data?.status === 'cancelled';
  const recipientCount = latestDispatch?.recipients?.length ?? preview.data?.recipients.length ?? 0;
  const liveSharedCount = liveLocations.data?.filter((location) => location.location_is_current === true || location.sharing_status === 'shared').length ?? 0;
  const canManageIncidents = hasPermission('incidents.manage');
  const canDeleteIncidents = hasPermission('incidents.delete');
  const canManageDispatches = hasPermission('dispatch.manage');
  const canOverrideStatus = hasPermission('status.override');
  const dispatchedTeamIds = dispatchTargetTeamIds(dispatches.data ?? []);
  const escalationTeams = (teams.data ?? []).filter((team) => team.is_operational && !dispatchedTeamIds.includes(team.id));

  useEffect(() => {
    const currentIncident = incident.data;
    if (currentIncident == null || editModalOpen) {
      return;
    }

    setEditForm(formFromIncident(currentIncident));
    setStatusReason('');
  }, [editModalOpen, incident.data]);

  useEffect(() => {
    if (!incidentId || incident.data?.status === 'resolved' || incident.data?.status === 'cancelled') {
      return;
    }

    const timer = window.setInterval(() => {
      void liveLocations.silentReload();
    }, 10_000);

    return () => window.clearInterval(timer);
  }, [incident.data?.status, incidentId, liveLocations.silentReload]);

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

  const openEscalationModal = () => {
    setEscalationTeamIds([]);
    setEscalationError(null);
    setDispatchActionMessage(null);
    setEscalationModalOpen(true);
  };

  const toggleEscalationTeam = (teamId: string, checked: boolean) => {
    setEscalationTeamIds((current) => checked ? [...current, teamId] : current.filter((id) => id !== teamId));
  };

  const runEscalation = async () => {
    if (!latestDispatch || escalationTeamIds.length === 0) {
      setEscalationError('Kies minimaal een extra team om naar op te schalen.');
      return;
    }

    const selectedLabels = escalationTeams
      .filter((team) => escalationTeamIds.includes(team.id))
      .map((team) => team.code)
      .join(', ');

    setDispatchAction('escalate');
    setEscalationError(null);
    setDispatchActionMessage(null);
    try {
      await api.post<DispatchRequest>(`/dispatches/${latestDispatch.id}/escalate`, {
        team_ids: escalationTeamIds,
      });
      setDispatchActionMessage(`Opgeschaald naar ${selectedLabels}. De extra teams zijn aan het incident gekoppeld en gealarmeerd.`);
      setEscalationModalOpen(false);
      setEscalationTeamIds([]);
      await incident.reload();
      await preview.reload();
      await dispatches.reload();
      await timeline.reload();
    } catch (err) {
      setEscalationError(err instanceof ApiClientError ? err.message : 'Opschalen kon niet worden uitgevoerd.');
    } finally {
      setDispatchAction(null);
    }
  };

  const runDispatchAction = async (action: 'realert') => {
    if (!latestDispatch) {
      return;
    }

    setDispatchAction(action);
    setDispatchActionMessage(null);
    try {
      await api.post<DispatchRequest>(`/dispatches/${latestDispatch.id}/re-alert`);
      setDispatchActionMessage('Heralarmering is verstuurd naar ontvangers zonder reactie.');
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

  const updateOperatorStatus = async (userId: string, status: 'en_route' | 'on_scene') => {
    setOperatorStatusUpdatingUserId(userId);
    setRecipientUpdateMessage(null);
    try {
      await api.post(`/status/users/${userId}/override`, {
        status,
        reason: 'Handmatig aangepast vanuit incidentdetail.',
      });
      setRecipientUpdateMessage('Gebruikersstatus aangepast.');
      await dispatches.reload();
      await liveLocations.reload();
      await timeline.reload();
    } catch (err) {
      setRecipientUpdateMessage(err instanceof ApiClientError ? err.message : 'Gebruikersstatus kon niet worden aangepast.');
    } finally {
      setOperatorStatusUpdatingUserId(null);
    }
  };

  const requestLocationSharing = async (userId: string) => {
    if (!incidentId) {
      return;
    }

    setLocationRequestingUserId(userId);
    setRecipientUpdateMessage(null);
    try {
      await api.post(`/incidents/${incidentId}/location/request`, { user_id: userId });
      setRecipientUpdateMessage('Locatieverzoek is naar de gebruiker gestuurd.');
      await liveLocations.reload();
      await timeline.reload();
    } catch (err) {
      setRecipientUpdateMessage(err instanceof ApiClientError ? err.message : 'Locatieverzoek kon niet worden verzonden.');
    } finally {
      setLocationRequestingUserId(null);
    }
  };

  const downloadReport = async () => {
    if (!incidentId) {
      return;
    }

    setReportDownloading(true);
    setReportError(null);
    try {
      const response = await api.download(`/incidents/${incidentId}/report.pdf`);
      const url = URL.createObjectURL(response.blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = response.filename ?? `${incident.data?.reference ?? 'incident'}-rapport.pdf`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      setReportError(err instanceof ApiClientError ? err.message : 'Rapport kon niet worden gedownload.');
    } finally {
      setReportDownloading(false);
    }
  };

  const refreshFlightContext = async () => {
    if (!incidentId) {
      return;
    }

    setFlightRefreshLoading(true);
    setFlightRefreshMessage(null);
    try {
      await api.post(`/incidents/${incidentId}/flight-context/refresh`);
      setFlightRefreshMessage('Drone vluchtinformatie is bijgewerkt.');
      await incident.reload();
    } catch (err) {
      setFlightRefreshMessage(err instanceof ApiClientError ? err.message : 'Drone vluchtinformatie kon niet worden bijgewerkt.');
    } finally {
      setFlightRefreshLoading(false);
    }
  };

  const deleteIncident = async () => {
    if (!incidentId || !incident.data) {
      return;
    }

    const confirmed = window.confirm(`Incident ${incident.data.reference} permanent verwijderen? Bijbehorende opkomst, tijdlijn, live locatiegegevens en opgeslagen rapportdata worden ook verwijderd.`);
    if (!confirmed) {
      return;
    }

    setDeletingIncident(true);
    setIncidentError(null);
    try {
      await api.delete(`/incidents/${incidentId}`);
      navigate('/incidents', { replace: true });
    } catch (err) {
      setIncidentError(err instanceof ApiClientError ? err.message : 'Incident kon niet worden verwijderd.');
    } finally {
      setDeletingIncident(false);
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
          <div className="table-actions">
            {reportAvailable ? (
              <button className="primary-button" type="button" onClick={() => void downloadReport()} disabled={reportDownloading}>
                <Download size={16} /> {reportDownloading ? 'Rapport...' : 'Rapport PDF'}
              </button>
            ) : null}
            {canManageIncidents ? (
              <button className="secondary-button" type="button" onClick={openEditModal}>
                <Pencil size={16} /> Aanpassen
              </button>
            ) : null}
            {canDeleteIncidents ? (
              <button className="danger-button" type="button" onClick={() => void deleteIncident()} disabled={deletingIncident}>
                <Trash2 size={16} /> {deletingIncident ? 'Verwijderen...' : 'Verwijderen'}
              </button>
            ) : null}
          </div>
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
                  <MetaItem icon={<Users size={16} />} label="Teams" value={incidentTeamsLabel(incident.data)} />
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
              {incidentError && !editModalOpen ? <p className="form-error">{incidentError}</p> : null}
              {reportError ? <p className="form-error">{reportError}</p> : null}
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      {showDraftPanel && canManageIncidents ? (
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
                  <span>Teams</span>
                  <strong>{previewTeamsLabel(preview.data)}</strong>
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
                  {canManageDispatches ? <div className="dispatch-toolbar__actions">
                    <button className="secondary-button" type="button" onClick={openEscalationModal} disabled={dispatchAction !== null || latestDispatch.status === 'cancelled' || latestDispatch.status === 'escalated'}>
                      <TrendingUp size={16} /> {dispatchAction === 'escalate' ? 'Opschalen...' : 'Opschalen'}
                    </button>
                    <button className="secondary-button" type="button" onClick={() => void runDispatchAction('realert')} disabled={dispatchAction !== null || latestDispatch.status === 'cancelled' || countResponses(latestDispatch, 'pending') === 0}>
                      <BellRing size={16} /> {dispatchAction === 'realert' ? 'Heralarmeren...' : 'Heralarmeren'}
                    </button>
                  </div> : null}
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
                  {latestDispatch.recipients?.map((recipient) => {
                    const userStatus = recipient.user?.statuses?.[0]?.status;
                    const location = liveLocations.data?.find((item) => item.user_id === recipient.user_id);
                    const canEditOperatorStatus = canOverrideStatus && recipient.response_status === 'accepted' && recipient.user_id !== '';

                    return (
                      <article className={`recipient-row recipient-row--${recipient.response_status}`} key={recipient.id}>
                        <div className="recipient-row__identity">
                          <strong>{recipient.user?.name ?? recipient.user_id}</strong>
                          <span>{recipient.user?.email ?? '-'}</span>
                        </div>
                        <div className="recipient-row__states">
                          <StatusPill value={responseLabel(recipient.response_status)} tone={recipient.response_status === 'accepted' ? 'good' : recipient.response_status === 'declined' ? 'bad' : undefined} />
                          <StatusPill value={operatorStatusLabel(userStatus)} tone={operatorStatusTone(userStatus)} />
                          <StatusPill value={locationSharingLabel(location?.sharing_status)} tone={location?.sharing_status === 'shared' ? 'good' : location?.sharing_status === 'declined' ? 'bad' : 'neutral'} />
                        </div>
                        <div className="recipient-row__time">
                          <span>Reactie</span>
                          <strong>{formatDate(recipient.responded_at)}</strong>
                        </div>
                        {canManageDispatches ? (
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
                        ) : null}
                        {canEditOperatorStatus ? (
                          <div className="table-actions">
                            <button className="secondary-button" type="button" onClick={() => void updateOperatorStatus(recipient.user_id, 'en_route')} disabled={operatorStatusUpdatingUserId === recipient.user_id || userStatus === 'en_route'}>
                              Onderweg
                            </button>
                            <button className="secondary-button" type="button" onClick={() => void updateOperatorStatus(recipient.user_id, 'on_scene')} disabled={operatorStatusUpdatingUserId === recipient.user_id || userStatus === 'on_scene'}>
                              Op locatie
                            </button>
                          </div>
                        ) : null}
                        {recipient.response_note ? <p className="recipient-row__note">{recipient.response_note}</p> : null}
                      </article>
                    );
                  })}
                </div>
                {recipientUpdateMessage ? <p className={recipientUpdateMessage.includes('kon niet') ? 'form-error' : 'form-note'}>{recipientUpdateMessage}</p> : null}
                {canManageDispatches ? (
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
                ) : null}
              </>
            ) : null}
          </div>
        </ResourceState>
      </Panel>

      <Panel title="Kaart en live locaties">
        <ResourceState loading={liveLocations.loading} error={liveLocations.error} empty={false}>
          <LiveLocationMap
            incident={incident.data}
            locations={liveLocations.data ?? []}
            canRequestLocation={canManageDispatches}
            requestingUserId={locationRequestingUserId}
            onRequestLocation={requestLocationSharing}
          />
        </ResourceState>
      </Panel>

      <Panel
        title="Drone vluchtinformatie"
        action={incident.data && canManageIncidents ? (
          <button className="secondary-button" type="button" onClick={() => void refreshFlightContext()} disabled={flightRefreshLoading}>
            <RefreshCw size={16} /> {flightRefreshLoading ? 'Bijwerken...' : 'Bijwerken'}
          </button>
        ) : null}
      >
        <ResourceState loading={incident.loading} error={incident.error} empty={!incident.data}>
          <DroneFlightContextDetail context={incident.data?.drone_flight_context ?? null} />
          {flightRefreshMessage ? <p className={flightRefreshMessage.includes('kon niet') ? 'form-error' : 'form-note'}>{flightRefreshMessage}</p> : null}
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

      {escalationModalOpen && latestDispatch && canManageDispatches ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="incident-escalation-title">
            <header className="modal__header">
              <h2 id="incident-escalation-title">Incident opschalen</h2>
              <button className="icon-button" type="button" onClick={() => setEscalationModalOpen(false)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <div className="panel-body">
              <p className="form-note">
                Opschalen koppelt de gekozen teams aan dit incident en verstuurt direct een extra alarmering naar beschikbare gebruikers in die teams.
              </p>
              <div className="summary-grid">
                <SummaryItem label="Huidige incidentteams" value={incident.data ? incidentTeamsLabel(incident.data) : '-'} />
                <SummaryItem label="Al gealarmeerd" value={dispatchTeamsLabel(dispatches.data ?? [])} />
                <SummaryItem label="Laatste alarmering" value={dispatchStatusLabel(latestDispatch.status)} />
              </div>
              <div>
                <strong>Extra teams</strong>
                {escalationTeams.length > 0 ? (
                  <div className="checkbox-grid checkbox-grid--dense">
                    {escalationTeams.map((team) => (
                      <label className="checkbox-card" key={team.id}>
                        <input
                          type="checkbox"
                          checked={escalationTeamIds.includes(team.id)}
                          onChange={(event) => toggleEscalationTeam(team.id, event.target.checked)}
                        />
                        <span>
                          <strong>{team.code} - {team.name}</strong>
                          <small>{team.alert_teams?.length ? `Alarmeert ook: ${team.alert_teams.map((alertTeam) => alertTeam.code).join(', ')}` : team.type}</small>
                        </span>
                      </label>
                    ))}
                  </div>
                ) : (
                  <p className="form-note">Er zijn geen extra operationele teams beschikbaar die nog niet zijn gealarmeerd.</p>
                )}
              </div>
              {escalationError ? <p className="form-error">{escalationError}</p> : null}
              <div className="form-actions">
                <button className="secondary-button" type="button" onClick={() => setEscalationModalOpen(false)}>Annuleren</button>
                <button className="primary-button" type="button" onClick={() => void runEscalation()} disabled={dispatchAction === 'escalate' || escalationTeamIds.length === 0}>
                  <TrendingUp size={16} /> {dispatchAction === 'escalate' ? 'Opschalen...' : 'Opschalen'}
                </button>
              </div>
            </div>
          </section>
        </div>
      ) : null}

      {editModalOpen && editForm !== null && canManageIncidents ? (
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

function DroneFlightContextDetail({ context }: { context: DroneFlightContext | null }) {
  if (!context) {
    return (
      <div className="drone-flight-empty">
        <Plane size={24} />
        <span>Geen drone vluchtinformatie opgeslagen. Werk de informatie bij zodra de incidentlocatie bekend is.</span>
      </div>
    );
  }

  const weather = context.weather;
  const airspace = context.airspace;

  return (
    <div className="drone-flight-detail">
      <div className="drone-flight-map-card">
        <div>
          <span>Dronekaart</span>
          <strong>{context.location?.label ?? 'Incidentlocatie'}</strong>
          <small>Snapshot: {formatDate(context.generated_at)}</small>
        </div>
        <div className="drone-flight-links">
          {context.map?.aeret_url ? <a href={context.map.aeret_url} target="_blank" rel="noreferrer">Open Aeret kaart</a> : null}
          {context.map?.openstreetmap_url ? <a href={context.map.openstreetmap_url} target="_blank" rel="noreferrer">Open OSM kaart</a> : null}
        </div>
      </div>
      {context.map?.aeret_url ? (
        <iframe className="drone-flight-aeret-frame" title="Aeret dronekaart" src={context.map.aeret_url} loading="lazy" />
      ) : null}
      <div className="drone-flight-grid">
        <FlightDetailCard
          icon={<CloudSun size={18} />}
          title="Weer"
          items={[
            ['Status', providerStatusLabel(weather?.status)],
            ['Samenvatting', weather?.summary ?? '-'],
            ['Temperatuur', formatFlightMetric(weather?.temperature_c, ' C')],
            ['Gevoelstemperatuur', formatFlightMetric(weather?.feels_like_c, ' C')],
            ['Wind', formatFlightMetric(weather?.wind_speed_kmh, ' km/u')],
            ['Windstoten', formatFlightMetric(weather?.wind_gust_kmh, ' km/u')],
            ['Windrichting', formatFlightMetric(weather?.wind_direction_degrees, ' graden')],
            ['Zicht', formatVisibility(weather?.visibility_m)],
            ['Neerslag', formatFlightMetric(weather?.precipitation_mm, ' mm')],
            ['Bewolking', formatFlightMetric(weather?.cloud_cover_percent, '%')],
          ]}
        />
        <FlightDetailCard
          icon={<Plane size={18} />}
          title="Luchtruim"
          items={[
            ['Status', providerStatusLabel(airspace?.status)],
            ['Samenvatting', airspace?.summary ?? '-'],
            ['No-fly zones', String(airspace?.no_fly_zones?.length ?? 0)],
            ['NOTAM', String(airspace?.notams?.length ?? 0)],
            ['Beperkingen', String(airspace?.restrictions?.length ?? 0)],
          ]}
        />
      </div>
      <div className="drone-flight-list-row">
        <FlightList title="No-fly zones" items={airspace?.no_fly_zones ?? []} empty="Geen no-fly zones ontvangen van provider." />
        <FlightList title="NOTAM" items={airspace?.notams ?? []} empty="Geen NOTAM regels ontvangen van provider." />
        <FlightList title="Vliegcheck" items={context.checklist ?? []} empty="Geen checklist opgeslagen." />
      </div>
    </div>
  );
}

function FlightDetailCard({ icon, title, items }: { icon: ReactNode; title: string; items: Array<[string, string]> }) {
  return (
    <article className="drone-flight-card">
      <h4>{icon}{title}</h4>
      <dl>
        {items.map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>{value}</dd>
          </div>
        ))}
      </dl>
    </article>
  );
}

function FlightList({ title, items, empty }: { title: string; items: unknown[]; empty: string }) {
  return (
    <article className="drone-flight-list">
      <h4>{title}</h4>
      {items.length > 0 ? (
        <ul>
          {items.map((item, index) => <li key={index}>{formatFlightItem(item)}</li>)}
        </ul>
      ) : (
        <p>{empty}</p>
      )}
    </article>
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
    teamIds: incident.teams?.length ? incident.teams.map((team) => team.id) : incident.team?.id ? [incident.team.id] : [],
  };
}

function incidentTeamsLabel(incident: Incident): string {
  const teams = incident.teams?.length ? incident.teams : incident.team ? [incident.team] : [];

  return teams.map((team) => `${team.code} - ${team.name}`).join(', ') || '-';
}

function dispatchTargetTeamIds(dispatches: DispatchRequest[]): string[] {
  return Array.from(new Set(dispatches
    .map((dispatch) => dispatch.target_team?.id ?? dispatch.target_team_id)
    .filter((teamId): teamId is string => typeof teamId === 'string' && teamId !== '')));
}

function dispatchTeamsLabel(dispatches: DispatchRequest[]): string {
  const teams = dispatches
    .map((dispatch) => dispatch.target_team)
    .filter((team): team is Team => team !== null && team !== undefined);

  const uniqueTeams = Array.from(new Map(teams.map((team) => [team.id, team])).values());

  return uniqueTeams.map((team) => `${team.code} - ${team.name}`).join(', ') || '-';
}

function previewTeamsLabel(preview?: DispatchPreview | null): string {
  const teams = preview?.teams?.length ? preview.teams : preview?.team ? [preview.team] : [];

  return teams.map((team) => `${team.code} - ${team.name}`).join(', ') || '-';
}

function SummaryItem({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function LiveLocationMap({
  incident,
  locations,
  canRequestLocation,
  requestingUserId,
  onRequestLocation,
}: {
  incident: Incident | null;
  locations: IncidentLiveLocation[];
  canRequestLocation: boolean;
  requestingUserId: string | null;
  onRequestLocation: (userId: string) => Promise<void>;
}) {
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
            src={`https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/${tile.z}/${tile.y}/${tile.x}`}
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
            {canRequestLocation ? <th>Actie</th> : null}
          </tr>
        </thead>
        <tbody>
          {locations.map((location) => {
            const hasCurrentLiveLocation = isCurrentLiveLocation(location);
            const requestDisabled = requestingUserId === location.user_id;

            return (
              <tr key={location.user_id}>
                <td>{location.user?.name ?? location.user_id}</td>
                <td>{locationStatusLabel(location)}</td>
                <td>{location.eta_minutes ? `${location.eta_minutes} min` : '-'}</td>
                <td>{formatDate(location.recorded_at)}</td>
                <td>{location.accuracy_meters ? `${Number(location.accuracy_meters).toFixed(0)} m` : '-'}</td>
                {canRequestLocation && !hasCurrentLiveLocation ? (
                  <td>
                    <button className="secondary-button" type="button" onClick={() => void onRequestLocation(location.user_id)} disabled={requestDisabled}>
                      {requestingUserId === location.user_id ? 'Vragen...' : 'Vraag locatie'}
                    </button>
                  </td>
                ) : canRequestLocation ? (
                  <td><span className="form-note">Live</span></td>
                ) : null}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function formatDate(value?: string | null): string {
  return formatDateTime(value);
}

function isCurrentLiveLocation(location: IncidentLiveLocation): boolean {
  if (location.location_is_current === true) {
    return true;
  }

  if (location.latitude === null || location.latitude === undefined || location.longitude === null || location.longitude === undefined || !location.recorded_at) {
    return false;
  }

  const recordedAt = new Date(location.recorded_at).getTime();
  if (!Number.isFinite(recordedAt)) {
    return false;
  }

  return Date.now() - recordedAt <= LIVE_LOCATION_STALE_MS;
}

function formatFlightMetric(value: unknown, suffix: string): string {
  if (value === null || value === undefined || value === '') {
    return '-';
  }

  return `${value}${suffix}`;
}

function formatVisibility(value: unknown): string {
  const meters = Number(value);
  if (!Number.isFinite(meters)) {
    return '-';
  }

  return `${(meters / 1000).toFixed(1)} km`;
}

function providerStatusLabel(status?: string | null): string {
  switch (status) {
    case 'available':
      return 'Opgehaald';
    case 'linked':
      return 'Gekoppeld';
    case 'not_configured':
      return 'Niet gekoppeld';
    case 'unavailable':
      return 'Niet beschikbaar';
    default:
      return status ?? '-';
  }
}

function formatFlightItem(item: unknown): string {
  if (typeof item === 'string' || typeof item === 'number' || typeof item === 'boolean') {
    return String(item);
  }

  if (item && typeof item === 'object') {
    const record = item as Record<string, unknown>;
    const preferred = record.name ?? record.title ?? record.description ?? record.summary ?? record.message ?? record.identifier ?? record.id;
    if (typeof preferred === 'string' || typeof preferred === 'number') {
      return String(preferred);
    }
  }

  return JSON.stringify(item);
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
    case 'stale':
      return 'locatie verlopen';
    case 'consented':
      return 'toestemming gegeven, wacht op locatie';
    case 'requested':
      return 'verzoek verzonden';
    case 'pending':
      return 'wacht op locatie';
    case 'declined':
      return location.refusal_reason ? `geweigerd (${location.refusal_reason})` : 'geweigerd';
    default:
      return 'niet gevraagd';
  }
}

function locationSharingLabel(status?: IncidentLiveLocation['sharing_status']): string {
  switch (status) {
    case 'shared':
      return 'Locatie gedeeld';
    case 'stale':
      return 'Locatie verlopen';
    case 'consented':
      return 'Toestemming gegeven';
    case 'requested':
      return 'Locatie gevraagd';
    case 'pending':
      return 'Locatie gevraagd';
    case 'declined':
      return 'Locatie geweigerd';
    default:
      return 'Locatie niet gevraagd';
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
