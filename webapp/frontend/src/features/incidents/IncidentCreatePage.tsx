'use client';

import { type FormEvent, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Incident, IncidentFormConfig, Team, User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { createEmptyIncidentForm, IncidentForm, incidentPayload, type IncidentFormState } from './IncidentsPage';

export function IncidentCreatePage() {
  const router = useRouter();
  const { api } = useAuth();
  const users = useApiResource<User[]>('/users?per_page=200');
  const teams = useApiResource<Team[]>('/teams');
  const incidentFormConfig = useApiResource<IncidentFormConfig>('/incident-form/config?target=web');
  const [form, setForm] = useState<IncidentFormState>(createEmptyIncidentForm);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const createIncident = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setCreating(true);
    setError(null);

    try {
      const response = await api.post<Incident>('/incidents', incidentPayload(form));
      router.push(`/incidents/${response.data.id}`);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Incident kon niet worden aangemaakt.');
      setCreating(false);
    }
  };

  return (
    <div className="page-stack incident-detail-page incident-create-page">
      <Panel
        title="Incident aanmaken"
        action={(
          <Link className="secondary-button" href="/incidents">
            <ArrowLeft size={16} /> Terug naar incidenten
          </Link>
        )}
      >
        <ResourceState
          loading={users.loading || teams.loading || incidentFormConfig.loading}
          error={incidentFormConfig.error}
          empty={false}
        >
          <IncidentForm
            form={form}
            users={users.data ?? []}
            teams={teams.data ?? []}
            customFields={incidentFormConfig.data?.fields ?? []}
            layout={incidentFormConfig.data?.layout ?? []}
            usersError={users.error}
            teamsError={teams.error}
            saving={creating}
            error={error}
            submitLabel="Incident aanmaken"
            onCancel={() => router.push('/incidents')}
            onSubmit={createIncident}
            onChange={setForm}
          />
        </ResourceState>
      </Panel>
    </div>
  );
}
