import { expect, test } from 'playwright/test';
import {
  loadDeploymentLocationResults,
  mapWithConcurrency,
  replaceDeploymentLocationsAfterPoll,
} from '../src/features/deployments/deploymentMapRequests';
import type { DeploymentLiveLocation } from '../src/types/api';

test('limits parallel deployment map requests and preserves response order', async () => {
  let active = 0;
  let maximumActive = 0;

  const results = await mapWithConcurrency(
    Array.from({ length: 17 }, (_, index) => index),
    4,
    async (value) => {
      active += 1;
      maximumActive = Math.max(maximumActive, active);
      await new Promise((resolve) => setTimeout(resolve, value % 3));
      active -= 1;
      return `deployment-${value}`;
    },
  );

  expect(maximumActive).toBeLessThanOrEqual(4);
  expect(maximumActive).toBeGreaterThan(1);
  expect(results).toEqual(Array.from({ length: 17 }, (_, index) => `deployment-${index}`));
});

test('rejects invalid concurrency limits', async () => {
  await expect(mapWithConcurrency([1], 0, async (value) => value)).rejects.toThrow(RangeError);
  await expect(mapWithConcurrency([1], 1.5, async (value) => value)).rejects.toThrow(RangeError);
});

test('waits for all in-flight requests before reporting a worker failure', async () => {
  let active = 0;
  let maximumActive = 0;
  let completed = 0;

  const request = mapWithConcurrency([0, 1, 2, 3, 4, 5], 3, async (value) => {
    active += 1;
    maximumActive = Math.max(maximumActive, active);
    try {
      await new Promise((resolve) => setTimeout(resolve, value === 1 ? 1 : 12));
      if (value === 1) {
        throw new Error('route request failed');
      }
      completed += 1;
      return value;
    } finally {
      active -= 1;
    }
  });

  await expect(request).rejects.toThrow('route request failed');
  expect(active).toBe(0);
  expect(maximumActive).toBeLessThanOrEqual(3);
  expect(completed).toBeGreaterThan(0);
});

test('settles deployment location requests independently and keeps loading after one failure', async () => {
  const attempted: string[] = [];
  const locationFor = (userId: string): DeploymentLiveLocation => ({
    user_id: userId,
    latitude: 52.1,
    longitude: 5.1,
    recorded_at: '2026-07-17T08:00:00Z',
  });

  const results = await loadDeploymentLocationResults(
    ['deployment-a', 'deployment-b', 'deployment-c'],
    2,
    async (deploymentId) => {
      attempted.push(deploymentId);
      if (deploymentId === 'deployment-b') {
        throw new Error('temporary route failure');
      }

      return [locationFor(`pilot-${deploymentId}`)];
    },
  );

  expect(attempted.sort()).toEqual(['deployment-a', 'deployment-b', 'deployment-c']);
  expect(results).toHaveLength(3);
  expect(results[0].locations?.[0].user_id).toBe('pilot-deployment-a');
  expect(results[1].locations).toBeNull();
  expect(results[1].error).toBeInstanceOf(Error);
  expect(results[2].locations?.[0].user_id).toBe('pilot-deployment-c');
});

test('atomically replaces successful routes and clears a failed deployment route', () => {
  const previous: Record<string, DeploymentLiveLocation[]> = {
    'deployment-a': [{
      user_id: 'pilot-a',
      latitude: 52.1,
      longitude: 5.1,
      recorded_at: '2026-07-17T08:00:00Z',
      location_is_current: true,
      route: {
        source: 'navigation',
        distance_meters: 1500,
        duration_seconds: 240,
        geometry: { type: 'LineString', coordinates: [[5.1, 52.1], [5.2, 52.2]] },
      },
    }],
    'deployment-b': [{ user_id: 'pilot-b-old' }],
    obsolete: [{ user_id: 'pilot-obsolete' }],
  };
  const newRoute: DeploymentLiveLocation = {
    user_id: 'pilot-b-new',
    latitude: 51.9,
    longitude: 4.5,
    recorded_at: '2026-07-17T08:00:10Z',
    route: {
      source: 'navigation',
      distance_meters: 2100,
      duration_seconds: 300,
      geometry: { type: 'LineString', coordinates: [[4.5, 51.9], [4.6, 52.0]] },
    },
  };

  const next = replaceDeploymentLocationsAfterPoll(previous, [
    { deploymentId: 'deployment-a', locations: null, error: new Error('failed') },
    { deploymentId: 'deployment-b', locations: [newRoute], error: null },
  ]);

  expect(Object.keys(next)).toEqual(['deployment-a', 'deployment-b']);
  expect(next['deployment-a'][0].route).toBeNull();
  expect(next['deployment-a'][0].location_is_current).toBeUndefined();
  expect(next['deployment-b']).toEqual([newRoute]);
  expect(next.obsolete).toBeUndefined();
  expect(previous['deployment-a'][0].route).not.toBeNull();
  expect(previous['deployment-a'][0].location_is_current).toBe(true);
});
