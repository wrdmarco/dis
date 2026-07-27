import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  buildDeploymentReferenceSettingsPayload,
  DEFAULT_DEPLOYMENT_REFERENCE_TEMPLATE,
  DEPLOYMENT_REFERENCE_SETTING_KEY,
  deploymentReferencePreview,
  deploymentReferenceTemplate,
  deploymentReferenceTokens,
} from '../src/features/admin/deploymentReferenceSettings';
import type { SystemSetting } from '../src/types/api';

test('maps and saves the deployment reference setting with a backwards-compatible default', () => {
  expect(deploymentReferenceTemplate([])).toBe(DEFAULT_DEPLOYMENT_REFERENCE_TEMPLATE);

  const settings: SystemSetting[] = [{
    key: DEPLOYMENT_REFERENCE_SETTING_KEY,
    value: 'NDT-{{date}}-{{time}}-{{sequence}}',
    is_sensitive: false,
  }];

  expect(deploymentReferenceTemplate(settings)).toBe('NDT-{{date}}-{{time}}-{{sequence}}');
  expect(buildDeploymentReferenceSettingsPayload(' NDT-{{sequence}} ')).toEqual({
    [DEPLOYMENT_REFERENCE_SETTING_KEY]: 'NDT-{{sequence}}',
  });
  expect(deploymentReferenceTokens.map((token) => token.key)).toEqual(['date', 'time', 'sequence', 'random']);
});

test('renders a safe live preview without lowercasing the configured prefix', () => {
  expect(deploymentReferencePreview('NDT-{{date}}-{{time}}-{{sequence}}', {
    date: '20260727',
    time: '123700',
    sequence: '0042',
    random: 'A1B2',
  })).toBe('NDT-20260727-123700-0042');
});

test('places an explicit deployment reference editor before the raw admin settings table', () => {
  const source = readFileSync(new URL('../src/features/admin/AdminPage.tsx', import.meta.url), 'utf8');
  const manual = readFileSync(new URL('../src/features/help/manuals/managementManual.ts', import.meta.url), 'utf8');
  const editor = source.indexOf('<DeploymentReferenceSettings');
  const rawSettingsTable = source.indexOf('<table className="data-table">', editor);

  expect(editor).toBeGreaterThan(-1);
  expect(rawSettingsTable).toBeGreaterThan(editor);
  expect(source).toContain('<h3>Inzetreferentie</h3>');
  expect(source).toContain('De referentie is daarna automatisch zichtbaar in web, pushberichten,');
  expect(source).toContain('mobiele apps, het wallboard en rapporten.');
  expect(source).toContain('Bestaande inzetten behouden hun huidige referentie.');
  expect(manual).toContain("id: 'admin-deployment-reference'");
  expect(manual).toContain('Bestaande inzetten behouden hun bestaande referentie.');
});
