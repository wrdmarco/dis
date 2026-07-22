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
