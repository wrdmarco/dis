export const countryOptions = [
  { value: 'NL', label: 'Nederland' },
  { value: 'BE', label: 'Belgie' },
  { value: 'DE', label: 'Duitsland' },
  { value: 'FR', label: 'Frankrijk' },
  { value: 'LU', label: 'Luxemburg' },
] as const;

export const regionsByCountry: Record<string, string[]> = {
  NL: [
    'Drenthe',
    'Flevoland',
    'Friesland',
    'Gelderland',
    'Groningen',
    'Limburg',
    'Noord-Brabant',
    'Noord-Holland',
    'Overijssel',
    'Utrecht',
    'Zeeland',
    'Zuid-Holland',
  ],
  BE: [
    'Antwerpen',
    'Henegouwen',
    'Limburg',
    'Luik',
    'Luxemburg',
    'Namen',
    'Oost-Vlaanderen',
    'Vlaams-Brabant',
    'Waals-Brabant',
    'West-Vlaanderen',
    'Brussels Hoofdstedelijk Gewest',
  ],
};

export function regionOptionsForCountry(country?: string | null): string[] {
  return regionsByCountry[(country ?? '').toUpperCase()] ?? [];
}

export function countryLabel(country?: string | null): string {
  return countryOptions.find((option) => option.value === (country ?? '').toUpperCase())?.label ?? country ?? '-';
}

export function locationLabel(city?: string | null, region?: string | null, country?: string | null): string {
  const parts = [city, region, countryLabel(country)].filter((part): part is string => typeof part === 'string' && part.trim() !== '' && part !== '-');

  return parts.length > 0 ? parts.join(', ') : '-';
}
