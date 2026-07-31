import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';

const vacationPlanner = readSource('../src/features/vacations/VacationPlanner.tsx');
const modalDialog = readSource('../src/components/ModalDialog.tsx');
const profilePage = readSource('../src/features/profile/ProfilePage.tsx');
const userDetails = readSource('../src/features/users/UserDetailPage.tsx');
const operationalDetails = readSource('../src/features/users/UserOperationalDetails.tsx');
const apiTypes = readSource('../src/types/api.ts');
const helpPage = readSource('../src/features/help/HelpPage.tsx');
const resourceManual = readSource('../src/features/help/manuals/resourceManual.ts');
const roleForm = readSource('../src/features/roles/RoleForm.tsx');

test('uses one vacation planner for self-service and administrator details', () => {
  expect(profilePage).toContain('<VacationPlanner');
  expect(profilePage).toContain('scope="mine"');
  expect(profilePage).toContain(
    'onChanged={() => setAvailabilityScheduleVersion((current) => current + 1)}',
  );
  expect(operationalDetails).toContain('<VacationPlanner');
  expect(operationalDetails).toContain('scope="user"');
  expect(operationalDetails).toContain('canView={canViewVacations}');
  expect(operationalDetails).toContain('canManage={canManageVacations}');
  expect(operationalDetails).not.toContain('cancelVacation');
});

test('uses dedicated read and management permissions for other users vacations', () => {
  expect(userDetails).toContain("const canManageVacations = hasPermission('vacations.manage');");
  expect(userDetails).toContain("const canViewVacations = hasPermission('vacations.view') || canManageVacations;");
  expect(userDetails).toContain('canViewVacations={canViewVacations}');
  expect(userDetails).toContain('canManageVacations={canManageVacations}');
  expect(userDetails).not.toContain('canManageVacations={canManageUsers}');
});

test('supports create, edit and confirmed deletion for both endpoint scopes', () => {
  expect(vacationPlanner).toContain("scope === 'mine'");
  expect(vacationPlanner).toContain("'/vacations/mine'");
  expect(vacationPlanner).toContain('`/users/${encodeURIComponent(userId ?? \'\')}/vacations`');
  expect(vacationPlanner).toContain('api.post<UserVacation>(listPath, payload)');
  expect(vacationPlanner).toContain('api.patch<UserVacation>(`/vacations/${editingId}`, payload)');
  expect(vacationPlanner).toContain('current.filter((vacation) => vacation.id !== response.data.id)');
  expect(vacationPlanner).toContain('await api.delete(`/vacations/${target.id}`)');
  expect(vacationPlanner).toContain('is_available: form.isAvailable');
  expect(vacationPlanner).toContain('Periode definitief verwijderen');
  expect(vacationPlanner).toContain('Annuleren');
  expect(vacationPlanner).toContain('<ModalDialog');
  expect(vacationPlanner).toContain('title={editingId === null ? \'Periode toevoegen\' : \'Periode aanpassen\'}');
  expect(vacationPlanner).toContain('data-dialog-initial="true"');
  expect(vacationPlanner).toContain('className="compact-record-list"');
  expect(vacationPlanner).toContain('onClick={requestVacationDelete}');
  expect(vacationPlanner).not.toContain('openDeleteModal(vacation)');
  expect(modalDialog).toContain('role="dialog"');
  expect(modalDialog).toContain('aria-modal="true"');
  expect(modalDialog).toContain("if (event.key === 'Escape')");
  expect(modalDialog).toContain('previouslyFocused.focus()');
  expect(vacationPlanner).toContain('{deleteError ? <p className="form-error" role="alert">{deleteError}</p> : null}');
  expect(vacationPlanner).not.toContain('Intrekken');
});

test('models only open vacation periods and documents the new workflow', () => {
  expect(apiTypes).toMatch(/export interface UserVacation[\s\S]*is_available: boolean;/);
  expect(apiTypes).toMatch(/status: 'scheduled' \| 'active';/);
  expect(apiTypes).not.toMatch(/status: 'scheduled' \| 'active' \| 'cancelled' \| 'completed';/);
  expect(helpPage).toContain("permissions: ['vacations.view']");
  expect(helpPage).toContain("permissions: ['vacations.manage']");
  expect(helpPage).toContain('na een extra bevestiging verwijderen');
  expect(resourceManual).toContain("permissions: ['vacations.manage']");
  expect(resourceManual).toContain('periode definitief mag worden verwijderd');
  expect(resourceManual).not.toContain('Vakantie voor een gebruiker vastleggen of intrekken');
  expect(roleForm).toContain("case 'vacation_management':");
  expect(roleForm).toContain("return 'Vakantieplanning';");
});

function readSource(relativePath: string): string {
  return readFileSync(new URL(relativePath, import.meta.url), 'utf8');
}
