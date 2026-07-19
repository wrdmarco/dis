export type SecondsStepperDirection = -1 | 1;

export function normalizeSecondsStepperValue(
  value: number,
  min: number,
  max: number,
  step = 1,
): number {
  assertSecondsStepperConstraints(min, max, step);

  if (!Number.isFinite(value)) return min;

  const maximumStepIndex = Math.floor(((max - min) / step) + Number.EPSILON);
  const requestedStepIndex = Math.round((value - min) / step);
  const stepIndex = Math.min(maximumStepIndex, Math.max(0, requestedStepIndex));
  const precision = Math.min(12, Math.max(decimalPlaces(min), decimalPlaces(max), decimalPlaces(step)));

  return Number((min + (stepIndex * step)).toFixed(precision));
}

export function moveSecondsStepperValue(
  value: number,
  direction: SecondsStepperDirection,
  min: number,
  max: number,
  step = 1,
): number {
  const current = normalizeSecondsStepperValue(value, min, max, step);
  return normalizeSecondsStepperValue(current + (direction * step), min, max, step);
}

export function commitSecondsStepperDraft(
  draft: string,
  currentValue: number,
  min: number,
  max: number,
  step = 1,
): number {
  const parsed = draft.trim() === '' ? Number.NaN : Number(draft);
  return normalizeSecondsStepperValue(
    Number.isFinite(parsed) ? parsed : currentValue,
    min,
    max,
    step,
  );
}

function assertSecondsStepperConstraints(min: number, max: number, step: number): void {
  if (!Number.isFinite(min) || !Number.isFinite(max) || min > max) {
    throw new RangeError('SecondsStepper vereist eindige grenzen waarbij min niet groter is dan max.');
  }
  if (!Number.isFinite(step) || step <= 0) {
    throw new RangeError('SecondsStepper vereist een eindige stapgrootte groter dan nul.');
  }
}

function decimalPlaces(value: number): number {
  const [, fraction = '', exponent = '0'] = value.toString().match(/^(?:\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/i) ?? [];
  return Math.max(0, fraction.length - Number(exponent));
}
