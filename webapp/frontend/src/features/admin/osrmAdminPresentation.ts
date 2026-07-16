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
  'merging',
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

export function osrmOperationRequest(action: OsrmManagementAction): OsrmOperationRequest {
  return { action };
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
    return 'De huidige kaart blijft beschikbaar tijdens de verwerking. DIS verifieert de downloads voor Nederland en België afzonderlijk met de officiële Geofabrik-MD5-bestanden.';
  }

  return 'OSRM is niet gezond. DIS bouwt de dekking voor Nederland en België opnieuw op en verifieert beide downloads afzonderlijk met de officiële Geofabrik-MD5-bestanden.';
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
    merging: 'Kaartdekking samenvoegen',
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
