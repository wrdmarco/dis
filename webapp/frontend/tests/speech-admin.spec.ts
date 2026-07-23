import { readFileSync } from 'node:fs';
import { expect, test, type Page } from 'playwright/test';
import type { SpeechPreview } from '../src/types/api';
import {
  fixedSpeechPreviewAudioPath,
  formatSpeechBytes,
  formatSpeechParameterCount,
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

interface SpeechAdminMockOptions {
  preview?: SpeechPreview;
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
        body: JSON.stringify({ data: speechAdminUser() }),
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

function speechAdminUser() {
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
      }],
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
