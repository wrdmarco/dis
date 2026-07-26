'use client';

import { type FormEvent, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Incident, IncidentFormConfig, Team, User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import type { IntakeDossier, IntakeWorkflowRevision } from '../intakes/intakeWorkflow';
import { formFromIncident, IncidentForm, incidentPayload, type IncidentFormState } from './IncidentsPage';
import { changedIncidentPayloadRecords } from './incidentPatch';
import { isSystemAdministrator } from './incidentStatusFlow';

export function IncidentEditPage({ incidentId }: { incidentId: string }) {
  const router = useRouter();
  const { api, user } = useAuth();
  const incident = useApiResource<Incident>(`/incidents/${incidentId}`, Boolean(incidentId));
  const users = useApiResource<User[]>('/users?per_page=200');
  const teams = useApiResource<Team[]>('/teams');
  const incidentFormConfig = useApiResource<IncidentFormConfig>('/incident-form/config?target=web');
  const linkedToIntake = incident.data?.intake_dossier_id != null;
  const intakeDossier = useApiResource<IntakeDossier>(
    `/incidents/${incidentId}/intake-dossier`,
    linkedToIntake,
  );
  const intakeWorkflow = useApiResource<IntakeWorkflowRevision>(
    intakeDossier.data
      ? `/intake-workflow/config?dossier_id=${encodeURIComponent(intakeDossier.data.id)}`
      : '/intake-workflow/config',
    linkedToIntake && intakeDossier.data !== null,
  );
  const [form, setForm] = useState<IncidentFormState | null>(null);
  const [statusReason, setStatusReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const canManuallyChangeStatus = isSystemAdministrator(user);
  const statusChanged = canManuallyChangeStatus
    && form !== null
    && incident.data !== null
    && form.status !== incident.data.status;
  const intakeOwnedFieldKeys = useMemo(
    () => intakeOwnedIncidentFieldKeys(intakeDossier.data, intakeWorkflow.data),
    [intakeDossier.data, intakeWorkflow.data],
  );

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
      if (incident.data === null) return;
      const patch = changedIncidentPayload(
        form,
        formFromIncident(incident.data),
        statusChanged,
      );
      await api.patch(`/incidents/${incidentId}`, {
        ...patch,
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
          loading={
            incident.loading
            || users.loading
            || teams.loading
            || incidentFormConfig.loading
            || (linkedToIntake && (intakeDossier.loading || intakeWorkflow.loading))
            || (Boolean(incident.data) && form === null)
          }
          error={incident.error ?? incidentFormConfig.error ?? intakeDossier.error ?? intakeWorkflow.error}
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
              hiddenFieldKeys={intakeOwnedFieldKeys}
              showStatus={canManuallyChangeStatus}
              usersError={users.error}
              teamsError={teams.error}
              saving={saving}
              error={error}
              extraFields={(
                <>
                  {intakeDossier.data ? (
                    <div className="form-grid__wide incident-edit__intake-note">
                      Uitvraagvelden, prioriteit, teams en inzetmiddelen worden vanuit het meldingsdossier beheerd.
                      {' '}
                      <Link href={`/meldingen/${intakeDossier.data.id}`}>Meldingsdossier openen</Link>
                    </div>
                  ) : null}
                  {statusChanged ? (
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
                </>
              )}
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

function intakeOwnedIncidentFieldKeys(
  dossier: IntakeDossier | null,
  workflow: IntakeWorkflowRevision | null,
): string[] {
  if (dossier === null || workflow === null) return [];

  const legacyMirroredTargets = new Set([
    'requesting_organization',
    'requesting_unit',
    'on_scene_contact_name',
    'on_scene_contact_phone',
    'on_scene_contact_role',
    'required_resources',
  ]);
  const fields = new Map(workflow.configuration.fields.map((field) => [field.key, field]));
  const keys = new Set(['priority', 'teams', 'required_resources', 'custom_field:required_resources']);
  workflow.configuration.bindings.forEach((binding) => {
    const field = fields.get(binding.field_key);
    if (field === undefined || (field.scope !== 'common' && field.scope !== dossier.subject_type)) return;

    if (binding.target === 'location_label') {
      keys.add('location_search');
    } else if (binding.target.startsWith('custom_fields.')) {
      keys.add(`custom_field:${binding.target.slice('custom_fields.'.length)}`);
    } else if (legacyMirroredTargets.has(binding.target)) {
      keys.add(binding.target);
      keys.add(`custom_field:${binding.target}`);
    } else {
      keys.add(binding.target);
    }
  });

  return [...keys];
}

function changedIncidentPayload(
  current: IncidentFormState,
  baseline: IncidentFormState,
  includeStatus: boolean,
): Record<string, unknown> {
  const currentPayload = incidentPayload(current, { includeStatus });
  const baselinePayload = incidentPayload(baseline, { includeStatus });
  return changedIncidentPayloadRecords(currentPayload, baselinePayload);
}
