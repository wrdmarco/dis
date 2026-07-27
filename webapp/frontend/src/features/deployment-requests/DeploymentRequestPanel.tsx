'use client';

import Link from 'next/link';
import { useEffect } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';
import { DeploymentRequestWorkspace } from './DeploymentRequestWorkspace';
import type {
  DeploymentRequest,
  DeploymentRequestWorkflowRevision,
} from './deploymentRequestWorkflow';

export function DeploymentRequestPanel(props: {
  deploymentId: string;
  canManage: boolean;
  refreshVersion?: number;
}) {
  const { deploymentId, canManage, refreshVersion = 0 } = props;
  const endpoint = `/deployments/${deploymentId}/deployment-request`;
  const deploymentRequest = useApiResource<DeploymentRequest>(endpoint, deploymentId !== '');
  const reloadDeploymentRequestSilently = deploymentRequest.silentReload;
  const workflow = useApiResource<DeploymentRequestWorkflowRevision>(
    deploymentRequest.data
      ? `/deployment-request-workflow/config?deployment_request_id=${encodeURIComponent(deploymentRequest.data.id)}`
      : '/deployment-request-workflow/config',
    canManage && deploymentRequest.data !== null,
  );

  useEffect(() => {
    if (deploymentId === '') return undefined;
    const timer = window.setInterval(() => void reloadDeploymentRequestSilently(), 20_000);
    return () => window.clearInterval(timer);
  }, [deploymentId, reloadDeploymentRequestSilently]);

  useEffect(() => {
    if (refreshVersion > 0) void reloadDeploymentRequestSilently();
  }, [refreshVersion, reloadDeploymentRequestSilently]);

  return (
    <Panel
      title="Uitvraag van de aanvraag"
      action={deploymentRequest.data ? (
        <Link className="secondary-button" href={`/aanvragen/${deploymentRequest.data.id}`}>
          Volledige aanvraag
        </Link>
      ) : null}
    >
      <ResourceState
        loading={deploymentRequest.loading || (canManage && deploymentRequest.data !== null && workflow.loading)}
        error={deploymentRequest.error ?? (canManage ? workflow.error : null)}
        empty={!deploymentRequest.data}
      >
        {deploymentRequest.data ? (
          <DeploymentRequestWorkspace
            deploymentRequest={deploymentRequest.data}
            workflow={workflow.data ?? null}
            canManage={canManage}
            saveEndpoint={endpoint}
            compact
            allowPreparedEditing
            onDeploymentRequestChange={deploymentRequest.mutate}
            onRefresh={reloadDeploymentRequestSilently}
          />
        ) : null}
      </ResourceState>
    </Panel>
  );
}
