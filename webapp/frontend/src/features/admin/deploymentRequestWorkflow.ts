import {
  fieldTypeHasOptions,
  fieldTypeLabel as sharedFieldTypeLabel,
  formFieldTypeOptions,
  isSatisfactionScore,
  type FormFieldType,
} from '../../lib/formFieldTypes';
import { dateTimeLocalInputIsoValue, dateTimeLocalInputValue } from '../../lib/dateTime';
import type { DeploymentSubjectType } from '../../types/api';

export type DeploymentRequestWorkflowFieldType = FormFieldType;
export type DeploymentRequestWorkflowScope = 'common' | DeploymentSubjectType;
export type DeploymentRequestWorkflowPriority = 'low' | 'medium' | 'high' | 'urgent';
export type DeploymentRequestWorkflowRuleMatch = 'all' | 'any';
export type DeploymentRequestWorkflowConditionOperator =
  | 'equals'
  | 'not_equals'
  | 'contains'
  | 'greater_than_or_equal'
  | 'less_than_or_equal'
  | 'is_true'
  | 'is_false'
  | 'is_present';

export interface DeploymentRequestWorkflowOption {
  label: string;
  value: string;
}

export interface DeploymentRequestWorkflowSubjectTypeOption {
  key: DeploymentSubjectType;
  label: string;
}

export interface DeploymentRequestWorkflowField {
  key: string;
  label: string;
  type: DeploymentRequestWorkflowFieldType;
  scope: DeploymentRequestWorkflowScope;
  required: boolean;
  operator_visible: boolean;
  help_text?: string | null;
  options: DeploymentRequestWorkflowOption[];
}

export interface DeploymentRequestWorkflowFlightTimeValue {
  start: string;
  end: string;
  duration_minutes: number | null;
}

export interface DeploymentRequestWorkflowBinding {
  field_key: string;
  target: string;
}

export interface DeploymentRequestWorkflowCondition {
  field_key: string;
  operator: DeploymentRequestWorkflowConditionOperator;
  value?: unknown;
}

export interface DeploymentRequestWorkflowPriorityRule {
  id: string;
  label: string;
  subject_types: DeploymentSubjectType[];
  match: DeploymentRequestWorkflowRuleMatch;
  conditions: DeploymentRequestWorkflowCondition[];
  priority: DeploymentRequestWorkflowPriority;
  explanation: string;
  deployment_profile_id?: string | null;
}

export interface DeploymentRequestWorkflowDeploymentProfile {
  id: string;
  label: string;
  subject_types: DeploymentSubjectType[];
  priorities: DeploymentRequestWorkflowPriority[];
  summary: string | null;
  team_ids: string[];
  resources: string[];
  recommended_recipient_count: number | null;
  recommended_dispatch_mode: 'preannouncement' | 'direct_dispatch' | null;
  required_certification_type_ids: string[];
  readonly team_snapshots?: DeploymentRequestWorkflowTeamSnapshot[];
  readonly certification_type_snapshots?: DeploymentRequestWorkflowCertificationType[];
}

export interface DeploymentRequestWorkflowConfiguration {
  subject_types: DeploymentRequestWorkflowSubjectTypeOption[];
  fields: DeploymentRequestWorkflowField[];
  bindings: DeploymentRequestWorkflowBinding[];
  priority_rules: DeploymentRequestWorkflowPriorityRule[];
  deployment_profiles: DeploymentRequestWorkflowDeploymentProfile[];
}

export interface DeploymentRequestWorkflowRevision {
  id: string;
  version: number | null;
  status: 'draft' | 'published';
  lock_version: number;
  configuration: DeploymentRequestWorkflowConfiguration;
  published_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface DeploymentRequestWorkflowDeploymentField {
  target: string;
  label: string;
  type: string;
  options?: DeploymentRequestWorkflowOption[];
}

export interface DeploymentRequestWorkflowTeam {
  id: string;
  name: string;
}

export interface DeploymentRequestWorkflowTeamSnapshot extends DeploymentRequestWorkflowTeam {
  code: string;
}

export interface DeploymentRequestWorkflowCertificationType {
  id: string;
  code: string;
  name: string;
}

export interface DeploymentRequestWorkflowOperatorCatalogItem {
  key: DeploymentRequestWorkflowConditionOperator;
  label: string;
  field_types: DeploymentRequestWorkflowFieldType[];
  needs_value: boolean;
}

export interface DeploymentRequestWorkflowAdminEnvelope {
  draft: DeploymentRequestWorkflowRevision;
  published: DeploymentRequestWorkflowRevision | null;
  history: DeploymentRequestWorkflowRevision[];
  catalogs: {
    deployment_fields: DeploymentRequestWorkflowDeploymentField[];
    teams: DeploymentRequestWorkflowTeam[];
    certification_types: DeploymentRequestWorkflowCertificationType[];
    operators: DeploymentRequestWorkflowOperatorCatalogItem[];
  };
}

export interface DeploymentRequestWorkflowValidationResult {
  valid: true;
  configuration: DeploymentRequestWorkflowConfiguration;
}

export interface DeploymentRequestWorkflowSimulationResult {
  triage: {
    state: string;
    recommended_priority: DeploymentRequestWorkflowPriority | null;
    reasons: string[];
    missing_fields: Array<{ key: string; label: string }>;
  };
  deployment_proposal: {
    profile_id: string;
    label: string;
    summary: string | null;
    team_ids: string[];
    teams: DeploymentRequestWorkflowTeam[];
    resources: string[];
    recommended_recipient_count: number | null;
    recommended_dispatch_mode: 'preannouncement' | 'direct_dispatch' | null;
    required_certification_type_ids: string[];
    required_certification_types: DeploymentRequestWorkflowCertificationType[];
  } | null;
}

export const deploymentRequestWorkflowScopes: Array<{ value: DeploymentRequestWorkflowScope; label: string }> = [
  { value: 'common', label: 'Gemeenschappelijk' },
  { value: 'person', label: 'Mens' },
  { value: 'animal', label: 'Dier' },
  { value: 'object', label: 'Object' },
];

export const deploymentRequestWorkflowPriorities: Array<{ value: DeploymentRequestWorkflowPriority; label: string }> = [
  { value: 'low', label: 'Laag' },
  { value: 'medium', label: 'Middel' },
  { value: 'high', label: 'Hoog' },
  { value: 'urgent', label: 'Urgent' },
];

export const deploymentRequestWorkflowFieldTypes: Array<{ value: DeploymentRequestWorkflowFieldType; label: string }> = [
  ...formFieldTypeOptions.map(({ value, label }) => ({ value, label })),
];

export const deploymentRequestWorkflowConditionOperators: DeploymentRequestWorkflowOperatorCatalogItem[] = [
  { key: 'equals', label: 'is gelijk aan', field_types: ['text', 'textarea', 'address', 'number', 'phone', 'flight_time', 'select', 'radio', 'checkbox', 'date', 'datetime', 'score'], needs_value: true },
  { key: 'not_equals', label: 'is niet gelijk aan', field_types: ['text', 'textarea', 'address', 'number', 'phone', 'flight_time', 'select', 'radio', 'checkbox', 'date', 'datetime', 'score'], needs_value: true },
  { key: 'contains', label: 'bevat', field_types: ['text', 'textarea', 'address', 'select', 'radio'], needs_value: true },
  { key: 'greater_than_or_equal', label: 'is minimaal', field_types: ['number', 'date', 'datetime', 'score'], needs_value: true },
  { key: 'less_than_or_equal', label: 'is maximaal', field_types: ['number', 'date', 'datetime', 'score'], needs_value: true },
  { key: 'is_true', label: 'is ja', field_types: ['checkbox'], needs_value: false },
  { key: 'is_false', label: 'is nee', field_types: ['checkbox'], needs_value: false },
  { key: 'is_present', label: 'is ingevuld', field_types: ['text', 'textarea', 'address', 'number', 'phone', 'flight_time', 'select', 'radio', 'checkbox', 'date', 'datetime', 'score'], needs_value: false },
];

export function createWorkflowField(
  fields: DeploymentRequestWorkflowField[],
  scope: DeploymentRequestWorkflowScope,
  type: DeploymentRequestWorkflowFieldType = 'text',
): DeploymentRequestWorkflowField {
  const prefix = type === 'section' ? 'sectie' : 'veld';

  return {
    key: nextWorkflowIdentifier(prefix, fields.map((field) => field.key)),
    label: type === 'section' ? 'Nieuwe sectie' : 'Nieuw veld',
    type,
    scope,
    required: false,
    operator_visible: false,
    options: fieldTypeHasOptions(type)
      ? [
          { value: 'optie_1', label: 'Optie 1' },
          { value: 'optie_2', label: 'Optie 2' },
        ]
      : [],
  };
}

export function createWorkflowPriorityRule(
  rules: DeploymentRequestWorkflowPriorityRule[],
  fields: DeploymentRequestWorkflowField[],
): DeploymentRequestWorkflowPriorityRule {
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

export function createWorkflowPriorityRuleForSubject(
  rules: DeploymentRequestWorkflowPriorityRule[],
  fields: DeploymentRequestWorkflowField[],
  subject: DeploymentSubjectType,
): DeploymentRequestWorkflowPriorityRule {
  const rule = createWorkflowPriorityRule(rules, fields);
  const firstField = ruleSafeFields(fields, [subject])[0];
  const operator = firstField === undefined ? null : defaultConditionOperator(firstField.type);

  return {
    ...rule,
    subject_types: [subject],
    conditions: firstField === undefined || operator === null
      ? []
      : [{
          field_key: firstField.key,
          operator,
          ...(conditionOperatorNeedsValue(operator) ? { value: defaultConditionValue(firstField) } : {}),
        }],
  };
}

export function workflowPriorityRulesForSubject(
  rules: DeploymentRequestWorkflowPriorityRule[],
  subject: DeploymentSubjectType,
): DeploymentRequestWorkflowPriorityRule[] {
  return rules.filter((rule) => rule.subject_types.includes(subject));
}

export function moveWorkflowPriorityRuleForSubject(
  rules: DeploymentRequestWorkflowPriorityRule[],
  ruleId: string,
  subject: DeploymentSubjectType,
  direction: -1 | 1,
): DeploymentRequestWorkflowPriorityRule[] {
  const scoped = workflowPriorityRulesForSubject(rules, subject);
  const scopedIndex = scoped.findIndex((rule) => rule.id === ruleId);
  const target = scoped[scopedIndex + direction];
  if (scopedIndex < 0 || target === undefined) {
    return rules;
  }

  const currentIndex = rules.findIndex((rule) => rule.id === ruleId);
  const targetIndex = rules.findIndex((rule) => rule.id === target.id);
  const next = [...rules];
  [next[currentIndex], next[targetIndex]] = [next[targetIndex], next[currentIndex]];

  return next;
}

export function createWorkflowDeploymentProfile(
  profiles: DeploymentRequestWorkflowDeploymentProfile[],
): DeploymentRequestWorkflowDeploymentProfile {
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

export function updateWorkflowDeploymentProfile(
  configuration: DeploymentRequestWorkflowConfiguration,
  profileId: string,
  updater: (
    profile: DeploymentRequestWorkflowDeploymentProfile,
  ) => DeploymentRequestWorkflowDeploymentProfile,
): DeploymentRequestWorkflowConfiguration {
  const deploymentProfiles = configuration.deployment_profiles.map((profile) =>
    profile.id === profileId ? updater(profile) : profile);
  const updatedProfile = deploymentProfiles.find((profile) => profile.id === profileId);

  return {
    ...configuration,
    deployment_profiles: deploymentProfiles,
    priority_rules: configuration.priority_rules.map((rule) => {
      if (rule.deployment_profile_id !== profileId || updatedProfile === undefined) {
        return rule;
      }
      const compatible = rule.subject_types.every((subject) =>
        updatedProfile.subject_types.includes(subject))
        && updatedProfile.priorities.includes(rule.priority);

      return compatible ? rule : { ...rule, deployment_profile_id: null };
    }),
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

export function scopeLabel(scope: DeploymentRequestWorkflowScope): string {
  return deploymentRequestWorkflowScopes.find((item) => item.value === scope)?.label ?? scope;
}

export function priorityLabel(priority: DeploymentRequestWorkflowPriority): string {
  return deploymentRequestWorkflowPriorities.find((item) => item.value === priority)?.label ?? priority;
}

export function fieldTypeLabel(type: DeploymentRequestWorkflowFieldType): string {
  return sharedFieldTypeLabel(type);
}

export function fieldsForScope(
  fields: DeploymentRequestWorkflowField[],
  scope: DeploymentRequestWorkflowScope,
): DeploymentRequestWorkflowField[] {
  return fields.filter((field) => field.scope === scope);
}

export function ruleSafeFields(
  fields: DeploymentRequestWorkflowField[],
  subjectTypes: DeploymentSubjectType[],
): DeploymentRequestWorkflowField[] {
  return fields.filter((field) => field.type !== 'section'
    && (field.scope === 'common'
      || (subjectTypes.length === 1 && field.scope === subjectTypes[0])));
}

export function conditionOperatorsForField(
  field: DeploymentRequestWorkflowField | undefined,
  catalog: DeploymentRequestWorkflowOperatorCatalogItem[] = deploymentRequestWorkflowConditionOperators,
): DeploymentRequestWorkflowOperatorCatalogItem[] {
  if (field === undefined) {
    return [];
  }

  const available = catalog.length > 0 ? catalog : deploymentRequestWorkflowConditionOperators;

  return available.filter((item) => item.field_types.includes(field.type));
}

export function conditionOperatorNeedsValue(
  operator: DeploymentRequestWorkflowConditionOperator,
  catalog: DeploymentRequestWorkflowOperatorCatalogItem[] = deploymentRequestWorkflowConditionOperators,
): boolean {
  return catalog.find((item) => item.key === operator)?.needs_value
    ?? !['is_true', 'is_false', 'is_present'].includes(operator);
}

export function defaultConditionOperator(
  type: DeploymentRequestWorkflowFieldType,
): DeploymentRequestWorkflowConditionOperator {
  if (type === 'checkbox') {
    return 'is_true';
  }

  return 'equals';
}

export function defaultConditionValue(field: DeploymentRequestWorkflowField): unknown {
  if (field.type === 'number') {
    return 0;
  }

  if (field.type === 'score') {
    return 3;
  }

  if (field.type === 'checkbox') {
    return true;
  }

  if (field.type === 'flight_time') {
    return { start: '09:00', end: '10:00', duration_minutes: 60 };
  }

  if (field.type === 'select' || field.type === 'radio') {
    return field.options[0]?.value ?? '';
  }

  return '';
}

export function availableBindingTargets(
  fieldKey: string,
  fields: DeploymentRequestWorkflowField[],
  bindings: DeploymentRequestWorkflowBinding[],
  deploymentFields: DeploymentRequestWorkflowDeploymentField[],
): DeploymentRequestWorkflowDeploymentField[] {
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

  return deploymentFields.filter((candidate) =>
    bindingTypesCompatible(selectedField, candidate)
    && !occupied.has(candidate.target));
}

export function bindingTypesCompatible(
  sourceField: Pick<DeploymentRequestWorkflowField, 'type' | 'options'> | undefined,
  targetField: Pick<DeploymentRequestWorkflowDeploymentField, 'type' | 'options'>,
): boolean {
  if (sourceField === undefined) {
    return false;
  }

  if (targetField.type === 'number') {
    return sourceField.type === 'number';
  }
  if (targetField.type === 'score') {
    return sourceField.type === 'score';
  }
  if (targetField.type === 'checkbox') {
    return sourceField.type === 'checkbox';
  }
  if (targetField.type === 'select' || targetField.type === 'radio') {
    if (sourceField.type !== 'select' && sourceField.type !== 'radio') {
      return false;
    }

    const targetValues = new Set((targetField.options ?? []).map((option) => option.value));
    return sourceField.options.every((option) => targetValues.has(option.value));
  }
  if (targetField.type === 'phone') {
    return sourceField.type === 'phone'
      || sourceField.type === 'text'
      || sourceField.type === 'select'
      || sourceField.type === 'radio';
  }
  if (targetField.type === 'flight_time') {
    return sourceField.type === 'flight_time'
      || sourceField.type === 'text'
      || sourceField.type === 'textarea';
  }
  if (targetField.type === 'address') {
    return sourceField.type === 'address' || sourceField.type === 'text';
  }
  if (targetField.type === 'date') {
    return sourceField.type === 'date';
  }
  if (targetField.type === 'datetime') {
    return sourceField.type === 'datetime';
  }

  return ['text', 'textarea', 'address', 'select', 'radio', 'date', 'datetime', 'phone'].includes(sourceField.type);
}

export function updateWorkflowBinding(
  bindings: DeploymentRequestWorkflowBinding[],
  fieldKey: string,
  target: string,
): DeploymentRequestWorkflowBinding[] {
  const withoutField = bindings.filter((binding) => binding.field_key !== fieldKey);

  if (target === '') {
    return withoutField;
  }

  return [...withoutField, { field_key: fieldKey, target }];
}

export function removeWorkflowField(
  configuration: DeploymentRequestWorkflowConfiguration,
  fieldKey: string,
): DeploymentRequestWorkflowConfiguration {
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

export function reorderWorkflowFieldsForScope(
  fields: DeploymentRequestWorkflowField[],
  scope: DeploymentRequestWorkflowScope,
  sourceKey: string,
  targetKey: string,
): DeploymentRequestWorkflowField[] {
  if (sourceKey === targetKey) {
    return fields;
  }

  const scopedIndexes = fields.flatMap((field, index) => field.scope === scope ? [index] : []);
  const scopedFields = scopedIndexes.map((index) => fields[index]);
  const sourceIndex = scopedFields.findIndex((field) => field.key === sourceKey);
  const targetIndex = scopedFields.findIndex((field) => field.key === targetKey);
  if (sourceIndex < 0 || targetIndex < 0) {
    return fields;
  }

  const reordered = [...scopedFields];
  const [source] = reordered.splice(sourceIndex, 1);
  reordered.splice(targetIndex, 0, source);

  const next = [...fields];
  scopedIndexes.forEach((fieldIndex, index) => {
    next[fieldIndex] = reordered[index];
  });

  return next;
}

export function normalizeRuleForSubjects(
  rule: DeploymentRequestWorkflowPriorityRule,
  fields: DeploymentRequestWorkflowField[],
): DeploymentRequestWorkflowPriorityRule {
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
      const typedValueIsValid = field.type !== 'score' || isSatisfactionScore(condition.value);

      return [{
        field_key: field.key,
        operator,
        value: supported && optionStillExists && hasValue && typedValueIsValid
          ? condition.value
          : defaultConditionValue(field),
      }];
    }),
  };
}

export function configurationEquals(
  left: DeploymentRequestWorkflowConfiguration,
  right: DeploymentRequestWorkflowConfiguration,
): boolean {
  return JSON.stringify(left) === JSON.stringify(right);
}

export function optionsToLines(options: DeploymentRequestWorkflowOption[]): string {
  return options.map((option) => option.label).join('\n');
}

export function optionsFromLines(value: string): DeploymentRequestWorkflowOption[] {
  return value
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .reduce<DeploymentRequestWorkflowOption[]>(
      (options, label) => [...options, createWorkflowOption(options, label)],
      [],
    );
}

export function createWorkflowOption(
  options: DeploymentRequestWorkflowOption[],
  label = 'Nieuwe optie',
): DeploymentRequestWorkflowOption {
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
  options: DeploymentRequestWorkflowOption[],
  value: string,
  label: string,
): DeploymentRequestWorkflowOption[] {
  return options.map((option) => option.value === value ? { ...option, label } : option);
}

export function linesToResources(value: string): string[] {
  return [...new Set(value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean))];
}

export function deploymentRequestWorkflowDateTimeLocalValue(value: unknown): string {
  return dateTimeLocalInputValue(value);
}

export function deploymentRequestWorkflowDateTimeIsoValue(value: string): string {
  return dateTimeLocalInputIsoValue(value);
}

export function workflowFlightTimeValue(value: unknown): DeploymentRequestWorkflowFlightTimeValue {
  const legacy = typeof value === 'string'
    ? value.match(/^\s*((?:[01]\d|2[0-4]):[0-5]\d)\s*-\s*((?:[01]\d|2[0-4]):[0-5]\d)\s*$/)
    : null;
  const record = value !== null && typeof value === 'object'
    ? value as Record<string, unknown>
    : null;
  const start = normalizeWorkflowTime(legacy?.[1] ?? record?.start);
  const end = normalizeWorkflowTime(legacy?.[2] ?? record?.end);

  return {
    start,
    end,
    duration_minutes: workflowFlightDurationMinutes(start, end),
  };
}

export function updateWorkflowFlightTime(
  current: DeploymentRequestWorkflowFlightTimeValue,
  part: 'start' | 'end',
  value: string,
): DeploymentRequestWorkflowFlightTimeValue {
  const next = {
    ...current,
    [part]: normalizeWorkflowTime(value),
  };

  return {
    ...next,
    duration_minutes: workflowFlightDurationMinutes(next.start, next.end),
  };
}

function normalizeWorkflowTime(value: unknown): string {
  return typeof value === 'string' && /^(?:[01]\d|2[0-4]):[0-5]\d$/.test(value.trim())
    ? value.trim()
    : '';
}

function workflowFlightDurationMinutes(start: string, end: string): number | null {
  if (start === '' || end === '') {
    return null;
  }

  const [startHour, startMinute] = start.split(':').map(Number);
  const [endHour, endMinute] = end.split(':').map(Number);
  const startTotal = startHour * 60 + startMinute;
  let endTotal = endHour * 60 + endMinute;
  if (endTotal < startTotal) {
    endTotal += 24 * 60;
  }

  return endTotal - startTotal;
}
