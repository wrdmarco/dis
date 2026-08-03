import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';

const help = readFileSync(new URL('../src/features/help/HelpPage.tsx', import.meta.url), 'utf8');
const managementManual = readFileSync(
  new URL('../src/features/help/manuals/managementManual.ts', import.meta.url),
  'utf8',
);
const operationManual = readFileSync(
  new URL('../src/features/help/manuals/operationManual.ts', import.meta.url),
  'utf8',
);
const resourceManual = readFileSync(
  new URL('../src/features/help/manuals/resourceManual.ts', import.meta.url),
  'utf8',
);

test('documents the separate priority-decision workspace and its publication scope', () => {
  const formsTopic = help.slice(
    help.indexOf("id: 'forms'"),
    help.indexOf("id: 'priority-decisions'"),
  );

  expect(help).toMatch(
    /id: 'priority-decisions'[\s\S]+?href: '\/prioriteitsbesluiten'[\s\S]+?permissions: \['forms\.manage'\]/,
  );
  expect(help).toContain('Regels per onderwerp beheren');
  expect(help).toContain('Profielen en standaardteams beheren');
  expect(help).toContain('Opslaan, valideren en publiceren');
  expect(help).toContain('Publiceren activeert de volledige opgeslagen uitvraagconfiguratie');
  expect(formsTopic).not.toContain('Prioriteit adviseren');
  expect(formsTopic).not.toContain('Inzetvoorstel beheren');
  expect(managementManual).toContain("'priority-decisions': [");
  expect(managementManual).toContain("id: 'priority-decisions-configure'");
  expect(managementManual).toContain("id: 'forms-deployment-request-workflow'");
});

test('keeps help links aligned with the management route permission matrix', () => {
  expect(help).toMatch(/id: 'expiry'[\s\S]+?permissions: \['expiry\.view'\]/);
  expect(help).toMatch(/id: 'branding'[\s\S]+?permissions: \['branding\.manage'\]/);
  expect(help).toMatch(
    /id: 'routing'[\s\S]+?permissions: \['system\.routing\.view', 'system\.routing\.manage'\]/,
  );
});

test('uses aanvraag before preparation and inzet after preparation in manuals', () => {
  const manuals = `${managementManual}\n${operationManual}\n${resourceManual}`;

  expect(managementManual).toContain('simulatie of nieuwe aanvraag');
  expect(operationManual).toContain('leg de kern van de aanvraag vast');
  expect(operationManual).toContain('Alarmering versturen in Alarmeringsconcept');
  expect(resourceManual).toContain('Historische namen in inzetrapportages');
  expect(resourceManual).toContain('voor inzetalarmering');
  expect(manuals).not.toContain('nieuwe melding.');
  expect(manuals).not.toContain('deploymentrapporten');
  expect(manuals).not.toContain('deploymentalarmering');
});

test('matches dispatch help to the actual view and manage gates', () => {
  const compositeGate = "permissions: ['deployments.dispatch.view', 'deployments.dispatch.manage'], oneOfPermissions: ['deployments.view', 'deployments.manage']";

  expect(help).toContain(compositeGate);
  expect(operationManual).toContain("permissions: ['deployments.dispatch.view', 'deployments.dispatch.manage']");
  expect(operationManual).toContain("oneOfPermissions: ['deployments.view', 'deployments.manage']");
  expect(operationManual).toContain("id: 'dispatch-link-pilot'");
  expect(operationManual).toContain('ontvangt een informatief pushbericht. Er klinkt geen alarm.');
  expect(operationManual).toContain("id: 'dispatch-correct-arrival'");
  expect(operationManual).toContain("permissions: ['deployments.dispatch.manage', 'status.override']");
  expect(operationManual).toContain('De participantstatus voor deze inzet is vastgelegd.');
  expect(help).toContain('oneOfPermissions.some((permission) => access.permissions.has(permission))');
});
