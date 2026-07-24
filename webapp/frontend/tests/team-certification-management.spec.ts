import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';

test('team management exposes only fields that affect operational behavior', () => {
  const form = readFileSync(new URL('../src/features/teams/TeamFormPage.tsx', import.meta.url), 'utf8');
  const overview = readFileSync(new URL('../src/features/teams/TeamsPage.tsx', import.meta.url), 'utf8');
  const help = readFileSync(new URL('../src/features/help/manuals/resourceManual.ts', import.meta.url), 'utf8');

  expect(form).not.toContain('Ouderteam');
  expect(form).not.toContain('parent_team_id');
  expect(form).not.toContain('candidate.type');
  expect(form).not.toContain('type: form.type');
  expect(overview).not.toContain('<th scope="col">Type</th>');
  expect(overview).not.toContain('<th scope="col">Ouderteam</th>');
  expect(help).not.toContain('Kies het ouderteam');
  expect(form).toContain('Geen certificaten geselecteerd betekent dat dit team geen certificaateis heeft.');
});

test('dispatch requirements are configured only through explicit team certification links', () => {
  const form = readFileSync(new URL('../src/features/certifications/CertificationFormPage.tsx', import.meta.url), 'utf8');
  const overview = readFileSync(new URL('../src/features/certifications/CertificationsPage.tsx', import.meta.url), 'utf8');
  const help = readFileSync(new URL('../src/features/help/HelpPage.tsx', import.meta.url), 'utf8');

  expect(form).not.toContain('Dispatch vereist');
  expect(form).not.toContain('is_required_for_dispatch');
  expect(overview).not.toContain('<th scope="col">Dispatch</th>');
  expect(overview).not.toContain('certification.is_required_for_dispatch');
  expect(help).toContain('De alarmeringseis wordt per team ingesteld.');
});
