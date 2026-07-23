export const SPEECH_POLL_INTERVAL_MS = 2_000;

const TEMPLATE_TOKEN_PATTERN = /\{([a-z][a-z0-9_]*)\}/g;

export interface RenderedSpeechSegment {
  index: number;
  source: string;
  rendered: string;
  missingTokens: string[];
}

export interface RenderedSpeechTemplate {
  segments: RenderedSpeechSegment[];
  missingTokens: string[];
}

export interface SpeechConfigurationModel {
  id: string;
  name: string;
  status: string;
  built_in_voice_available: boolean;
}

export interface SpeechConfigurationVoiceProfile {
  id: string;
  name: string;
  status: string;
  compatible_model_ids: readonly string[];
}

export type SpeechConfigurationIssueCode =
  | 'model_missing'
  | 'model_not_installed'
  | 'voice_profile_missing'
  | 'voice_profile_not_ready'
  | 'voice_profile_incompatible'
  | 'voice_profile_required';

export interface SpeechConfigurationIssue {
  code: SpeechConfigurationIssueCode;
  message: string;
}

interface SpeechConfigurationInput {
  enabled: boolean;
  model: SpeechConfigurationModel | null;
  voiceProfileId: string | null;
  voiceProfile: SpeechConfigurationVoiceProfile | null;
}

export function semanticSpeechLines(value: string): string[] {
  return value.split(/\r?\n/).map((line) => line.trim()).filter((line) => line !== '');
}

export function renderSpeechTemplate(
  template: string,
  values: Readonly<Record<string, string>>,
): RenderedSpeechTemplate {
  const missing = new Set<string>();
  const segments = semanticSpeechLines(template)
    .map((source, offset): RenderedSpeechSegment => {
      const segmentMissing = new Set<string>();
      const rendered = source.replace(TEMPLATE_TOKEN_PATTERN, (placeholder, token: string) => {
        const value = values[token]?.trim();
        if (value === undefined || value === '') {
          missing.add(token);
          segmentMissing.add(token);
          return placeholder;
        }

        return value;
      });

      return {
        index: offset + 1,
        source,
        rendered,
        missingTokens: [...segmentMissing],
      };
    });

  return { segments, missingTokens: [...missing] };
}

export function speechTemplateTokens(template: string): string[] {
  const tokens = new Set<string>();
  for (const match of template.matchAll(TEMPLATE_TOKEN_PATTERN)) {
    if (match[1] !== undefined) tokens.add(match[1]);
  }

  return [...tokens];
}

export function normalizeSpeechToken(token: string): string {
  return token.trim().replace(/^\{/, '').replace(/\}$/, '');
}

export function speechTokenLabel(token: string): string {
  const normalized = normalizeSpeechToken(token);
  const labels: Record<string, string> = {
    title: 'Titel',
    street: 'Straat',
    house_number: 'Huisnummer',
    postcode: 'Postcode',
    place: 'Plaats',
  };

  return labels[normalized] ?? normalized.replaceAll('_', ' ');
}

export function insertSpeechToken(
  value: string,
  token: string,
  selectionStart: number | null,
  selectionEnd: number | null,
): { value: string; cursor: number } {
  const placeholder = `{${token}}`;
  const start = selectionStart ?? value.length;
  const end = selectionEnd ?? start;
  const prefix = value.slice(0, start);
  const suffix = value.slice(end);

  return {
    value: `${prefix}${placeholder}${suffix}`,
    cursor: prefix.length + placeholder.length,
  };
}

export function normalizeSpeechProgress(progress: number | null | undefined): number {
  if (!Number.isFinite(progress)) return 0;
  return Math.min(100, Math.max(0, Math.round(progress ?? 0)));
}

export function speechWorkIsActive(status: string | null | undefined): boolean {
  return ['uploaded', 'queued', 'running', 'downloading', 'installing', 'processing', 'regenerating'].includes(status ?? '');
}

export function speechVoiceProfileIsReadyForModel(
  profile: SpeechConfigurationVoiceProfile,
  modelId: string,
): boolean {
  return profile.status === 'ready' && profile.compatible_model_ids.includes(modelId);
}

export function speechConfigurationIssue({
  enabled,
  model,
  voiceProfileId,
  voiceProfile,
}: SpeechConfigurationInput): SpeechConfigurationIssue | null {
  if (voiceProfileId !== null && voiceProfile === null) {
    return {
      code: 'voice_profile_missing',
      message: 'Het gekozen stemprofiel is niet meer beschikbaar. Kies een ander gereed stemprofiel.',
    };
  }
  if (voiceProfile !== null && voiceProfile.status !== 'ready') {
    return {
      code: 'voice_profile_not_ready',
      message: `${voiceProfile.name} is nog niet gereed. Kies een gereed stemprofiel of wacht tot de verwerking klaar is.`,
    };
  }
  if (model !== null && voiceProfile !== null && !voiceProfile.compatible_model_ids.includes(model.id)) {
    return {
      code: 'voice_profile_incompatible',
      message: `${voiceProfile.name} is niet geschikt voor ${model.name}. Kies een compatibel stemprofiel.`,
    };
  }
  if (!enabled) return null;
  if (model === null) {
    return {
      code: 'model_missing',
      message: 'Kies en installeer eerst een servermodel.',
    };
  }
  if (model.status !== 'installed') {
    return {
      code: 'model_not_installed',
      message: `${model.name} is niet gereed. Installeer het model voordat de centrale serverstem wordt ingeschakeld.`,
    };
  }
  if (voiceProfile === null && !model.built_in_voice_available) {
    return {
      code: 'voice_profile_required',
      message: `${model.name} heeft geen ingebouwde stem. Kies een stemprofiel met status Gereed.`,
    };
  }

  return null;
}

export function speechStatusLabel(status: string | null | undefined): string {
  const labels: Record<string, string> = {
    not_installed: 'Niet geïnstalleerd',
    uploaded: 'Geüpload',
    queued: 'In wachtrij',
    running: 'Bezig',
    downloading: 'Downloaden',
    installing: 'Installeren',
    processing: 'Verwerken',
    regenerating: 'Opbouwen',
    installed: 'Geïnstalleerd',
    ready: 'Gereed',
    failed: 'Mislukt',
    expired: 'Verlopen',
  };

  return status === null || status === undefined ? 'Niet gestart' : labels[status] ?? status.replaceAll('_', ' ');
}

export function speechStatusTone(status: string | null | undefined): 'neutral' | 'good' | 'warn' | 'bad' {
  if (status === 'ready' || status === 'installed') return 'good';
  if (status === 'failed') return 'bad';
  if (speechWorkIsActive(status)) return 'warn';
  return 'neutral';
}

export function formatSpeechBytes(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value) || value < 0) return '-';
  if (value < 1_024) return `${Math.round(value)} B`;

  const units = ['KiB', 'MiB', 'GiB', 'TiB'];
  let amount = value / 1_024;
  let unitIndex = 0;
  while (amount >= 1_024 && unitIndex < units.length - 1) {
    amount /= 1_024;
    unitIndex += 1;
  }

  const digits = amount >= 10 ? 0 : 1;
  return `${amount.toLocaleString('nl-NL', { maximumFractionDigits: digits })} ${units[unitIndex]}`;
}

export function formatSpeechParameterCount(value: number): string {
  if (!Number.isFinite(value) || value < 0) return '-';
  if (value >= 1_000_000_000) {
    return `${(value / 1_000_000_000).toLocaleString('nl-NL', { maximumFractionDigits: 1 })} mld.`;
  }
  if (value >= 1_000_000) {
    return `${Math.round(value / 1_000_000).toLocaleString('nl-NL')} mln.`;
  }

  return Math.round(value).toLocaleString('nl-NL');
}

export function speechCacheHitRate(hitCount: number, missCount: number): string {
  const total = Math.max(0, hitCount) + Math.max(0, missCount);
  if (total === 0) return '-';

  return `${Math.round((Math.max(0, hitCount) / total) * 100)}%`;
}

export function speechCacheUsagePercentage(totalBytes: number, quotaBytes: number): number {
  if (!Number.isFinite(totalBytes) || !Number.isFinite(quotaBytes) || quotaBytes <= 0) return 0;
  return Math.min(100, Math.max(0, Math.round((totalBytes / quotaBytes) * 100)));
}

export function fixedSpeechPreviewAudioPath(previewId: string): string {
  return `/admin/speech/previews/${encodeURIComponent(previewId)}/audio`;
}

export function fixedSpeechCacheAudioPath(entryId: string): string {
  return `/admin/speech/cache/entries/${encodeURIComponent(entryId)}/audio`;
}

export function formatSpeechDuration(durationMs: number | null | undefined): string {
  if (durationMs === null || durationMs === undefined || !Number.isFinite(durationMs) || durationMs < 0) {
    return '-';
  }

  const totalSeconds = Math.round(durationMs / 1_000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;

  return minutes > 0
    ? `${minutes}:${String(seconds).padStart(2, '0')} min`
    : `${totalSeconds} sec.`;
}

export function microphoneRecordingError(error: unknown, secureContext: boolean): string {
  if (!secureContext) {
    return 'Microfoonopname werkt alleen via een beveiligde HTTPS-verbinding.';
  }

  const name = typeof error === 'object'
    && error !== null
    && 'name' in error
    && typeof error.name === 'string'
    ? error.name
    : '';

  if (name === 'NotAllowedError' || name === 'SecurityError') {
    return 'Microfoontoegang is geweigerd. Sta de microfoon voor deze website toe in de browser- en toestelinstellingen en probeer opnieuw, of upload een audiobestand.';
  }
  if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
    return 'Er is geen geschikte microfoon gevonden. Sluit een microfoon aan of upload een audiobestand.';
  }
  if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') {
    return 'De microfoon is bezet of tijdelijk niet beschikbaar. Sluit andere opname-apps en probeer opnieuw.';
  }

  return 'Microfoonopname kon niet worden gestart. Probeer opnieuw of upload een audiobestand.';
}

export function microphoneRequestIsCurrent(
  mounted: boolean,
  currentGeneration: number,
  requestGeneration: number,
): boolean {
  return mounted && currentGeneration === requestGeneration;
}
