'use client';

import Link from 'next/link';
import { useEffect } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import { RealtimeBridge } from '../realtime/RealtimeBridge';
import { DeploymentRequestWorkspace } from './DeploymentRequestWorkspace';
import type {
  DeploymentRequest,
  DeploymentRequestWorkflowRevision,
} from './deploymentRequestWorkflow';

export function DeploymentRequestDetailPage({
  deploymentRequestId,
}: {
  deploymentRequestId: string;
}) {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('deployments.manage');
  const deploymentRequest = useApiResource<DeploymentRequest>(
    `/deployment-requests/${deploymentRequestId}`,
    deploymentRequestId !== '',
  );
  const reloadDeploymentRequestSilently = deploymentRequest.silentReload;
  const workflow = useApiResource<DeploymentRequestWorkflowRevision>(
    `/deployment-request-workflow/config?deployment_request_id=${encodeURIComponent(deploymentRequestId)}`,
    canManage && deploymentRequestId !== '',
  );

  useEffect(() => {
    if (!deploymentRequestId) return undefined;
    const timer = window.setInterval(() => void reloadDeploymentRequestSilently(), 20_000);
    return () => window.clearInterval(timer);
  }, [deploymentRequestId, reloadDeploymentRequestSilently]);

  return (
    <div className="page-stack deployment-request-detail-page">
      <RealtimeBridge onDeploymentRequestEvent={() => void reloadDeploymentRequestSilently()} />
      <Panel
        title="Aanvraag"
        action={<Link className="secondary-button" href="/aanvragen">Terug naar aanvragen</Link>}
      >
        <ResourceState
          loading={deploymentRequest.loading || (canManage && workflow.loading)}
          error={deploymentRequest.error ?? (canManage ? workflow.error : null)}
          empty={!deploymentRequest.data}
        >
          {deploymentRequest.data ? (
            <DeploymentRequestWorkspace
              deploymentRequest={deploymentRequest.data}
              workflow={workflow.data ?? null}
              canManage={canManage}
              onDeploymentRequestChange={deploymentRequest.mutate}
              onRefresh={reloadDeploymentRequestSilently}
            />
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}
