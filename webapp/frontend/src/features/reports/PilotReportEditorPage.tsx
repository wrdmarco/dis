'use client';

import { type FormEvent, useEffect, useState } from 'react';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Incident, PilotIncidentReport, PilotReportFormConfig } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { PilotReportField } from './ReportsPage';

export function PilotReportEditorPage({ incidentId, userId }: { incidentId: string; userId: string }) {
  const { api } = useAuth();
  const incident = useApiResource<Incident>(`/incidents/${incidentId}`);
  const report = useApiResource<PilotIncidentReport>(`/incidents/${incidentId}/pilot-reports/${userId}`);
  const config = useApiResource<PilotReportFormConfig>(`/pilot-report/form-config?target=web&user_id=${encodeURIComponent(userId)}`);
  const [values, setValues] = useState<Record<string, unknown>>({});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    if (report.data) {
      setValues(report.data.custom_fields ?? {});
    }
  }, [report.data]);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!report.data || report.data.can_edit === false) {
      return;
    }

    setSaving(true);
    setError(null);
    setMessage(null);
    try {
      const response = await api.patch<PilotIncidentReport>(`/incidents/${incidentId}/pilot-reports/${userId}`, {
        custom_fields: values,
      });
      report.mutate(response.data);
      setMessage('Inzetrapport opgeslagen.');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Inzetrapport kon niet worden opgeslagen.');
    } finally {
      setSaving(false);
    }
  }

  async function finalize() {
    if (report.data?.status !== 'submitted' || report.data.can_edit === false) {
      return;
    }

    setSaving(true);
    setError(null);
    setMessage(null);
    try {
      const response = await api.post<PilotIncidentReport>(`/incidents/${incidentId}/pilot-reports/${userId}/finalize`, {});
      report.mutate(response.data);
      setMessage('Inzetrapport is definitief gemaakt.');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Inzetrapport kon niet definitief worden gemaakt.');
    } finally {
      setSaving(false);
    }
  }

  const loading = incident.loading || report.loading || config.loading;
  const loadError = incident.error ?? report.error ?? config.error;

  return (
    <div className="page-stack incident-detail-page pilot-report-editor-page">
      <Panel
        title="Inzetrapport invullen"
        action={(
          <Link className="secondary-button" href="/reports">
            <ArrowLeft size={16} /> Terug naar rapporten
          </Link>
        )}
      >
        <ResourceState loading={loading} error={loadError} empty={!report.data || !incident.data}>
          {report.data && incident.data ? (
            <form className="form-grid" onSubmit={save}>
              <div className="form-grid__wide">
                <span className="field-label">Namens</span>
                <strong>{report.data.user_name ?? 'Onbekende gebruiker'}</strong>
                <p className="muted-text">{incident.data.reference} - {incident.data.title}</p>
              </div>
              {report.data.can_edit === false ? (
                <p className="form-note form-grid__wide">Dit inzetrapport is definitief en kan niet meer worden aangepast.</p>
              ) : report.data.status === 'submitted' ? (
                <p className="form-note form-grid__wide">Dit inzetrapport is ingediend en blijft wijzigbaar totdat het definitief wordt gemaakt.</p>
              ) : null}
              {(config.data?.fields ?? []).filter((field) => field.visible).map((field) => (
                <PilotReportField
                  field={field}
                  value={values[field.key]}
                  onChange={(value) => setValues((current) => ({ ...current, [field.key]: value }))}
                  disabled={report.data?.can_edit === false}
                  key={field.key}
                />
              ))}
              {error ? <p className="form-error form-grid__wide">{error}</p> : null}
              {message ? <p className="form-note form-grid__wide">{message}</p> : null}
              <div className="form-actions form-grid__wide">
                <Link className="secondary-button" href="/reports">Annuleren</Link>
                <button className="primary-button" type="submit" disabled={saving || report.data.can_edit === false}>
                  {saving ? 'Opslaan...' : 'Rapport opslaan'}
                </button>
                {report.data.status === 'submitted' && report.data.can_edit !== false ? (
                  <button className="secondary-button" type="button" onClick={() => void finalize()} disabled={saving}>
                    Definitief maken
                  </button>
                ) : null}
              </div>
            </form>
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}
