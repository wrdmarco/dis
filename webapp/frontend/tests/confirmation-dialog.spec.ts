import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'playwright/test';

const sourceRoots = [
  fileURLToPath(new URL('../app', import.meta.url)),
  fileURLToPath(new URL('../src', import.meta.url)),
];

test('uses the shared accessible confirmation dialog instead of native browser dialogs', () => {
  const offenders = sourceRoots.flatMap(sourceFiles).flatMap((path) => {
    const contents = readFileSync(path, 'utf8');
    return /\b(?:window\.)?(?:alert|confirm|prompt)\s*\(/.test(contents) ? [path] : [];
  });

  expect(offenders).toEqual([]);

  const dialog = source('../src/components/ConfirmDialogContext.tsx');
  const modal = source('../src/components/ModalDialog.tsx');
  const providers = source('../app/providers.tsx');

  expect(providers).toContain('<ConfirmDialogProvider>{children}</ConfirmDialogProvider>');
  expect(dialog).toContain('role="alertdialog"');
  expect(dialog).toContain('data-dialog-initial="true"');
  expect(dialog).toContain("intent === 'danger' ? 'danger-button' : 'primary-button'");
  expect(dialog).toContain("window.addEventListener('popstate', cancelOnHistoryNavigation)");
  expect(dialog).toContain('pathnameRef.current !== pathname');
  expect(modal).toContain("role?: 'dialog' | 'alertdialog'");
  expect(modal).toContain("event.key === 'Escape'");
  expect(modal).toContain("event.key !== 'Tab'");
  expect(modal).toContain('previouslyFocused.focus()');
});

test('gives backup cleanup and unsaved changes explicit modal actions', () => {
  const backups = source('../src/features/backups/BackupPage.tsx');
  const workflowStudio = source('../src/features/admin/DeploymentRequestWorkflowStudio.tsx');
  const registrationDialog = source('../src/features/calendar/CalendarRegistrationDialog.tsx');

  expect(backups).toContain('const confirmed = await confirmAction({');
  expect(backups).toContain("title: `Oude ${targetLabel(target).toLowerCase()} backups opruimen?`");
  expect(backups).toContain("confirmLabel: 'Backups opruimen'");
  expect(backups).toContain("SAFE_BACKUP_RECOVERY_COMMANDS");
  expect(backups).toContain("Veilige shellcommando&apos;s");
  expect(backups).toContain("error.details?.shell_commands");
  expect(backups).not.toContain('rm -rf');
  expect(workflowStudio).toContain('event.intercept({');
  expect(workflowStudio).toContain("title: 'Pagina verlaten?'");
  expect(registrationDialog).toContain('participantToRemove !== null');
  expect(registrationDialog).toContain('className="danger-button"');
  expect(registrationDialog).toContain('data-calendar-removal-trigger={participant.user.id}');
  expect(registrationDialog).toContain('candidate.dataset.calendarRemovalTrigger === triggerUserId');
  expect(registrationDialog).toContain('aria-busy={pendingUserId !== null}');
});

function source(relativePath: string): string {
  return readFileSync(new URL(relativePath, import.meta.url), 'utf8');
}

function sourceFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      return sourceFiles(path);
    }
    return entry.isFile() && /\.(?:ts|tsx)$/.test(entry.name) ? [path] : [];
  });
}
