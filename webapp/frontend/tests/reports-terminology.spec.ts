import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';

test('uses inzet terminology for operational report records and alarmering for notifications', () => {
  const reports = readFileSync(
    new URL('../src/features/reports/ReportsPage.tsx', import.meta.url),
    'utf8',
  );

  expect(reports).toContain('label="Inzetrapporten"');
  expect(reports).toContain('title="Inzetrapporten"');
  expect(reports).toContain('Laatste alarmering');
  expect(reports).toContain('Laatste alarmeringen zonder reactie');
  expect(reports).toContain('title="Inzetten in selectie"');
  expect(reports).not.toContain('Incidentrapporten');
  expect(reports).not.toContain('Meldingen in selectie');
});
