export function changedDeploymentPayloadRecords(
  currentPayload: Record<string, unknown>,
  baselinePayload: Record<string, unknown>,
): Record<string, unknown> {
  const patch: Record<string, unknown> = {};

  Object.entries(currentPayload).forEach(([key, value]) => {
    if (key === 'custom_fields') {
      const changedCustomFields = changedRecord(
        value as Record<string, unknown>,
        baselinePayload.custom_fields as Record<string, unknown>,
      );
      if (Object.keys(changedCustomFields).length > 0) {
        patch.custom_fields = changedCustomFields;
      }
      return;
    }

    if (!valuesEqual(value, baselinePayload[key])) {
      patch[key] = value;
    }
  });

  return patch;
}

function changedRecord(
  current: Record<string, unknown>,
  baseline: Record<string, unknown>,
): Record<string, unknown> {
  const changed: Record<string, unknown> = {};
  new Set([...Object.keys(current), ...Object.keys(baseline)]).forEach((key) => {
    const value = Object.hasOwn(current, key) ? current[key] : null;
    if (!valuesEqual(value, baseline[key])) {
      changed[key] = value;
    }
  });
  return changed;
}

function valuesEqual(left: unknown, right: unknown): boolean {
  return JSON.stringify(left) === JSON.stringify(right);
}
