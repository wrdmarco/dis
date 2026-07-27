import { type ReactNode, useMemo, useState } from 'react';
import { AlertTriangle, CheckCircle2, Download, FileText, MessageCircleOff, Users } from 'lucide-react';
import Link from 'next/link';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { ConfigurableFormField, DispatchStatistics, DispatchStatisticsDeploymentSummary, ReportDeployment } from '../../types/api';

export function ReportsPage() {
  const { api, hasPermission } = useAuth();
  const [deploymentLimit, setDeploymentLimit] = useState(5);
  const [reportDownloadingId, setReportDownloadingId] = useState<string | null>(null);
  const [reportError, setReportError] = useState<string | null>(null);
  const canManageDeployments = hasPermission('deployments.manage');
  const resourcePath = useMemo(
    () => `/reports/dispatch-statistics?deployment_limit=${deploymentLimit}`,
    [deploymentLimit],
  );
  const statistics = useApiResource<DispatchStatistics>(resourcePath);
  const reportDeployments = useApiResource<ReportDeployment[]>('/reports/deployments?limit=50');
  const summary = statistics.data?.summary;
  const reportSummary = useMemo(() => {
    const deployments = reportDeployments.data ?? [];
    const finalReports = deployments.filter((deployment) => deployment.report_status === 'final').length;
    const missingReports = deployments.reduce((total, deployment) => total + deployment.missing_pilot_report_count, 0);
    const submittedReports = deployments.reduce((total, deployment) => total + deployment.submitted_pilot_report_count, 0);

    return { deployments: deployments.length, finalReports, missingReports, submittedReports };
  }, [reportDeployments.data]);

  async function downloadReport(deployment: ReportDeployment) {
    setReportDownloadingId(deployment.id);
    setReportError(null);

    try {
      const response = await api.download(`/deployments/${deployment.id}/report`);
      const url = URL.createObjectURL(response.blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = response.filename ?? `${deployment.reference}-rapport.pdf`;
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
      <div className="stats-grid">
        <StatCard icon={<FileText />} label="Inzetrapporten" value={String(reportSummary.deployments)} />
        <StatCard icon={<CheckCircle2 />} label="Definitief" value={String(reportSummary.finalReports)} tone="good" />
        <StatCard icon={<AlertTriangle />} label="Vluchtrapporten missen" value={String(reportSummary.missingReports)} tone={reportSummary.missingReports > 0 ? 'warn' : 'good'} />
        <StatCard icon={<Users />} label="Vluchtrapporten binnen" value={String(reportSummary.submittedReports)} />
      </div>

      <Panel title="Inzetrapporten">
        <ResourceState loading={reportDeployments.loading} error={reportDeployments.error} empty={(reportDeployments.data?.length ?? 0) === 0}>
          <div className="panel-body">
            {reportError ? <p className="form-error">{reportError}</p> : null}
            <table className="data-table reports-table">
              <thead>
                <tr>
                  <th scope="col">Referentie</th>
                  <th scope="col">Titel</th>
                  <th scope="col">Inzetstatus</th>
                  <th scope="col">Rapportstatus</th>
                  <th scope="col">Team</th>
                  <th scope="col">Gesloten</th>
                  <th scope="col">Vluchtrapporten</th>
                  <th scope="col">Status inzetrapporten</th>
                  <th scope="col">PDF</th>
                </tr>
              </thead>
              <tbody>
                {reportDeployments.data?.map((deployment) => (
                  <tr key={deployment.id}>
                    <td data-label="Referentie"><Link href={`/inzetten/${deployment.id}`}>{deployment.reference}</Link></td>
                    <td data-label="Titel">{deployment.title}</td>
                    <td data-label="Inzetstatus"><StatusPill value={deploymentStatusLabel(deployment.status)} tone={deployment.status === 'resolved' ? 'good' : 'warn'} /></td>
                    <td data-label="Rapport"><StatusPill value={deployment.report_status === 'final' ? 'Definitief' : 'Concept'} tone={deployment.report_status === 'final' ? 'good' : 'warn'} /></td>
                    <td data-label="Team">{deployment.team?.code ?? '-'}</td>
                    <td data-label="Gesloten">{formatDateTime(deployment.closed_at)}</td>
                    <td data-label="Vluchtrapporten">
                      {deployment.submitted_pilot_report_count}/{deployment.expected_pilot_report_count}
                    </td>
                    <td data-label="Status inzetrapporten">
                      <MissingPilotReports deployment={deployment} canManage={canManageDeployments} />
                    </td>
                    <td data-label="Rapport">
                      <button className="secondary-button" type="button" onClick={() => void downloadReport(deployment)} disabled={reportDownloadingId === deployment.id}>
                        {reportDownloadingId === deployment.id ? <FileText size={16} /> : <Download size={16} />}
                        {reportDownloadingId === deployment.id ? 'Maken...' : 'PDF'}
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
            Laatste inzetten
            <select value={deploymentLimit} onChange={(event) => setDeploymentLimit(Number(event.target.value))}>
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
            Gebaseerd op {inzetCountLabel(statistics.data?.scope.deployment_count ?? 0)} binnen de laatste {inzetCountLabel(statistics.data?.scope.deployment_limit ?? deploymentLimit)}.
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
                  <SummaryItem label="Laatste inzet" value={deploymentLink(userStat.last_deployment)} />
                  <SummaryItem label="Laatste alarmering" value={deploymentLink(userStat.last_alert)} />
                </div>
                {userStat.recent_no_response.length > 0 ? (
                  <div className="recent-list">
                    <span className="field-label">Laatste alarmeringen zonder reactie</span>
                    {userStat.recent_no_response.map((deployment) => (
                      <div key={`${userStat.user?.id}-${deployment.deployment_id}-${deployment.sent_at}`}>
                        {deployment.deployment_id ? <Link href={`/inzetten/${deployment.deployment_id}`}>{deployment.reference}</Link> : <span>{deployment.reference ?? '-'}</span>}
                        <span>{deployment.title ?? '-'}</span>
                        <small>{formatDateTime(deployment.sent_at)}</small>
                      </div>
                    ))}
                  </div>
                ) : null}
              </article>
            ))}
          </div>
        </ResourceState>
      </Panel>

      <Panel title="Inzetten in selectie">
        <ResourceState loading={statistics.loading} error={statistics.error} empty={(statistics.data?.deployments.length ?? 0) === 0}>
          <table className="data-table">
            <thead>
              <tr>
                <th scope="col">Referentie</th>
                <th scope="col">Titel</th>
                <th scope="col">Verstuurd</th>
                <th scope="col">Ontvangers</th>
                <th scope="col">Komt</th>
                <th scope="col">Komt niet</th>
                <th scope="col">Geen reactie</th>
              </tr>
            </thead>
            <tbody>
              {statistics.data?.deployments.map((deployment) => (
                <tr key={deployment.id ?? deployment.reference}>
                  <td>{deployment.id ? <Link href={`/inzetten/${deployment.id}`}>{deployment.reference}</Link> : deployment.reference}</td>
                  <td>{deployment.title ?? '-'}</td>
                  <td>{formatDateTime(deployment.sent_at)}</td>
                  <td>{deployment.total_alerts}</td>
                  <td>{deployment.accepted}</td>
                  <td>{deployment.declined}</td>
                  <td><StatusPill value={`${deployment.no_response_rate}%`} tone={deployment.no_response_rate > 25 ? 'bad' : deployment.no_response_rate > 0 ? 'warn' : 'good'} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>

    </div>
  );
}

function MissingPilotReports({ deployment, canManage }: { deployment: ReportDeployment; canManage: boolean }) {
  const unfinalized = deployment.unfinalized_pilot_reports ?? [];
  if (deployment.missing_pilot_report_count === 0 && unfinalized.length === 0) {
    return <span className="muted-text">Compleet en definitief</span>;
  }

  return (
    <div className="missing-report-list">
      {unfinalized.map((report) => canManage ? (
        <Link className="secondary-button" href={`/reports/deployments/${deployment.id}/pilot-reports/${report.user_id}`} key={`unfinalized-${report.user_id}`} title={report.email ?? undefined}>
          {report.name} definitief maken
        </Link>
      ) : <span key={`unfinalized-${report.user_id}`}>{report.name}: ingediend</span>)}
      {deployment.missing_pilot_reports.map((report) => canManage ? (
        <Link className="secondary-button" href={`/reports/deployments/${deployment.id}/pilot-reports/${report.user_id}`} key={`missing-${report.user_id}`} title={report.email ?? undefined}>
          {report.name} invullen
        </Link>
      ) : <span key={`missing-${report.user_id}`}>{report.name}: ontbreekt</span>)}
    </div>
  );
}

export function PilotReportField({ field, value, onChange, disabled = false }: { field: ConfigurableFormField; value: unknown; onChange: (value: unknown) => void; disabled?: boolean }) {
  if (field.type === 'section') {
    return <div className="form-grid__wide section-heading"><h3>{field.label}</h3></div>;
  }

  const label = field.required ? `${field.label} *` : field.label;
  const className = field.width === 'full' ? 'form-grid__wide' : undefined;

  if (field.type === 'textarea') {
    return <label className="form-grid__wide">{label}<textarea value={asFormString(value)} required={field.required} rows={4} disabled={disabled} onChange={(event) => onChange(event.target.value)} /></label>;
  }

  if (field.type === 'number') {
    return <label className={className}>{label}<input type="number" min="0" value={asFormString(value)} required={field.required} disabled={disabled} onChange={(event) => onChange(event.target.value === '' ? null : Number(event.target.value))} /></label>;
  }

  if (field.type === 'phone') {
    return (
      <label className={className}>
        {label}
        <input
          type="tel"
          inputMode="tel"
          pattern={phonePattern(field)}
          placeholder={phonePlaceholder(field)}
          title={`Gebruik een internationaal nummer met ${phoneCountryLabels(field)}.`}
          value={asFormString(value)}
          required={field.required}
          disabled={disabled}
          onChange={(event) => onChange(event.target.value)}
        />
      </label>
    );
  }

  if (field.type === 'flight_time') {
    const flightTime = flightTimeValue(value);
    return (
      <div className="form-grid__wide">
        <span className="field-label">{label}</span>
        <div className="form-grid">
          <label>Start<input type="time" value={flightTime.start} required={field.required} disabled={disabled} onChange={(event) => onChange({ ...flightTime, start: event.target.value })} /></label>
          <label>Eind<input type="time" value={flightTime.end} required={field.required} disabled={disabled} onChange={(event) => onChange({ ...flightTime, end: event.target.value })} /></label>
        </div>
      </div>
    );
  }

  if (field.type === 'select') {
    return <label className={className}>{label}<select value={asFormString(value)} required={field.required} disabled={disabled} onChange={(event) => onChange(event.target.value)}><option value="">Selecteer</option>{(field.options ?? []).map((option) => <option value={option.value} key={option.value}>{option.label}</option>)}</select></label>;
  }

  if (field.type === 'radio') {
    return (
      <div className="form-grid__wide">
        <span className="field-label">{label}</span>
        <div className="checkbox-grid">
          {(field.options ?? []).map((option) => (
            <label className="checkbox-card" key={option.value}>
              <input type="radio" name={`pilot-report-${field.key}`} checked={asFormString(value) === option.value} required={field.required} disabled={disabled} onChange={() => onChange(option.value)} />
              <span><strong>{option.label}</strong></span>
            </label>
          ))}
        </div>
      </div>
    );
  }

  if (field.type === 'checkbox') {
    return <label className="checkbox-card form-grid__wide"><input type="checkbox" checked={value === true} disabled={disabled} onChange={(event) => onChange(event.target.checked)} /><span><strong>{label}</strong></span></label>;
  }

  return <label className={className}>{label}<input value={asFormString(value)} required={field.required} disabled={disabled} onChange={(event) => onChange(event.target.value)} /></label>;
}

function asFormString(value: unknown): string {
  return typeof value === 'string' || typeof value === 'number' ? String(value) : '';
}

function phoneCountries(field: ConfigurableFormField): string[] {
  const supported = ['31', '32'];
  const values = (field.phone_countries ?? []).filter((country) => supported.includes(country));
  return values.length > 0 ? values : supported;
}

function phonePattern(field: ConfigurableFormField): string {
  return `^\\+(${phoneCountries(field).join('|')})[\\s-]?[1-9](?:[\\s-]?[0-9]){7,11}$`;
}

function phonePlaceholder(field: ConfigurableFormField): string {
  return phoneCountries(field).includes('31') ? '+31612345678' : '+32470123456';
}

function phoneCountryLabels(field: ConfigurableFormField): string {
  return phoneCountries(field).map((country) => `+${country}`).join(' of ');
}

function flightTimeValue(value: unknown): { start: string; end: string } {
  if (value !== null && typeof value === 'object') {
    const candidate = value as { start?: unknown; end?: unknown };
    return {
      start: typeof candidate.start === 'string' ? candidate.start : '',
      end: typeof candidate.end === 'string' ? candidate.end : '',
    };
  }

  return { start: '', end: '' };
}

function deploymentStatusLabel(status: ReportDeployment['status']): string {
  switch (status) {
    case 'resolved':
      return 'Afgerond';
    case 'cancelled':
      return 'Geannuleerd';
    case 'draft':
      return 'Concept';
    case 'active':
      return 'Actief';
    case 'dispatching':
      return 'Alarmeren';
    case 'in_progress':
      return 'Uitvoering';
    default:
      return status;
  }
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

function deploymentLink(deployment?: DispatchStatisticsDeploymentSummary | null): ReactNode {
  if (!deployment?.deployment_id) {
    return '-';
  }

  return (
    <Link href={`/inzetten/${deployment.deployment_id}`}>
      {deployment.reference ?? 'Inzet'}
      <span className="inline-date"> {formatDateTime(deployment.sent_at)}</span>
    </Link>
  );
}

function inzetCountLabel(count: number): string {
  return `${count} ${count === 1 ? 'inzet' : 'inzetten'}`;
}
