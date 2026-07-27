import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import { webRouteAccess } from '../src/features/auth/webRouteAccess';
import { changedDeploymentPayloadRecords } from '../src/features/deployments/deploymentPatch';
import {
  deploymentRequestApplicableFields,
  deploymentRequestBooleanChoice,
  deploymentRequestCompleteness,
  deploymentRequestDecisionProfileId,
  deploymentRequestPilotVisibleAnswers,
  deploymentRequestPilotVisibleChanges,
  deploymentRequestPilotVisibleChangesMessage,
  deploymentRequestTitle,
  deploymentRequestPriorityLabel,
  deploymentRequestRequiredAnswersAreComplete,
  deploymentRequestSuggestedDecisionPriority,
  mergeDeploymentRequestChanges,
  mergeQueuedDeploymentRequestChanges,
  rebaseDeploymentRequestTeamIds,
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
      label: 'Laatst gezien locatie',
      type: 'address',
      scope: 'common',
      required: true,
      operator_visible: true,
    },
    {
      key: 'last_seen_at',
      label: 'Laatst gezien datum en tijd',
      type: 'datetime',
      scope: 'common',
      required: false,
      operator_visible: true,
    },
    {
      key: 'deployment_location',
      label: 'Opkomstlocatie',
      type: 'address',
      scope: 'common',
      required: false,
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
  bindings: [{ field_key: 'deployment_location', target: 'location_label' }],
  priority_rules: [],
  deployment_profiles: [],
};

test('keeps common fields and exactly one subject branch in the questionnaire', () => {
  expect(deploymentRequestApplicableFields(configuration, 'person').map((field) => field.key))
    .toEqual(['last_seen_location', 'last_seen_at', 'deployment_location', 'age']);
  expect(deploymentRequestApplicableFields(configuration, 'animal').map((field) => field.key))
    .toEqual(['last_seen_location', 'last_seen_at', 'deployment_location', 'animal_type']);
  expect(deploymentRequestApplicableFields(configuration, 'object').map((field) => field.key))
    .toEqual(['last_seen_location', 'last_seen_at', 'deployment_location']);
});

test('renders address workflow fields independently without changing the datetime field', () => {
  const workspace = source('../src/features/deployment-requests/DeploymentRequestWorkspace.tsx');
  const addressAutocomplete = source('../src/components/AddressAutocomplete.tsx');

  expect(configuration.fields.find((field) => field.key === 'last_seen_location')?.type).toBe('address');
  expect(configuration.fields.find((field) => field.key === 'deployment_location')?.type).toBe('address');
  expect(configuration.fields.find((field) => field.key === 'last_seen_at')?.type).toBe('datetime');
  expect(configuration.bindings).toEqual([
    { field_key: 'deployment_location', target: 'location_label' },
  ]);
  expect(workspace).toContain("field.type === 'address'");
  expect(workspace).toContain('<AddressAutocomplete');
  expect(workspace).toContain("field.type === 'datetime'");
  expect(workspace).toContain("'datetime-local'");
  expect(addressAutocomplete).toContain('fetchLocationSuggestions(query, controller.signal)');
  expect(addressAutocomplete).toContain('const SEARCH_DELAY_MS = 250');
  expect(addressAutocomplete).toContain('const MINIMUM_QUERY_LENGTH = 3');
  expect(addressAutocomplete).toContain('const MAXIMUM_LABEL_LENGTH = 255');
  expect(addressAutocomplete).toContain('new AbortController()');
  expect(addressAutocomplete).toContain('role="combobox"');
  expect(addressAutocomplete).toContain('role="listbox"');
  expect(addressAutocomplete).toContain("event.key === 'ArrowDown'");
  expect(addressAutocomplete).toContain("'ArrowUp'");
  expect(addressAutocomplete).toContain("event.key === 'Enter'");
  expect(addressAutocomplete).toContain("event.key === 'Escape'");
  expect(addressAutocomplete).toContain('Je kunt de locatie handmatig invullen.');
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
  expect(prepareFlow.indexOf('const persisted = await persistAllChanges();'))
    .toBeLessThan(prepareFlow.indexOf("currentDeploymentRequest.triage.state === 'incomplete'"));
  expect(prepareFlow).toContain('let currentDeploymentRequest = persisted;');
  expect(prepareFlow).not.toContain('Leg de aangepaste beoordeling en het inzetvoorstel eerst afzonderlijk vast.');
  expect(workspace).toContain('disabled={interactionLocked || decisionSaving || !requiredAnswersComplete}');
  expect(workspace).not.toContain('disabled={preparingDeployment || decisionSaving || assessmentBlocked || draft.decided_priority === null}');
  expect(prepareFlow).toContain('currentDeploymentRequest = adoptActionResponse(decided)');
  expect(prepareFlow).toContain('selected_deployment_profile_id: currentDeploymentRequest.deployment_proposal?.profile_id ?? null');
  expect(workspace).toContain('Het geadviseerde inzetvoorstel en de teams worden bij voorbereiden automatisch vastgelegd.');
  expect(prepareFlow.indexOf("window.confirm('Conceptinzet voorbereiden"))
    .toBeLessThan(prepareFlow.indexOf('currentDeploymentRequest = adoptActionResponse(decided)'));
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

test('rebases explicit team additions and removals without dropping concurrent server teams', () => {
  expect(rebaseDeploymentRequestTeamIds(
    ['team-c'],
    ['team-a'],
    ['team-a', 'team-b'],
  )).toEqual(['team-b', 'team-c']);
  expect(rebaseDeploymentRequestTeamIds(
    [],
    ['team-a'],
    ['team-a'],
  )).toEqual([]);
  expect(rebaseDeploymentRequestTeamIds(
    ['team-a', 'team-c'],
    ['team-a'],
    ['team-b'],
  )).toEqual(['team-b', 'team-c']);
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
  expect(workspace).toContain('const serverDeploymentDraft = deploymentFormFromRequest(conflict.current)');
  expect(workspace).toContain('deploymentDraftBaselineRef.current');
  expect(workspace).toContain('rebaseDeploymentRequestTeamIds(local.teamIds, baseline.teamIds, server.teamIds)');
  expect(workspace).toContain("dirtyFields.has('teamIds')");
  expect(workspace).toContain("dirtyFields.has('resources')");
  expect(workspace).toContain("dirtyFields.has('recipientCount')");
  expect(workspace).toContain("dirtyFields.has('dispatchMode')");
  expect(workspace).toContain("dirtyFields.has('notes')");
  expect(workspace).toContain('deploymentFormDirtyFields(');
  expect(workspace).toContain('deploymentDraftRef.current = rebasedDeploymentDraft');
  expect(workspace).toContain('decisionReasonAdjustedRef.current');
  expect(workspace).toContain('const effectiveDecisionReason = decisionReasonAdjustedRef.current');
  expect(workspace).toContain('saveAllInFlightRef.current === null');
  expect(workspace).toContain('window.setTimeout(() => void saveAllRef.current(), 0)');
  expect(workspace).toContain("window.addEventListener('online'");
  expect(workspace).toContain("`/deployment-requests/${current.id}/priority`");
  expect(workspace).toContain('...(currentPlanChanged ? {');
  expect(workspace).toContain("`/deployment-requests/${currentDeploymentRequest.id}/prepare-deployment`");
  expect(workspace).toContain('prepareDeploymentMutationIdRef.current');
  expect(workspace).toContain('client_mutation_id: preparationMutationId');
  expect(workspace).toContain('Er wordt geen alarm verstuurd.');
  expect(workspace).toContain('recommended_recipient_count: effectiveDeploymentDraft.recipientCount');
  expect(workspace).toContain('recommended_dispatch_mode: effectiveDeploymentDraft.dispatchMode');
  expect(workspace).not.toContain('required_certification_type_ids:');
  expect(workspace).not.toContain('certificationTypeIds');
  expect(workspace).not.toContain('/certifications/options');
  expect(workspace).not.toContain('Vereiste certificaattypen');
  expect(workspace).toContain('Het geadviseerde voorstel en de bijbehorende teams zijn vooraf geselecteerd.');
  expect(workspace).toContain('teamIds: proposal?.team_ids ?? []');
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
  expect(deploymentPanel).toContain('title="Belangrijke inzetinformatie"');
  expect(deploymentPanel).toContain('modal--deployment-request');
  expect(deploymentPanel).toContain('Gewijzigde pilootinformatie ook versturen');
  expect(deploymentPanel).toContain('deploymentRequestPilotVisibleAnswers');
  expect(deploymentEdit).not.toContain('DeploymentRequestPanel');
  expect(deploymentEdit).toContain('hiddenFieldKeys={deploymentRequestOwnedFieldKeys}');
  expect(deploymentEdit).toContain('changedDeploymentPayload(');
  expect(deploymentEdit).toContain('Aanvraag openen');
  expect(realtime).toContain(".listen('.deployment.changed', options.onOperationalEvent)");
  expect(realtime).toContain("echo.private('deployment-requests')");
  expect(realtime).toContain(".listen('.deployment-request.changed', options.onDeploymentRequestEvent)");
  expect(list).toContain('onDeploymentRequestEvent={() => void reloadDeploymentRequestsSilently()}');
});

test('shows and forwards only changed answers explicitly visible to pilots', () => {
  const before = {
    answer_rows: [
      answerRow('last_seen_location', 'Stationsplein'),
      { ...answerRow('contact_phone', '0612345678'), operator_visible: false },
      answerRow('clothing', 'Blauwe jas'),
    ],
  };
  const after = {
    answer_rows: [
      answerRow('last_seen_location', 'Marktplein'),
      { ...answerRow('contact_phone', '0687654321'), operator_visible: false },
    ],
  };

  expect(deploymentRequestPilotVisibleAnswers(after).map((answer) => answer.key))
    .toEqual(['last_seen_location']);
  const changes = deploymentRequestPilotVisibleChanges(before, after);
  expect(changes).toEqual([
    {
      key: 'last_seen_location',
      label: 'last_seen_location',
      display_value: 'Marktplein',
    },
    {
      key: 'clothing',
      label: 'clothing',
      display_value: '',
    },
  ]);
  expect(deploymentRequestPilotVisibleChangesMessage(changes)).toBe(
    'Aanvulling inzetinformatie:\n'
      + '- last_seen_location: Marktplein\n'
      + '- clothing: Niet langer ingevuld',
  );
});

test('persists modal edits before optionally sending the pilot-visible subset', () => {
  const panel = source('../src/features/deployment-requests/DeploymentRequestPanel.tsx');
  const workspace = source('../src/features/deployment-requests/DeploymentRequestWorkspace.tsx');
  const finishStart = panel.indexOf('const finishEditing = async');
  const finishEnd = panel.indexOf('\n  return (', finishStart);
  const finishFlow = panel.slice(finishStart, finishEnd);
  const persistStart = workspace.indexOf('const performPersistAllChanges = async');
  const persistEnd = workspace.indexOf('\n  const saveDecision = async', persistStart);
  const persistFlow = workspace.slice(persistStart, persistEnd);

  expect(finishStart).toBeGreaterThan(-1);
  expect(persistStart).toBeGreaterThan(-1);
  expect(finishFlow.indexOf('workspaceRef.current.savePendingChanges()'))
    .toBeLessThan(finishFlow.indexOf('deploymentRequestPilotVisibleChanges(editBaseline, persisted)'));
  expect(finishFlow.indexOf('deploymentRequestPilotVisibleChanges(editBaseline, persisted)'))
    .toBeLessThan(finishFlow.indexOf('await onSendAdditionalInfo(message)'));
  expect(finishFlow).toContain('touchedAnswerKeysRef.current.has(change.key)');
  expect(finishFlow).toContain('if (message.length > 2000)');
  expect(panel.indexOf('modal--deployment-request'))
    .toBeLessThan(panel.indexOf('              <DeploymentRequestWorkspace'));
  expect(workspace).toContain('savePendingChanges: persistAllChanges');
  expect(persistFlow.indexOf('if (!await flushSave()) return null;'))
    .toBeLessThan(persistFlow.indexOf('await requestDecision(current, desiredDecision)'));
  expect(persistFlow).toContain('decisionSelectionAdjustedRef.current');
  expect(persistFlow).toContain('deploymentDraftAdjustedRef.current');
  expect(persistFlow).toContain('team_ids: effectiveDeploymentDraft.teamIds');
  expect(persistFlow).toContain('prioritySelectionAdjustedRef.current');
  expect(persistFlow).toContain('deploymentFormFromRequest(current)');
  expect(workspace).toContain('saveAllRef.current()');
  expect(workspace).toContain('saveAllInFlightRef.current');
  expect(panel).toContain('interactionDisabled={finishingEdit}');
  expect(workspace).toContain('onReasonChange={(reason) => {');
  expect(panel).toContain('Wijzigingen opslaan en sluiten');
  expect(panel).not.toContain('Antwoorden opslaan en sluiten');
});

test('prefills the current recommendation while preserving an explicit decision', () => {
  const deploymentRequest = dossierFixture();
  deploymentRequest.triage.recommended_priority = 'medium';

  expect(deploymentRequestSuggestedDecisionPriority(deploymentRequest)).toBe('medium');
  deploymentRequest.decided_priority = 'high';
  expect(deploymentRequestSuggestedDecisionPriority(deploymentRequest)).toBe('high');
  deploymentRequest.decided_priority = null;
  deploymentRequest.triage.recommended_priority = null;
  expect(deploymentRequestSuggestedDecisionPriority(deploymentRequest)).toBeNull();
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
  expect(workspace).toContain('lock_version: current.lock_version');
  expect(workspace).toContain("setSaveState('dirty')");
  expect(workspace).toContain("setSaveState('conflict')");
  expect(workspace).toContain('const shown = selected ?? proposal;');
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
