import type { IncidentSubjectType } from '../../types/api';

export type IntakeWorkflowFieldType =
  | 'section'
  | 'text'
  | 'textarea'
  | 'number'
  | 'select'
  | 'radio'
  | 'checkbox'
  | 'date'
  | 'datetime';
export type IntakeWorkflowScope = 'common' | IncidentSubjectType;
export type IntakeWorkflowPriority = 'low' | 'medium' | 'high' | 'urgent';
export type IntakeWorkflowRuleMatch = 'all' | 'any';
export type IntakeWorkflowConditionOperator =
  | 'equals'
  | 'not_equals'
  | 'contains'
  | 'greater_than_or_equal'
  | 'less_than_or_equal'
  | 'is_true'
  | 'is_false'
  | 'is_present';

export interface IntakeWorkflowOption {
  label: string;
  value: string;
}

export interface IntakeWorkflowSubjectTypeOption {
  key: IncidentSubjectType;
  label: string;
}

export interface IntakeWorkflowField {
  key: string;
  label: string;
  type: IntakeWorkflowFieldType;
  scope: IntakeWorkflowScope;
  required: boolean;
  operator_visible: boolean;
  help_text?: string | null;
  options: IntakeWorkflowOption[];
}

export interface IntakeWorkflowBinding {
  field_key: string;
  target: string;
}

export interface IntakeWorkflowCondition {
  field_key: string;
  operator: IntakeWorkflowConditionOperator;
  value?: unknown;
}

export interface IntakeWorkflowPriorityRule {
  id: string;
  label: string;
  subject_types: IncidentSubjectType[];
  match: IntakeWorkflowRuleMatch;
  conditions: IntakeWorkflowCondition[];
  priority: IntakeWorkflowPriority;
  explanation: string;
  deployment_profile_id?: string | null;
}

export interface IntakeWorkflowDeploymentProfile {
  id: string;
  label: string;
  subject_types: IncidentSubjectType[];
  priorities: IntakeWorkflowPriority[];
  summary: string | null;
  team_ids: string[];
  resources: string[];
  recommended_recipient_count: number | null;
  recommended_dispatch_mode: 'preannouncement' | 'direct_dispatch' | null;
  required_certification_type_ids: string[];
  readonly team_snapshots?: IntakeWorkflowTeamSnapshot[];
  readonly certification_type_snapshots?: IntakeWorkflowCertificationType[];
}

export interface IntakeWorkflowConfiguration {
  subject_types: IntakeWorkflowSubjectTypeOption[];
  fields: IntakeWorkflowField[];
  bindings: IntakeWorkflowBinding[];
  priority_rules: IntakeWorkflowPriorityRule[];
  deployment_profiles: IntakeWorkflowDeploymentProfile[];
}

export interface IntakeWorkflowRevision {
  id: string;
  version: number | null;
  status: 'draft' | 'published';
  lock_version: number;
  configuration: IntakeWorkflowConfiguration;
  published_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface IntakeWorkflowIncidentField {
  target: string;
  label: string;
  type: string;
}

export interface IntakeWorkflowTeam {
  id: string;
  name: string;
}

export interface IntakeWorkflowTeamSnapshot extends IntakeWorkflowTeam {
  code: string;
}

export interface IntakeWorkflowCertificationType {
  id: string;
  code: string;
  name: string;
}

export interface IntakeWorkflowOperatorCatalogItem {
  key: IntakeWorkflowConditionOperator;
  label: string;
  field_types: IntakeWorkflowFieldType[];
  needs_value: boolean;
}

export interface IntakeWorkflowAdminEnvelope {
  draft: IntakeWorkflowRevision;
  published: IntakeWorkflowRevision | null;
  history: IntakeWorkflowRevision[];
  catalogs: {
    incident_fields: IntakeWorkflowIncidentField[];
    teams: IntakeWorkflowTeam[];
    certification_types: IntakeWorkflowCertificationType[];
    operators: IntakeWorkflowOperatorCatalogItem[];
  };
}

export interface IntakeWorkflowValidationResult {
  valid: true;
  configuration: IntakeWorkflowConfiguration;
}

export interface IntakeWorkflowSimulationResult {
  triage: {
    state: string;
    recommended_priority: IntakeWorkflowPriority | null;
    reasons: string[];
    missing_fields: Array<{ key: string; label: string }>;
  };
  deployment_proposal: {
    profile_id: string;
    label: string;
    summary: string | null;
    team_ids: string[];
    teams: IntakeWorkflowTeam[];
    resources: string[];
    recommended_recipient_count: number | null;
    recommended_dispatch_mode: 'preannouncement' | 'direct_dispatch' | null;
    required_certification_type_ids: string[];
    required_certification_types: IntakeWorkflowCertificationType[];
  } | null;
}

export const intakeWorkflowScopes: Array<{ value: IntakeWorkflowScope; label: string }> = [
  { value: 'common', label: 'Gemeenschappelijk' },
  { value: 'person', label: 'Mens' },
  { value: 'animal', label: 'Dier' },
  { value: 'object', label: 'Object' },
];

export const intakeWorkflowPriorities: Array<{ value: IntakeWorkflowPriority; label: string }> = [
  { value: 'low', label: 'Laag' },
  { value: 'medium', label: 'Middel' },
  { value: 'high', label: 'Hoog' },
  { value: 'urgent', label: 'Urgent' },
];

export const intakeWorkflowFieldTypes: Array<{ value: IntakeWorkflowFieldType; label: string }> = [
  { value: 'section', label: 'Sectie' },
  { value: 'text', label: 'Korte tekst' },
  { value: 'textarea', label: 'Lange tekst' },
  { value: 'number', label: 'Getal' },
  { value: 'select', label: 'Dropdown' },
  { value: 'radio', label: 'Keuzelijst' },
  { value: 'checkbox', label: 'Ja / nee' },
  { value: 'date', label: 'Datum' },
  { value: 'datetime', label: 'Datum en tijd' },
];

export const intakeWorkflowConditionOperators: IntakeWorkflowOperatorCatalogItem[] = [
  { key: 'equals', label: 'is gelijk aan', field_types: ['text', 'textarea', 'number', 'select', 'radio', 'checkbox', 'date', 'datetime'], needs_value: true },
  { key: 'not_equals', label: 'is niet gelijk aan', field_types: ['text', 'textarea', 'number', 'select', 'radio', 'checkbox', 'date', 'datetime'], needs_value: true },
  { key: 'contains', label: 'bevat', field_types: ['text', 'textarea', 'select', 'radio'], needs_value: true },
  { key: 'greater_than_or_equal', label: 'is minimaal', field_types: ['number', 'date', 'datetime'], needs_value: true },
  { key: 'less_than_or_equal', label: 'is maximaal', field_types: ['number', 'date', 'datetime'], needs_value: true },
  { key: 'is_true', label: 'is ja', field_types: ['checkbox'], needs_value: false },
  { key: 'is_false', label: 'is nee', field_types: ['checkbox'], needs_value: false },
  { key: 'is_present', label: 'is ingevuld', field_types: ['text', 'textarea', 'number', 'select', 'radio', 'checkbox', 'date', 'datetime'], needs_value: false },
];

export function createWorkflowField(
  fields: IntakeWorkflowField[],
  scope: IntakeWorkflowScope,
  type: IntakeWorkflowFieldType = 'text',
): IntakeWorkflowField {
  const prefix = type === 'section' ? 'sectie' : 'veld';

  return {
    key: nextWorkflowIdentifier(prefix, fields.map((field) => field.key)),
    label: type === 'section' ? 'Nieuwe sectie' : 'Nieuw veld',
    type,
    scope,
    required: false,
    operator_visible: false,
    options: type === 'select' || type === 'radio'
      ? [
          { value: 'optie_1', label: 'Optie 1' },
          { value: 'optie_2', label: 'Optie 2' },
        ]
      : [],
  };
}

export function createWorkflowPriorityRule(
  rules: IntakeWorkflowPriorityRule[],
  fields: IntakeWorkflowField[],
): IntakeWorkflowPriorityRule {
  const firstField = ruleSafeFields(fields, ['person'])[0];

  return {
    id: nextWorkflowIdentifier('regel', rules.map((rule) => rule.id)),
    label: 'Nieuwe prioriteitsregel',
    subject_types: ['person'],
    match: 'all',
    conditions: firstField === undefined
      ? []
      : [{
          field_key: firstField.key,
          operator: defaultConditionOperator(firstField.type),
          ...(conditionOperatorNeedsValue(defaultConditionOperator(firstField.type))
            ? { value: defaultConditionValue(firstField) }
            : {}),
        }],
    priority: 'medium',
    explanation: '',
    deployment_profile_id: null,
  };
}

export function createWorkflowDeploymentProfile(
  profiles: IntakeWorkflowDeploymentProfile[],
): IntakeWorkflowDeploymentProfile {
  return {
    id: nextWorkflowIdentifier('inzet', profiles.map((profile) => profile.id)),
    label: 'Nieuw inzetvoorstel',
    subject_types: ['person'],
    priorities: ['medium'],
    summary: '',
    team_ids: [],
    resources: [],
    recommended_recipient_count: null,
    recommended_dispatch_mode: null,
    required_certification_type_ids: [],
  };
}

export function nextWorkflowIdentifier(prefix: string, usedIdentifiers: string[]): string {
  const used = new Set(usedIdentifiers);
  let index = 1;
  let candidate = `${prefix}_${index}`;

  while (used.has(candidate)) {
    index += 1;
    candidate = `${prefix}_${index}`;
  }

  return candidate;
}

export function scopeLabel(scope: IntakeWorkflowScope): string {
  return intakeWorkflowScopes.find((item) => item.value === scope)?.label ?? scope;
}

export function priorityLabel(priority: IntakeWorkflowPriority): string {
  return intakeWorkflowPriorities.find((item) => item.value === priority)?.label ?? priority;
}

export function fieldTypeLabel(type: IntakeWorkflowFieldType): string {
  return intakeWorkflowFieldTypes.find((item) => item.value === type)?.label ?? type;
}

export function fieldsForScope(
  fields: IntakeWorkflowField[],
  scope: IntakeWorkflowScope,
): IntakeWorkflowField[] {
  return fields.filter((field) => field.scope === scope);
}

export function ruleSafeFields(
  fields: IntakeWorkflowField[],
  subjectTypes: IncidentSubjectType[],
): IntakeWorkflowField[] {
  return fields.filter((field) => field.type !== 'section'
    && (field.scope === 'common'
      || (subjectTypes.length === 1 && field.scope === subjectTypes[0])));
}

export function conditionOperatorsForField(
  field: IntakeWorkflowField | undefined,
  catalog: IntakeWorkflowOperatorCatalogItem[] = intakeWorkflowConditionOperators,
): IntakeWorkflowOperatorCatalogItem[] {
  if (field === undefined) {
    return [];
  }

  const available = catalog.length > 0 ? catalog : intakeWorkflowConditionOperators;

  return available.filter((item) => item.field_types.includes(field.type));
}

export function conditionOperatorNeedsValue(
  operator: IntakeWorkflowConditionOperator,
  catalog: IntakeWorkflowOperatorCatalogItem[] = intakeWorkflowConditionOperators,
): boolean {
  return catalog.find((item) => item.key === operator)?.needs_value
    ?? !['is_true', 'is_false', 'is_present'].includes(operator);
}

export function defaultConditionOperator(type: IntakeWorkflowFieldType): IntakeWorkflowConditionOperator {
  if (type === 'checkbox') {
    return 'is_true';
  }

  return 'equals';
}

export function defaultConditionValue(field: IntakeWorkflowField): unknown {
  if (field.type === 'number') {
    return 0;
  }

  if (field.type === 'checkbox') {
    return true;
  }

  if (field.type === 'select' || field.type === 'radio') {
    return field.options[0]?.value ?? '';
  }

  return '';
}

export function availableBindingTargets(
  fieldKey: string,
  fields: IntakeWorkflowField[],
  bindings: IntakeWorkflowBinding[],
  incidentFields: IntakeWorkflowIncidentField[],
): IntakeWorkflowIncidentField[] {
  const selectedField = fields.find((field) => field.key === fieldKey);
  const occupied = new Set(
    bindings
      .filter((binding) => binding.field_key !== fieldKey)
      .filter((binding) => {
        const boundField = fields.find((field) => field.key === binding.field_key);

        return selectedField === undefined
          || boundField === undefined
          || selectedField.scope === 'common'
          || boundField.scope === 'common'
          || selectedField.scope === boundField.scope;
      })
      .map((binding) => binding.target),
  );

  return incidentFields.filter((candidate) =>
    bindingTypesCompatible(selectedField?.type, candidate.type)
    && !occupied.has(candidate.target));
}

export function bindingTypesCompatible(
  sourceType: IntakeWorkflowFieldType | undefined,
  targetType: string,
): boolean {
  if (sourceType === undefined) {
    return false;
  }

  if (targetType === 'number') {
    return sourceType === 'number';
  }
  if (targetType === 'checkbox') {
    return sourceType === 'checkbox';
  }
  if (targetType === 'select' || targetType === 'radio') {
    return sourceType === 'select' || sourceType === 'radio';
  }
  if (targetType === 'phone') {
    return sourceType === 'text' || sourceType === 'select' || sourceType === 'radio';
  }
  if (targetType === 'flight_time') {
    return false;
  }

  return sourceType !== 'checkbox' && sourceType !== 'section';
}

export function updateWorkflowBinding(
  bindings: IntakeWorkflowBinding[],
  fieldKey: string,
  target: string,
): IntakeWorkflowBinding[] {
  const withoutField = bindings.filter((binding) => binding.field_key !== fieldKey);

  if (target === '') {
    return withoutField;
  }

  return [...withoutField, { field_key: fieldKey, target }];
}

export function removeWorkflowField(
  configuration: IntakeWorkflowConfiguration,
  fieldKey: string,
): IntakeWorkflowConfiguration {
  return {
    ...configuration,
    fields: configuration.fields.filter((field) => field.key !== fieldKey),
    bindings: configuration.bindings.filter((binding) => binding.field_key !== fieldKey),
    priority_rules: configuration.priority_rules.map((rule) => ({
      ...rule,
      conditions: rule.conditions.filter((condition) => condition.field_key !== fieldKey),
    })),
  };
}

export function moveWorkflowItem<T>(
  items: T[],
  index: number,
  direction: -1 | 1,
): T[] {
  const target = index + direction;
  if (index < 0 || index >= items.length || target < 0 || target >= items.length) {
    return items;
  }

  const next = [...items];
  const [item] = next.splice(index, 1);
  next.splice(target, 0, item);

  return next;
}

export function normalizeRuleForSubjects(
  rule: IntakeWorkflowPriorityRule,
  fields: IntakeWorkflowField[],
): IntakeWorkflowPriorityRule {
  const safeFields = ruleSafeFields(fields, rule.subject_types);
  const safeFieldMap = new Map(safeFields.map((field) => [field.key, field]));

  return {
    ...rule,
    conditions: rule.conditions.flatMap((condition) => {
      const field = safeFieldMap.get(condition.field_key);
      if (field === undefined) {
        return [];
      }

      const operators = conditionOperatorsForField(field);
      const supported = operators.some((operator) => operator.key === condition.operator);
      const operator = supported ? condition.operator : defaultConditionOperator(field.type);
      if (!conditionOperatorNeedsValue(operator)) {
        return [{ field_key: field.key, operator }];
      }

      const optionStillExists = field.type !== 'select' && field.type !== 'radio'
        || field.options.some((option) => option.value === condition.value);
      if (!optionStillExists) {
        return [];
      }
      const hasValue = condition.value !== null
        && condition.value !== undefined
        && condition.value !== '';

      return [{
        field_key: field.key,
        operator,
        value: supported && optionStillExists && hasValue
          ? condition.value
          : defaultConditionValue(field),
      }];
    }),
  };
}

export function configurationEquals(
  left: IntakeWorkflowConfiguration,
  right: IntakeWorkflowConfiguration,
): boolean {
  return JSON.stringify(left) === JSON.stringify(right);
}

export function optionsToLines(options: IntakeWorkflowOption[]): string {
  return options.map((option) => option.label).join('\n');
}

export function optionsFromLines(value: string): IntakeWorkflowOption[] {
  return value
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .reduce<IntakeWorkflowOption[]>(
      (options, label) => [...options, createWorkflowOption(options, label)],
      [],
    );
}

export function createWorkflowOption(
  options: IntakeWorkflowOption[],
  label = 'Nieuwe optie',
): IntakeWorkflowOption {
  const used = new Set(options.map((option) => option.value));
  const base = label
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '') || 'optie';
  let value = base;
  let suffix = 2;
  while (used.has(value)) {
    value = `${base}_${suffix}`;
    suffix += 1;
  }

  return { label, value };
}

export function updateWorkflowOptionLabel(
  options: IntakeWorkflowOption[],
  value: string,
  label: string,
): IntakeWorkflowOption[] {
  return options.map((option) => option.value === value ? { ...option, label } : option);
}

export function linesToResources(value: string): string[] {
  return [...new Set(value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean))];
}
