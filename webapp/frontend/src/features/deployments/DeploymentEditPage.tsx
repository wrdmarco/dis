'use client';

import { type FormEvent, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Deployment, DeploymentFormConfig, Team, User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import type {
  DeploymentRequest,
  DeploymentRequestWorkflowRevision,
} from '../deployment-requests/deploymentRequestWorkflow';
import {
  DeploymentForm,
  deploymentPayload,
  formFromDeployment,
  type DeploymentFormState,
} from './DeploymentsPage';
import { changedDeploymentPayloadRecords } from './deploymentPatch';
import { isSystemAdministrator } from './deploymentStatusFlow';

export function DeploymentEditPage({ deploymentId }: { deploymentId: string }) {
  const router = useRouter();
  const { api, user } = useAuth();
  const deployment = useApiResource<Deployment>(
    `/deployments/${deploymentId}`,
    Boolean(deploymentId),
  );
  const users = useApiResource<User[]>('/users?per_page=200');
  const teams = useApiResource<Team[]>('/teams');
  const deploymentFormConfig = useApiResource<DeploymentFormConfig>('/deployment-form/config?target=web');
  const linkedToDeploymentRequest = deployment.data?.deployment_request_id != null;
  const deploymentRequest = useApiResource<DeploymentRequest>(
    `/deployments/${deploymentId}/deployment-request`,
    linkedToDeploymentRequest,
  );
  const deploymentRequestWorkflow = useApiResource<DeploymentRequestWorkflowRevision>(
    deploymentRequest.data
      ? `/deployment-request-workflow/config?deployment_request_id=${encodeURIComponent(deploymentRequest.data.id)}`
      : '/deployment-request-workflow/config',
    linkedToDeploymentRequest && deploymentRequest.data !== null,
  );
  const [form, setForm] = useState<DeploymentFormState | null>(null);
  const [statusReason, setStatusReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const canManuallyChangeStatus = isSystemAdministrator(user);
  const statusChanged = canManuallyChangeStatus
    && form !== null
    && deployment.data !== null
    && form.status !== deployment.data.status;
  const deploymentRequestOwnedFieldKeys = useMemo(
    () => deploymentRequestOwnedDeploymentFieldKeys(
      deploymentRequest.data,
      deploymentRequestWorkflow.data,
    ),
    [deploymentRequest.data, deploymentRequestWorkflow.data],
  );

  useEffect(() => {
    if (deployment.data) {
      setForm(formFromDeployment(deployment.data));
    }
  }, [deployment.data]);

  const updateDeployment = async (event: FormEvent<HTMLFormElement>) => {
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
      if (deployment.data === null) return;
      const patch = changedDeploymentPayload(
        form,
        formFromDeployment(deployment.data),
        statusChanged,
      );
      await api.patch(`/deployments/${deploymentId}`, {
        ...patch,
        ...(statusChanged ? {
          manual_status_override: true,
          status_reason: statusReason.trim(),
        } : {}),
      });
      router.push(`/inzetten/${deploymentId}`);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'De inzet kon niet worden opgeslagen.');
      setSaving(false);
    }
  };

  const detailPath = `/inzetten/${deploymentId}`;

  return (
    <div className="page-stack deployment-detail-page deployment-edit-page">
      <Panel
        title="Inzet aanpassen"
        action={(
          <Link className="secondary-button" href={detailPath}>
            <ArrowLeft size={16} /> Terug naar inzet
          </Link>
        )}
      >
        <ResourceState
          loading={
            deployment.loading
            || users.loading
            || teams.loading
            || deploymentFormConfig.loading
            || (linkedToDeploymentRequest && (deploymentRequest.loading || deploymentRequestWorkflow.loading))
            || (Boolean(deployment.data) && form === null)
          }
          error={deployment.error ?? deploymentFormConfig.error ?? deploymentRequest.error ?? deploymentRequestWorkflow.error}
          empty={!deployment.data}
        >
          {form ? (
            <DeploymentForm
              form={form}
              users={users.data ?? []}
              teams={teams.data ?? []}
              customFields={deploymentFormConfig.data?.fields ?? []}
              layout={deploymentFormConfig.data?.layout ?? []}
              enforceConfiguredRequiredFixedInputs={false}
              hiddenFieldKeys={deploymentRequestOwnedFieldKeys}
              showStatus={canManuallyChangeStatus}
              usersError={users.error}
              teamsError={teams.error}
              saving={saving}
              error={error}
              extraFields={(
                <>
                  {deploymentRequest.data ? (
                    <div className="form-grid__wide deployment-edit__deployment-request-note">
                      Uitvraagvelden, prioriteit, teams en inzetmiddelen worden vanuit de aanvraag beheerd.
                      {' '}
                      <Link href={`/aanvragen/${deploymentRequest.data.id}`}>Aanvraag openen</Link>
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
              submitLabel="Inzet opslaan"
              onCancel={() => router.push(detailPath)}
              onSubmit={updateDeployment}
              onChange={(updater) => setForm((current) => current === null ? current : updater(current))}
            />
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}

function deploymentRequestOwnedDeploymentFieldKeys(
  deploymentRequest: DeploymentRequest | null,
  workflow: DeploymentRequestWorkflowRevision | null,
): string[] {
  if (deploymentRequest === null || workflow === null) return [];

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
    if (
      field === undefined
      || (field.scope !== 'common' && field.scope !== deploymentRequest.subject_type)
    ) return;

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

function changedDeploymentPayload(
  current: DeploymentFormState,
  baseline: DeploymentFormState,
  includeStatus: boolean,
): Record<string, unknown> {
  const currentPayload = deploymentPayload(current, { includeStatus });
  const baselinePayload = deploymentPayload(baseline, { includeStatus });
  return changedDeploymentPayloadRecords(currentPayload, baselinePayload);
}
