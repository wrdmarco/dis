import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  reorderWorkflowFieldsForScope,
  type DeploymentRequestWorkflowField,
} from '../src/features/admin/deploymentRequestWorkflow';
import {
  fieldTypeDefaultWidth,
  formFieldTypeOptions,
  formFieldTypeValues,
  isSatisfactionScore,
  satisfactionScoreOptions,
} from '../src/lib/formFieldTypes';
import {
  deploymentRequestFlightTimeChangeValue,
  deploymentRequestFlightTimeValue,
  updateDeploymentRequestFlightTimeValue,
} from '../src/features/deployment-requests/deploymentRequestFlightTime';

const sharedFieldTypes = [
  'section',
  'text',
  'textarea',
  'address',
  'number',
  'phone',
  'flight_time',
  'select',
  'radio',
  'checkbox',
  'date',
  'datetime',
  'score',
] as const;

test('all three form builders consume the same field-type catalog', () => {
  expect(formFieldTypeValues).toEqual(sharedFieldTypes);
  expect(formFieldTypeOptions.map(({ value }) => value)).toEqual(sharedFieldTypes);
  expect(formFieldTypeOptions.find(({ value }) => value === 'score')?.label).toBe('Smiley-score');

  const configurableBuilders = source('../src/features/admin/AdminPage.tsx');
  const workflowContract = source('../src/features/admin/deploymentRequestWorkflow.ts');

  expect(configurableBuilders).toContain('formBuilderPalette:');
  expect(configurableBuilders).toContain('formFieldTypeOptions');
  expect(workflowContract).toContain('formFieldTypeOptions');
  expect(workflowContract).toContain('deploymentRequestWorkflowFieldTypes');
});

test('workflow drag ordering stays inside its subject scope without mutating state', () => {
  const fields: DeploymentRequestWorkflowField[] = [
    workflowField('common_a', 'common'),
    workflowField('person_a', 'person'),
    workflowField('common_b', 'common'),
    workflowField('animal_a', 'animal'),
    workflowField('common_c', 'common'),
  ];

  const reordered = reorderWorkflowFieldsForScope(fields, 'common', 'common_c', 'common_a');

  expect(reordered.map(({ key }) => key)).toEqual([
    'common_c',
    'person_a',
    'common_a',
    'animal_a',
    'common_b',
  ]);
  expect(fields.map(({ key }) => key)).toEqual([
    'common_a',
    'person_a',
    'common_b',
    'animal_a',
    'common_c',
  ]);
  expect(reorderWorkflowFieldsForScope(fields, 'common', 'missing', 'common_a')).toBe(fields);
});

test('Uitvraag exposes drag ordering with labelled button alternatives', () => {
  const studio = source('../src/features/admin/DeploymentRequestWorkflowStudio.tsx');

  expect(studio).toContain('draggable');
  expect(studio).toContain('onDragStart={onDragStart}');
  expect(studio).toContain('onDrop={onDrop}');
  expect(studio).toContain('reorderWorkflowFieldsForScope');
  expect(studio).toContain('aria-label={`${field.label} omhoog`}');
  expect(studio).toContain('aria-label={`${field.label} omlaag`}');
});

test('smiley score has a stable numeric 1-to-5 contract and accessible labels', () => {
  expect(satisfactionScoreOptions).toEqual([
    { value: 1, label: 'Niet goed' },
    { value: 2, label: 'Matig' },
    { value: 3, label: 'Neutraal' },
    { value: 4, label: 'Goed' },
    { value: 5, label: 'Zeer goed' },
  ]);
  expect([1, 2, 3, 4, 5].every(isSatisfactionScore)).toBe(true);
  expect([0, 6, '3', null].some(isSatisfactionScore)).toBe(false);
  expect(fieldTypeDefaultWidth('score')).toBe('full');

  const scoreField = source('../src/components/SatisfactionScoreField.tsx');
  expect(scoreField).toContain('type="radio"');
  expect(scoreField).toContain('onChange={() => onChange(option.value)}');
  expect(scoreField).toContain('score ${option.value} van 5');
  expect(scoreField).toContain('Score wissen');
});

test('every web form runtime renders smiley score as a dedicated control', () => {
  for (const file of [
    '../src/features/admin/AdminPage.tsx',
    '../src/features/admin/DeploymentRequestWorkflowStudio.tsx',
    '../src/features/deployment-requests/DeploymentRequestWorkspace.tsx',
    '../src/features/deployments/DeploymentsPage.tsx',
    '../src/features/reports/ReportsPage.tsx',
  ]) {
    const runtime = source(file);
    expect(runtime, `${file} mist de scorecomponent`).toContain('SatisfactionScoreField');
    expect(runtime, `${file} mist de scoretak`).toContain("'score'");
  }
});

test('deployment request flight time preserves canonical reloads and round-trips edits', () => {
  const canonical = {
    start: '09:15',
    end: '10:45',
    duration_minutes: 90,
  };

  const reloaded = deploymentRequestFlightTimeValue(canonical);
  expect(reloaded).toEqual(canonical);
  expect(deploymentRequestFlightTimeValue({
    ...canonical,
    timezone: 'Europe/Amsterdam',
  })).toEqual(canonical);
  const updated = updateDeploymentRequestFlightTimeValue(reloaded, 'end', '11:00');
  expect(updated).toEqual({
    start: '09:15',
    end: '11:00',
    duration_minutes: 105,
  });
  expect(deploymentRequestFlightTimeValue(updated)).toEqual(updated);
  expect(canonical).toEqual({ start: '09:15', end: '10:45', duration_minutes: 90 });
  const incomplete = updateDeploymentRequestFlightTimeValue(reloaded, 'start', '');
  expect(incomplete).toEqual({ start: '', end: '10:45', duration_minutes: null });
  expect(deploymentRequestFlightTimeValue(incomplete)).toEqual(incomplete);
  expect(deploymentRequestFlightTimeChangeValue(incomplete)).toEqual(incomplete);
  expect(deploymentRequestFlightTimeChangeValue({
    start: '',
    end: '',
    duration_minutes: null,
  })).toBeNull();
  expect(deploymentRequestFlightTimeValue('23:30-00:15')).toEqual({
    start: '23:30',
    end: '00:15',
    duration_minutes: 45,
  });
  expect(deploymentRequestFlightTimeValue({
    start: '09:15',
    end: '10:45',
    duration_minutes: 999,
  })).toEqual({ start: '', end: '', duration_minutes: null });
  expect(deploymentRequestFlightTimeValue('24:00-00:15')).toEqual({
    start: '',
    end: '',
    duration_minutes: null,
  });
  expect(deploymentRequestFlightTimeValue({
    start: '23:30',
    end: '24:59',
    duration_minutes: 89,
  })).toEqual({ start: '', end: '', duration_minutes: null });
});

test('help describes shared types, drag alternatives and numeric score semantics', () => {
  const manual = source('../src/features/help/manuals/managementManual.ts');
  const help = source('../src/features/help/HelpPage.tsx');

  for (const text of [manual, help]) {
    expect(text).toContain('Smiley-score');
    expect(text).toContain('1 tot en met 5');
    expect(text).toContain('Omhoog en Omlaag');
  }
  for (const label of ['Niet goed', 'Matig', 'Neutraal', 'Goed', 'Zeer goed']) {
    expect(manual).toContain(label);
  }
});

function source(path: string): string {
  return readFileSync(new URL(path, import.meta.url), 'utf8');
}

function workflowField(
  key: string,
  scope: DeploymentRequestWorkflowField['scope'],
): DeploymentRequestWorkflowField {
  return {
    key,
    label: key,
    type: 'text',
    scope,
    required: false,
    operator_visible: false,
    options: [],
  };
}
