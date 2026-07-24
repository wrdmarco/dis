import type {
  IncidentSpeechPreparationPhase,
  IncidentSpeechPreparationStatus,
  IncidentSpeechPreparations,
} from '../../types/api';

export const INCIDENT_SPEECH_PREPARATION_POLL_INTERVAL_MS = 5_000;

export interface IncidentSpeechPreparationPresentation {
  label: string;
  description: string;
  tone: 'neutral' | 'good' | 'warn' | 'bad';
}

export function incidentSpeechPreparationPhaseLabel(
  phase: IncidentSpeechPreparationPhase,
): string {
  switch (phase) {
    case 'availability':
      return 'TTS-vooralarmering';
    case 'attendance':
      return 'TTS-alarm';
  }
}

export function presentIncidentSpeechPreparation(
  status: IncidentSpeechPreparationStatus,
): IncidentSpeechPreparationPresentation {
  switch (status) {
    case 'disabled':
      return {
        label: 'Uitgeschakeld',
        description: 'De centrale serverstem staat uit.',
        tone: 'neutral',
      };
    case 'not_scheduled':
      return {
        label: 'Niet ingepland',
        description: 'Voor deze melding is nog geen serveraudio ingepland.',
        tone: 'neutral',
      };
    case 'queued':
      return {
        label: 'In wachtrij',
        description: 'De spraakservice wacht om deze audio te verwerken.',
        tone: 'warn',
      };
    case 'processing':
      return {
        label: 'Wordt gegenereerd',
        description: 'De serveraudio wordt nu opgebouwd.',
        tone: 'warn',
      };
    case 'ready':
      return {
        label: 'Gereed',
        description: 'De serveraudio is voorbereid voor verzending.',
        tone: 'good',
      };
    case 'failed':
      return {
        label: 'Mislukt',
        description: 'Het voorbereiden van de serveraudio is mislukt.',
        tone: 'bad',
      };
    case 'cancelled':
      return {
        label: 'Geannuleerd',
        description: 'De voorbereiding van deze serveraudio is geannuleerd.',
        tone: 'neutral',
      };
  }
}

export function incidentSpeechPreparationIsActive(
  status: IncidentSpeechPreparationStatus,
): boolean {
  return status === 'queued' || status === 'processing';
}

export function incidentSpeechPreparationsAreActive(
  preparations: IncidentSpeechPreparations | null | undefined,
): boolean {
  return preparations !== null
    && preparations !== undefined
    && (
      incidentSpeechPreparationIsActive(preparations.availability.status)
      || incidentSpeechPreparationIsActive(preparations.attendance.status)
    );
}

export function normalizeIncidentSpeechPreparationProgress(value: number): number {
  if (!Number.isFinite(value)) return 0;

  return Math.min(100, Math.max(0, Math.round(value)));
}
