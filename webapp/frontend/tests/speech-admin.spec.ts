import { readFileSync } from 'node:fs';
import { expect, test, type Page } from 'playwright/test';
import type {
  PaginationMeta,
  SpeechCacheEntrySummary,
  SpeechPreparationKind,
  SpeechPreparationPreset,
  SpeechPreparationPresetResult,
  SpeechPreparationStatus,
  SpeechPreparationSummary,
  SpeechPreparedPhrase,
  SpeechPreview,
} from '../src/types/api';
import {
  fixedSpeechCacheAudioPath,
  fixedSpeechPreparationAudioPath,
  fixedSpeechPreviewAudioPath,
  formatSpeechBytes,
  formatSpeechDuration,
  formatSpeechParameterCount,
  formatSpeechSynthesisDuration,
  insertSpeechToken,
  microphoneRecordingError,
  microphoneRequestIsCurrent,
  normalizeSpeechProgress,
  normalizeSpeechToken,
  renderSpeechTemplate,
  semanticSpeechLines,
  SPEECH_POLL_INTERVAL_MS,
  speechCacheHitRate,
  speechCacheUsagePercentage,
  speechConfigurationIssue,
  speechStatusLabel,
  speechStatusTone,
  speechTemplateTokens,
  speechTokenLabel,
  speechVoiceProfileIsReadyForModel,
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
  expect(formatSpeechDuration(8_400)).toBe('8 sec.');
  expect(formatSpeechDuration(83_000)).toBe('1:23 min');
  expect(formatSpeechDuration(null)).toBe('-');
  expect(formatSpeechSynthesisDuration(842)).toBe('842 ms');
  expect(formatSpeechSynthesisDuration(18_640)).toBe('18,6 sec.');
  expect(formatSpeechSynthesisDuration(83_040)).toBe('1 min 23 sec.');
  expect(formatSpeechSynthesisDuration(null)).toBe('Niet vastgelegd');
  expect(speechStatusLabel('expired')).toBe('Verlopen');
  expect(fixedSpeechCacheAudioPath('cache/entry')).toBe('/admin/speech/cache/entries/cache%2Fentry/audio');
});

test('explains microphone failures in actionable Dutch without exposing browser errors', () => {
  const namedError = (name: string) => Object.assign(new Error('Permission denied'), { name });

  expect(microphoneRecordingError(null, false)).toContain('HTTPS');
  expect(microphoneRecordingError(namedError('NotAllowedError'), true)).toContain('browser- en toestelinstellingen');
  expect(microphoneRecordingError(namedError('NotFoundError'), true)).toContain('geen geschikte microfoon');
  expect(microphoneRecordingError(namedError('NotReadableError'), true)).toContain('bezet of tijdelijk niet beschikbaar');
  expect(microphoneRecordingError(new Error('raw browser failure'), true)).toBe(
    'Microfoonopname kon niet worden gestart. Probeer opnieuw of upload een audiobestand.',
  );
});

test('rejects a microphone stream that resolves after navigation or a newer request', () => {
  expect(microphoneRequestIsCurrent(true, 4, 4)).toBe(true);
  expect(microphoneRequestIsCurrent(false, 4, 4)).toBe(false);
  expect(microphoneRequestIsCurrent(true, 5, 4)).toBe(false);
});

test('derives server voice validity from installed models, built-in voices and ready compatible profiles', () => {
  const profileRequiredModel = {
    id: 'profile-required',
    name: 'Profielmodel',
    status: 'installed',
    built_in_voice_available: false,
  };
  const builtInVoiceModel = {
    id: 'built-in',
    name: 'Model met ingebouwde stem',
    status: 'installed',
    built_in_voice_available: true,
  };
  const readyProfile = {
    id: 'ready-profile',
    name: 'Gereed profiel',
    status: 'ready',
    compatible_model_ids: ['profile-required'],
  };
  const processingProfile = {
    ...readyProfile,
    id: 'processing-profile',
    name: 'Profiel in verwerking',
    status: 'processing',
  };
  const incompatibleProfile = {
    ...readyProfile,
    id: 'incompatible-profile',
    name: 'Ander profiel',
    compatible_model_ids: ['another-model'],
  };

  expect(speechConfigurationIssue({
    enabled: true,
    model: null,
    voiceProfileId: null,
    voiceProfile: null,
  })?.code).toBe('model_missing');
  expect(speechConfigurationIssue({
    enabled: true,
    model: { ...profileRequiredModel, status: 'installing' },
    voiceProfileId: null,
    voiceProfile: null,
  })?.code).toBe('model_not_installed');
  expect(speechConfigurationIssue({
    enabled: true,
    model: profileRequiredModel,
    voiceProfileId: null,
    voiceProfile: null,
  })?.code).toBe('voice_profile_required');
  expect(speechConfigurationIssue({
    enabled: true,
    model: profileRequiredModel,
    voiceProfileId: readyProfile.id,
    voiceProfile: readyProfile,
  })).toBeNull();
  expect(speechConfigurationIssue({
    enabled: true,
    model: builtInVoiceModel,
    voiceProfileId: null,
    voiceProfile: null,
  })).toBeNull();
  expect(speechVoiceProfileIsReadyForModel(readyProfile, profileRequiredModel.id)).toBe(true);
  expect(speechVoiceProfileIsReadyForModel(processingProfile, profileRequiredModel.id)).toBe(false);
  expect(speechVoiceProfileIsReadyForModel(incompatibleProfile, profileRequiredModel.id)).toBe(false);
});

test('always rejects an explicitly selected unavailable voice profile, even while server speech is off', () => {
  const model = {
    id: 'model-a',
    name: 'Model A',
    status: 'installed',
    built_in_voice_available: true,
  };
  const processingProfile = {
    id: 'processing-profile',
    name: 'Profiel in verwerking',
    status: 'processing',
    compatible_model_ids: [model.id],
  };
  const incompatibleProfile = {
    ...processingProfile,
    id: 'incompatible-profile',
    name: 'Incompatibel profiel',
    status: 'ready',
    compatible_model_ids: ['model-b'],
  };

  expect(speechConfigurationIssue({
    enabled: false,
    model,
    voiceProfileId: 'missing-profile',
    voiceProfile: null,
  })?.code).toBe('voice_profile_missing');
  expect(speechConfigurationIssue({
    enabled: false,
    model,
    voiceProfileId: processingProfile.id,
    voiceProfile: processingProfile,
  })?.code).toBe('voice_profile_not_ready');
  expect(speechConfigurationIssue({
    enabled: false,
    model,
    voiceProfileId: incompatibleProfile.id,
    voiceProfile: incompatibleProfile,
  })?.code).toBe('voice_profile_incompatible');
  expect(speechConfigurationIssue({
    enabled: false,
    model: null,
    voiceProfileId: null,
    voiceProfile: null,
  })).toBeNull();
  expect(speechConfigurationIssue({
    enabled: false,
    model: { ...model, status: 'not_installed', built_in_voice_available: false },
    voiceProfileId: null,
    voiceProfile: null,
  })).toBeNull();
  expect(speechConfigurationIssue({
    enabled: false,
    model: { ...model, built_in_voice_available: false },
    voiceProfileId: null,
    voiceProfile: null,
  })).toBeNull();
});

test('explains the required voice profile without overflowing a phone viewport', async ({ page }) => {
  await mockSpeechAdminApi(page);
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/speech');

  const profileSelect = page.getByLabel('Actief stemprofiel');
  await expect(page.getByRole('heading', { name: 'Spraakregie' })).toBeVisible();
  await expect(profileSelect).toHaveValue('');
  await expect(profileSelect.locator('option:checked')).toHaveText('Kies een gereed stemprofiel');
  await expect(page.getByText(/Chatterbox Multilingual V3 heeft geen ingebouwde stem/).first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Spraakinstellingen opslaan' }).first()).toBeDisabled();

  const widths = await page.evaluate(() => ({
    viewport: window.innerWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth,
  }));
  expect(Math.max(widths.document, widths.body)).toBeLessThanOrEqual(widths.viewport);

  await page.getByLabel('Actief servermodel').selectOption('voxcpm2');
  await expect(profileSelect.locator('option:checked')).toHaveText('Ingebouwde stem van dit servermodel');
  await expect(page.getByText('Zonder eigen profiel gebruikt dit model zijn ingebouwde serverstem.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Spraakinstellingen opslaan' }).first()).toBeEnabled();
});

test('lets an administrator clear a disappeared profile while server speech is off', async ({ page }) => {
  const status = speechAdminStatus();
  await mockSpeechAdminApi(page, {
    ...status,
    settings: { ...status.settings, enabled: false, voice_profile_id: 'removed-profile' },
  });
  await page.goto('/speech');

  const profileSelect = page.getByLabel('Actief stemprofiel');
  await expect(profileSelect).toHaveValue('removed-profile');
  await expect(profileSelect.locator('option:checked')).toHaveText('Niet meer beschikbaar stemprofiel');
  await profileSelect.selectOption('');

  await expect(profileSelect).toHaveValue('');
  await expect(page.getByRole('button', { name: 'Spraakinstellingen opslaan' }).first()).toBeEnabled();
});

test('hides incident text cache contents without incident access while retaining cache maintenance', async ({ page }) => {
  await mockSpeechAdminApi(page, speechAdminStatus(), {
    canViewCacheContent: false,
  });
  await page.goto('/speech');

  await expect(page.getByRole('button', { name: 'Cache-inhoud bekijken' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Regenereren en voorverwarmen' })).toBeVisible();
});

test('loads cache contents only after opening and closes accessibly without exposing internal identifiers', async ({ page }) => {
  const entry = speechCacheEntry();
  const cacheRequests: URL[] = [];
  await mockSpeechAdminApi(page, speechAdminStatus(), {
    cacheEntries: {
      response: () => ({
        items: [entry],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
      }),
      onRequest: (url) => cacheRequests.push(url),
    },
  });
  await page.goto('/speech');

  const opener = page.getByRole('button', { name: 'Cache-inhoud bekijken' });
  await expect(opener).toBeVisible();
  expect(cacheRequests).toHaveLength(0);

  await opener.click();
  const dialog = page.getByRole('dialog', { name: 'Inhoud van de audiocache' });
  await expect(dialog).toBeVisible();
  await expect.poll(() => cacheRequests.length).toBe(1);
  await expect(dialog.getByText(entry.text ?? '')).toBeVisible();
  await expect(dialog.getByText('Chatterbox Multilingual V3')).toBeVisible();
  await expect(dialog.getByText('Rustige centralistenstem')).toBeVisible();
  await expect(dialog.getByText('1,10×')).toBeVisible();
  await expect(dialog.getByText('1:23 min')).toBeVisible();
  await expect(dialog.getByText('18,6 sec.')).toBeVisible();
  await expect(dialog.getByText('12 KiB')).toBeVisible();

  const player = dialog.getByLabel(`Cachefragment afspelen: ${entry.text}`);
  await expect(player).toHaveAttribute('preload', 'none');
  await expect(player).toHaveAttribute(
    'src',
    `/api${fixedSpeechCacheAudioPath(entry.id)}`,
  );
  const visibleText = await dialog.textContent();
  expect(visibleText).not.toContain(entry.id);
  expect(visibleText).not.toContain('cache-key');
  expect(visibleText).not.toContain('objects/');

  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(opener).toBeFocused();

  await opener.click();
  await page.getByRole('button', { name: 'Cache-inhoud sluiten' }).click();
  await expect(page.getByRole('dialog', { name: 'Inhoud van de audiocache' })).toHaveCount(0);

  await opener.click();
  const reopenedDialog = page.getByRole('dialog', { name: 'Inhoud van de audiocache' });
  await reopenedDialog.locator('..').click({ position: { x: 2, y: 2 } });
  await expect(reopenedDialog).toHaveCount(0);
});

test('sends search, category, status and pagination to the cache endpoint', async ({ page }) => {
  const cacheRequests: URL[] = [];
  await mockSpeechAdminApi(page, speechAdminStatus(), {
    cacheEntries: {
      response: (url) => {
        const currentPage = Number(url.searchParams.get('page') ?? 1);
        return {
          items: [{ ...speechCacheEntry(), id: `cache-entry-page-${currentPage}` }],
          meta: { current_page: currentPage, last_page: 2, per_page: 20, total: 40 },
        };
      },
      onRequest: (url) => cacheRequests.push(url),
    },
  });
  await page.goto('/speech');
  await page.getByRole('button', { name: 'Cache-inhoud bekijken' }).click();
  const dialog = page.getByRole('dialog', { name: 'Inhoud van de audiocache' });
  await expect(dialog.getByText('Pagina 1 van 2')).toBeVisible();

  await dialog.getByLabel('Zoeken in tekst').fill('Utrecht');
  await dialog.getByLabel('Categorie').selectOption('composite');
  await dialog.getByLabel('Status').selectOption('ready');
  await expect.poll(() => cacheRequests.some((request) => (
    request.searchParams.get('search') === 'Utrecht'
      && request.searchParams.get('category') === 'composite'
      && request.searchParams.get('status') === 'ready'
      && request.searchParams.get('page') === '1'
      && request.searchParams.get('per_page') === '20'
  ))).toBe(true);

  await dialog.getByRole('button', { name: 'Volgende' }).click();
  await expect(dialog.getByText('Pagina 2 van 2')).toBeVisible();
  await expect.poll(() => cacheRequests.some((request) => (
    request.searchParams.get('search') === 'Utrecht'
      && request.searchParams.get('category') === 'composite'
      && request.searchParams.get('status') === 'ready'
      && request.searchParams.get('page') === '2'
  ))).toBe(true);
});

test('shows clear empty, error and phone-sized cache states', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await mockSpeechAdminApi(page, speechAdminStatus(), {
    cacheEntries: {
      response: () => ({
        items: [],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
      }),
    },
  });
  await page.goto('/speech');
  await page.getByRole('button', { name: 'Cache-inhoud bekijken' }).click();
  const dialog = page.getByRole('dialog', { name: 'Inhoud van de audiocache' });
  await expect(dialog.getByText('De audiocache is nog leeg')).toBeVisible();

  const box = await dialog.boundingBox();
  expect(box).not.toBeNull();
  expect(box?.x).toBe(0);
  expect(box?.y).toBe(0);
  expect(box?.width).toBe(360);
  expect(box?.height).toBe(800);
  const widths = await page.evaluate(() => ({
    viewport: window.innerWidth,
    document: document.documentElement.scrollWidth,
  }));
  expect(widths.document).toBeLessThanOrEqual(widths.viewport);
});

test('offers a retry when cache contents cannot be loaded', async ({ page }) => {
  let cacheRequests = 0;
  await mockSpeechAdminApi(page, speechAdminStatus(), {
    cacheEntries: {
      status: 503,
      response: () => ({
        items: [],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
      }),
      onRequest: () => { cacheRequests += 1; },
    },
  });
  await page.goto('/speech');
  await page.getByRole('button', { name: 'Cache-inhoud bekijken' }).click();
  const dialog = page.getByRole('dialog', { name: 'Inhoud van de audiocache' });
  await expect(dialog.getByText('Laden mislukt')).toBeVisible();
  await expect(dialog.getByText('Cache niet beschikbaar.')).toBeVisible();
  await dialog.getByRole('button', { name: 'Opnieuw proberen' }).click();
  await expect.poll(() => cacheRequests).toBe(2);
});

test('loads ready preview audio through the protected same-origin media endpoint', async ({ page }) => {
  const preview = readySpeechPreview();
  let audioRequests = 0;
  const cspViolations: string[] = [];
  page.on('console', (message) => {
    if (/content security policy|violat(?:e|ion).*csp/i.test(message.text())) {
      cspViolations.push(message.text());
    }
  });
  await mockSpeechAdminApi(page, speechAdminStatus(), {
    preview,
    audio: {
      body: silentWav(),
      contentType: 'audio/wav',
      onRequest: () => { audioRequests += 1; },
    },
  });
  await page.goto('/speech');

  await page.getByRole('button', { name: 'Proefmelding genereren' }).click();
  const player = page.getByLabel('Proefmelding afspelen');
  await expect(player).toBeVisible();
  await expect.poll(() => audioRequests).toBeGreaterThan(0);
  await expect.poll(() => player.evaluate((element) => {
    const audio = element as HTMLAudioElement;
    return Number.isFinite(audio.duration) ? audio.duration : 0;
  })).toBeGreaterThan(0);
  expect(cspViolations).toEqual([]);
  await expect(page.getByText('De audio kon niet worden geladen.')).toHaveCount(0);
});

test('shows an actionable error when ready preview audio cannot be loaded', async ({ page }) => {
  await mockSpeechAdminApi(page, speechAdminStatus(), {
    preview: readySpeechPreview(),
    audio: {
      status: 410,
      contentType: 'application/json',
      body: Buffer.from(JSON.stringify({
        error: { code: 'speech_preview_expired', message: 'Preview verlopen.', details: {} },
      })),
    },
  });
  await page.goto('/speech');

  await page.getByRole('button', { name: 'Proefmelding genereren' }).click();

  await expect(page.getByText(
    'De audio kon niet worden geladen. Genereer de proefmelding opnieuw of vernieuw deze pagina.',
    { exact: true },
  )).toBeVisible();
});

test('opens the permanent preparation library read-only and supports accessible keyboard tabs', async ({ page }) => {
  const entry = speechPreparedPhrase('Utrecht');
  const listRequests: Array<{
    payload: SpeechPreparationSearchPayload;
    transport: 'index' | 'search';
  }> = [];
  await mockSpeechAdminApi(page, speechAdminStatus(), {
    canViewPreparations: true,
    canManagePreparations: false,
    preparations: {
      summary: () => ({ data: speechPreparationSummary(1) }),
      search: (payload, transport) => {
        listRequests.push({ payload, transport });

        return {
          items: [entry],
          meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
        };
      },
      audio: {
        body: silentWav(),
        contentType: 'audio/wav',
      },
    },
  });
  await page.goto('/speech');

  const opener = page.getByRole('button', { name: 'Voorbereidingsbibliotheek bekijken' });
  await expect(opener).toBeEnabled();
  await opener.click();

  const dialog = page.getByRole('dialog', { name: 'Voorbereidingsbibliotheek bekijken' });
  await expect(dialog.getByText(/Je hebt alleen leesrechten/)).toBeVisible();
  await expect(dialog.getByText(entry.value ?? '')).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Verwijderen' })).toHaveCount(0);
  await expect(dialog.getByRole('button', { name: 'Opnieuw genereren' })).toHaveCount(0);
  await expect(dialog.getByText('Volledige voorbereidingscache legen')).toHaveCount(0);
  await expect(dialog.getByLabel(`Voorbereide audio afspelen voor ${entry.value}`)).toHaveAttribute(
    'src',
    `/api${fixedSpeechPreparationAudioPath(entry.id)}`,
  );
  await expect.poll(() => listRequests.length).toBe(1);
  expect(listRequests[0]).toEqual({
    transport: 'index',
    payload: {
      kind: 'residence',
      page: 1,
      per_page: 20,
    },
  });

  await dialog.getByLabel('Zoeken').fill('Utrecht');
  await expect.poll(() => listRequests.length).toBe(2);
  expect(listRequests[1]).toEqual({
    transport: 'search',
    payload: {
      kind: 'residence',
      page: 1,
      per_page: 20,
      search: 'Utrecht',
    },
  });

  const residenceTab = dialog.getByRole('tab', { name: 'Woonplaatsen' });
  const fixedPhraseTab = dialog.getByRole('tab', { name: 'Vaste template- en pushzinnen' });
  await expect(residenceTab).toHaveAttribute('tabindex', '0');
  await expect(fixedPhraseTab).toHaveAttribute('tabindex', '-1');
  await residenceTab.focus();
  await page.keyboard.press('End');
  await expect(fixedPhraseTab).toBeFocused();
  await expect(fixedPhraseTab).toHaveAttribute('aria-selected', 'true');
  await expect(dialog.getByRole('tabpanel', { name: 'Vaste template- en pushzinnen' })).toBeVisible();

  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(opener).toBeFocused();
});

test('returns to a valid page after deleting its last preparation and focuses safe confirmation', async ({ page }) => {
  const firstPageEntry = speechPreparedPhrase('Amsterdam', '01PREPAREDPHRASEPAGE100000001');
  const lastPageEntry = speechPreparedPhrase('Utrecht', '01PREPAREDPHRASEPAGE200000001');
  let deleted = false;
  await mockSpeechAdminApi(page, speechAdminStatus(), {
    canViewPreparations: true,
    canManagePreparations: true,
    preparations: {
      summary: () => ({ data: speechPreparationSummary(deleted ? 20 : 21) }),
      search: (payload) => {
        if (payload.page === 2 && !deleted) {
          return {
            items: [lastPageEntry],
            meta: { current_page: 2, last_page: 2, per_page: 20, total: 21 },
          };
        }
        if (payload.page === 2) {
          return {
            items: [],
            meta: { current_page: 2, last_page: 1, per_page: 20, total: 20 },
          };
        }

        return {
          items: [firstPageEntry],
          meta: {
            current_page: 1,
            last_page: deleted ? 1 : 2,
            per_page: 20,
            total: deleted ? 20 : 21,
          },
        };
      },
      onDelete: (id) => {
        expect(id).toBe(lastPageEntry.id);
        deleted = true;
      },
    },
  });
  await page.goto('/speech');
  await page.getByRole('button', { name: 'Voorbereidingsbibliotheek beheren' }).click();

  const dialog = page.getByRole('dialog', { name: 'Voorbereidingsbibliotheek beheren' });
  await expect(dialog.getByText(firstPageEntry.value ?? '')).toBeVisible();
  await dialog.getByRole('button', { name: 'Volgende pagina' }).click();
  await expect(dialog.getByText(lastPageEntry.value ?? '', { exact: true })).toBeVisible();

  await dialog.getByRole('button', { name: 'Verwijderen' }).click();
  const cancelButton = dialog.getByRole('button', { name: 'Annuleren' });
  await expect(cancelButton).toBeFocused();
  await dialog.getByRole('button', { name: 'Definitief verwijderen' }).click();

  await expect(dialog.getByText(firstPageEntry.value ?? '')).toBeVisible();
  await expect(dialog.getByText('Pagina 1 van 1')).toBeVisible();
  await expect(dialog.getByText(lastPageEntry.value ?? '', { exact: true })).toHaveCount(0);
});

test('prepares a server-driven weekly test alert without manual phrase entry', async ({ page }) => {
  const preset: SpeechPreparationPreset = {
    id: 'weekly_test_alert',
    label: 'Wekelijks proefalarm',
    description: 'De actuele proefalarmmelding en bijbehorende gesproken regels.',
    preview_lines: [
      'Dit is het wekelijkse proefalarm.',
      'Open de app en bevestig ontvangst.',
    ],
    phrase_count: 2,
  };
  let preparedPresetId: string | null = null;
  let prepareRequestBody: string | null = null;
  await mockSpeechAdminApi(page, speechAdminStatus(), {
    canViewPreparations: true,
    canManagePreparations: true,
    preparations: {
      summary: () => ({ data: speechPreparationSummary(0) }),
      search: () => ({
        items: [],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
      }),
      presets: {
        list: () => ({ data: [preset] }),
        prepare: (id, body) => {
          preparedPresetId = id;
          prepareRequestBody = body;

          return {
            preset,
            preparations: preset.preview_lines.map((line, index) => ({
              ...speechPreparedPhrase(line, `01PRESETPHRASE00000000000${index}`),
              kind: 'fixed_phrase',
            })),
          };
        },
      },
    },
  });
  await page.goto('/speech');
  await page.getByRole('button', { name: 'Voorbereidingsbibliotheek beheren' }).click();

  const dialog = page.getByRole('dialog', { name: 'Voorbereidingsbibliotheek beheren' });
  await dialog.getByRole('tab', { name: 'Vaste template- en pushzinnen' }).click();
  await expect(dialog.getByRole('heading', { name: 'Kant-en-klare meldingen' })).toBeVisible();
  await expect(dialog.getByText(preset.label, { exact: true })).toBeVisible();
  await expect(dialog.getByText(preset.description, { exact: true })).toBeVisible();
  await expect(dialog.getByText(preset.preview_lines[0], { exact: true })).toBeVisible();
  await expect(dialog.getByText(preset.preview_lines[1], { exact: true })).toBeVisible();
  await expect(dialog.getByLabel('Eén waarde of exacte zin per regel')).not.toBeVisible();

  await dialog.getByRole('button', { name: 'Wekelijks proefalarm voorbereiden' }).click();

  await expect.poll(() => preparedPresetId).toBe(preset.id);
  expect(prepareRequestBody).toBeNull();
  await expect(dialog.getByText(
    /Wekelijks proefalarm: 2 exacte regels staan blijvend in de voorbereidingsbibliotheek\./u,
  )).toBeVisible();
  await dialog.getByText('Handmatig voorbereiden', { exact: true }).click();
  await expect(dialog.getByLabel('Eén waarde of exacte zin per regel')).toBeVisible();
});

test('shows an honest unknown preparation summary and retries it', async ({ page }) => {
  let summaryRequests = 0;
  await mockSpeechAdminApi(page, speechAdminStatus(), {
    canViewPreparations: true,
    canManagePreparations: false,
    preparations: {
      summary: () => {
        summaryRequests += 1;

        return summaryRequests === 1
          ? { status: 503 }
          : { data: speechPreparationSummary(1) };
      },
      search: () => ({
        items: [],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
      }),
    },
  });
  await page.goto('/speech');

  await expect(page.getByText('Status onbekend')).toBeVisible();
  await expect(page.getByText('De voorbereidingsstatus kon niet worden opgehaald.')).toBeVisible();
  await expect(page.getByText('–').first()).toBeVisible();
  await page.getByRole('button', { name: 'Opnieuw proberen' }).click();

  await expect(page.getByText('Blijvend opgeslagen')).toBeVisible();
  await expect.poll(() => summaryRequests).toBe(2);
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
  expect(page).not.toContain('Standaardstem van het servermodel');
  expect(page).not.toContain('De standaardstem van een geïnstalleerd model blijft beschikbaar');
});

test('makes the fixed nl-NL emergency voice distinction explicit without a device voice selector', () => {
  const page = readFileSync(new URL('../src/features/speech/SpeechAdminPage.tsx', import.meta.url), 'utf8');

  expect(page).toContain('vaste Nederlandse <b>nl-NL</b>-apparaatstem');
  expect(page).toContain('operators kunnen TTS alleen aan- of uitzetten');
  expect(page).toContain('is alleen voor de centrale servergenerator');
  expect(page).not.toContain('fallback_voice_id');
  expect(page).not.toContain('device_voice_id');
});

interface SpeechPreparationSearchPayload {
  kind: SpeechPreparationKind;
  page: number;
  per_page: number;
  search?: string;
  status?: SpeechPreparationStatus;
}

interface SpeechPreparationMockOptions {
  summary: () => {
    status?: number;
    data?: SpeechPreparationSummary;
  };
  search: (
    payload: SpeechPreparationSearchPayload,
    transport: 'index' | 'search',
  ) => {
    items: SpeechPreparedPhrase[];
    meta: PaginationMeta;
  };
  onDelete?: (id: string) => void;
  audio?: {
    status?: number;
    contentType: string;
    body: Buffer;
  };
  presets?: {
    list: () => {
      status?: number;
      data?: SpeechPreparationPreset[];
    };
    prepare: (
      id: string,
      body: string | null,
    ) => SpeechPreparationPresetResult;
  };
}

interface SpeechAdminMockOptions {
  canViewCacheContent?: boolean;
  canViewPreparations?: boolean;
  canManagePreparations?: boolean;
  preview?: SpeechPreview;
  preparations?: SpeechPreparationMockOptions;
  cacheEntries?: {
    status?: number;
    response: (url: URL) => { items: SpeechCacheEntrySummary[]; meta: PaginationMeta };
    onRequest?: (url: URL) => void;
  };
  audio?: {
    status?: number;
    contentType: string;
    body: Buffer;
    onRequest?: () => void;
  };
}

async function mockSpeechAdminApi(
  page: Page,
  status: unknown = speechAdminStatus(),
  options: SpeechAdminMockOptions = {},
): Promise<void> {
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/auth/me') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: speechAdminUser({
            canViewCacheContent: options.canViewCacheContent ?? true,
            canViewPreparations: options.canViewPreparations ?? false,
            canManagePreparations: options.canManagePreparations ?? false,
          }),
        }),
      });
      return;
    }
    if (path === '/api/branding') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: { name: 'DIS', short_name: 'DIS', tenant_name: 'Testorganisatie', logo_data_url: '' },
        }),
      });
      return;
    }
    if (path === '/api/auth/csrf-cookie') {
      await route.fulfill({ status: 204, body: '' });
      return;
    }
    if (path === '/api/admin/speech') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: status }),
      });
      return;
    }
    if (path === '/api/admin/speech/preparations/summary' && options.preparations) {
      const response = options.preparations.summary();
      const statusCode = response.status ?? 200;
      await route.fulfill({
        status: statusCode,
        contentType: 'application/json',
        body: JSON.stringify(statusCode >= 400
          ? {
              error: {
                code: 'speech_preparation_summary_unavailable',
                message: 'Voorbereidingsstatus niet beschikbaar.',
                details: {},
              },
            }
          : { data: response.data ?? speechPreparationSummary(0) }),
      });
      return;
    }
    if (path === '/api/admin/speech/preparations/search'
      && route.request().method() === 'POST'
      && options.preparations) {
      const payload = route.request().postDataJSON() as SpeechPreparationSearchPayload;
      const response = options.preparations.search(payload, 'search');
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: response.items, meta: response.meta }),
      });
      return;
    }
    if (path === '/api/admin/speech/preparations'
      && route.request().method() === 'GET'
      && options.preparations) {
      const url = new URL(route.request().url());
      const statusFilter = url.searchParams.get('status');
      const payload: SpeechPreparationSearchPayload = {
        kind: url.searchParams.get('kind') as SpeechPreparationKind,
        page: Number(url.searchParams.get('page')),
        per_page: Number(url.searchParams.get('per_page')),
        ...(statusFilter === null ? {} : { status: statusFilter as SpeechPreparationStatus }),
      };
      const response = options.preparations.search(payload, 'index');
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: response.items, meta: response.meta }),
      });
      return;
    }
    if (path === '/api/admin/speech/preparations/presets'
      && route.request().method() === 'GET'
      && options.preparations?.presets) {
      const response = options.preparations.presets.list();
      const statusCode = response.status ?? 200;
      await route.fulfill({
        status: statusCode,
        contentType: 'application/json',
        body: JSON.stringify(statusCode >= 400
          ? {
              error: {
                code: 'speech_preparation_presets_unavailable',
                message: 'Vaste sjablonen niet beschikbaar.',
                details: {},
              },
            }
          : { data: response.data ?? [] }),
      });
      return;
    }
    const preparationPresetMatch = path.match(
      /^\/api\/admin\/speech\/preparations\/presets\/([^/]+)\/prepare$/u,
    );
    if (preparationPresetMatch
      && route.request().method() === 'POST'
      && options.preparations?.presets) {
      const response = options.preparations.presets.prepare(
        decodeURIComponent(preparationPresetMatch[1]),
        route.request().postData(),
      );
      await route.fulfill({
        status: 202,
        contentType: 'application/json',
        body: JSON.stringify({ data: response }),
      });
      return;
    }
    const preparationAudioMatch = path.match(/^\/api\/admin\/speech\/preparations\/([^/]+)\/audio$/u);
    if (preparationAudioMatch && route.request().method() === 'GET' && options.preparations?.audio) {
      await route.fulfill({
        status: options.preparations.audio.status ?? 200,
        contentType: options.preparations.audio.contentType,
        body: options.preparations.audio.body,
      });
      return;
    }
    const preparationDeleteMatch = path.match(/^\/api\/admin\/speech\/preparations\/([^/]+)$/u);
    if (preparationDeleteMatch && route.request().method() === 'DELETE' && options.preparations) {
      options.preparations.onDelete?.(decodeURIComponent(preparationDeleteMatch[1]));
      await route.fulfill({ status: 204, body: '' });
      return;
    }
    if (path === '/api/admin/speech/cache/entries' && options.cacheEntries) {
      const url = new URL(route.request().url());
      options.cacheEntries.onRequest?.(url);
      const response = options.cacheEntries.response(url);
      await route.fulfill({
        status: options.cacheEntries.status ?? 200,
        contentType: 'application/json',
        body: JSON.stringify(options.cacheEntries.status && options.cacheEntries.status >= 400
          ? { error: { code: 'speech_cache_unavailable', message: 'Cache niet beschikbaar.', details: {} } }
          : { data: response.items, meta: response.meta }),
      });
      return;
    }
    if (path === '/api/admin/speech/previews' && route.request().method() === 'POST' && options.preview) {
      await route.fulfill({
        status: 202,
        contentType: 'application/json',
        body: JSON.stringify({ data: options.preview }),
      });
      return;
    }
    if (options.preview && path === `/api/admin/speech/previews/${options.preview.id}`) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: options.preview }),
      });
      return;
    }
    if (options.preview && options.audio
      && path === `/api/admin/speech/previews/${options.preview.id}/audio`) {
      options.audio.onRequest?.();
      await route.fulfill({
        status: options.audio.status ?? 200,
        contentType: options.audio.contentType,
        body: options.audio.body,
      });
      return;
    }

    await route.fulfill({
      status: 404,
      contentType: 'application/json',
      body: JSON.stringify({ error: { code: 'not_found', message: 'Testroute niet gemockt.', details: {} } }),
    });
  });
}

function readySpeechPreview(): SpeechPreview {
  return {
    id: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    phase: 'availability',
    status: 'ready',
    progress_percent: 100,
    rendered_lines: [
      'Voorwaarschuwing voor een mogelijke inzet in Utrecht.',
      'Open de app en geef je beschikbaarheid door.',
    ],
    error_code: null,
    created_at: '2026-07-22T12:00:00Z',
    expires_at: '2026-07-23T12:00:00Z',
  };
}

function speechCacheEntry(): SpeechCacheEntrySummary {
  return {
    id: '01CACHEENTRYINTERNAL00000001',
    text: 'Voorwaarschuwing voor een mogelijke inzet in Utrecht.',
    text_available: true,
    text_source: 'cache',
    category: 'segment',
    status: 'ready',
    error_code: null,
    model_id: 'internal-model-id',
    model_name: 'Chatterbox Multilingual V3',
    model_revision: 'internal-model-revision',
    voice_type: 'profile',
    voice_name: 'Rustige centralistenstem',
    voice_revision: 'internal-voice-revision',
    locale: 'nl-NL',
    speed: 1.1,
    audio_recipe_revision: 'internal-audio-recipe',
    duration_ms: 83_000,
    synthesis_duration_ms: 18_640,
    byte_size: 12_345,
    hit_count: 17,
    audio_available: true,
    audio_url: '/admin/speech/cache/entries/01CACHEENTRYINTERNAL00000001/audio',
    created_at: '2026-07-23T12:00:00Z',
    updated_at: '2026-07-23T12:05:00Z',
    last_used_at: '2026-07-23T14:30:00Z',
    expires_at: '2026-08-22T12:00:00Z',
  };
}

function speechPreparationSummary(total: number): SpeechPreparationSummary {
  return {
    counts: {
      residence: total,
      province: 0,
      postcode: 0,
      fixed_phrase: 0,
    },
    total_count: total,
    ready_count: total,
    pending_count: 0,
    failed_count: 0,
    disk_bytes: total * 1_024,
  };
}

function speechPreparedPhrase(
  value: string,
  id = '01PREPAREDPHRASE0000000001',
): SpeechPreparedPhrase {
  return {
    id,
    kind: 'residence',
    value,
    status: 'ready',
    progress_percent: 100,
    error_code: null,
    audio_url: `/api/admin/speech/preparations/${id}/audio`,
    byte_size: 1_024,
    duration_ms: 1_250,
    created_at: '2026-07-24T08:00:00Z',
    updated_at: '2026-07-24T08:01:00Z',
    prepared_at: '2026-07-24T08:01:00Z',
  };
}

function silentWav(): Buffer {
  const sampleRate = 8_000;
  const sampleCount = 2_000;
  const dataBytes = sampleCount * 2;
  const wav = Buffer.alloc(44 + dataBytes);
  wav.write('RIFF', 0);
  wav.writeUInt32LE(36 + dataBytes, 4);
  wav.write('WAVE', 8);
  wav.write('fmt ', 12);
  wav.writeUInt32LE(16, 16);
  wav.writeUInt16LE(1, 20);
  wav.writeUInt16LE(1, 22);
  wav.writeUInt32LE(sampleRate, 24);
  wav.writeUInt32LE(sampleRate * 2, 28);
  wav.writeUInt16LE(2, 32);
  wav.writeUInt16LE(16, 34);
  wav.write('data', 36);
  wav.writeUInt32LE(dataBytes, 40);

  return wav;
}

function speechAdminUser({
  canViewCacheContent = true,
  canViewPreparations = false,
  canManagePreparations = false,
}: {
  canViewCacheContent?: boolean;
  canViewPreparations?: boolean;
  canManagePreparations?: boolean;
} = {}) {
  return {
    id: 'speech-admin',
    name: 'Spraakbeheerder',
    email: 'speech@example.test',
    account_status: 'active',
    push_enabled: true,
    max_operator_devices: 3,
    two_factor_enabled: true,
    profile_completion_required: false,
    roles: [{
      id: 'speech-admin-role',
      name: 'speech_admin',
      display_name: 'Spraakbeheerder',
      can_use_operator_app: false,
      can_use_admin_app: true,
      permissions: [{
        id: 'settings-manage',
        name: 'settings.manage',
        category: 'settings',
        display_name: 'Instellingen beheren',
      }, ...(canViewCacheContent ? [{
        id: 'incidents-view',
        name: 'incidents.view',
        category: 'incidents',
        display_name: 'Incidenten bekijken',
      }] : []), ...(canViewPreparations ? [{
        id: 'speech-cache-view',
        name: 'speech.cache.view',
        category: 'speech',
        display_name: 'Spraakvoorbereidingen bekijken',
      }] : []), ...(canManagePreparations ? [{
        id: 'speech-cache-manage',
        name: 'speech.cache.manage',
        category: 'speech',
        display_name: 'Spraakvoorbereidingen beheren',
      }] : [])],
    }],
  };
}

function speechAdminStatus() {
  const chatterbox = {
    id: 'chatterbox_multilingual_v3',
    name: 'Chatterbox Multilingual V3',
    description: 'Meertalig model voor eigen stemprofielen.',
    parameter_count: 500_000_000,
    download_bytes: 1_600_000_000,
    license_spdx: 'MIT',
    commercial_use: true,
    quality_tier: 'high_end',
    supported_languages: ['nl-NL'],
    built_in_voice_available: false,
    capabilities: { voice_clone: true, voice_design: false, speed_control: false },
    cpu: { supported: true, recommended_ram_bytes: 16_000_000_000, note: 'CPU-only ondersteund.' },
    status: 'installed',
    progress_percent: 100,
    error_code: null,
    installed_revision: 'test-revision',
  };
  const voxcpm = {
    ...chatterbox,
    id: 'voxcpm2',
    name: 'VoxCPM2',
    description: 'Model met vaste Nederlandse serverstem.',
    built_in_voice_available: true,
    capabilities: { voice_clone: true, voice_design: true, speed_control: false },
    status: 'installed',
    progress_percent: 100,
    installed_revision: 'test-voxcpm-revision',
  };

  return {
    settings: {
      enabled: true,
      model_id: chatterbox.id,
      voice_profile_id: null,
      speed: 1.1,
      pre_generate_on_save: true,
      templates: {
        availability: ['Beschikbaarheidsverzoek voor {place}.'],
        attendance: ['Alarm voor {street} {house_number} in {place}.'],
        test_ack: ['Dit is een proefalarm.'],
      },
    },
    template_definitions: [{
      phase: 'availability',
      label: 'Beschikbaarheid',
      allowed_tokens: ['place'],
      example_rendered_lines: ['Beschikbaarheidsverzoek voor Utrecht.'],
    }, {
      phase: 'attendance',
      label: 'Opkomst',
      allowed_tokens: ['street', 'house_number', 'place'],
      example_rendered_lines: ['Alarm voor Dorpsstraat 1 in Utrecht.'],
    }, {
      phase: 'test_ack',
      label: 'Proefalarm',
      allowed_tokens: [],
      example_rendered_lines: ['Dit is een proefalarm.'],
    }],
    models: [chatterbox, voxcpm],
    voice_profiles: [],
    cache: {
      segment_count: 0,
      composite_count: 0,
      hit_count: 0,
      miss_count: 0,
      disk_bytes: 0,
      quota_bytes: 5_000_000_000,
      pending_count: 0,
      failed_count: 0,
      last_pruned_at: null,
      active_job: null,
    },
  };
}
