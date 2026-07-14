import { Activity, AlertTriangle, ArrowRight, CheckCircle2, Clock3, MapPin, RadioTower, Send, ShieldAlert, Users, Wrench } from 'lucide-react';
import Link from 'next/link';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { assetDisplayLabel } from '../../lib/assetLabels';
import { useApiResource } from '../../lib/useApiResource';
import type { Asset, AvailabilityStatus, DispatchRequest, Incident } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

export function DashboardPage() {
  const incidents = useApiResource<Incident[]>('/incidents?status=active');
  const dispatches = useApiResource<DispatchRequest[]>('/dispatches');
  const statuses = useApiResource<AvailabilityStatus[]>('/availability-statuses/users');
  const assets = useApiResource<Asset[]>('/assets');

  const available = statuses.data?.filter((status) => status.is_available).length ?? 0;
  const statusTotal = statuses.data?.length ?? 0;
  const unavailable = statusTotal - available;
  const readyAssets = assets.data?.filter((asset) => asset.status === 'ready').length ?? 0;
  const assetIssues = (assets.data?.length ?? 0) - readyAssets;
  const activeIncidents = incidents.data ?? [];
  const activeDispatches = dispatches.data ?? [];
  const criticalIncidents = activeIncidents.filter((incident) => incident.priority === 'critical').length;
  const highIncidents = activeIncidents.filter((incident) => incident.priority === 'high').length;
  const dispatchingIncidents = activeIncidents.filter((incident) => incident.status === 'dispatching').length;
  const pendingDispatches = activeDispatches.filter((dispatch) => dispatch.status === 'draft' || dispatch.status === 'sent' || dispatch.status === 'escalated').length;
  const acceptedResponses = activeDispatches.reduce((total, dispatch) => total + (dispatch.recipients?.filter((recipient) => recipient.response_status === 'accepted').length ?? 0), 0);
  const pendingResponses = activeDispatches.reduce((total, dispatch) => total + (dispatch.recipients?.filter((recipient) => recipient.response_status === 'pending' || recipient.response_status === 'no_response').length ?? 0), 0);
  const availabilityRatio = statusTotal > 0 ? Math.round((available / statusTotal) * 100) : 100;
  const reloadOperationalData = () => {
    void incidents.reload();
    void dispatches.reload();
    void statuses.reload();
    void assets.reload();
  };

  return (
    <div className="page-stack dashboard-page">
      <RealtimeBridge onOperationalEvent={reloadOperationalData} />

      <section className="dashboard-hero" aria-labelledby="dashboard-title">
        <div className="dashboard-hero__content">
          <span className="dashboard-hero__eyebrow">Live operationeel beeld</span>
          <h2 id="dashboard-title">Incidenten, mensen en middelen in een scanbaar overzicht</h2>
          <div className="dashboard-hero__signals" aria-label="Belangrijkste operationele signalen">
            <Signal label="Kritiek" value={criticalIncidents} tone={criticalIncidents > 0 ? 'bad' : 'good'} />
            <Signal label="Hoog" value={highIncidents} tone={highIncidents > 0 ? 'warn' : 'neutral'} />
            <Signal label="Alarmeren" value={dispatchingIncidents} tone={dispatchingIncidents > 0 ? 'warn' : 'neutral'} />
            <Signal label="Beschikbaar" value={`${availabilityRatio}%`} tone={availabilityRatio >= 60 ? 'good' : 'warn'} />
          </div>
        </div>
        <div className="dashboard-hero__actions">
          <Link className="primary-button" href="/incidents">Incidenten openen <ArrowRight aria-hidden size={16} /></Link>
          <Link className="secondary-button" href="/operational-status">Beschikbaarheid</Link>
        </div>
      </section>

      <section className="kpi-grid dashboard-kpi-grid" aria-label="Operationele kengetallen">
        <Kpi icon={<RadioTower />} label="Actieve incidenten" value={activeIncidents.length} meta={`${criticalIncidents} kritiek`} tone={criticalIncidents > 0 ? 'red' : 'blue'} />
        <Kpi icon={<Send />} label="Dispatches" value={activeDispatches.length} meta={`${pendingDispatches} openstaand`} tone="amber" />
        <Kpi icon={<Users />} label="Beschikbaar" value={available} meta={`${availabilityRatio}% bezetting`} tone="green" />
        <Kpi icon={<ShieldAlert />} label="Niet beschikbaar" value={unavailable} meta={`${statusTotal} totaal`} tone="blue" />
        <Kpi icon={<CheckCircle2 />} label="Assets gereed" value={readyAssets} meta={`${assets.data?.length ?? 0} geregistreerd`} tone="green" />
        <Kpi icon={<AlertTriangle />} label="Asset issues" value={assetIssues} meta={assetIssues === 0 ? 'Geen blokkade' : 'Actie nodig'} tone={assetIssues > 0 ? 'amber' : 'green'} />
      </section>

      <div className="dashboard-grid">
        <Panel title="Actieve incidenten">
          <ResourceState loading={incidents.loading} error={incidents.error} empty={(incidents.data?.length ?? 0) === 0}>
            <div className="dashboard-incident-list">
              {activeIncidents.map((incident) => (
                <Link className="dashboard-incident" href={`/incidents/${incident.id}`} key={incident.id}>
                  <span className={`dashboard-incident__rail dashboard-incident__rail--${incident.priority}`} />
                  <span className="dashboard-incident__main">
                    <strong>{incident.reference}</strong>
                    <span>{incident.title}</span>
                  </span>
                  <span className="dashboard-incident__meta">
                    <StatusPill value={incident.priority} tone={incident.priority === 'critical' ? 'bad' : incident.priority === 'high' ? 'warn' : 'neutral'} />
                    <StatusPill value={incident.status} />
                  </span>
                  <span className="dashboard-incident__location">
                    <MapPin aria-hidden size={15} />
                    {incident.location_label || 'Locatie volgt'}
                  </span>
                </Link>
              ))}
            </div>
          </ResourceState>
        </Panel>

        <Panel title="Dispatch respons">
          <ResourceState loading={dispatches.loading} error={dispatches.error} empty={(dispatches.data?.length ?? 0) === 0}>
            <div className="dashboard-response-summary">
              <MetricTile icon={<CheckCircle2 />} label="Komt / beschikbaar" value={acceptedResponses} tone="good" />
              <MetricTile icon={<Clock3 />} label="Wacht op reactie" value={pendingResponses} tone="warn" />
            </div>
            <div className="dashboard-dispatch-list">
              {activeDispatches.slice(0, 8).map((dispatch) => (
                <article className="dashboard-dispatch" key={dispatch.id}>
                  <div className="dashboard-dispatch__body">
                    <strong>{dispatch.incident?.reference ?? dispatch.incident_id}</strong>
                    <span>{dispatch.message || dispatch.incident?.title || 'Dispatch zonder bericht'}</span>
                  </div>
                  <div className="dashboard-dispatch__state">
                    <StatusPill value={dispatch.priority} tone={dispatch.priority === 'critical' ? 'bad' : dispatch.priority === 'high' ? 'warn' : 'neutral'} />
                    <StatusPill value={dispatch.status} />
                  </div>
                </article>
              ))}
            </div>
          </ResourceState>
        </Panel>
      </div>

      <div className="dashboard-grid dashboard-grid--secondary">
        <Panel title="Beschikbaarheid">
          <ResourceState loading={statuses.loading} error={statuses.error} empty={(statuses.data?.length ?? 0) === 0}>
            <div className="dashboard-availability">
              <div className="dashboard-gauge">
                <svg className="dashboard-gauge__ring" viewBox="0 0 42 42" aria-hidden="true">
                  <circle className="dashboard-gauge__track" cx="21" cy="21" r="16" pathLength="100" />
                  <circle
                    className="dashboard-gauge__value"
                    cx="21"
                    cy="21"
                    r="16"
                    pathLength="100"
                    strokeDasharray={`${availabilityRatio} ${100 - availabilityRatio}`}
                  />
                </svg>
                <span>{availabilityRatio}%</span>
                <small>beschikbaar</small>
              </div>
              <div className="dashboard-availability__list">
                {statuses.data?.slice(0, 6).map((status) => (
                  <div className="dashboard-person" key={status.id}>
                    <span className={`dashboard-person__dot ${status.is_available ? 'dashboard-person__dot--available' : ''}`} />
                    <strong>{status.user?.name ?? status.user_id}</strong>
                    <StatusPill value={status.status} tone={status.is_available ? 'good' : 'neutral'} />
                  </div>
                ))}
              </div>
            </div>
          </ResourceState>
        </Panel>

        <Panel title="Middelenstatus">
          <ResourceState loading={assets.loading} error={assets.error} empty={(assets.data?.length ?? 0) === 0}>
            <div className="dashboard-asset-list">
              {assets.data?.slice(0, 6).map((asset) => (
                <div className="dashboard-asset" key={asset.id}>
                  <span className={`dashboard-asset__icon dashboard-asset__icon--${asset.status}`}>
                    {asset.status === 'ready' ? <CheckCircle2 aria-hidden size={17} /> : <Wrench aria-hidden size={17} />}
                  </span>
                  <div className="dashboard-asset__body">
                    <strong>{asset.name}</strong>
                    <span>{assetDisplayLabel(asset)}</span>
                  </div>
                  <StatusPill value={asset.status} tone={asset.status === 'ready' ? 'good' : asset.status === 'maintenance' || asset.status === 'unavailable' ? 'warn' : 'neutral'} />
                </div>
              ))}
            </div>
          </ResourceState>
        </Panel>
      </div>
    </div>
  );
}

function Signal({ label, value, tone }: { label: string; value: number | string; tone: 'good' | 'warn' | 'bad' | 'neutral' }) {
  return (
    <span className={`dashboard-signal dashboard-signal--${tone}`}>
      <strong>{value}</strong>
      {label}
    </span>
  );
}

function Kpi({ icon, label, value, meta, tone }: { icon: React.ReactNode; label: string; value: number; meta: string; tone: string }) {
  return (
    <article className={`kpi kpi--${tone}`}>
      <div className="kpi__top">
        <div className="kpi__icon">{icon}</div>
        <Activity aria-hidden size={15} className="kpi__pulse" />
      </div>
      <span>{label}</span>
      <strong>{value}</strong>
      <small>{meta}</small>
    </article>
  );
}

function MetricTile({ icon, label, value, tone }: { icon: React.ReactNode; label: string; value: number; tone: 'good' | 'warn' }) {
  return (
    <div className={`dashboard-metric dashboard-metric--${tone}`}>
      {icon}
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}
