import type { Asset } from '../types/api';
import { droneTypeLabel } from './droneTypes';

export function assetDisplayLabel(asset?: Asset | null): string {
  if (!asset) {
    return '-';
  }

  const name = asset.name.trim();
  const type = asset.drone_type ? droneTypeLabel(asset.drone_type) : asset.type;

  if (type === '' || type === '-' || type === name) {
    return name || '-';
  }

  return `${name || type} (${type})`;
}
