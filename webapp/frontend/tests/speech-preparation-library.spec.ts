import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  fixedSpeechPreparationAudioPath,
  speechPreparationContainsTemplateToken,
  speechPreparationValues,
} from '../src/features/speech/speechPresentation';

test('normalizes manually prepared values without merging their semantic kinds', () => {
  expect(speechPreparationValues(' Utrecht \n\nAmersfoort\r\nUtrecht ')).toEqual([
    'Utrecht',
    'Amersfoort',
  ]);
  expect(speechPreparationValues('1234 AB\n1234AB')).toEqual(['1234 AB', '1234AB']);
});

test('requires fixed push and template phrases to be exact rendered text', () => {
  expect(speechPreparationContainsTemplateToken(['Open de app en bevestig ontvangst.'])).toBe(false);
  expect(speechPreparationContainsTemplateToken(['Incident in {place}.'])).toBe(true);
  expect(speechPreparationContainsTemplateToken(['Incident in {{place}}.'])).toBe(true);
});

test('builds a fixed first-party audio path without trusting a server-supplied URL', () => {
  expect(fixedSpeechPreparationAudioPath('phrase/entry')).toBe(
    '/admin/speech/preparations/phrase%2Fentry/audio',
  );
});

test('keeps preparation management behind dedicated RBAC and destructive confirmation', () => {
  const page = readFileSync(
    new URL('../src/features/speech/SpeechAdminPage.tsx', import.meta.url),
    'utf8',
  );
  const library = readFileSync(
    new URL('../src/features/speech/SpeechPreparationLibrary.tsx', import.meta.url),
    'utf8',
  );

  expect(page).toContain("hasPermission('speech.cache.view')");
  expect(page).toContain("hasPermission('speech.cache.manage')");
  expect(library).toContain("const CLEAR_CONFIRMATION = 'VOORBEREIDINGSCACHE LEGEN'");
  expect(library).toContain("'/admin/speech/preparations/clear'");
  expect(library).toContain("`/admin/speech/preparations/${encodeURIComponent(entry.id)}/regenerate`");
  expect(library).toContain('Opnieuw genereren');
  expect(library).toContain('fixedSpeechPreparationAudioPath(entry.id)');
});

test('links push-template management to exact manual speech preparation', () => {
  const branding = readFileSync(
    new URL('../src/features/branding/BrandingPage.tsx', import.meta.url),
    'utf8',
  );

  expect(branding).toContain("hasPermission('speech.cache.manage')");
  expect(branding).toContain('Pushtekst vooraf als spraak voorbereiden');
  expect(branding).toContain('href="/speech"');
  expect(branding).toContain("['postcode', 'Postcode']");
  expect(branding).toContain("['province', 'Provincie']");
});

test('keeps place, province, postcode and fixed phrases in separate tabs', () => {
  const library = readFileSync(
    new URL('../src/features/speech/SpeechPreparationLibrary.tsx', import.meta.url),
    'utf8',
  );

  expect(library).toContain("kind: 'residence'");
  expect(library).toContain("kind: 'province'");
  expect(library).toContain("kind: 'postcode'");
  expect(library).toContain("kind: 'fixed_phrase'");
  expect(library).toContain('const audioSource = entry.audio_url !== null');
});

test('uses server-driven fixed phrase presets with manual preparation as fallback', () => {
  const library = readFileSync(
    new URL('../src/features/speech/SpeechPreparationLibrary.tsx', import.meta.url),
    'utf8',
  );

  expect(library).toContain("'/admin/speech/preparations/presets'");
  expect(library).toContain('`/admin/speech/preparations/presets/${encodeURIComponent(preset.id)}/prepare`');
  expect(library).toContain('preset.preview_lines.map');
  expect(library).toContain('Handmatig voorbereiden');
  expect(library).not.toContain('weekly_test_alert');
  expect(library).not.toContain('Dit is een wekelijks proefalarm.');
});

test('uses GET without user text and a JSON POST when a search term is present', () => {
  const library = readFileSync(
    new URL('../src/features/speech/SpeechPreparationLibrary.tsx', import.meta.url),
    'utf8',
  );

  expect(library).toContain("const listRequest = deferredSearch === ''");
  expect(library).toContain('api.get<SpeechPreparedPhrase[]>(');
  expect(library).toContain('`/admin/speech/preparations?${indexParameters.toString()}`');
  expect(library).toContain("api.post<SpeechPreparedPhrase[]>('/admin/speech/preparations/search'");
  expect(library).toContain('search: deferredSearch');
});
