const PILOT_ROUTE_COLORS = ['#7dd3fc', '#fbbf24', '#a7f3d0', '#fca5a5', '#c4b5fd', '#fdba74', '#93c5fd', '#f0abfc'] as const;
const MAX_PILOT_ROUTE_POINTS = 20_000;

export interface PilotRoutePoint {
  latitude: number;
  longitude: number;
}

export interface PilotRoutePresentation {
  points: PilotRoutePoint[];
  source: string | null;
  distanceMeters: number | null;
  durationSeconds: number | null;
}

export function parsePilotRoute(value: unknown): PilotRoutePresentation | null {
  if (!isRecord(value) || !isRecord(value.geometry) || value.geometry.type !== 'LineString') {
    return null;
  }

  const coordinates = value.geometry.coordinates;
  if (!Array.isArray(coordinates) || coordinates.length < 2 || coordinates.length > MAX_PILOT_ROUTE_POINTS) {
    return null;
  }

  const points: PilotRoutePoint[] = [];
  for (const coordinate of coordinates) {
    if (!Array.isArray(coordinate) || coordinate.length !== 2) {
      return null;
    }

    const longitude = boundedCoordinate(coordinate[0], -180, 180);
    const latitude = boundedCoordinate(coordinate[1], -90, 90);
    if (longitude === null || latitude === null) {
      return null;
    }

    points.push({ latitude, longitude });
  }

  return {
    points,
    source: typeof value.source === 'string' && value.source.trim() !== '' ? value.source.trim() : null,
    distanceMeters: nonNegativeNumber(value.distance_meters),
    durationSeconds: nonNegativeNumber(value.duration_seconds),
  };
}

export function pilotRouteColor(userId: string): string {
  let hash = 0;
  for (let index = 0; index < userId.length; index += 1) {
    hash = ((hash * 31) + userId.charCodeAt(index)) >>> 0;
  }

  return PILOT_ROUTE_COLORS[hash % PILOT_ROUTE_COLORS.length];
}

export function parseMapPoint(latitudeValue: unknown, longitudeValue: unknown): PilotRoutePoint | null {
  const latitude = boundedCoordinate(latitudeValue, -90, 90);
  const longitude = boundedCoordinate(longitudeValue, -180, 180);

  return latitude === null || longitude === null ? null : { latitude, longitude };
}

function boundedCoordinate(value: unknown, minimum: number, maximum: number): number | null {
  if (value === null || value === undefined || value === '' || typeof value === 'boolean') {
    return null;
  }

  if (typeof value === 'string' && value.trim() === '') {
    return null;
  }

  if (typeof value !== 'number' && typeof value !== 'string') {
    return null;
  }

  const coordinate = Number(value);
  return Number.isFinite(coordinate) && coordinate >= minimum && coordinate <= maximum
    ? coordinate
    : null;
}

function nonNegativeNumber(value: unknown): number | null {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) {
    return null;
  }

  return value;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
