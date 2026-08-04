import type { FormFieldType } from '../types/api';

export type { FormFieldType } from '../types/api';

export const formFieldTypeOptions = [
  { value: 'section', label: 'Sectie', description: 'Groep of tussenkop' },
  { value: 'text', label: 'Tekst', description: 'Korte invoer' },
  { value: 'textarea', label: 'Grote tekst', description: 'Meerdere regels' },
  { value: 'address', label: 'Adreszoekveld', description: 'Zoekbaar adres' },
  { value: 'number', label: 'Getal', description: 'Numerieke invoer' },
  { value: 'phone', label: 'Telefoon', description: '+31, +32' },
  { value: 'flight_time', label: 'Vluchttijd', description: 'Start en eind' },
  { value: 'select', label: 'Dropdown', description: 'Een keuze' },
  { value: 'radio', label: 'Radio', description: 'Keuzelijst' },
  { value: 'checkbox', label: 'Checkbox', description: 'Aan of uit' },
  { value: 'date', label: 'Datum', description: 'Kalenderdatum' },
  { value: 'datetime', label: 'Datum en tijd', description: 'Datum met tijdstip' },
  { value: 'score', label: 'Smiley-score', description: 'Vijf smileys' },
] as const satisfies ReadonlyArray<{
  value: FormFieldType;
  label: string;
  description: string;
}>;

type MissingFormFieldType = Exclude<FormFieldType, (typeof formFieldTypeOptions)[number]['value']>;
const formFieldTypeCatalogIsComplete: MissingFormFieldType extends never ? true : never = true;
void formFieldTypeCatalogIsComplete;

export type FormFieldWidth = 'half' | 'full';

export const formFieldTypeValues: FormFieldType[] = formFieldTypeOptions.map((option) => option.value);

export const satisfactionScoreOptions = [
  { value: 1, label: 'Niet goed' },
  { value: 2, label: 'Matig' },
  { value: 3, label: 'Neutraal' },
  { value: 4, label: 'Goed' },
  { value: 5, label: 'Zeer goed' },
] as const;

export type SatisfactionScore = (typeof satisfactionScoreOptions)[number]['value'];

export function isFormFieldType(value: unknown): value is FormFieldType {
  return typeof value === 'string' && formFieldTypeValues.some((type) => type === value);
}

export function fieldTypeLabel(type: FormFieldType): string {
  return formFieldTypeOptions.find((option) => option.value === type)?.label ?? type;
}

export function fieldTypeDefaultWidth(type: FormFieldType): FormFieldWidth {
  const fullWidthTypes: readonly FormFieldType[] = [
    'section',
    'textarea',
    'flight_time',
    'radio',
    'checkbox',
    'score',
  ];

  return fullWidthTypes.includes(type)
    ? 'full'
    : 'half';
}

export function fieldTypeHasOptions(type: FormFieldType): boolean {
  return type === 'select' || type === 'radio';
}

export function isSatisfactionScore(value: unknown): value is SatisfactionScore {
  return typeof value === 'number'
    && Number.isInteger(value)
    && satisfactionScoreOptions.some((option) => option.value === value);
}
