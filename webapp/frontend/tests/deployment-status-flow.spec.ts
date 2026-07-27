import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  deploymentLifecycleActionForStatus,
  deploymentStatusPayload,
  isSystemAdministrator,
} from '../src/features/deployments/deploymentStatusFlow';

test('offers only the deployment lifecycle action allowed for the current status', () => {
  expect(deploymentLifecycleActionForStatus('draft')).toBe('cancel');
  expect(deploymentLifecycleActionForStatus('active')).toBe('cancel');
  expect(deploymentLifecycleActionForStatus('dispatching')).toBeNull();
  expect(deploymentLifecycleActionForStatus('in_progress')).toBe('close');
  expect(deploymentLifecycleActionForStatus('resolved')).toBeNull();
  expect(deploymentLifecycleActionForStatus('cancelled')).toBeNull();
});

test('recognizes only the canonical system administrator role for manual status changes', () => {
  expect(isSystemAdministrator({
    roles: [{ name: 'system-administrator' }],
  })).toBe(true);
  expect(isSystemAdministrator({
    roles: [{ name: 'administrator' }, { name: 'deployment-coordinator' }],
  })).toBe(false);
  expect(isSystemAdministrator(null)).toBe(false);
});

test('omits status unless a system administrator edit explicitly includes it', () => {
  expect(deploymentStatusPayload('draft', false)).toEqual({});
  expect(deploymentStatusPayload('in_progress', true)).toEqual({ status: 'in_progress' });
});

test('routes creation through deployment request preparation and keeps deployment edits guarded', () => {
  const createRoute = readFileSync(
    new URL('../app/incidents/new/page.tsx', import.meta.url),
    'utf8',
  );
  const deploymentRequestWorkspace = readFileSync(
    new URL('../src/features/deployment-requests/DeploymentRequestWorkspace.tsx', import.meta.url),
    'utf8',
  );
  const editPage = readFileSync(
    new URL('../src/features/deployments/DeploymentEditPage.tsx', import.meta.url),
    'utf8',
  );
  const deploymentForm = readFileSync(
    new URL('../src/features/deployments/DeploymentsPage.tsx', import.meta.url),
    'utf8',
  );

  expect(createRoute).toContain("redirect('/aanvragen/new')");
  expect(deploymentRequestWorkspace).toContain("`/deployment-requests/${draft.id}/prepare-deployment`");
  expect(deploymentRequestWorkspace).toContain('Er wordt geen alarm verstuurd.');
  expect(editPage).toContain('showStatus={canManuallyChangeStatus}');
  expect(editPage).toContain('changedDeploymentPayload(');
  expect(editPage).toContain('const currentPayload = deploymentPayload(current, { includeStatus })');
  expect(editPage).not.toContain('includeDeploymentRequest');
  expect(editPage).toContain('manual_status_override: true');
  expect(deploymentForm).toContain('return showStatus ? [{ ...item, visible: true }] : [];');
});
