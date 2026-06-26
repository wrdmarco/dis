import { type ReactNode, useMemo, useState } from 'react';
import { AlertTriangle, CheckCircle2, Download, FileText, MessageCircleOff, Users } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { DispatchStatistics, DispatchStatisticsIncidentSummary, ReportIncident } from '../../types/api';

export function ReportsPage() {
  const { api } = useAuth();
  const [incidentLimit, setIncidentLimit] = useState(5);
  const [reportDownloadingId, setReportDownloadingId] = useState<string | null>(null);
  const [reportError, setReportError] = useState<string | null>(null);
  const resourcePath = useMemo(() => `/reports/dispatch-statistics?incident_limit=${incidentLimit}`, [incidentLimit]);
  const statistics = useApiResource<DispatchStatistics>(resourcePath);
  const reportIncidents = useApiResource<ReportIncident[]>('/reports/incidents?limit=50');
  const summary = statistics.data?.summary;

  async function downloadReport(incident: ReportIncident) {
    setReportDownloadingId(incident.id);
    setReportError(null);

    try {
      const response = await api.download(`/incidents/${incident.id}/report.pdf`);
      const url = URL.createObjectURL(response.blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = response.filename ?? `${incident.reference}-rapport.pdf`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      setReportError(err instanceof ApiClientError ? err.message : 'Rapport kon niet worden gedownload.');
    } finally {
      setReportDownloadingId(null);
    }
  }

  return (
    <div className="page-stack reports-page">
      <Panel title="Incidentrapporten">
        <ResourceState loading={reportIncidents.loading} error={reportIncidents.error} empty={(reportIncidents.data?.length ?? 0) === 0}>
          <div className="panel-body">
            {reportError ? <p className="form-error">{reportError}</p> : null}
            <table className="data-table">
              <thead>
                <tr>
                  <th>Referentie</th>
                  <th>Titel</th>
                  <th>Status</th>
                  <th>Team</th>
                  <th>Gesloten</th>
                  <th>Ontvangers</th>
                  <th>Komt</th>
                  <th>Geen reactie</th>
                  <th>Rapport</th>
                </tr>
              </thead>
              <tbody>
                {reportIncidents.data?.map((incident) => (
                  <tr key={incident.id}>
                    <td><Link to={`/incidents/${incident.id}`}>{incident.reference}</Link></td>
                    <td>{incident.title}</td>
                    <td><StatusPill value={incident.status} tone={incident.status === 'resolved' ? 'good' : 'warn'} /></td>
                    <td>{incident.team?.code ?? '-'}</td>
                    <td>{formatDateTime(incident.closed_at)}</td>
                    <td>{incident.recipient_count}</td>
                    <td>{incident.accepted}</td>
                    <td>{incident.no_response}</td>
                    <td>
                      <button className="secondary-button" type="button" onClick={() => void downloadReport(incident)} disabled={reportDownloadingId === incident.id}>
                        {reportDownloadingId === incident.id ? <FileText size={16} /> : <Download size={16} />}
                        {reportDownloadingId === incident.id ? 'Maken...' : 'PDF'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </ResourceState>
      </Panel>

      <Panel
        title="Statistieken"
        action={(
          <label className="compact-control">
            Laatste meldingen
            <select value={incidentLimit} onChange={(event) => setIncidentLimit(Number(event.target.value))}>
              <option value={5}>5</option>
              <option value={10}>10</option>
              <option value={25}>25</option>
              <option value={50}>50</option>
            </select>
          </label>
        )}
      >
        <ResourceState loading={statistics.loading} error={statistics.error} empty={!statistics.data}>
          <div className="stats-grid">
            <StatCard icon={<Users />} label="Alarmeringen" value={String(summary?.total_alerts ?? 0)} />
            <StatCard icon={<CheckCircle2 />} label="Komt" value={`${summary?.accepted_rate ?? 0}%`} sub={`${summary?.accepted ?? 0} reacties`} tone="good" />
            <StatCard icon={<AlertTriangle />} label="Komt niet" value={`${summary?.declined_rate ?? 0}%`} sub={`${summary?.declined ?? 0} reacties`} tone="warn" />
            <StatCard icon={<MessageCircleOff />} label="Geen reactie" value={`${summary?.no_response_rate ?? 0}%`} sub={`${summary?.no_response ?? 0} zonder reactie`} tone="bad" />
          </div>
          <p className="muted-text">
            Gebaseerd op {statistics.data?.scope.incident_count ?? 0} incident(en) binnen de laatste {statistics.data?.scope.incident_limit ?? incidentLimit} meldingen.
          </p>
        </ResourceState>
      </Panel>

      <Panel title="Gebruikers zonder reactie">
        <ResourceState loading={statistics.loading} error={statistics.error} empty={(statistics.data?.users.length ?? 0) === 0}>
          <div className="user-stats-list">
            {statistics.data?.users.map((userStat) => (
              <article className="user-stat-card" key={userStat.user?.id ?? userStat.user?.email ?? 'unknown'}>
                <div className="user-stat-card__header">
                  <div>
                    <h3>{userStat.user?.name ?? 'Onbekende gebruiker'}</h3>
                    <span>{userStat.user?.email ?? '-'}</span>
                  </div>
                  <strong>{userStat.no_response_rate}%</strong>
                </div>
                <div className="summary-grid summary-grid--compact">
                  <SummaryItem label="Totaal" value={String(userStat.total_alerts)} />
                  <SummaryItem label="Komt" value={String(userStat.accepted)} />
                  <SummaryItem label="Komt niet" value={String(userStat.declined)} />
                  <SummaryItem label="Geen reactie" value={String(userStat.no_response)} />
                  <SummaryItem label="Laatste inzet" value={incidentLink(userStat.last_deployment)} />
                  <SummaryItem label="Laatste melding" value={incidentLink(userStat.last_alert)} />
                </div>
                {userStat.recent_no_response.length > 0 ? (
                  <div className="recent-list">
                    <span className="field-label">Laatste meldingen zonder reactie</span>
                    {userStat.recent_no_response.map((incident) => (
                      <div key={`${userStat.user?.id}-${incident.incident_id}-${incident.sent_at}`}>
                        {incident.incident_id ? <Link to={`/incidents/${incident.incident_id}`}>{incident.reference}</Link> : <span>{incident.reference ?? '-'}</span>}
                        <span>{incident.title ?? '-'}</span>
                        <small>{formatDateTime(incident.sent_at)}</small>
                      </div>
                    ))}
                  </div>
                ) : null}
              </article>
            ))}
          </div>
        </ResourceState>
      </Panel>

      <Panel title="Meldingen in selectie">
        <ResourceState loading={statistics.loading} error={statistics.error} empty={(statistics.data?.incidents.length ?? 0) === 0}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Referentie</th>
                <th>Titel</th>
                <th>Verstuurd</th>
                <th>Ontvangers</th>
                <th>Komt</th>
                <th>Komt niet</th>
                <th>Geen reactie</th>
              </tr>
            </thead>
            <tbody>
              {statistics.data?.incidents.map((incident) => (
                <tr key={incident.id ?? incident.reference}>
                  <td>{incident.id ? <Link to={`/incidents/${incident.id}`}>{incident.reference}</Link> : incident.reference}</td>
                  <td>{incident.title ?? '-'}</td>
                  <td>{formatDateTime(incident.sent_at)}</td>
                  <td>{incident.total_alerts}</td>
                  <td>{incident.accepted}</td>
                  <td>{incident.declined}</td>
                  <td><StatusPill value={`${incident.no_response_rate}%`} tone={incident.no_response_rate > 25 ? 'bad' : incident.no_response_rate > 0 ? 'warn' : 'good'} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}

function StatCard({ icon, label, value, sub, tone = 'neutral' }: { icon: ReactNode; label: string; value: string; sub?: string; tone?: 'neutral' | 'good' | 'warn' | 'bad' }) {
  return (
    <div className={`stat-card stat-card--${tone}`}>
      <span>{icon}</span>
      <div>
        <small>{label}</small>
        <strong>{value}</strong>
        {sub ? <em>{sub}</em> : null}
      </div>
    </div>
  );
}

function SummaryItem({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function incidentLink(incident?: DispatchStatisticsIncidentSummary | null): ReactNode {
  if (!incident?.incident_id) {
    return '-';
  }

  return (
    <Link to={`/incidents/${incident.incident_id}`}>
      {incident.reference ?? 'Incident'}
      <span className="inline-date"> {formatDateTime(incident.sent_at)}</span>
    </Link>
  );
}
