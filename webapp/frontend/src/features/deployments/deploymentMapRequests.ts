import type { DeploymentLiveLocation } from '../../types/api';

export interface DeploymentLocationPollResult {
  deploymentId: string;
  locations: DeploymentLiveLocation[] | null;
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

export async function loadDeploymentLocationResults(
  deploymentIds: readonly string[],
  concurrency: number,
  loader: (deploymentId: string, index: number) => Promise<DeploymentLiveLocation[]>,
): Promise<DeploymentLocationPollResult[]> {
  return mapWithConcurrency(deploymentIds, concurrency, async (deploymentId, index) => {
    try {
      return {
        deploymentId,
        locations: await loader(deploymentId, index),
        error: null,
      };
    } catch (error) {
      return {
        deploymentId,
        locations: null,
        error,
      };
    }
  });
}

export function replaceDeploymentLocationsAfterPoll(
  previous: Readonly<Record<string, DeploymentLiveLocation[]>>,
  results: readonly DeploymentLocationPollResult[],
): Record<string, DeploymentLiveLocation[]> {
  return Object.fromEntries(results.map((result) => [
    result.deploymentId,
    result.locations ?? clearPilotRoutes(previous[result.deploymentId] ?? []),
  ]));
}

export function clearPilotRoutes(
  locations: readonly DeploymentLiveLocation[],
): DeploymentLiveLocation[] {
  return locations.map((location) => ({
    ...location,
    route: null,
    // A failed refresh must not let a previously server-confirmed location
    // remain current forever. The timestamp fallback can retain a recent
    // marker briefly, while the route is removed immediately.
    location_is_current: undefined,
  }));
}
