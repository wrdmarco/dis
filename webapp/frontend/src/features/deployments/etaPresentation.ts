import type { DeploymentLiveLocation, DispatchPreview } from '../../types/api';

const LIVE_LOCATION_STALE_MS = 5 * 60 * 1000;

type EtaSource = DispatchPreview['recipients'][number]['eta_source'];

export function dispatchEtaLabel(
  etaMinutes: number | null | undefined,
  etaSource: EtaSource,
): string {
  if (!isUsableEta(etaMinutes)) {
    return 'ETA onbekend';
  }

  switch (etaSource) {
    case 'navigation':
      return `Navigatie-ETA-ring ${etaMinutes} min`;
    case 'fallback':
      return `Geschatte ETA-ring ${etaMinutes} min`;
    default:
      return `ETA-ring ${etaMinutes} min (bron onbekend)`;
  }
}

export function liveLocationEtaLabel(location: DeploymentLiveLocation, now = Date.now()): string {
  if (!isCurrentLiveLocation(location, now)) {
    return 'Niet actueel';
  }

  if (!isUsableEta(location.eta_minutes)) {
    return '-';
  }

  switch (location.eta_source) {
    case 'navigation':
      return `Navigatie: ${location.eta_minutes} min`;
    case 'fallback':
      return `Schatting: ${location.eta_minutes} min`;
    default:
      return `ETA: ${location.eta_minutes} min (bron onbekend)`;
  }
}

export function isCurrentLiveLocation(location: DeploymentLiveLocation, now = Date.now()): boolean {
  if (location.sharing_status === 'stale' || location.location_is_current === false) {
    return false;
  }

  if (location.location_is_current === true) {
    return true;
  }

  if (
    location.latitude === null
    || location.latitude === undefined
    || location.longitude === null
    || location.longitude === undefined
    || !location.recorded_at
  ) {
    return false;
  }

  const recordedAt = new Date(location.recorded_at).getTime();
  if (!Number.isFinite(recordedAt)) {
    return false;
  }

  const ageMs = now - recordedAt;

  return ageMs >= -2 * 60 * 1000 && ageMs <= LIVE_LOCATION_STALE_MS;
}

export function currentLiveLocations(
  locations: DeploymentLiveLocation[],
  now = Date.now(),
): DeploymentLiveLocation[] {
  return locations.filter((location) => isCurrentLiveLocation(location, now));
}

function isUsableEta(value: number | null | undefined): value is number {
  return typeof value === 'number' && Number.isFinite(value) && value > 0;
}
