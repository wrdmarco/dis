import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  fixedSpeechPreviewAudioPath,
  formatSpeechBytes,
  formatSpeechParameterCount,
  insertSpeechToken,
  normalizeSpeechProgress,
  normalizeSpeechToken,
  renderSpeechTemplate,
  semanticSpeechLines,
  SPEECH_POLL_INTERVAL_MS,
  speechCacheHitRate,
  speechCacheUsagePercentage,
  speechStatusLabel,
  speechStatusTone,
  speechTemplateTokens,
  speechTokenLabel,
  speechWorkIsActive,
} from '../src/features/speech/speechPresentation';

test('exposes Spraak as its own protected Beheer route', () => {
  const route = readFileSync(new URL('../app/speech/page.tsx', import.meta.url), 'utf8');
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');

  expect(route).toContain("permissions={['settings.manage']}");
  expect(navigation).toContain("to: '/speech', label: 'Spraak'");
  expect(navigation).toContain("permissions: ['settings.manage']");
  expect(navigation).toContain("'/speech': () => import('../features/speech/SpeechAdminPage')");
});

test('keeps semantic template lines ordered and reports unresolved variables', () => {
  expect(semanticSpeechLines('  Eerste regel  \n\n Tweede regel\r\n ')).toEqual([
    'Eerste regel',
    'Tweede regel',
  ]);

  const rendered = renderSpeechTemplate(
    'Melding {title}\nGa naar {street} {house_number}\nPlaats {place}',
    { title: 'Brand', street: 'Dorpsstraat', house_number: '', place: 'Utrecht' },
  );

  expect(rendered.segments.map((segment) => segment.rendered)).toEqual([
    'Melding Brand',
    'Ga naar Dorpsstraat {house_number}',
    'Plaats Utrecht',
  ]);
  expect(rendered.segments[1]?.missingTokens).toEqual(['house_number']);
  expect(rendered.missingTokens).toEqual(['house_number']);
  expect(speechTemplateTokens('{place} {place} {postcode}')).toEqual(['place', 'postcode']);
});

test('inserts only a normalized token at the active caret', () => {
  expect(normalizeSpeechToken('{house_number}')).toBe('house_number');
  expect(speechTokenLabel('house_number')).toBe('Huisnummer');
  expect(insertSpeechToken('Ga naar ', 'place', 8, 8)).toEqual({
    value: 'Ga naar {place}',
    cursor: 15,
  });
  expect(insertSpeechToken('Melding oud', 'title', 8, 11)).toEqual({
    value: 'Melding {title}',
    cursor: 15,
  });
});

test('bounds work progress and formats cache/model facts for Dutch admins', () => {
  expect(SPEECH_POLL_INTERVAL_MS).toBe(2_000);
  expect(normalizeSpeechProgress(-4)).toBe(0);
  expect(normalizeSpeechProgress(55.6)).toBe(56);
  expect(normalizeSpeechProgress(108)).toBe(100);
  expect(speechWorkIsActive('uploaded')).toBe(true);
  expect(speechWorkIsActive('queued')).toBe(true);
  expect(speechWorkIsActive('processing')).toBe(true);
  expect(speechWorkIsActive('ready')).toBe(false);
  expect(speechStatusLabel('not_installed')).toBe('Niet geïnstalleerd');
  expect(speechStatusTone('installed')).toBe('good');
  expect(formatSpeechBytes(1_610_612_736)).toBe('1,5 GiB');
  expect(formatSpeechParameterCount(2_000_000_000)).toBe('2 mld.');
  expect(speechCacheHitRate(9, 1)).toBe('90%');
  expect(speechCacheUsagePercentage(90, 100)).toBe(90);
  expect(speechCacheUsagePercentage(120, 100)).toBe(100);
});

test('uses fixed speech endpoints and a backend-driven model catalog', () => {
  const page = readFileSync(new URL('../src/features/speech/SpeechAdminPage.tsx', import.meta.url), 'utf8');

  expect(page).toContain("useApiResource<SpeechAdminStatus>('/admin/speech'");
  expect(page).toContain("api.patch<SpeechAdminStatus>('/admin/speech/settings', settingsPayload)");
  expect(page).toContain("api.post<SpeechPreview>('/admin/speech/previews', { phase: selectedPhase })");
  expect(page).toContain('`/admin/speech/models/${encodeURIComponent(model.id)}/install`');
  expect(page).toContain("api.postForm<SpeechVoiceProfile>('/admin/speech/voice-profiles'");
  expect(page).toContain("api.post<unknown>('/admin/speech/cache/regenerate', { scope })");
  expect(fixedSpeechPreviewAudioPath('preview/a')).toBe('/admin/speech/previews/preview%2Fa/audio');
  expect(page).not.toContain('VoxCPM2');
  expect(page).toContain('data.models.map');
});

test('makes the fixed nl-NL emergency voice distinction explicit without a device voice selector', () => {
  const page = readFileSync(new URL('../src/features/speech/SpeechAdminPage.tsx', import.meta.url), 'utf8');

  expect(page).toContain('vaste Nederlandse <b>nl-NL</b>-apparaatstem');
  expect(page).toContain('operators kunnen TTS alleen aan- of uitzetten');
  expect(page).toContain('is alleen voor de centrale servergenerator');
  expect(page).not.toContain('fallback_voice_id');
  expect(page).not.toContain('device_voice_id');
});
