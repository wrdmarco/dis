import type { Incident } from '../../types/api';

export type IntakeSubjectType = 'person' | 'animal' | 'object';
export type IntakePriority = 'low' | 'medium' | 'high' | 'urgent';
export type IntakeDossierStatus = 'open' | 'promoted' | 'closed';
export type IntakeTriageState = 'incomplete' | 'unknown' | 'determined';
export type IntakeFieldType = 'section' | 'text' | 'textarea' | 'number' | 'select' | 'radio' | 'checkbox' | 'date' | 'datetime';

export interface IntakeSubjectOption {
  key: IntakeSubjectType;
  label: string;
}

export interface IntakeFieldOption {
  value: string;
  label: string;
}

export interface IntakeWorkflowField {
  key: string;
  label: string;
  type: IntakeFieldType;
  scope: 'common' | IntakeSubjectType;
  required: boolean;
  operator_visible: boolean;
  help_text?: string | null;
  options?: IntakeFieldOption[];
}

export interface IntakeWorkflowBinding {
  field_key: string;
  target: string;
}

export interface IntakeWorkflowConfiguration {
  subject_types: IntakeSubjectOption[];
  fields: IntakeWorkflowField[];
  bindings: IntakeWorkflowBinding[];
  priority_rules: unknown[];
  deployment_profiles: unknown[];
}

export interface IntakeWorkflowRevision {
  id: string;
  version: number;
  status: string;
  lock_version: number;
  configuration: IntakeWorkflowConfiguration;
  published_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface IntakeAnswerRow {
  key: string;
  label: string;
  type: IntakeFieldType;
  value: unknown;
  display_value: string;
  section?: string | null;
  operator_visible: boolean;
}

export interface IntakeMissingField {
  key: string;
  label: string;
}

export interface IntakeDeploymentProposal {
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

export interface IntakeDossier {
  id: string;
  status: IntakeDossierStatus;
  subject_type: IntakeSubjectType;
  subject_type_label: string;
  workflow_revision: {
    id: string;
    version: number;
  };
  answers: Record<string, unknown>;
  answer_rows: IntakeAnswerRow[];
  triage: {
    state: IntakeTriageState;
    recommended_priority: IntakePriority | null;
    reasons: string[];
    missing_fields: IntakeMissingField[];
  };
  decided_priority: IntakePriority | null;
  priority_override_reason: string | null;
  deployment_proposal: IntakeDeploymentProposal | null;
  selected_deployment_proposal: IntakeDeploymentProposal | null;
  lock_version: number;
  incident_id: string | null;
  created_at: string;
  updated_at: string;
}

export interface IntakeDossierPage {
  data: IntakeDossier[];
  meta?: {
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
  };
}

export interface IntakePromotion {
  dossier: IntakeDossier;
  incident: Incident;
}

export interface IntakeChanges {
  subject_type?: IntakeSubjectType;
  answers?: Record<string, unknown | null>;
}

export type IntakeSaveState = 'saved' | 'dirty' | 'saving' | 'offline' | 'conflict' | 'error';

export const intakePriorityOptions = [
  { value: 'low', label: 'Laag' },
  { value: 'medium', label: 'Middel' },
  { value: 'high', label: 'Hoog' },
  { value: 'urgent', label: 'Urgent' },
] as const satisfies ReadonlyArray<{ value: IntakePriority; label: string }>;

export function intakePriorityLabel(priority: IntakePriority | null): string {
  return intakePriorityOptions.find((option) => option.value === priority)?.label ?? 'Nog niet bepaald';
}

export function intakePriorityTone(priority: IntakePriority | null): 'neutral' | 'good' | 'warn' | 'bad' {
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

export function intakeDecisionProfileId(
  decisionPriority: IntakePriority,
  existingDecisionPriority: IntakePriority | null,
  recommendedPriority: IntakePriority | null,
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

export function intakeDossierStatusLabel(status: IntakeDossierStatus): string {
  switch (status) {
    case 'open':
      return 'In uitvraag';
    case 'promoted':
      return 'Incident aangemaakt';
    case 'closed':
      return 'Afgesloten';
  }
}

export function intakeDossierStatusTone(status: IntakeDossierStatus): 'neutral' | 'good' | 'warn' {
  if (status === 'open') return 'warn';
  if (status === 'promoted') return 'good';
  return 'neutral';
}

export function intakeDossierTitle(
  dossier: Pick<IntakeDossier, 'answer_rows' | 'subject_type_label'>,
): string {
  const preferredKeys = [
    'person_name',
    'animal_name',
    'animal_species',
    'object_category',
    'last_seen_location',
  ];
  for (const key of preferredKeys) {
    const answer = dossier.answer_rows.find((candidate) => (
      candidate.key === key && candidate.display_value.trim() !== ''
    ));
    if (answer) return answer.display_value;
  }

  const fallback = dossier.answer_rows.find((answer) => answer.display_value.trim() !== '');
  return fallback?.display_value ?? `Uitvraag ${dossier.subject_type_label.toLowerCase()}`;
}

export function intakeApplicableFields(
  configuration: IntakeWorkflowConfiguration,
  subjectType: IntakeSubjectType,
): IntakeWorkflowField[] {
  return configuration.fields.filter((field) => field.scope === 'common' || field.scope === subjectType);
}

export function intakeCommonFields(configuration: IntakeWorkflowConfiguration): IntakeWorkflowField[] {
  return configuration.fields.filter((field) => field.scope === 'common');
}

export function intakeBranchFields(
  configuration: IntakeWorkflowConfiguration,
  subjectType: IntakeSubjectType,
): IntakeWorkflowField[] {
  return configuration.fields.filter((field) => field.scope === subjectType);
}

export function intakeCompleteness(
  dossier: IntakeDossier,
  configuration: IntakeWorkflowConfiguration,
): number {
  const required = intakeApplicableFields(configuration, dossier.subject_type)
    .filter((field) => field.required && field.type !== 'section');
  if (required.length === 0) {
    return dossier.triage.state === 'incomplete' ? 0 : 100;
  }

  const missingKeys = new Set(dossier.triage.missing_fields.map((field) => field.key));
  const completed = required.reduce((total, field) => total + (missingKeys.has(field.key) ? 0 : 1), 0);

  return Math.round((completed / required.length) * 100);
}

export function intakeSaveLabel(state: IntakeSaveState): string {
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

export function intakeFieldIsAnswered(field: IntakeWorkflowField, value: unknown): boolean {
  if (field.type === 'checkbox') return typeof value === 'boolean';
  if (Array.isArray(value)) return value.length > 0;
  if (typeof value === 'string') return value.trim() !== '';
  return value !== null && value !== undefined;
}

export function intakeBooleanChoice(value: unknown): boolean | null {
  return typeof value === 'boolean' ? value : null;
}

export function mergeIntakeChanges(base: IntakeDossier, changes: IntakeChanges): IntakeDossier {
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

export function mergeQueuedIntakeChanges(older: IntakeChanges, newer: IntakeChanges): IntakeChanges {
  return {
    ...(older.subject_type === undefined ? {} : { subject_type: older.subject_type }),
    ...(newer.subject_type === undefined ? {} : { subject_type: newer.subject_type }),
    answers: {
      ...(older.answers ?? {}),
      ...(newer.answers ?? {}),
    },
  };
}

export function intakeHasChanges(changes: IntakeChanges): boolean {
  return changes.subject_type !== undefined || Object.keys(changes.answers ?? {}).length > 0;
}

export function bindingLabel(target: string): string {
  const labels: Record<string, string> = {
    title: 'Incidenttitel',
    description: 'Incidentomschrijving',
    reporter_name: 'Naam melder',
    reporter_phone: 'Telefoon melder',
    location_label: 'Incidentlocatie',
    requesting_organization: 'Aanvragende organisatie',
    requesting_unit: 'Aanvragende eenheid',
    on_scene_contact_name: 'Contact ter plaatse',
    on_scene_contact_phone: 'Telefoon ter plaatse',
    on_scene_contact_role: 'Rol ter plaatse',
    required_resources: 'Benodigde middelen',
  };

  return labels[target] ?? 'Incidentgegeven';
}

export function makeIntakeMutationId(): string {
  if (typeof globalThis.crypto?.randomUUID === 'function') {
    return globalThis.crypto.randomUUID();
  }

  return `web-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function conflictDossier(details: Record<string, unknown> | undefined): IntakeDossier | null {
  const current = details?.current;
  if (current === null || typeof current !== 'object') return null;
  const candidate = current as Partial<IntakeDossier>;

  return typeof candidate.id === 'string' && Number.isSafeInteger(candidate.lock_version)
    ? candidate as IntakeDossier
    : null;
}
