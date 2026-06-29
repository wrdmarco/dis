import type { DroneType } from '../types/api';

type DroneTypeName = Pick<DroneType, 'manufacturer' | 'model'>;

export function droneTypeLabel(droneType?: DroneTypeName | null): string {
  if (!droneType) {
    return '-';
  }

  const manufacturer = droneType.manufacturer.trim();
  const model = droneType.model.trim();

  if (manufacturer === '') {
    return model || '-';
  }

  if (model.toLowerCase().startsWith(`${manufacturer.toLowerCase()} `)) {
    return model;
  }

  return `${manufacturer} ${model}`.trim();
}
