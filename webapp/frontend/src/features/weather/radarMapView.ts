import type { WallboardForecastLocationMode } from '../../types/api';

export const NETHERLANDS_RADAR_VIEW_BOUNDS = [1, 49, 10, 55] as const;
export const RADAR_ADDRESS_ZOOM = 10;

export type RadarMapViewTarget =
  | {
    kind: 'netherlands';
    bounds: typeof NETHERLANDS_RADAR_VIEW_BOUNDS;
  }
  | {
    kind: 'address';
    center: readonly [longitude: number, latitude: number];
    zoom: typeof RADAR_ADDRESS_ZOOM;
  };

export function radarMapViewTarget(
  mode: WallboardForecastLocationMode | null,
  latitude: number | null,
  longitude: number | null,
): RadarMapViewTarget {
  const center = mode === 'address' ? validCoordinates(latitude, longitude) : null;
  if (center !== null) {
    return {
      kind: 'address',
      center,
      zoom: RADAR_ADDRESS_ZOOM,
    };
  }

  return { kind: 'netherlands', bounds: NETHERLANDS_RADAR_VIEW_BOUNDS };
}

export function radarMapViewKey(target: RadarMapViewTarget): string {
  return target.kind === 'address'
    ? `address:${target.center[0]}:${target.center[1]}`
    : 'netherlands';
}

function validCoordinates(
  latitude: number | null,
  longitude: number | null,
): readonly [longitude: number, latitude: number] | null {
  const valid = latitude !== null
    && longitude !== null
    && Number.isFinite(latitude)
    && Number.isFinite(longitude)
    && latitude >= -90
    && latitude <= 90
    && longitude >= -180
    && longitude <= 180;
  return valid ? [longitude, latitude] : null;
}
