import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import { webRouteAccess } from '../src/features/auth/webRouteAccess';
import { changedDeploymentPayloadRecords } from '../src/features/deployments/deploymentPatch';
import {
  deploymentRequestApplicableFields,
  deploymentRequestBooleanChoice,
  deploymentRequestCompleteness,
  deploymentRequestDecisionProfileId,
  deploymentRequestTitle,
  deploymentRequestPriorityLabel,
  deploymentRequestRequiredAnswersAreComplete,
  mergeDeploymentRequestChanges,
  mergeQueuedDeploymentRequestChanges,
  type DeploymentRequest,
  type DeploymentRequestWorkflowConfiguration,
} from '../src/features/deployment-requests/deploymentRequestWorkflow';

const configuration: DeploymentRequestWorkflowConfiguration = {
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
  expect(deploymentRequestApplicableFields(configuration, 'person').map((field) => field.key))
    .toEqual(['last_seen_location', 'age']);
  expect(deploymentRequestApplicableFields(configuration, 'animal').map((field) => field.key))
    .toEqual(['last_seen_location', 'animal_type']);
  expect(deploymentRequestApplicableFields(configuration, 'object').map((field) => field.key))
    .toEqual(['last_seen_location']);
});

test('derives completeness from the server-authoritative missing-field list', () => {
  const dossier = dossierFixture();
  expect(deploymentRequestCompleteness(dossier, configuration)).toBe(50);

  dossier.triage.missing_fields = [];
  dossier.triage.state = 'determined';
  expect(deploymentRequestCompleteness(dossier, configuration)).toBe(100);
});

test('allows deployment preparation to flush locally complete answers before checking server triage', () => {
  const deploymentRequest = dossierFixture();

  expect(deploymentRequest.triage.state).toBe('incomplete');
  expect(deploymentRequestRequiredAnswersAreComplete(deploymentRequest, configuration)).toBe(true);

  deploymentRequest.answers.age = null;
  expect(deploymentRequestRequiredAnswersAreComplete(deploymentRequest, configuration)).toBe(false);

  const workspace = source('../src/features/deployment-requests/DeploymentRequestWorkspace.tsx');
  const prepareStart = workspace.indexOf('const prepareDeployment = async () => {');
  const prepareEnd = workspace.indexOf('const closeWithoutDeployment = async () => {');
  expect(prepareStart).toBeGreaterThan(-1);
  expect(prepareEnd).toBeGreaterThan(prepareStart);
  const prepareFlow = workspace.slice(prepareStart, prepareEnd);
  expect(prepareFlow.indexOf('if (!await flushSave()) return;'))
    .toBeLessThan(prepareFlow.indexOf("currentDeploymentRequest.triage.state === 'incomplete'"));
  expect(workspace).toContain('disabled={preparingDeployment || decisionSaving || !requiredAnswersComplete}');
  expect(workspace).not.toContain('disabled={preparingDeployment || decisionSaving || assessmentBlocked || draft.decided_priority === null}');
  expect(workspace).toContain('Leg eerst de beoordeling en het inzetvoorstel vast.');
});

test('merges dirty patches without dropping newer keystrokes and null removes an answer', () => {
  expect(mergeQueuedDeploymentRequestChanges(
    { answers: { name: 'Jan', age: 70 } },
    { answers: { age: 71, clothing: 'Blauwe jas' } },
  )).toEqual({ answers: { name: 'Jan', age: 71, clothing: 'Blauwe jas' } });

  expect(mergeDeploymentRequestChanges(dossierFixture(), {
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
  expect(deploymentRequestBooleanChoice(null)).toBeNull();
  expect(deploymentRequestBooleanChoice(undefined)).toBeNull();
  expect(deploymentRequestBooleanChoice(true)).toBe(true);
  expect(deploymentRequestBooleanChoice(false)).toBe(false);

  const workspace = source('../src/features/deployment-requests/DeploymentRequestWorkspace.tsx');
  expect(workspace).toContain("option={{ value: 'yes', label: 'Ja' }}");
  expect(workspace).toContain("option={{ value: 'no', label: 'Nee' }}");
  expect(workspace).toContain("option={{ value: 'unanswered', label: 'Onbeantwoord' }}");
  expect(workspace).toContain('onSelect={() => onChange(null)}');
  expect(workspace).toContain('selected={selected === false}');
});

test('uses the pre-deployment work queue as the only new-deployment entry point', () => {
  const navigation = source('../src/app/CommandLayout.tsx');
  const deployments = source('../src/features/deployments/DeploymentsPage.tsx');
  const redirect = source('../app/incidents/new/page.tsx');
  const requestsIndex = navigation.indexOf("to: '/aanvragen'");
  const deploymentsIndex = navigation.indexOf("to: '/inzetten'");

  expect(requestsIndex).toBeGreaterThan(-1);
  expect(requestsIndex).toBeLessThan(deploymentsIndex);
  expect(navigation).toContain("'/aanvragen': () => import('../features/deployment-requests/DeploymentRequestListPage')");
  expect(deployments).toContain('href="/aanvragen/new"');
  expect(deployments).not.toContain('href="/inzetten/new"');
  expect(redirect).toContain("redirect('/aanvragen/new')");
  expect(webRouteAccess.deploymentRequests.permissions).toEqual(['deployments.manage']);
});

test('creates no empty deployment request before the centralist chooses person, animal or object', () => {
  const page = source('../src/features/deployment-requests/DeploymentRequestCreatePage.tsx');

  expect(page).toContain('workflow.data.configuration.subject_types.map');
  expect(page).toContain('onClick={() => void createDeploymentRequest({');
  expect(page).toContain("api.post<DeploymentRequest>('/deployment-requests'");
  expect(page).toContain('client_mutation_id: nextAttempt.mutationId');
  expect(page).not.toContain('useEffect(');
});

test('implements conflict-safe autosave, decision and idempotent deployment preparation without alarm calls', () => {
  const workspace = source('../src/features/deployment-requests/DeploymentRequestWorkspace.tsx');

  expect(workspace).toContain('lock_version: mutation.lockVersion');
  expect(workspace).toContain('client_mutation_id: mutation.clientMutationId');
  expect(workspace).toContain("caught.status === 409");
  expect(workspace).toContain("'deployment_request_version_conflict'");
  expect(workspace).toContain('Serverversie laden');
  expect(workspace).toContain('Mijn wijzigingen opnieuw toepassen');
  expect(workspace).toContain("window.addEventListener('online'");
  expect(workspace).toContain("`/deployment-requests/${draft.id}/priority`");
  expect(workspace).toContain('...(planChanged ? {');
  expect(workspace).toContain("`/deployment-requests/${draft.id}/prepare-deployment`");
  expect(workspace).toContain('prepareDeploymentMutationIdRef.current');
  expect(workspace).toContain('client_mutation_id: preparationMutationId');
  expect(workspace).toContain('Er wordt geen alarm verstuurd.');
  expect(workspace).toContain('recommended_recipient_count: deploymentDraft.recipientCount');
  expect(workspace).toContain('recommended_dispatch_mode: deploymentDraft.dispatchMode');
  expect(workspace).toContain('required_certification_type_ids: deploymentDraft.certificationTypeIds');
  expect(workspace).toContain('Dit voorstel selecteert of alarmeert niemand automatisch.');
  expect(workspace).toContain("draft.triage.state === 'incomplete'");
  expect(workspace).toContain('Inzet voorbereiden wordt beschikbaar zodra de kerngegevens compleet zijn.');
  expect(workspace).not.toContain('/dispatch');
});

test('paginates open and closed work queues and reuses the linked request in deployment pages', () => {
  const list = source('../src/features/deployment-requests/DeploymentRequestListPage.tsx');
  const deploymentPanel = source('../src/features/deployment-requests/DeploymentRequestPanel.tsx');
  const deploymentEdit = source('../src/features/deployments/DeploymentEditPage.tsx');
  const realtime = source('../src/lib/realtime.ts');

  expect(list).toContain('`/deployment-requests?status=${view}&per_page=${DEPLOYMENT_REQUESTS_PER_PAGE}&page=${page}`');
  expect(list).toContain("type DeploymentRequestListView = 'open' | 'closed'");
  expect(list).toContain('Afgesloten aanvragen blijven hier raadpleegbaar.');
  expect(list).not.toContain('status=open,prepared');
  expect(list).toContain('pagination.current_page >= pagination.last_page');
  expect(deploymentPanel).toContain('`/deployments/${deploymentId}/deployment-request`');
  expect(deploymentPanel).toContain('allowPreparedEditing');
  expect(deploymentEdit).not.toContain('DeploymentRequestPanel');
  expect(deploymentEdit).toContain('hiddenFieldKeys={deploymentRequestOwnedFieldKeys}');
  expect(deploymentEdit).toContain('changedDeploymentPayload(');
  expect(deploymentEdit).toContain('Aanvraag openen');
  expect(realtime).toContain(".listen('.deployment.changed', options.onOperationalEvent)");
  expect(realtime).toContain("echo.private('deployment-requests')");
  expect(realtime).toContain(".listen('.deployment-request.changed', options.onDeploymentRequestEvent)");
  expect(list).toContain('onDeploymentRequestEvent={() => void reloadDeploymentRequestsSilently()}');
});

test('presents the required Dutch priority vocabulary', () => {
  expect(deploymentRequestPriorityLabel('low')).toBe('Laag');
  expect(deploymentRequestPriorityLabel('medium')).toBe('Middel');
  expect(deploymentRequestPriorityLabel('high')).toBe('Hoog');
  expect(deploymentRequestPriorityLabel('urgent')).toBe('Urgent');
  expect(deploymentRequestPriorityLabel(null)).toBe('Nog niet bepaald');
});

test('does not reuse an incompatible advised deployment profile for a priority override', () => {
  expect(deploymentRequestDecisionProfileId('high', null, 'low', undefined, 'low-response')).toBeNull();
  expect(deploymentRequestDecisionProfileId('low', null, 'low', undefined, 'low-response')).toBe('low-response');
  expect(deploymentRequestDecisionProfileId('high', 'high', 'low', 'high-response', 'low-response')).toBe('high-response');
});

test('uses stable subject-specific fields for person, animal and object work-queue titles', () => {
  expect(deploymentRequestTitle({
    subject_type_label: 'Persoon',
    answer_rows: [
      answerRow('last_seen_at', '18:30'),
      answerRow('person_name', 'Jan de Vries'),
    ],
  })).toBe('Jan de Vries');
  expect(deploymentRequestTitle({
    subject_type_label: 'Dier',
    answer_rows: [
      answerRow('last_seen_location', 'Park'),
      answerRow('animal_species', 'Hond'),
    ],
  })).toBe('Hond');
  expect(deploymentRequestTitle({
    subject_type_label: 'Object',
    answer_rows: [
      answerRow('last_seen_location', 'Station'),
      answerRow('object_category', 'Rugzak'),
    ],
  })).toBe('Rugzak');
});

test('guards dirty deploymentRequest work on unload and flushes it during client-side unmount', () => {
  const workspace = source('../src/features/deployment-requests/DeploymentRequestWorkspace.tsx');

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
  const workspace = source('../src/features/deployment-requests/DeploymentRequestWorkspace.tsx');

  expect(workspace).toContain('const decisionMutationRef = useRef');
  expect(workspace).toContain('decisionMutationRef.current?.signature !== decisionSignature');
  expect(workspace).toContain('const closeMutationRef = useRef');
  expect(workspace).toContain('closeMutationRef.current?.signature !== closeSignature');
});

test('sends only changed deployment fields and key-wise custom-field patches', () => {
  const baseline = {
    title: 'Vermist persoon',
    description: 'Startinformatie',
    priority: 'high',
    custom_fields: {
      public_note: 'Oud',
      deploymentRequest_bound: 'Blijft gelijk',
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

  expect(changedDeploymentPayloadRecords(current, baseline)).toEqual({
    description: 'Aangevulde details',
    custom_fields: {
      public_note: 'Nieuwe informatie',
    },
  });
});

test('focuses an input inside choice groups when a missing-field shortcut is used', () => {
  const workspace = source('../src/features/deployment-requests/DeploymentRequestWorkspace.tsx');

  expect(workspace).toContain('target instanceof HTMLFieldSetElement');
  expect(workspace).toContain("target.querySelector<HTMLInputElement>('input:not(:disabled)')?.focus()");
});

function dossierFixture(): DeploymentRequest {
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
    deployment_id: null,
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
