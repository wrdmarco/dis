import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  incidentLifecycleActionForStatus,
  incidentStatusPayload,
  isSystemAdministrator,
} from '../src/features/incidents/incidentStatusFlow';

test('offers only the incident lifecycle action allowed for the current status', () => {
  expect(incidentLifecycleActionForStatus('draft')).toBe('cancel');
  expect(incidentLifecycleActionForStatus('active')).toBe('cancel');
  expect(incidentLifecycleActionForStatus('dispatching')).toBeNull();
  expect(incidentLifecycleActionForStatus('in_progress')).toBe('close');
  expect(incidentLifecycleActionForStatus('resolved')).toBeNull();
  expect(incidentLifecycleActionForStatus('cancelled')).toBeNull();
});

test('recognizes only the canonical system administrator role for manual status changes', () => {
  expect(isSystemAdministrator({
    roles: [{ name: 'system-administrator' }],
  })).toBe(true);
  expect(isSystemAdministrator({
    roles: [{ name: 'administrator' }, { name: 'incident-coordinator' }],
  })).toBe(false);
  expect(isSystemAdministrator(null)).toBe(false);
});

test('omits status unless a system administrator edit explicitly includes it', () => {
  expect(incidentStatusPayload('draft', false)).toEqual({});
  expect(incidentStatusPayload('in_progress', true)).toEqual({ status: 'in_progress' });
});

test('wires create and edit forms to the guarded status payload', () => {
  const createPage = readFileSync(
    new URL('../src/features/incidents/IncidentCreatePage.tsx', import.meta.url),
    'utf8',
  );
  const editPage = readFileSync(
    new URL('../src/features/incidents/IncidentEditPage.tsx', import.meta.url),
    'utf8',
  );
  const incidentForm = readFileSync(
    new URL('../src/features/incidents/IncidentsPage.tsx', import.meta.url),
    'utf8',
  );

  expect(createPage).toContain("api.post<Incident>('/incidents', incidentPayload(form))");
  expect(createPage).not.toContain('includeStatus');
  expect(editPage).toContain('showStatus={canManuallyChangeStatus}');
  expect(editPage).toContain('incidentPayload(form, { includeStatus: statusChanged })');
  expect(editPage).toContain('manual_status_override: true');
  expect(incidentForm).toContain('return showStatus ? [{ ...item, visible: true }] : [];');
});
