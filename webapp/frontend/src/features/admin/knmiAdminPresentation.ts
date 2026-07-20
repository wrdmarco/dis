import type { KnmiAdminSettingsRequest, KnmiForecastOperationState } from '../../types/api';

export const KNMI_ACTIVE_POLL_INTERVAL_MS = 5_000;

export function buildKnmiKeyPayload(form: { openDataApiKey: string; edrApiKey: string }): KnmiAdminSettingsRequest {
  const payload: KnmiAdminSettingsRequest = {};
  const openDataApiKey = form.openDataApiKey.trim();
  const edrApiKey = form.edrApiKey.trim();

  if (openDataApiKey !== '') {
    payload.open_data_api_key = openDataApiKey;
  }
  if (edrApiKey !== '') {
    payload.edr_api_key = edrApiKey;
  }

  return payload;
}

export function knmiOperationIsActive(state?: KnmiForecastOperationState | null): boolean {
  return state === 'queued' || state === 'running';
}

export function knmiOperationStateLabel(state: KnmiForecastOperationState, unchanged = false): string {
  if (state === 'queued') {
    return 'In wachtrij';
  }
  if (state === 'running') {
    return 'Bezig';
  }
  if (state === 'succeeded') {
    return unchanged ? 'Al actueel' : 'Bijgewerkt';
  }

  return 'Mislukt';
}

export function knmiOperationStateTone(state: KnmiForecastOperationState): 'neutral' | 'good' | 'warn' | 'bad' {
  if (state === 'succeeded') {
    return 'good';
  }
  if (state === 'failed') {
    return 'bad';
  }

  return state === 'running' ? 'warn' : 'neutral';
}

export function knmiOperationStageLabel(stage?: string | null): string {
  const labels: Record<string, string> = {
    queued: 'Wachten op uitvoering',
    starting: 'Import voorbereiden',
    metadata: 'Actuele modelset controleren',
    discovering: 'Actuele modelrun zoeken',
    downloading: 'Volledig archief downloaden',
    verifying: 'Download controleren',
    extracting: 'Forecasturen uitpakken',
    validating: 'KNMI-parameters controleren',
    indexing: 'Forecasturen indexeren',
    activating: 'Nieuwe gegevens activeren',
    completed: 'Afgerond',
    failed: 'Afgebroken',
  };

  if (!stage) {
    return '-';
  }

  return labels[stage] ?? stage.replaceAll('_', ' ');
}

export function normalizeKnmiProgress(value?: number | null): number | null {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return null;
  }

  return Math.min(100, Math.max(0, Math.round(value)));
}

export function formatKnmiBytes(value?: number | null): string {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) {
    return '-';
  }
  if (value === 0) {
    return '0 B';
  }

  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
  const unitIndex = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
  const scaled = value / (1024 ** unitIndex);
  const digits = unitIndex === 0 || scaled >= 100 ? 0 : scaled >= 10 ? 1 : 2;

  return `${new Intl.NumberFormat('nl-NL', {
    maximumFractionDigits: digits,
    minimumFractionDigits: 0,
  }).format(scaled)} ${units[unitIndex]}`;
}

export function knmiKeySourceLabel(source?: string | null): string {
  const labels: Record<string, string> = {
    open_data_setting: 'Opgeslagen in D.I.S.',
    open_data_environment: 'Serveromgeving',
    legacy_edr_setting: 'Bestaande EDR-instelling',
    legacy_edr_environment: 'Bestaande EDR-serveromgeving',
    edr_setting: 'Opgeslagen in D.I.S.',
    edr_environment: 'Serveromgeving',
    setting: 'Opgeslagen in D.I.S.',
    environment: 'Serveromgeving',
  };

  return source ? labels[source] ?? 'Ingesteld' : 'Niet ingesteld';
}
