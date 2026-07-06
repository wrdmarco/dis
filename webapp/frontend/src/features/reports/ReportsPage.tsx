import { type FormEvent, type ReactNode, useMemo, useState } from 'react';
import { AlertTriangle, CheckCircle2, Download, FileText, MessageCircleOff, Users } from 'lucide-react';
import Link from 'next/link';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { ConfigurableFormField, DispatchStatistics, DispatchStatisticsIncidentSummary, PilotIncidentReport, PilotReportFormConfig, ReportIncident } from '../../types/api';

export function ReportsPage() {
  const { api } = useAuth();
  const [incidentLimit, setIncidentLimit] = useState(5);
  const [reportDownloadingId, setReportDownloadingId] = useState<string | null>(null);
  const [reportError, setReportError] = useState<string | null>(null);
  const [adminReportTarget, setAdminReportTarget] = useState<{ incident: ReportIncident; user: ReportIncident['missing_pilot_reports'][number] } | null>(null);
  const [adminReportFields, setAdminReportFields] = useState<ConfigurableFormField[]>([]);
  const [adminReportValues, setAdminReportValues] = useState<Record<string, unknown>>({});
  const [adminReportLoading, setAdminReportLoading] = useState(false);
  const [adminReportSaving, setAdminReportSaving] = useState(false);
  const [adminReportError, setAdminReportError] = useState<string | null>(null);
  const resourcePath = useMemo(() => `/reports/dispatch-statistics?incident_limit=${incidentLimit}`, [incidentLimit]);
  const statistics = useApiResource<DispatchStatistics>(resourcePath);
  const reportIncidents = useApiResource<ReportIncident[]>('/reports/incidents?limit=50');
  const summary = statistics.data?.summary;
  const reportSummary = useMemo(() => {
    const incidents = reportIncidents.data ?? [];
    const finalReports = incidents.filter((incident) => incident.report_status === 'final').length;
    const missingReports = incidents.reduce((total, incident) => total + incident.missing_pilot_report_count, 0);
    const submittedReports = incidents.reduce((total, incident) => total + incident.submitted_pilot_report_count, 0);

    return { incidents: incidents.length, finalReports, missingReports, submittedReports };
  }, [reportIncidents.data]);

  async function downloadReport(incident: ReportIncident) {
    setReportDownloadingId(incident.id);
    setReportError(null);

    try {
      const response = await api.download(`/incidents/${incident.id}/report`);
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

  async function openAdminPilotReport(incident: ReportIncident, user: ReportIncident['missing_pilot_reports'][number]) {
    setAdminReportTarget({ incident, user });
    setAdminReportFields([]);
    setAdminReportValues({});
    setAdminReportError(null);
    setAdminReportLoading(true);

    try {
      const [configResponse, reportResponse] = await Promise.all([
        api.get<PilotReportFormConfig>(`/pilot-report/form-config?target=web&user_id=${encodeURIComponent(user.user_id)}`),
        api.get<PilotIncidentReport>(`/incidents/${incident.id}/pilot-reports/${user.user_id}`),
      ]);
      setAdminReportFields(configResponse.data.fields);
      setAdminReportValues(reportResponse.data.custom_fields ?? {});
    } catch (err) {
      setAdminReportError(err instanceof ApiClientError ? err.message : 'Inzetrapport kon niet worden geladen.');
    } finally {
      setAdminReportLoading(false);
    }
  }

  async function saveAdminPilotReport(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (adminReportTarget === null) {
      return;
    }

    setAdminReportSaving(true);
    setAdminReportError(null);
    try {
      await api.patch(`/incidents/${adminReportTarget.incident.id}/pilot-reports/${adminReportTarget.user.user_id}`, {
        custom_fields: adminReportValues,
      });
      setAdminReportTarget(null);
      await reportIncidents.reload();
    } catch (err) {
      setAdminReportError(err instanceof ApiClientError ? err.message : 'Inzetrapport kon niet worden opgeslagen.');
    } finally {
      setAdminReportSaving(false);
    }
  }

  return (
    <div className="page-stack reports-page">
      <div className="stats-grid">
        <StatCard icon={<FileText />} label="Incidentrapporten" value={String(reportSummary.incidents)} />
        <StatCard icon={<CheckCircle2 />} label="Definitief" value={String(reportSummary.finalReports)} tone="good" />
        <StatCard icon={<AlertTriangle />} label="Vluchtrapporten missen" value={String(reportSummary.missingReports)} tone={reportSummary.missingReports > 0 ? 'warn' : 'good'} />
        <StatCard icon={<Users />} label="Vluchtrapporten binnen" value={String(reportSummary.submittedReports)} />
      </div>

      <Panel title="Incidentrapporten">
        <ResourceState loading={reportIncidents.loading} error={reportIncidents.error} empty={(reportIncidents.data?.length ?? 0) === 0}>
          <div className="panel-body">
            {reportError ? <p className="form-error">{reportError}</p> : null}
            <table className="data-table reports-table">
              <thead>
                <tr>
                  <th>Referentie</th>
                  <th>Titel</th>
                  <th>Incident</th>
                  <th>Rapportstatus</th>
                  <th>Team</th>
                  <th>Gesloten</th>
                  <th>Vluchtrapporten</th>
                  <th>Ontbreekt</th>
                  <th>PDF</th>
                </tr>
              </thead>
              <tbody>
                {reportIncidents.data?.map((incident) => (
                  <tr key={incident.id}>
                    <td data-label="Referentie"><Link href={`/incidents/${incident.id}`}>{incident.reference}</Link></td>
                    <td data-label="Titel">{incident.title}</td>
                    <td data-label="Incident"><StatusPill value={incidentStatusLabel(incident.status)} tone={incident.status === 'resolved' ? 'good' : 'warn'} /></td>
                    <td data-label="Rapport"><StatusPill value={incident.report_status === 'final' ? 'Definitief' : 'Concept'} tone={incident.report_status === 'final' ? 'good' : 'warn'} /></td>
                    <td data-label="Team">{incident.team?.code ?? '-'}</td>
                    <td data-label="Gesloten">{formatDateTime(incident.closed_at)}</td>
                    <td data-label="Vluchtrapporten">
                      {incident.submitted_pilot_report_count}/{incident.expected_pilot_report_count}
                    </td>
                    <td data-label="Ontbreekt">
                      <MissingPilotReports incident={incident} onFill={openAdminPilotReport} />
                    </td>
                    <td data-label="Rapport">
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
                        {incident.incident_id ? <Link href={`/incidents/${incident.incident_id}`}>{incident.reference}</Link> : <span>{incident.reference ?? '-'}</span>}
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
                  <td>{incident.id ? <Link href={`/incidents/${incident.id}`}>{incident.reference}</Link> : incident.reference}</td>
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

      {adminReportTarget ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal modal--incident-form" role="dialog" aria-modal="true" aria-labelledby="admin-pilot-report-title">
            <header className="modal__header">
              <h2 id="admin-pilot-report-title">Inzetrapport invullen</h2>
              <button className="icon-button" type="button" onClick={() => setAdminReportTarget(null)} aria-label="Sluiten">×</button>
            </header>
            <form className="form-grid" onSubmit={saveAdminPilotReport}>
              <div className="form-grid__wide">
                <span className="field-label">Namens</span>
                <strong>{adminReportTarget.user.name}</strong>
                <p className="muted-text">{adminReportTarget.incident.reference} - {adminReportTarget.incident.title}</p>
              </div>
              {adminReportLoading ? <p className="form-grid__wide muted-text">Inzetrapport laden...</p> : null}
              {adminReportFields.filter((field) => field.visible).map((field) => (
                <PilotReportField
                  field={field}
                  value={adminReportValues[field.key]}
                  onChange={(value) => setAdminReportValues((current) => ({ ...current, [field.key]: value }))}
                  key={field.key}
                />
              ))}
              {adminReportError ? <p className="form-error form-grid__wide">{adminReportError}</p> : null}
              <div className="form-actions form-grid__wide">
                <button className="secondary-button" type="button" onClick={() => setAdminReportTarget(null)}>Annuleren</button>
                <button className="primary-button" type="submit" disabled={adminReportLoading || adminReportSaving}>
                  {adminReportSaving ? 'Opslaan...' : 'Rapport opslaan'}
                </button>
              </div>
            </form>
          </section>
        </div>
      ) : null}
    </div>
  );
}

function MissingPilotReports({ incident, onFill }: { incident: ReportIncident; onFill: (incident: ReportIncident, user: ReportIncident['missing_pilot_reports'][number]) => void }) {
  if (incident.missing_pilot_report_count === 0) {
    return <span className="muted-text">Compleet</span>;
  }

  return (
    <div className="missing-report-list">
      {incident.missing_pilot_reports.map((report) => (
        <button className="secondary-button" type="button" onClick={() => onFill(incident, report)} key={report.user_id} title={report.email ?? undefined}>
          {report.name}
        </button>
      ))}
    </div>
  );
}

function PilotReportField({ field, value, onChange }: { field: ConfigurableFormField; value: unknown; onChange: (value: unknown) => void }) {
  if (field.type === 'section') {
    return <div className="form-grid__wide section-heading"><h3>{field.label}</h3></div>;
  }

  const label = field.required ? `${field.label} *` : field.label;
  const className = field.width === 'full' ? 'form-grid__wide' : undefined;

  if (field.type === 'textarea') {
    return <label className="form-grid__wide">{label}<textarea value={asFormString(value)} required={field.required} rows={4} onChange={(event) => onChange(event.target.value)} /></label>;
  }

  if (field.type === 'number') {
    return <label className={className}>{label}<input type="number" min="0" value={asFormString(value)} required={field.required} onChange={(event) => onChange(event.target.value === '' ? null : Number(event.target.value))} /></label>;
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
          <label>Start<input type="time" value={flightTime.start} required={field.required} onChange={(event) => onChange({ ...flightTime, start: event.target.value })} /></label>
          <label>Eind<input type="time" value={flightTime.end} required={field.required} onChange={(event) => onChange({ ...flightTime, end: event.target.value })} /></label>
        </div>
      </div>
    );
  }

  if (field.type === 'select') {
    return <label className={className}>{label}<select value={asFormString(value)} required={field.required} onChange={(event) => onChange(event.target.value)}><option value="">Selecteer</option>{(field.options ?? []).map((option) => <option value={option.value} key={option.value}>{option.label}</option>)}</select></label>;
  }

  if (field.type === 'radio') {
    return (
      <div className="form-grid__wide">
        <span className="field-label">{label}</span>
        <div className="checkbox-grid">
          {(field.options ?? []).map((option) => (
            <label className="checkbox-card" key={option.value}>
              <input type="radio" name={`pilot-report-${field.key}`} checked={asFormString(value) === option.value} required={field.required} onChange={() => onChange(option.value)} />
              <span><strong>{option.label}</strong></span>
            </label>
          ))}
        </div>
      </div>
    );
  }

  if (field.type === 'checkbox') {
    return <label className="checkbox-card form-grid__wide"><input type="checkbox" checked={value === true} onChange={(event) => onChange(event.target.checked)} /><span><strong>{label}</strong></span></label>;
  }

  return <label className={className}>{label}<input value={asFormString(value)} required={field.required} onChange={(event) => onChange(event.target.value)} /></label>;
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

function incidentStatusLabel(status: ReportIncident['status']): string {
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

function incidentLink(incident?: DispatchStatisticsIncidentSummary | null): ReactNode {
  if (!incident?.incident_id) {
    return '-';
  }

  return (
    <Link href={`/incidents/${incident.incident_id}`}>
      {incident.reference ?? 'Incident'}
      <span className="inline-date"> {formatDateTime(incident.sent_at)}</span>
    </Link>
  );
}
