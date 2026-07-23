'use client';

import { type FormEvent, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Incident, IncidentFormConfig, Team, User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { formFromIncident, IncidentForm, incidentPayload, type IncidentFormState } from './IncidentsPage';
import { isSystemAdministrator } from './incidentStatusFlow';

export function IncidentEditPage({ incidentId }: { incidentId: string }) {
  const router = useRouter();
  const { api, user } = useAuth();
  const incident = useApiResource<Incident>(`/incidents/${incidentId}`, Boolean(incidentId));
  const users = useApiResource<User[]>('/users?per_page=200');
  const teams = useApiResource<Team[]>('/teams');
  const incidentFormConfig = useApiResource<IncidentFormConfig>('/incident-form/config?target=web');
  const [form, setForm] = useState<IncidentFormState | null>(null);
  const [statusReason, setStatusReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const canManuallyChangeStatus = isSystemAdministrator(user);
  const statusChanged = canManuallyChangeStatus
    && form !== null
    && incident.data !== null
    && form.status !== incident.data.status;

  useEffect(() => {
    if (incident.data) {
      setForm(formFromIncident(incident.data));
    }
  }, [incident.data]);

  const updateIncident = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (form === null) {
      return;
    }

    if (statusChanged && statusReason.trim() === '') {
      setError('Vul een reden in voor de handmatige statuswijziging.');
      return;
    }

    setSaving(true);
    setError(null);

    try {
      await api.patch(`/incidents/${incidentId}`, {
        ...incidentPayload(form, { includeStatus: statusChanged }),
        ...(statusChanged ? {
          manual_status_override: true,
          status_reason: statusReason.trim(),
        } : {}),
      });
      router.push(`/incidents/${incidentId}`);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Incident kon niet worden opgeslagen.');
      setSaving(false);
    }
  };

  const detailPath = `/incidents/${incidentId}`;

  return (
    <div className="page-stack incident-detail-page incident-edit-page">
      <Panel
        title="Incident aanpassen"
        action={(
          <Link className="secondary-button" href={detailPath}>
            <ArrowLeft size={16} /> Terug naar incident
          </Link>
        )}
      >
        <ResourceState
          loading={incident.loading || users.loading || teams.loading || incidentFormConfig.loading || (Boolean(incident.data) && form === null)}
          error={incident.error ?? incidentFormConfig.error}
          empty={!incident.data}
        >
          {form ? (
            <IncidentForm
              form={form}
              users={users.data ?? []}
              teams={teams.data ?? []}
              customFields={incidentFormConfig.data?.fields ?? []}
              layout={incidentFormConfig.data?.layout ?? []}
              enforceConfiguredRequiredFixedInputs={false}
              showStatus={canManuallyChangeStatus}
              usersError={users.error}
              teamsError={teams.error}
              saving={saving}
              error={error}
              extraFields={statusChanged ? (
                <label className="form-grid__wide">
                  Reden handmatige statuswijziging *
                  <input
                    value={statusReason}
                    maxLength={1000}
                    required
                    onChange={(event) => setStatusReason(event.target.value)}
                  />
                </label>
              ) : null}
              submitLabel="Incident opslaan"
              onCancel={() => router.push(detailPath)}
              onSubmit={updateIncident}
              onChange={(updater) => setForm((current) => current === null ? current : updater(current))}
            />
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}
