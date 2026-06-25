import { AlertTriangle, CheckCircle2, RadioTower, Send, ShieldAlert, Users } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { useApiResource } from '../../lib/useApiResource';
import type { Asset, AvailabilityStatus, DispatchRequest, Incident } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

export function DashboardPage() {
  const incidents = useApiResource<Incident[]>('/incidents?status=active');
  const dispatches = useApiResource<DispatchRequest[]>('/dispatches');
  const statuses = useApiResource<AvailabilityStatus[]>('/status/users');
  const assets = useApiResource<Asset[]>('/assets');

  const available = statuses.data?.filter((status) => status.is_available).length ?? 0;
  const unavailable = (statuses.data?.length ?? 0) - available;
  const readyAssets = assets.data?.filter((asset) => asset.status === 'ready').length ?? 0;
  const reloadOperationalData = () => {
    void incidents.reload();
    void dispatches.reload();
    void statuses.reload();
    void assets.reload();
  };

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={reloadOperationalData} />
      <section className="kpi-grid">
        <Kpi icon={<RadioTower />} label="Actieve incidenten" value={incidents.data?.length ?? 0} tone="red" />
        <Kpi icon={<Send />} label="Dispatches" value={dispatches.data?.length ?? 0} tone="amber" />
        <Kpi icon={<Users />} label="Beschikbaar" value={available} tone="green" />
        <Kpi icon={<ShieldAlert />} label="Onbeschikbaar" value={unavailable} tone="blue" />
        <Kpi icon={<CheckCircle2 />} label="Assets gereed" value={readyAssets} tone="green" />
        <Kpi icon={<AlertTriangle />} label="Asset issues" value={(assets.data?.length ?? 0) - readyAssets} tone="amber" />
      </section>

      <div className="two-column">
        <Panel title="Actieve incidenten">
          <ResourceState loading={incidents.loading} error={incidents.error} empty={(incidents.data?.length ?? 0) === 0}>
            <table className="data-table">
              <thead>
                <tr>
                  <th>Referentie</th>
                  <th>Titel</th>
                  <th>Prioriteit</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {incidents.data?.map((incident) => (
                  <tr key={incident.id}>
                    <td>{incident.reference}</td>
                    <td>{incident.title}</td>
                    <td><StatusPill value={incident.priority} tone={incident.priority === 'critical' ? 'bad' : 'warn'} /></td>
                    <td><StatusPill value={incident.status} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </ResourceState>
        </Panel>

        <Panel title="Laatste dispatches">
          <ResourceState loading={dispatches.loading} error={dispatches.error} empty={(dispatches.data?.length ?? 0) === 0}>
            <table className="data-table">
              <thead>
                <tr>
                  <th>Incident</th>
                  <th>Prioriteit</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {dispatches.data?.slice(0, 8).map((dispatch) => (
                  <tr key={dispatch.id}>
                    <td>{dispatch.incident?.reference ?? dispatch.incident_id}</td>
                    <td>{dispatch.priority}</td>
                    <td><StatusPill value={dispatch.status} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </ResourceState>
        </Panel>
      </div>
    </div>
  );
}

function Kpi({ icon, label, value, tone }: { icon: React.ReactNode; label: string; value: number; tone: string }) {
  return (
    <article className={`kpi kpi--${tone}`}>
      <div className="kpi__icon">{icon}</div>
      <span>{label}</span>
      <strong>{value}</strong>
    </article>
  );
}
