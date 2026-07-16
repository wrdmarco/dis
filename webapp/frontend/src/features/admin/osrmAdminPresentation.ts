import type {
  OsrmManagementAction,
  OsrmManagementState,
  OsrmOperationLogLine,
  OsrmOperationRequest,
  OsrmOperationStage,
  OsrmOperationState,
  OsrmOperationSummary,
} from '../../types/api';

const operationActions = new Set<OsrmManagementAction>(['install_activate', 'update']);
const operationStates = new Set<OsrmOperationState>(['queued', 'running', 'succeeded', 'failed']);
const operationStages = new Set<OsrmOperationStage>([
  'validating',
  'downloading',
  'installing_package',
  'provisioning',
  'extracting',
  'partitioning',
  'customizing',
  'activating',
  'verifying',
  'configuring',
  'completed',
]);

export interface OsrmOperationFormValues {
  sourceSha256: string;
  longitude: string;
  latitude: string;
}

export type OsrmFormValidation =
  | { valid: true; request: OsrmOperationRequest }
  | { valid: false; message: string };

export function validateOsrmOperationForm(
  action: OsrmManagementAction,
  form: OsrmOperationFormValues,
): OsrmFormValidation {
  const sourceSha256 = form.sourceSha256.trim().toLowerCase();
  if (!/^[a-f0-9]{64}$/.test(sourceSha256)) {
    return { valid: false, message: 'De onafhankelijke SHA-256 moet exact 64 hexadecimale tekens bevatten.' };
  }

  if (action === 'update') {
    return {
      valid: true,
      request: {
        action,
        source_sha256: sourceSha256,
      },
    };
  }

  const longitude = Number(form.longitude);
  const latitude = Number(form.latitude);
  if (form.longitude.trim() === '' || !Number.isFinite(longitude) || longitude < -180 || longitude > 180) {
    return { valid: false, message: 'Lengtegraad moet een getal tussen -180 en 180 zijn.' };
  }
  if (form.latitude.trim() === '' || !Number.isFinite(latitude) || latitude < -90 || latitude > 90) {
    return { valid: false, message: 'Breedtegraad moet een getal tussen -90 en 90 zijn.' };
  }

  return {
    valid: true,
    request: {
      action,
      source_sha256: sourceSha256,
      health_coordinate: { longitude, latitude },
    },
  };
}

export function osrmStateLabel(state: OsrmManagementState): string {
  switch (state) {
    case 'not_installed':
      return 'Niet geïnstalleerd';
    case 'installed_inactive':
      return 'Geïnstalleerd, niet actief';
    case 'ready':
      return 'Actief en gezond';
    case 'degraded':
      return 'Aandacht vereist';
  }
}

export function osrmStateTone(state: OsrmManagementState): 'neutral' | 'good' | 'warn' | 'bad' {
  switch (state) {
    case 'ready':
      return 'good';
    case 'degraded':
      return 'bad';
    case 'installed_inactive':
      return 'warn';
    case 'not_installed':
      return 'neutral';
  }
}

export function osrmActionLabel(action: OsrmManagementAction): string {
  return action === 'install_activate' ? 'OSRM installeren en activeren' : 'Kaartgegevens bijwerken';
}

export function osrmUpdateGuidance(state: OsrmManagementState, healthy: boolean): string {
  if (state === 'ready' && healthy) {
    return 'De huidige kaart blijft beschikbaar tijdens de verwerking. Geef voor deze update een nieuwe, onafhankelijk gecontroleerde SHA-256 op.';
  }

  return 'OSRM is niet gezond. Voor herstel mag je dezelfde onafhankelijk gecontroleerde SHA-256 van de actieve kaart opnieuw gebruiken; een nieuwere gecontroleerde SHA-256 mag ook.';
}

export function osrmConfirmationTitle(action: OsrmManagementAction): string {
  return action === 'install_activate' ? 'OSRM installeren en activeren?' : 'OSRM-kaartgegevens bijwerken?';
}

export function osrmOperationStateLabel(state: OsrmOperationState): string {
  switch (state) {
    case 'queued':
      return 'In wachtrij';
    case 'running':
      return 'Bezig';
    case 'succeeded':
      return 'Geslaagd';
    case 'failed':
      return 'Mislukt';
  }
}

export function osrmOperationTone(state: OsrmOperationState): 'neutral' | 'good' | 'warn' | 'bad' {
  switch (state) {
    case 'queued':
      return 'neutral';
    case 'running':
      return 'warn';
    case 'succeeded':
      return 'good';
    case 'failed':
      return 'bad';
  }
}

export function osrmOperationStageLabel(stage: OsrmOperationStage): string {
  const labels: Record<OsrmOperationStage, string> = {
    validating: 'Invoer controleren',
    downloading: 'Kaartgegevens downloaden',
    installing_package: 'OSRM installeren',
    provisioning: 'Service voorbereiden',
    extracting: 'Wegennet inlezen',
    partitioning: 'Routeringsnetwerk partitioneren',
    customizing: 'Routeringsnetwerk voorbereiden',
    activating: 'Nieuwe kaart activeren',
    verifying: 'Installatie controleren',
    configuring: 'DIS-routering activeren',
    completed: 'Afgerond',
  };

  return labels[stage];
}

export function osrmOperationIsActive(state: OsrmOperationState): boolean {
  return state === 'queued' || state === 'running';
}

export function nextOsrmPollDelay(state: OsrmOperationState, receivedLineCount: number): number | null {
  if (osrmOperationIsActive(state)) {
    return 2000;
  }

  return receivedLineCount >= 200 ? 0 : null;
}

export function mergeOsrmLogLines(current: OsrmOperationLogLine[], incoming: OsrmOperationLogLine[]): OsrmOperationLogLine[] {
  const merged = new Map(current.map((line) => [line.seq, line]));
  for (const line of incoming) {
    merged.set(line.seq, line);
  }

  return [...merged.values()]
    .sort((left, right) => left.seq - right.seq)
    .slice(-1000);
}

export function isOsrmOperationSummary(value: unknown): value is OsrmOperationSummary {
  if (value === null || typeof value !== 'object') {
    return false;
  }

  const candidate = value as Partial<OsrmOperationSummary>;

  return typeof candidate.id === 'string'
    && operationActions.has(candidate.action as OsrmManagementAction)
    && operationStates.has(candidate.state as OsrmOperationState)
    && operationStages.has(candidate.stage as OsrmOperationStage)
    && typeof candidate.message === 'string';
}
