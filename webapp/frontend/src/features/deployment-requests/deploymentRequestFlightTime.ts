export interface DeploymentRequestFlightTimeValue {
  start: string;
  end: string;
  duration_minutes: number | null;
}

const DEPLOYMENT_REQUEST_TIME_PATTERN = /^(?:[01]\d|2[0-3]):[0-5]\d$/;
const LEGACY_DEPLOYMENT_REQUEST_FLIGHT_TIME_PATTERN = /^\s*((?:[01]\d|2[0-3]):[0-5]\d)\s*-\s*((?:[01]\d|2[0-3]):[0-5]\d)\s*$/;

export function deploymentRequestFlightTimeValue(value: unknown): DeploymentRequestFlightTimeValue {
  const legacy = typeof value === 'string'
    ? value.match(LEGACY_DEPLOYMENT_REQUEST_FLIGHT_TIME_PATTERN)
    : null;
  if (legacy !== null) {
    return completeDeploymentRequestFlightTimeValue(legacy[1], legacy[2]);
  }

  if (!isCanonicalDeploymentRequestFlightTimeValue(value)) {
    if (!isPartialDeploymentRequestFlightTimeValue(value)) {
      return emptyDeploymentRequestFlightTimeValue();
    }

    return {
      start: value.start,
      end: value.end,
      duration_minutes: null,
    };
  }

  return {
    start: value.start,
    end: value.end,
    duration_minutes: value.duration_minutes,
  };
}

export function updateDeploymentRequestFlightTimeValue(
  current: DeploymentRequestFlightTimeValue,
  part: 'start' | 'end',
  value: string,
): DeploymentRequestFlightTimeValue {
  const next = {
    ...current,
    [part]: normalizeDeploymentRequestTime(value),
  };

  return completeDeploymentRequestFlightTimeValue(next.start, next.end);
}

export function deploymentRequestFlightTimeChangeValue(
  value: DeploymentRequestFlightTimeValue,
): DeploymentRequestFlightTimeValue | null {
  return value.start === '' && value.end === '' ? null : value;
}

function isCanonicalDeploymentRequestFlightTimeValue(
  value: unknown,
): value is { start: string; end: string; duration_minutes: number } {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    return false;
  }

  const record = value as Record<string, unknown>;
  if (
    !isDeploymentRequestTime(record.start)
    || !isDeploymentRequestTime(record.end)
    || typeof record.duration_minutes !== 'number'
    || !Number.isInteger(record.duration_minutes)
  ) {
    return false;
  }

  return record.duration_minutes === deploymentRequestFlightDurationMinutes(record.start, record.end);
}

function isPartialDeploymentRequestFlightTimeValue(
  value: unknown,
): value is { start: string; end: string; duration_minutes: null } {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    return false;
  }

  const record = value as Record<string, unknown>;
  const startIsValid = record.start === '' || isDeploymentRequestTime(record.start);
  const endIsValid = record.end === '' || isDeploymentRequestTime(record.end);

  return startIsValid
    && endIsValid
    && (record.start === '' || record.end === '')
    && record.duration_minutes === null;
}

function completeDeploymentRequestFlightTimeValue(
  start: string,
  end: string,
): DeploymentRequestFlightTimeValue {
  return {
    start,
    end,
    duration_minutes: deploymentRequestFlightDurationMinutes(start, end),
  };
}

function emptyDeploymentRequestFlightTimeValue(): DeploymentRequestFlightTimeValue {
  return { start: '', end: '', duration_minutes: null };
}

function normalizeDeploymentRequestTime(value: unknown): string {
  return typeof value === 'string' && isDeploymentRequestTime(value.trim()) ? value.trim() : '';
}

function isDeploymentRequestTime(value: unknown): value is string {
  return typeof value === 'string' && DEPLOYMENT_REQUEST_TIME_PATTERN.test(value);
}

function deploymentRequestFlightDurationMinutes(start: string, end: string): number | null {
  if (!isDeploymentRequestTime(start) || !isDeploymentRequestTime(end)) {
    return null;
  }

  const [startHour, startMinute] = start.split(':').map(Number);
  const [endHour, endMinute] = end.split(':').map(Number);
  const startTotal = startHour * 60 + startMinute;
  let endTotal = endHour * 60 + endMinute;
  if (endTotal < startTotal) {
    endTotal += 24 * 60;
  }

  return endTotal - startTotal;
}
