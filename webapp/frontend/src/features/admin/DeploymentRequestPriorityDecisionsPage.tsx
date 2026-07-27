'use client';

import { DeploymentRequestWorkflowStudio } from './DeploymentRequestWorkflowStudio';

export function DeploymentRequestPriorityDecisionsPage() {
  return (
    <div className="page-stack">
      <DeploymentRequestWorkflowStudio mode="decisions" />
    </div>
  );
}
