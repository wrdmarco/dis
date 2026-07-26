import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import { webRouteAccess } from '../src/features/auth/webRouteAccess';
import { changedIncidentPayloadRecords } from '../src/features/incidents/incidentPatch';
import {
  intakeApplicableFields,
  intakeBooleanChoice,
  intakeCompleteness,
  intakeDecisionProfileId,
  intakeDossierTitle,
  intakePriorityLabel,
  mergeIntakeChanges,
  mergeQueuedIntakeChanges,
  type IntakeDossier,
  type IntakeWorkflowConfiguration,
} from '../src/features/intakes/intakeWorkflow';

const configuration: IntakeWorkflowConfiguration = {
  subject_types: [
    { key: 'person', label: 'Persoon' },
    { key: 'animal', label: 'Dier' },
    { key: 'object', label: 'Object' },
  ],
  fields: [
    {
      key: 'last_seen_location',
      label: 'Laatst gezien',
      type: 'text',
      scope: 'common',
      required: true,
      operator_visible: true,
    },
    {
      key: 'age',
      label: 'Leeftijd',
      type: 'number',
      scope: 'person',
      required: true,
      operator_visible: true,
    },
    {
      key: 'animal_type',
      label: 'Diersoort',
      type: 'text',
      scope: 'animal',
      required: true,
      operator_visible: true,
    },
  ],
  bindings: [{ field_key: 'last_seen_location', target: 'location_label' }],
  priority_rules: [],
  deployment_profiles: [],
};

test('keeps common fields and exactly one subject branch in the questionnaire', () => {
  expect(intakeApplicableFields(configuration, 'person').map((field) => field.key))
    .toEqual(['last_seen_location', 'age']);
  expect(intakeApplicableFields(configuration, 'animal').map((field) => field.key))
    .toEqual(['last_seen_location', 'animal_type']);
  expect(intakeApplicableFields(configuration, 'object').map((field) => field.key))
    .toEqual(['last_seen_location']);
});

test('derives completeness from the server-authoritative missing-field list', () => {
  const dossier = dossierFixture();
  expect(intakeCompleteness(dossier, configuration)).toBe(50);

  dossier.triage.missing_fields = [];
  dossier.triage.state = 'determined';
  expect(intakeCompleteness(dossier, configuration)).toBe(100);
});

test('merges dirty patches without dropping newer keystrokes and null removes an answer', () => {
  expect(mergeQueuedIntakeChanges(
    { answers: { name: 'Jan', age: 70 } },
    { answers: { age: 71, clothing: 'Blauwe jas' } },
  )).toEqual({ answers: { name: 'Jan', age: 71, clothing: 'Blauwe jas' } });

  expect(mergeIntakeChanges(dossierFixture(), {
    subject_type: 'animal',
    answers: { age: null, animal_type: 'Hond' },
  })).toMatchObject({
    subject_type: 'animal',
    answers: {
      last_seen_location: 'Stationsplein',
      animal_type: 'Hond',
    },
  });
});

test('keeps an explicit required boolean answer distinct from unanswered', () => {
  expect(intakeBooleanChoice(null)).toBeNull();
  expect(intakeBooleanChoice(undefined)).toBeNull();
  expect(intakeBooleanChoice(true)).toBe(true);
  expect(intakeBooleanChoice(false)).toBe(false);

  const workspace = source('../src/features/intakes/IntakeWorkspace.tsx');
  expect(workspace).toContain("option={{ value: 'yes', label: 'Ja' }}");
  expect(workspace).toContain("option={{ value: 'no', label: 'Nee' }}");
  expect(workspace).toContain("option={{ value: 'unanswered', label: 'Onbeantwoord' }}");
  expect(workspace).toContain('onSelect={() => onChange(null)}');
  expect(workspace).toContain('selected={selected === false}');
});

test('uses the pre-incident work queue as the only new-incident entry point', () => {
  const navigation = source('../src/app/CommandLayout.tsx');
  const incidents = source('../src/features/incidents/IncidentsPage.tsx');
  const redirect = source('../app/incidents/new/page.tsx');
  const meldingenIndex = navigation.indexOf("to: '/meldingen'");
  const incidentsIndex = navigation.indexOf("to: '/incidents'");

  expect(meldingenIndex).toBeGreaterThan(-1);
  expect(meldingenIndex).toBeLessThan(incidentsIndex);
  expect(navigation).toContain("'/meldingen': () => import('../features/intakes/IntakeListPage')");
  expect(incidents).toContain('href="/meldingen/new"');
  expect(incidents).not.toContain('href="/incidents/new"');
  expect(redirect).toContain("redirect('/meldingen/new')");
  expect(webRouteAccess.intakes.permissions).toEqual(['incidents.manage']);
});

test('creates no empty dossier before the centralist chooses person, animal or object', () => {
  const page = source('../src/features/intakes/IntakeCreatePage.tsx');

  expect(page).toContain('workflow.data.configuration.subject_types.map');
  expect(page).toContain('onClick={() => void createDossier({');
  expect(page).toContain("api.post<IntakeDossier>('/intake-dossiers'");
  expect(page).toContain('client_mutation_id: nextAttempt.mutationId');
  expect(page).not.toContain('useEffect(');
});

test('implements conflict-safe autosave, decision and idempotent promotion without alarm calls', () => {
  const workspace = source('../src/features/intakes/IntakeWorkspace.tsx');

  expect(workspace).toContain('lock_version: mutation.lockVersion');
  expect(workspace).toContain('client_mutation_id: mutation.clientMutationId');
  expect(workspace).toContain("caught.status === 409");
  expect(workspace).toContain("'intake_version_conflict'");
  expect(workspace).toContain('Serverversie laden');
  expect(workspace).toContain('Mijn wijzigingen opnieuw toepassen');
  expect(workspace).toContain("window.addEventListener('online'");
  expect(workspace).toContain("`/intake-dossiers/${draft.id}/priority`");
  expect(workspace).toContain('...(planChanged ? {');
  expect(workspace).toContain("`/intake-dossiers/${draft.id}/promote`");
  expect(workspace).toContain('promoteMutationIdRef.current ?? makeIntakeMutationId()');
  expect(workspace).toContain('client_mutation_id: promotionMutationId');
  expect(workspace).toContain('Er wordt geen alarm verstuurd.');
  expect(workspace).toContain('recommended_recipient_count: deploymentDraft.recipientCount');
  expect(workspace).toContain('recommended_dispatch_mode: deploymentDraft.dispatchMode');
  expect(workspace).toContain('required_certification_type_ids: deploymentDraft.certificationTypeIds');
  expect(workspace).toContain('Dit voorstel selecteert of alarmeert niemand automatisch.');
  expect(workspace).toContain("draft.triage.state === 'incomplete'");
  expect(workspace).toContain('Incident aanmaken wordt beschikbaar zodra de kerngegevens compleet zijn.');
  expect(workspace).not.toContain('/dispatch');
});

test('paginates open and closed work queues and reuses the linked dossier in incident pages', () => {
  const list = source('../src/features/intakes/IntakeListPage.tsx');
  const incidentPanel = source('../src/features/intakes/IncidentIntakeDossierPanel.tsx');
  const incidentEdit = source('../src/features/incidents/IncidentEditPage.tsx');
  const realtime = source('../src/lib/realtime.ts');

  expect(list).toContain('`/intake-dossiers?status=${view}&per_page=${DOSSIERS_PER_PAGE}&page=${page}`');
  expect(list).toContain("type IntakeListView = 'open' | 'closed'");
  expect(list).toContain('Afgesloten meldingen blijven hier raadpleegbaar.');
  expect(list).not.toContain('status=open,promoted');
  expect(list).toContain('pagination.current_page >= pagination.last_page');
  expect(incidentPanel).toContain('`/incidents/${incidentId}/intake-dossier`');
  expect(incidentPanel).toContain('allowPromotedEditing');
  expect(incidentEdit).not.toContain('IncidentIntakeDossierPanel');
  expect(incidentEdit).toContain('hiddenFieldKeys={intakeOwnedFieldKeys}');
  expect(incidentEdit).toContain('changedIncidentPayload(');
  expect(incidentEdit).toContain('Meldingsdossier openen');
  expect(realtime).toContain(".listen('.incident.intake.changed', options.onOperationalEvent)");
  expect(realtime).toContain("echo.private('intakes')");
  expect(realtime).toContain(".listen('.incident.intake.changed', options.onIntakeEvent)");
  expect(list).toContain('onIntakeEvent={() => void reloadDossiersSilently()}');
});

test('presents the required Dutch priority vocabulary', () => {
  expect(intakePriorityLabel('low')).toBe('Laag');
  expect(intakePriorityLabel('medium')).toBe('Middel');
  expect(intakePriorityLabel('high')).toBe('Hoog');
  expect(intakePriorityLabel('urgent')).toBe('Urgent');
  expect(intakePriorityLabel(null)).toBe('Nog niet bepaald');
});

test('does not reuse an incompatible advised deployment profile for a priority override', () => {
  expect(intakeDecisionProfileId('high', null, 'low', undefined, 'low-response')).toBeNull();
  expect(intakeDecisionProfileId('low', null, 'low', undefined, 'low-response')).toBe('low-response');
  expect(intakeDecisionProfileId('high', 'high', 'low', 'high-response', 'low-response')).toBe('high-response');
});

test('uses stable subject-specific fields for person, animal and object work-queue titles', () => {
  expect(intakeDossierTitle({
    subject_type_label: 'Persoon',
    answer_rows: [
      answerRow('last_seen_at', '18:30'),
      answerRow('person_name', 'Jan de Vries'),
    ],
  })).toBe('Jan de Vries');
  expect(intakeDossierTitle({
    subject_type_label: 'Dier',
    answer_rows: [
      answerRow('last_seen_location', 'Park'),
      answerRow('animal_species', 'Hond'),
    ],
  })).toBe('Hond');
  expect(intakeDossierTitle({
    subject_type_label: 'Object',
    answer_rows: [
      answerRow('last_seen_location', 'Station'),
      answerRow('object_category', 'Rugzak'),
    ],
  })).toBe('Rugzak');
});

test('guards dirty intake work on unload and flushes it during client-side unmount', () => {
  const workspace = source('../src/features/intakes/IntakeWorkspace.tsx');

  expect(workspace).toContain("window.addEventListener('beforeunload', warnBeforeUnload)");
  expect(workspace).toContain("window.removeEventListener('beforeunload', warnBeforeUnload)");
  expect(workspace).toContain("event.returnValue = ''");
  expect(workspace).toContain("document.addEventListener('click', saveBeforeInternalNavigation, true)");
  expect(workspace).toContain('event.preventDefault();');
  expect(workspace).toContain('router.push(`${destination.pathname}${destination.search}${destination.hash}`)');
  expect(workspace).toContain("navigation?.addEventListener('navigate', saveBeforeHistoryNavigation)");
  expect(workspace).toContain('precommitHandler: async () =>');
  expect(workspace).toContain('|| !event.cancelable');
  expect(workspace).toContain('void flushRef.current();');
  expect(workspace).not.toContain('if (!mountedRef.current) return true;');
});

test('keeps idempotency keys across uncertain decision and close retries', () => {
  const workspace = source('../src/features/intakes/IntakeWorkspace.tsx');

  expect(workspace).toContain('const decisionMutationRef = useRef');
  expect(workspace).toContain('decisionMutationRef.current?.signature !== decisionSignature');
  expect(workspace).toContain('const closeMutationRef = useRef');
  expect(workspace).toContain('closeMutationRef.current?.signature !== closeSignature');
});

test('sends only changed incident fields and key-wise custom-field patches', () => {
  const baseline = {
    title: 'Vermist persoon',
    description: 'Startinformatie',
    priority: 'high',
    custom_fields: {
      public_note: 'Oud',
      intake_bound: 'Blijft gelijk',
    },
  };
  const current = {
    ...baseline,
    description: 'Aangevulde details',
    custom_fields: {
      ...baseline.custom_fields,
      public_note: 'Nieuwe informatie',
    },
  };

  expect(changedIncidentPayloadRecords(current, baseline)).toEqual({
    description: 'Aangevulde details',
    custom_fields: {
      public_note: 'Nieuwe informatie',
    },
  });
});

test('focuses an input inside choice groups when a missing-field shortcut is used', () => {
  const workspace = source('../src/features/intakes/IntakeWorkspace.tsx');

  expect(workspace).toContain('target instanceof HTMLFieldSetElement');
  expect(workspace).toContain("target.querySelector<HTMLInputElement>('input:not(:disabled)')?.focus()");
});

function dossierFixture(): IntakeDossier {
  return {
    id: 'dossier-1',
    status: 'open',
    subject_type: 'person',
    subject_type_label: 'Persoon',
    workflow_revision: { id: 'revision-1', version: 3 },
    answers: {
      last_seen_location: 'Stationsplein',
      age: 76,
    },
    answer_rows: [],
    triage: {
      state: 'incomplete',
      recommended_priority: null,
      reasons: [],
      missing_fields: [{ key: 'age', label: 'Leeftijd' }],
    },
    decided_priority: null,
    priority_override_reason: null,
    deployment_proposal: null,
    selected_deployment_proposal: null,
    lock_version: 2,
    incident_id: null,
    created_at: '2026-07-26T10:00:00Z',
    updated_at: '2026-07-26T10:05:00Z',
  };
}

function answerRow(key: string, displayValue: string) {
  return {
    key,
    label: key,
    type: 'text' as const,
    value: displayValue,
    display_value: displayValue,
    operator_visible: true,
  };
}

function source(path: string): string {
  return readFileSync(new URL(path, import.meta.url), 'utf8');
}
