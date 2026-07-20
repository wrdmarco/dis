import type { SystemSetting } from '../../types/api';

export const KNMI_EDR_COLLECTION_ENDPOINT = 'https://api.dataplatform.knmi.nl/edr/v1/collections/10-minute-in-situ-meteorological-observations';
export const DEFAULT_AERET_MAP_URL = 'https://aeret.kaartviewer.nl/?@dpf_basic';

export interface AdminApiSettingsForm {
  aeretMapUrl: string;
  aeretApiUrl: string;
  aeretApiKey: string;
  knmiEdrApiKey: string;
}

export interface AdminApiSettingsConfiguration {
  form: AdminApiSettingsForm;
  aeretApiKeyConfigured: boolean;
  knmiEdrApiKeyConfigured: boolean;
}

export interface AdminApiSettingsPayload {
  'drone.aeret_map_url': string | null;
  'drone.aeret_api_url': string | null;
  'drone.aeret_api_key'?: string;
  'weather.knmi_edr_api_key'?: string;
}

export function mapAdminApiSettings(settings: SystemSetting[]): AdminApiSettingsConfiguration {
  const byKey = new Map(settings.map((setting) => [setting.key, setting.value]));

  return {
    form: {
      aeretMapUrl: asString(byKey.get('drone.aeret_map_url')) || DEFAULT_AERET_MAP_URL,
      aeretApiUrl: asString(byKey.get('drone.aeret_api_url')),
      aeretApiKey: '',
      knmiEdrApiKey: '',
    },
    aeretApiKeyConfigured: isConfiguredSecret(byKey.get('drone.aeret_api_key')),
    knmiEdrApiKeyConfigured: isConfiguredSecret(byKey.get('weather.knmi_edr_api_key')),
  };
}

export function preserveAdminApiSecrets(
  current: AdminApiSettingsForm,
  incoming: AdminApiSettingsForm,
): AdminApiSettingsForm {
  return {
    ...incoming,
    aeretApiKey: current.aeretApiKey,
    knmiEdrApiKey: current.knmiEdrApiKey,
  };
}

export function buildAdminApiSettingsPayload(form: AdminApiSettingsForm): AdminApiSettingsPayload {
  const aeretMapUrl = form.aeretMapUrl.trim();
  const aeretApiUrl = form.aeretApiUrl.trim();
  const aeretApiKey = form.aeretApiKey.trim();
  const knmiEdrApiKey = form.knmiEdrApiKey.trim();
  const payload: AdminApiSettingsPayload = {
    'drone.aeret_map_url': aeretMapUrl === '' ? null : aeretMapUrl,
    'drone.aeret_api_url': aeretApiUrl === '' ? null : aeretApiUrl,
  };

  if (aeretApiKey !== '') {
    payload['drone.aeret_api_key'] = aeretApiKey;
  }

  if (knmiEdrApiKey !== '') {
    payload['weather.knmi_edr_api_key'] = knmiEdrApiKey;
  }

  return payload;
}

function isConfiguredSecret(value: unknown): boolean {
  return isRecord(value) && value.configured === true;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value : '';
}
