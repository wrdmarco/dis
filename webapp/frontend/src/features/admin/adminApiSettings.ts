import type { SystemSetting } from '../../types/api';

export const DEFAULT_AERET_MAP_URL = 'https://aeret.kaartviewer.nl/?@dpf_basic';

export interface AdminApiSettingsForm {
  aeretMapUrl: string;
  aeretApiUrl: string;
  aeretApiKey: string;
}

export interface AdminApiSettingsConfiguration {
  form: AdminApiSettingsForm;
  aeretApiKeyConfigured: boolean;
}

export interface AdminApiSettingsPayload {
  'drone.aeret_map_url': string | null;
  'drone.aeret_api_url': string | null;
  'drone.aeret_api_key'?: string;
}

export function mapAdminApiSettings(settings: SystemSetting[]): AdminApiSettingsConfiguration {
  const byKey = new Map(settings.map((setting) => [setting.key, setting.value]));

  return {
    form: {
      aeretMapUrl: asString(byKey.get('drone.aeret_map_url')) || DEFAULT_AERET_MAP_URL,
      aeretApiUrl: asString(byKey.get('drone.aeret_api_url')),
      aeretApiKey: '',
    },
    aeretApiKeyConfigured: isConfiguredSecret(byKey.get('drone.aeret_api_key')),
  };
}

export function preserveAdminApiSecrets(
  current: AdminApiSettingsForm,
  incoming: AdminApiSettingsForm,
): AdminApiSettingsForm {
  return {
    ...incoming,
    aeretApiKey: current.aeretApiKey,
  };
}

export function buildAdminApiSettingsPayload(form: AdminApiSettingsForm): AdminApiSettingsPayload {
  const aeretMapUrl = form.aeretMapUrl.trim();
  const aeretApiUrl = form.aeretApiUrl.trim();
  const aeretApiKey = form.aeretApiKey.trim();
  const payload: AdminApiSettingsPayload = {
    'drone.aeret_map_url': aeretMapUrl === '' ? null : aeretMapUrl,
    'drone.aeret_api_url': aeretApiUrl === '' ? null : aeretApiUrl,
  };

  if (aeretApiKey !== '') {
    payload['drone.aeret_api_key'] = aeretApiKey;
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
