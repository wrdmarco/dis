import type { SystemSetting } from '../../types/api';

export const DEPLOYMENT_REFERENCE_SETTING_KEY = 'deployment.reference_template';
export const DEFAULT_DEPLOYMENT_REFERENCE_TEMPLATE = 'DIS-{{date}}-{{time}}-{{random}}';

export const deploymentReferenceTokens = [
  { key: 'date', label: 'Datum inzet' },
  { key: 'time', label: 'Tijd inzet' },
  { key: 'sequence', label: 'Volgnummer' },
  { key: 'random', label: 'Willekeurige code' },
] as const;

export interface DeploymentReferencePreviewValues {
  date: string;
  time: string;
  sequence: string;
  random: string;
}

const defaultPreviewValues: DeploymentReferencePreviewValues = {
  date: '20260727',
  time: '123700',
  sequence: '0042',
  random: 'A1B2',
};

export function deploymentReferenceTemplate(settings: SystemSetting[]): string {
  const value = settings.find((setting) => setting.key === DEPLOYMENT_REFERENCE_SETTING_KEY)?.value;

  return typeof value === 'string' && value.trim() !== ''
    ? value
    : DEFAULT_DEPLOYMENT_REFERENCE_TEMPLATE;
}

export function buildDeploymentReferenceSettingsPayload(template: string): Record<string, string> {
  return {
    [DEPLOYMENT_REFERENCE_SETTING_KEY]: template.trim() || DEFAULT_DEPLOYMENT_REFERENCE_TEMPLATE,
  };
}

export function deploymentReferencePreview(
  template: string,
  values: DeploymentReferencePreviewValues = defaultPreviewValues,
): string {
  let rendered = template.trim() || DEFAULT_DEPLOYMENT_REFERENCE_TEMPLATE;
  for (const token of deploymentReferenceTokens) {
    rendered = rendered.replaceAll(`{{${token.key}}}`, values[token.key]);
  }

  return rendered
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[<>:"/\\|?*\u0000-\u001f\u007f]/g, '-')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^[.\s-]+|[.\s-]+$/g, '');
}
