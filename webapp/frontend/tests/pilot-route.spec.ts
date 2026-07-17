import { expect, test } from 'playwright/test';
import { parseMapPoint, parsePilotRoute, pilotRouteColor } from '../src/features/incidents/pilotRoutePresentation';

const validRoute = {
  source: 'navigation',
  duration_seconds: 754,
  distance_meters: 12_345,
  geometry: {
    type: 'LineString',
    coordinates: [
      [4.901, 52.371],
      [4.978, 52.284],
      [5.122, 52.091],
    ],
  },
};

test('parses every point from a valid current pilot route', () => {
  expect(parsePilotRoute(validRoute)).toEqual({
    source: 'navigation',
    durationSeconds: 754,
    distanceMeters: 12_345,
    points: [
      { longitude: 4.901, latitude: 52.371 },
      { longitude: 4.978, latitude: 52.284 },
      { longitude: 5.122, latitude: 52.091 },
    ],
  });
});

test('rejects absent, empty and malformed route geometry instead of drawing a straight fallback', () => {
  const invalidRoutes: unknown[] = [
    null,
    {},
    { ...validRoute, geometry: null },
    { ...validRoute, geometry: { type: 'Point', coordinates: validRoute.geometry.coordinates } },
    { ...validRoute, geometry: { type: 'LineString', coordinates: [] } },
    { ...validRoute, geometry: { type: 'LineString', coordinates: [[4.9, 52.3]] } },
    { ...validRoute, geometry: { type: 'LineString', coordinates: [[4.9, 52.3], [null, 52.2]] } },
    { ...validRoute, geometry: { type: 'LineString', coordinates: [[4.9, 52.3], ['', 52.2]] } },
    { ...validRoute, geometry: { type: 'LineString', coordinates: [[4.9, 52.3], [Number.NaN, 52.2]] } },
    { ...validRoute, geometry: { type: 'LineString', coordinates: [[4.9, 52.3], [181, 52.2]] } },
    { ...validRoute, geometry: { type: 'LineString', coordinates: [[4.9, 52.3], [5.1, 91]] } },
    { ...validRoute, geometry: { type: 'LineString', coordinates: [[4.9, 52.3], [5.1, 52.2, 4.2]] } },
  ];

  for (const route of invalidRoutes) {
    expect(parsePilotRoute(route)).toBeNull();
  }
});

test('keeps valid geometry when optional route metrics are invalid', () => {
  expect(parsePilotRoute({
    ...validRoute,
    source: '  ',
    duration_seconds: -1,
    distance_meters: Number.POSITIVE_INFINITY,
  })).toMatchObject({
    source: null,
    durationSeconds: null,
    distanceMeters: null,
    points: validRoute.geometry.coordinates.map(([longitude, latitude]) => ({ longitude, latitude })),
  });
});

test('rejects route geometry above the client-side safety limit', () => {
  expect(parsePilotRoute({
    ...validRoute,
    geometry: {
      type: 'LineString',
      coordinates: Array.from({ length: 20_001 }, () => [5.1, 52.1]),
    },
  })).toBeNull();
});

test('validates map coordinates before numeric conversion', () => {
  expect(parseMapPoint('52.1', '5.1')).toEqual({ latitude: 52.1, longitude: 5.1 });

  for (const [latitude, longitude] of [
    [null, 5.1],
    ['', 5.1],
    ['   ', 5.1],
    [Number.NaN, 5.1],
    [91, 5.1],
    [52.1, null],
    [52.1, ''],
    [52.1, Number.POSITIVE_INFINITY],
    [52.1, 181],
  ] as Array<[unknown, unknown]>) {
    expect(parseMapPoint(latitude, longitude)).toBeNull();
  }
});

test('assigns a stable route color to the same pilot', () => {
  expect(pilotRouteColor('pilot-01')).toBe(pilotRouteColor('pilot-01'));
  expect(pilotRouteColor('pilot-01')).toMatch(/^#[0-9a-f]{6}$/i);
});
