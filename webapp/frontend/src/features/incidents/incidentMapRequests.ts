import type { IncidentLiveLocation } from '../../types/api';

export interface IncidentLocationPollResult {
  incidentId: string;
  locations: IncidentLiveLocation[] | null;
  error: unknown | null;
}

export async function mapWithConcurrency<T, R>(
  items: readonly T[],
  concurrency: number,
  mapper: (item: T, index: number) => Promise<R>,
): Promise<R[]> {
  if (!Number.isInteger(concurrency) || concurrency < 1) {
    throw new RangeError('Concurrency must be a positive integer.');
  }

  const results = new Array<R>(items.length);
  let nextIndex = 0;
  let hasFailure = false;
  let firstFailure: unknown;

  async function worker(): Promise<void> {
    while (!hasFailure && nextIndex < items.length) {
      const index = nextIndex;
      nextIndex += 1;
      try {
        results[index] = await mapper(items[index], index);
      } catch (error) {
        if (!hasFailure) {
          hasFailure = true;
          firstFailure = error;
        }
      }
    }
  }

  const workerCount = Math.min(concurrency, items.length);
  await Promise.allSettled(Array.from({ length: workerCount }, () => worker()));
  if (hasFailure) {
    throw firstFailure;
  }

  return results;
}

export async function loadIncidentLocationResults(
  incidentIds: readonly string[],
  concurrency: number,
  loader: (incidentId: string, index: number) => Promise<IncidentLiveLocation[]>,
): Promise<IncidentLocationPollResult[]> {
  return mapWithConcurrency(incidentIds, concurrency, async (incidentId, index) => {
    try {
      return {
        incidentId,
        locations: await loader(incidentId, index),
        error: null,
      };
    } catch (error) {
      return {
        incidentId,
        locations: null,
        error,
      };
    }
  });
}

export function replaceIncidentLocationsAfterPoll(
  previous: Readonly<Record<string, IncidentLiveLocation[]>>,
  results: readonly IncidentLocationPollResult[],
): Record<string, IncidentLiveLocation[]> {
  return Object.fromEntries(results.map((result) => [
    result.incidentId,
    result.locations ?? clearPilotRoutes(previous[result.incidentId] ?? []),
  ]));
}

export function clearPilotRoutes(locations: readonly IncidentLiveLocation[]): IncidentLiveLocation[] {
  return locations.map((location) => ({
    ...location,
    route: null,
    // A failed refresh must not let a previously server-confirmed location
    // remain current forever. The timestamp fallback can retain a recent
    // marker briefly, while the route is removed immediately.
    location_is_current: undefined,
  }));
}
