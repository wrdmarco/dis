import type { Deployment } from '../../types/api';

export type DeploymentRequestSubjectType = 'person' | 'animal' | 'object';
export type DeploymentRequestPriority = 'low' | 'medium' | 'high' | 'urgent';
export type DeploymentRequestStatus = 'open' | 'prepared' | 'closed';
export type DeploymentRequestTriageState = 'incomplete' | 'unknown' | 'determined';
export type DeploymentRequestFieldType = 'section' | 'text' | 'textarea' | 'address' | 'number' | 'select' | 'radio' | 'checkbox' | 'date' | 'datetime';

export interface DeploymentRequestSubjectOption {
  key: DeploymentRequestSubjectType;
  label: string;
}

export interface DeploymentRequestFieldOption {
  value: string;
  label: string;
}

export interface DeploymentRequestWorkflowField {
  key: string;
  label: string;
  type: DeploymentRequestFieldType;
  scope: 'common' | DeploymentRequestSubjectType;
  required: boolean;
  operator_visible: boolean;
  help_text?: string | null;
  options?: DeploymentRequestFieldOption[];
}

export interface DeploymentRequestWorkflowBinding {
  field_key: string;
  target: string;
}

export interface DeploymentRequestWorkflowConfiguration {
  subject_types: DeploymentRequestSubjectOption[];
  fields: DeploymentRequestWorkflowField[];
  bindings: DeploymentRequestWorkflowBinding[];
  priority_rules: unknown[];
  deployment_profiles: unknown[];
}

export interface DeploymentRequestWorkflowRevision {
  id: string;
  version: number;
  status: string;
  lock_version: number;
  configuration: DeploymentRequestWorkflowConfiguration;
  published_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface DeploymentRequestAnswerRow {
  key: string;
  label: string;
  type: DeploymentRequestFieldType;
  value: unknown;
  display_value: string;
  section?: string | null;
  operator_visible: boolean;
}

export interface DeploymentRequestPilotVisibleChange {
  key: string;
  label: string;
  display_value: string;
}

export interface DeploymentRequestMissingField {
  key: string;
  label: string;
}

export interface DeploymentProposal {
  profile_id: string | null;
  label: string;
  summary: string | null;
  team_ids: string[];
  teams: Array<{ id: string; name: string }>;
  resources: string[];
  recommended_recipient_count: number | null;
  recommended_dispatch_mode: 'preannouncement' | 'direct_dispatch' | null;
  required_certification_type_ids: string[];
  required_certification_types: Array<{ id: string; code: string; name: string }>;
  notes?: string | null;
}

export interface DeploymentRequest {
  id: string;
  status: DeploymentRequestStatus;
  subject_type: DeploymentRequestSubjectType;
  subject_type_label: string;
  workflow_revision: {
    id: string;
    version: number;
  };
  answers: Record<string, unknown>;
  answer_rows: DeploymentRequestAnswerRow[];
  triage: {
    state: DeploymentRequestTriageState;
    recommended_priority: DeploymentRequestPriority | null;
    reasons: string[];
    missing_fields: DeploymentRequestMissingField[];
  };
  decided_priority: DeploymentRequestPriority | null;
  priority_override_reason: string | null;
  deployment_proposal: DeploymentProposal | null;
  selected_deployment_proposal: DeploymentProposal | null;
  lock_version: number;
  deployment_id: string | null;
  created_at: string;
  updated_at: string;
}

export interface DeploymentRequestPage {
  data: DeploymentRequest[];
  meta?: {
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
  };
}

export interface PrepareDeploymentResponse {
  deployment_request: DeploymentRequest;
  deployment: Deployment;
}

export interface DeploymentRequestChanges {
  subject_type?: DeploymentRequestSubjectType;
  answers?: Record<string, unknown | null>;
}

export type DeploymentRequestSaveState = 'saved' | 'dirty' | 'saving' | 'offline' | 'conflict' | 'error';

export const deploymentRequestPriorityOptions = [
  { value: 'low', label: 'Laag' },
  { value: 'medium', label: 'Middel' },
  { value: 'high', label: 'Hoog' },
  { value: 'urgent', label: 'Urgent' },
] as const satisfies ReadonlyArray<{ value: DeploymentRequestPriority; label: string }>;

export function deploymentRequestPriorityLabel(priority: DeploymentRequestPriority | null): string {
  return deploymentRequestPriorityOptions.find((option) => option.value === priority)?.label ?? 'Nog niet bepaald';
}

export function deploymentRequestPriorityTone(priority: DeploymentRequestPriority | null): 'neutral' | 'good' | 'warn' | 'bad' {
  switch (priority) {
    case 'low':
      return 'good';
    case 'medium':
    case 'high':
      return 'warn';
    case 'urgent':
      return 'bad';
    default:
      return 'neutral';
  }
}

export function deploymentRequestDecisionProfileId(
  decisionPriority: DeploymentRequestPriority,
  existingDecisionPriority: DeploymentRequestPriority | null,
  recommendedPriority: DeploymentRequestPriority | null,
  selectedProfileId: string | null | undefined,
  recommendedProfileId: string | null | undefined,
): string | null {
  if (decisionPriority === existingDecisionPriority && selectedProfileId !== undefined) {
    return selectedProfileId;
  }
  if (decisionPriority === recommendedPriority) {
    return recommendedProfileId ?? null;
  }

  return null;
}

export function deploymentRequestSuggestedDecisionPriority(
  deploymentRequest: Pick<DeploymentRequest, 'decided_priority' | 'triage'>,
): DeploymentRequestPriority | null {
  return deploymentRequest.decided_priority
    ?? deploymentRequest.triage.recommended_priority;
}

export function deploymentRequestStatusLabel(status: DeploymentRequestStatus): string {
  switch (status) {
    case 'open':
      return 'In uitvraag';
    case 'prepared':
      return 'Inzet voorbereid';
    case 'closed':
      return 'Afgesloten';
  }
}

export function deploymentRequestStatusTone(status: DeploymentRequestStatus): 'neutral' | 'good' | 'warn' {
  if (status === 'open') return 'warn';
  if (status === 'prepared') return 'good';
  return 'neutral';
}

export function deploymentRequestTitle(
  deploymentRequest: Pick<DeploymentRequest, 'answer_rows' | 'subject_type_label'>,
): string {
  const preferredKeys = [
    'person_name',
    'animal_name',
    'animal_species',
    'object_category',
    'last_seen_location',
  ];
  for (const key of preferredKeys) {
    const answer = deploymentRequest.answer_rows.find((candidate) => (
      candidate.key === key && candidate.display_value.trim() !== ''
    ));
    if (answer) return answer.display_value;
  }

  const fallback = deploymentRequest.answer_rows.find((answer) => answer.display_value.trim() !== '');
  return fallback?.display_value ?? `Uitvraag ${deploymentRequest.subject_type_label.toLowerCase()}`;
}

export function deploymentRequestPilotVisibleAnswers(
  deploymentRequest: Pick<DeploymentRequest, 'answer_rows'>,
): DeploymentRequestAnswerRow[] {
  return deploymentRequest.answer_rows.filter((answer) => (
    answer.operator_visible && answer.display_value.trim() !== ''
  ));
}

export function deploymentRequestPilotVisibleChanges(
  before: Pick<DeploymentRequest, 'answer_rows'>,
  after: Pick<DeploymentRequest, 'answer_rows'>,
): DeploymentRequestPilotVisibleChange[] {
  const beforeByKey = new Map(
    before.answer_rows
      .filter((answer) => answer.operator_visible)
      .map((answer) => [answer.key, answer]),
  );
  const afterByKey = new Map(
    after.answer_rows
      .filter((answer) => answer.operator_visible)
      .map((answer) => [answer.key, answer]),
  );
  const keys = [...new Set([...beforeByKey.keys(), ...afterByKey.keys()])];

  return keys.flatMap((key) => {
    const previous = beforeByKey.get(key);
    const current = afterByKey.get(key);
    const previousValue = previous?.display_value.trim() ?? '';
    const currentValue = current?.display_value.trim() ?? '';
    if (previousValue === currentValue) return [];

    return [{
      key,
      label: current?.label ?? previous?.label ?? key,
      display_value: currentValue,
    }];
  });
}

export function deploymentRequestPilotVisibleChangesMessage(
  changes: DeploymentRequestPilotVisibleChange[],
): string {
  return [
    'Aanvulling inzetinformatie:',
    ...changes.map((change) => (
      `- ${change.label}: ${change.display_value || 'Niet langer ingevuld'}`
    )),
  ].join('\n');
}

export function deploymentRequestApplicableFields(
  configuration: DeploymentRequestWorkflowConfiguration,
  subjectType: DeploymentRequestSubjectType,
): DeploymentRequestWorkflowField[] {
  return configuration.fields.filter((field) => field.scope === 'common' || field.scope === subjectType);
}

export function deploymentRequestCommonFields(configuration: DeploymentRequestWorkflowConfiguration): DeploymentRequestWorkflowField[] {
  return configuration.fields.filter((field) => field.scope === 'common');
}

export function deploymentRequestBranchFields(
  configuration: DeploymentRequestWorkflowConfiguration,
  subjectType: DeploymentRequestSubjectType,
): DeploymentRequestWorkflowField[] {
  return configuration.fields.filter((field) => field.scope === subjectType);
}

export function deploymentRequestCompleteness(
  deploymentRequest: DeploymentRequest,
  configuration: DeploymentRequestWorkflowConfiguration,
): number {
  const required = deploymentRequestApplicableFields(configuration, deploymentRequest.subject_type)
    .filter((field) => field.required && field.type !== 'section');
  if (required.length === 0) {
    return deploymentRequest.triage.state === 'incomplete' ? 0 : 100;
  }

  const missingKeys = new Set(deploymentRequest.triage.missing_fields.map((field) => field.key));
  const completed = required.reduce((total, field) => total + (missingKeys.has(field.key) ? 0 : 1), 0);

  return Math.round((completed / required.length) * 100);
}

export function deploymentRequestRequiredAnswersAreComplete(
  deploymentRequest: Pick<DeploymentRequest, 'answers' | 'subject_type'>,
  configuration: DeploymentRequestWorkflowConfiguration,
): boolean {
  return deploymentRequestApplicableFields(configuration, deploymentRequest.subject_type)
    .filter((field) => field.required && field.type !== 'section')
    .every((field) => deploymentRequestFieldIsAnswered(field, deploymentRequest.answers[field.key]));
}

export function deploymentRequestSaveLabel(state: DeploymentRequestSaveState): string {
  switch (state) {
    case 'dirty':
      return 'Wijzigingen opslaan…';
    case 'saving':
      return 'Opslaan…';
    case 'offline':
      return 'Offline · nog niet opgeslagen';
    case 'conflict':
      return 'Nieuwere serverversie gevonden';
    case 'error':
      return 'Opslaan mislukt';
    default:
      return 'Opgeslagen';
  }
}

export function deploymentRequestFieldIsAnswered(field: DeploymentRequestWorkflowField, value: unknown): boolean {
  if (field.type === 'checkbox') return typeof value === 'boolean';
  if (Array.isArray(value)) return value.length > 0;
  if (typeof value === 'string') return value.trim() !== '';
  return value !== null && value !== undefined;
}

export function deploymentRequestBooleanChoice(value: unknown): boolean | null {
  return typeof value === 'boolean' ? value : null;
}

export function mergeDeploymentRequestChanges(base: DeploymentRequest, changes: DeploymentRequestChanges): DeploymentRequest {
  const answers = { ...base.answers };
  for (const [key, value] of Object.entries(changes.answers ?? {})) {
    if (value === null) {
      delete answers[key];
    } else {
      answers[key] = value;
    }
  }

  return {
    ...base,
    subject_type: changes.subject_type ?? base.subject_type,
    answers,
  };
}

export function mergeQueuedDeploymentRequestChanges(
  older: DeploymentRequestChanges,
  newer: DeploymentRequestChanges,
): DeploymentRequestChanges {
  return {
    ...(older.subject_type === undefined ? {} : { subject_type: older.subject_type }),
    ...(newer.subject_type === undefined ? {} : { subject_type: newer.subject_type }),
    answers: {
      ...(older.answers ?? {}),
      ...(newer.answers ?? {}),
    },
  };
}

export function rebaseDeploymentRequestTeamIds(
  localTeamIds: string[],
  baselineTeamIds: string[],
  serverTeamIds: string[],
): string[] {
  const baseline = new Set(baselineTeamIds);
  const local = new Set(localTeamIds);
  const removed = new Set(baselineTeamIds.filter((teamId) => !local.has(teamId)));
  const added = localTeamIds.filter((teamId) => !baseline.has(teamId));

  return [...new Set([
    ...serverTeamIds.filter((teamId) => !removed.has(teamId)),
    ...added,
  ])];
}

export function deploymentRequestHasChanges(changes: DeploymentRequestChanges): boolean {
  return changes.subject_type !== undefined || Object.keys(changes.answers ?? {}).length > 0;
}

export function bindingLabel(target: string): string {
  const labels: Record<string, string> = {
    title: 'Inzettitel',
    description: 'Inzetomschrijving',
    reporter_name: 'Naam melder',
    reporter_phone: 'Telefoon melder',
    location_label: 'Inzetlocatie',
    requesting_organization: 'Aanvragende organisatie',
    requesting_unit: 'Aanvragende eenheid',
    on_scene_contact_name: 'Contact ter plaatse',
    on_scene_contact_phone: 'Telefoon ter plaatse',
    on_scene_contact_role: 'Rol ter plaatse',
    required_resources: 'Benodigde middelen',
  };

  return labels[target] ?? 'Inzetgegeven';
}

export function makeDeploymentRequestMutationId(): string {
  if (typeof globalThis.crypto?.randomUUID === 'function') {
    return globalThis.crypto.randomUUID();
  }

  return `web-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function conflictDeploymentRequest(details: Record<string, unknown> | undefined): DeploymentRequest | null {
  const current = details?.current;
  if (current === null || typeof current !== 'object') return null;
  const candidate = current as Partial<DeploymentRequest>;

  return typeof candidate.id === 'string' && Number.isSafeInteger(candidate.lock_version)
    ? candidate as DeploymentRequest
    : null;
}
