import type { Asset } from '../types/api';
import { droneTypeLabel } from './droneTypes';

export function assetDisplayLabel(asset?: Asset | null): string {
  if (!asset) {
    return '-';
  }

  const name = asset.name.trim();
  const type = asset.drone_type ? droneTypeLabel(asset.drone_type) : assetTypeLabel(asset.type);

  if (type === '' || type === '-' || type === name) {
    return name || '-';
  }

  return `${name || type} (${type})`;
}

export function assetTypeLabel(type: string): string {
  switch (type) {
    case 'drone':
      return 'Drone';
    case 'battery':
      return 'Batterij';
    case 'sensor':
      return 'Sensor';
    case 'vehicle':
      return 'Voertuig';
    case 'support_equipment':
      return 'Ondersteunend materieel';
    default:
      return type;
  }
}
