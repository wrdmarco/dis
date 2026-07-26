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

test('routes creation through intake promotion and keeps incident edits guarded', () => {
  const createRoute = readFileSync(
    new URL('../app/incidents/new/page.tsx', import.meta.url),
    'utf8',
  );
  const intakeWorkspace = readFileSync(
    new URL('../src/features/intakes/IntakeWorkspace.tsx', import.meta.url),
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

  expect(createRoute).toContain("redirect('/meldingen/new')");
  expect(intakeWorkspace).toContain("`/intake-dossiers/${draft.id}/promote`");
  expect(intakeWorkspace).toContain('Er wordt geen alarm verstuurd.');
  expect(editPage).toContain('showStatus={canManuallyChangeStatus}');
  expect(editPage).toContain('changedIncidentPayload(');
  expect(editPage).toContain('const currentPayload = incidentPayload(current, { includeStatus })');
  expect(editPage).not.toContain('includeIntake');
  expect(editPage).toContain('manual_status_override: true');
  expect(incidentForm).toContain('return showStatus ? [{ ...item, visible: true }] : [];');
});
