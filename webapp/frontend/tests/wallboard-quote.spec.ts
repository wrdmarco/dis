import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  normalizeWallboardQuotes,
  selectWallboardDailyQuote,
  wallboardAmsterdamDateKey,
} from '../src/features/wallboards/wallboardPresentation';

test('selects one stable quote per Europe Amsterdam calendar day', () => {
  const quotes = [
    { text: 'Eerste', author: 'Team A' },
    { text: 'Tweede' },
    { text: 'Derde', author: 'Team B' },
  ];
  const morning = new Date('2026-07-19T07:00:00Z');
  const evening = new Date('2026-07-19T20:30:00Z');

  expect(wallboardAmsterdamDateKey(morning)).toBe('2026-07-19');
  expect(wallboardAmsterdamDateKey(evening)).toBe('2026-07-19');
  expect(selectWallboardDailyQuote(quotes, 'dagquote', morning))
    .toEqual(selectWallboardDailyQuote(quotes, 'dagquote', evening));
});

test('uses the Amsterdam date boundary instead of UTC midnight', () => {
  expect(wallboardAmsterdamDateKey(new Date('2026-07-19T21:59:59Z'))).toBe('2026-07-19');
  expect(wallboardAmsterdamDateKey(new Date('2026-07-19T22:00:01Z'))).toBe('2026-07-20');
});

test('normalizes plain quote content and fails visibly for an empty list', () => {
  expect(normalizeWallboardQuotes([
    { text: '  Geldige quote  ', author: '  Auteur  ' },
    { text: '   ' },
    { text: 'Zonder auteur' },
  ])).toEqual([
    { text: 'Geldige quote', author: 'Auteur' },
    { text: 'Zonder auteur' },
  ]);
  expect(selectWallboardDailyQuote([], 'dagquote', new Date('2026-07-19T12:00:00Z'))).toBeNull();
});

test('keeps quote content admin-managed and renders an explicit empty state', () => {
  const editor = readFileSync(
    new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url),
    'utf8',
  );
  const display = readFileSync(
    new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url),
    'utf8',
  );

  expect(editor).toContain('Quote toevoegen');
  expect(editor).toContain('er wordt geen externe quote opgehaald');
  expect(display).toContain('Geen quote geconfigureerd');
});
