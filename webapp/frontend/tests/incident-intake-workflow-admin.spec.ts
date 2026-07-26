import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import { adminTabChangeAllowed } from '../src/features/admin/adminTabNavigation';
import {
  availableBindingTargets,
  bindingTypesCompatible,
  conditionOperatorNeedsValue,
  conditionOperatorsForField,
  createWorkflowDeploymentProfile,
  createWorkflowField,
  createWorkflowPriorityRule,
  normalizeRuleForSubjects,
  removeWorkflowField,
  ruleSafeFields,
  updateWorkflowBinding,
  updateWorkflowOptionLabel,
  type IntakeWorkflowConfiguration,
  type IntakeWorkflowField,
  type IntakeWorkflowPriorityRule,
} from '../src/features/admin/incidentIntakeWorkflow';

const fields: IntakeWorkflowField[] = [
  field('last_seen_location', 'Locatie', 'common', 'text'),
  field('person_name', 'Naam persoon', 'person', 'text'),
  field('animal_name', 'Naam dier', 'animal', 'text'),
  field('object_category', 'Objectsoort', 'object', 'select'),
  field('person_age', 'Leeftijd', 'person', 'number'),
  field('person_vulnerable', 'Kwetsbaar', 'person', 'checkbox'),
];

test('renders all five workflow stages and uses only versioned workflow mutations', () => {
  const adminPage = source('../src/features/admin/AdminPage.tsx');
  const studio = source('../src/features/admin/IncidentIntakeWorkflowStudio.tsx');

  expect(adminPage).toContain('<IncidentIntakeWorkflowStudio onDirtyChange={setIntakeWorkflowDirty} />');
  expect(adminPage).not.toContain("'/admin/incident-intake-form/config'");
  for (const label of ['Uitvraag', 'Koppelingen', 'Prioriteitsregels', 'Inzetvoorstellen', 'Versies & testen']) {
    expect(studio).toContain(`label: '${label}'`);
  }
  for (const endpoint of [
    '/admin/intake-workflow/config',
    '/admin/intake-workflow/draft',
    '/admin/intake-workflow/validate',
    '/admin/intake-workflow/simulate',
    '/admin/intake-workflow/publish',
    '/admin/intake-workflow/restore',
  ]) {
    expect(studio).toContain(`'${endpoint}'`);
  }
  expect(studio).toContain('expected_revision: workflow.data.draft.lock_version');
  expect(studio).toContain('expected_revision: envelope.draft.lock_version');
  expect(studio).toContain('er wordt nooit automatisch gealarmeerd');
  expect(studio).toContain('Je lokale wijzigingen zijn bewaard');
  expect(studio).toContain('Mijn lokale versie behouden');
  expect(studio).toContain('disabled={restoringId !== null || dirty}');
  expect(studio).not.toContain('Concept v{draft.version}');
});

test('blocks a forms-tab switch while the intake workflow has unsaved changes', () => {
  let confirmations = 0;
  const blocked = adminTabChangeAllowed({
    currentTab: 'incidentIntake',
    nextTab: 'incidentForm',
    intakeWorkflowDirty: true,
    confirmLeave: () => {
      confirmations += 1;
      return false;
    },
  });
  const allowed = adminTabChangeAllowed({
    currentTab: 'incidentIntake',
    nextTab: 'pilotReport',
    intakeWorkflowDirty: true,
    confirmLeave: () => true,
  });

  expect(blocked).toBe(false);
  expect(allowed).toBe(true);
  expect(confirmations).toBe(1);

  const adminPage = source('../src/features/admin/AdminPage.tsx');
  expect(adminPage).toContain('onClick={() => changeActiveTab(tab.id)}');
  expect(adminPage).toContain('intakeWorkflowDirty');
});

test('keeps boolean simulation explicitly three-state', () => {
  const studio = source('../src/features/admin/IncidentIntakeWorkflowStudio.tsx');

  expect(studio).toContain('<option value="">Onbeantwoord</option>');
  expect(studio).toContain('<option value="true">Ja</option>');
  expect(studio).toContain('<option value="false">Nee</option>');
  expect(studio).toContain("event.target.value === '' ? undefined : event.target.value === 'true'");
});

test('creates stable keys and limits every field to common or one subject scope', () => {
  const first = createWorkflowField([], 'common', 'text');
  const second = createWorkflowField([first], 'person', 'text');
  const section = createWorkflowField([first, second], 'animal', 'section');

  expect(first.key).toBe('veld_1');
  expect(first.operator_visible).toBe(false);
  expect(second.key).toBe('veld_2');
  expect(second.scope).toBe('person');
  expect(section.key).toBe('sectie_1');
  expect(section.required).toBe(false);
  expect(section.operator_visible).toBe(false);
});

test('allows one incident target across mutually exclusive subject branches only', () => {
  const incidentFields = [
    { target: 'title', label: 'Titel', type: 'text' },
    { target: 'description', label: 'Omschrijving', type: 'text' },
  ];
  const exclusiveBindings = [
    { field_key: 'person_name', target: 'title' },
    { field_key: 'animal_name', target: 'title' },
  ];

  expect(availableBindingTargets(
    'object_category',
    fields,
    exclusiveBindings,
    incidentFields,
  ).map((target) => target.target)).toContain('title');

  expect(availableBindingTargets(
    'person_age',
    fields,
    exclusiveBindings,
    incidentFields,
  ).map((target) => target.target)).not.toContain('title');

  expect(availableBindingTargets(
    'last_seen_location',
    fields,
    exclusiveBindings,
    incidentFields,
  ).map((target) => target.target)).not.toContain('title');

  const commonBinding = [{ field_key: 'last_seen_location', target: 'description' }];
  expect(availableBindingTargets(
    'person_name',
    fields,
    commonBinding,
    incidentFields,
  ).map((target) => target.target)).not.toContain('description');

  expect(updateWorkflowBinding(exclusiveBindings, 'object_category', 'title')).toEqual([
    ...exclusiveBindings,
    { field_key: 'object_category', target: 'title' },
  ]);

  expect(bindingTypesCompatible(fields[4], { type: 'number' })).toBe(true);
  expect(bindingTypesCompatible(fields[5], { type: 'text' })).toBe(false);
  expect(bindingTypesCompatible(fields[0], { type: 'flight_time' })).toBe(true);
  expect(bindingTypesCompatible(
    field('notes', 'Toelichting', 'common', 'textarea'),
    { type: 'flight_time' },
  )).toBe(true);
  expect(bindingTypesCompatible(fields[4], { type: 'text' })).toBe(false);
  expect(bindingTypesCompatible(fields[3], {
    type: 'select',
    options: [{ value: 'fiets', label: 'Fiets' }],
  })).toBe(true);
  expect(bindingTypesCompatible(fields[3], {
    type: 'select',
    options: [{ value: 'auto', label: 'Auto' }],
  })).toBe(false);
});

test('keeps priority condition references subject-safe and operators typed', () => {
  expect(ruleSafeFields(fields, ['person']).map((candidate) => candidate.key)).toEqual([
    'last_seen_location',
    'person_name',
    'person_age',
    'person_vulnerable',
  ]);
  expect(ruleSafeFields(fields, ['person', 'animal']).map((candidate) => candidate.key))
    .toEqual(['last_seen_location']);

  expect(conditionOperatorsForField(fields[4]).map((operator) => operator.key)).toEqual([
    'equals',
    'not_equals',
    'greater_than_or_equal',
    'less_than_or_equal',
    'is_present',
  ]);
  expect(conditionOperatorsForField(fields[5]).map((operator) => operator.key)).toEqual([
    'equals',
    'not_equals',
    'is_true',
    'is_false',
    'is_present',
  ]);
  expect(conditionOperatorNeedsValue('greater_than_or_equal')).toBe(true);
  expect(conditionOperatorNeedsValue('is_present')).toBe(false);

  const normalized = normalizeRuleForSubjects({
    id: 'regel_1',
    label: 'Kwetsbaar',
    subject_types: ['person'],
    match: 'all',
    conditions: [
      { field_key: 'person_vulnerable', operator: 'greater_than_or_equal', value: 1 },
      { field_key: 'animal_name', operator: 'contains', value: 'hond' },
    ],
    priority: 'high',
    explanation: 'Kwetsbare persoon.',
  }, fields);
  expect(normalized.conditions).toEqual([
    { field_key: 'person_vulnerable', operator: 'is_true' },
  ]);
});

test('removing a field also removes its bindings and condition references', () => {
  const configuration: IntakeWorkflowConfiguration = {
    subject_types: [
      { key: 'person', label: 'Mens' },
      { key: 'animal', label: 'Dier' },
      { key: 'object', label: 'Object' },
    ],
    fields,
    bindings: [{ field_key: 'person_age', target: 'custom_fields.age' }],
    priority_rules: [{
      id: 'regel_1',
      label: 'Hogere leeftijd',
      subject_types: ['person'],
      match: 'all',
      conditions: [{ field_key: 'person_age', operator: 'greater_than_or_equal', value: 70 }],
      priority: 'high',
      explanation: 'Mogelijk kwetsbare persoon.',
      deployment_profile_id: null,
    }],
    deployment_profiles: [],
  };

  const cleaned = removeWorkflowField(configuration, 'person_age');
  expect(cleaned.fields.some((candidate) => candidate.key === 'person_age')).toBe(false);
  expect(cleaned.bindings).toEqual([]);
  expect(cleaned.priority_rules[0].conditions).toEqual([]);
});

test('keeps technical option values and rule conditions stable when a label changes', () => {
  const objectCategory: IntakeWorkflowField = {
    ...field('object_category', 'Objectsoort', 'object', 'select'),
    options: [
      { value: 'male', label: 'Man' },
      { value: 'female', label: 'Vrouw' },
    ],
  };
  const renamedOptions = updateWorkflowOptionLabel(objectCategory.options, 'male', 'Mannelijk');
  const rule: IntakeWorkflowPriorityRule = {
    id: 'regel_geslacht',
    label: 'Mannelijk',
    subject_types: ['object'],
    match: 'all',
    conditions: [{ field_key: 'object_category', operator: 'equals', value: 'male' }],
    priority: 'medium',
    explanation: 'Voorbeeldregel.',
    deployment_profile_id: null,
  };

  expect(renamedOptions).toEqual([
    { value: 'male', label: 'Mannelijk' },
    { value: 'female', label: 'Vrouw' },
  ]);
  expect(normalizeRuleForSubjects(rule, [{ ...objectCategory, options: renamedOptions }]).conditions)
    .toEqual([{ field_key: 'object_category', operator: 'equals', value: 'male' }]);
  expect(normalizeRuleForSubjects(rule, [{
    ...objectCategory,
    options: renamedOptions.filter((option) => option.value !== 'male'),
  }]).conditions).toEqual([]);

  const studio = source('../src/features/admin/IncidentIntakeWorkflowStudio.tsx');
  expect(studio).toContain('wordt ook uit de gekoppelde prioriteitsvoorwaarden verwijderd');
  expect(studio).toContain('onRemoveOption(option)');
});

test('creates deployment profiles and rules as editable proposals without dispatch state', () => {
  const profile = createWorkflowDeploymentProfile([]);
  const rule = createWorkflowPriorityRule([], fields);

  expect(profile.id).toBe('inzet_1');
  expect(profile.team_ids).toEqual([]);
  expect(profile.resources).toEqual([]);
  expect(profile.recommended_recipient_count).toBeNull();
  expect(profile.recommended_dispatch_mode).toBeNull();
  expect(profile.required_certification_type_ids).toEqual([]);
  expect(profile).not.toHaveProperty('dispatch');
  expect(rule.id).toBe('regel_1');
  expect(rule.priority).toBe('medium');
  expect(rule.conditions).toHaveLength(1);

  const studio = source('../src/features/admin/IncidentIntakeWorkflowStudio.tsx');
  expect(studio).toContain('Geadviseerd aantal ontvangers');
  expect(studio).toContain('Adviseer eerst vooraankondiging');
  expect(studio).toContain('Adviseer direct alarmeren');
  expect(studio).toContain('Vereiste certificaten');
});

test('guards dirty workflow changes for links, browser history and unload', () => {
  const studio = source('../src/features/admin/IncidentIntakeWorkflowStudio.tsx');

  expect(studio).toContain("window.addEventListener('beforeunload', warnBeforeUnload)");
  expect(studio).toContain("document.addEventListener('click', confirmInternalNavigation, true)");
  expect(studio).toContain("navigation?.addEventListener('navigate', confirmHistoryNavigation)");
  expect(studio).toContain('event.preventDefault();');
  expect(studio).toContain('|| !event.cancelable');
  expect(studio).toContain('event.metaKey');
  expect(studio).toContain('event.ctrlKey');
  expect(studio).toContain('allowNavigationRef.current = false');
});

function field(
  key: string,
  label: string,
  scope: IntakeWorkflowField['scope'],
  type: IntakeWorkflowField['type'],
): IntakeWorkflowField {
  return {
    key,
    label,
    scope,
    type,
    required: false,
    operator_visible: true,
    options: type === 'select'
      ? [{ value: 'fiets', label: 'Fiets' }]
      : [],
  };
}

function source(relativePath: string): string {
  return readFileSync(new URL(relativePath, import.meta.url), 'utf8');
}
