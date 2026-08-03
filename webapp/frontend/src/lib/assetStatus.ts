import type { AssetStatus } from '../types/api';
import { dateInputValueInAmsterdam } from './dateTime';
import { statusLabel } from './statusLabels';

export interface AssetStatusSource {
  status: AssetStatus;
  effective_status?: AssetStatus;
  is_effectively_ready?: boolean;
  maintenance_due_at?: string | null;
  maintenance_overdue?: boolean;
}

export interface AssetStatusPresentation {
  effectiveStatus: AssetStatus;
  label: string;
  maintenanceOverdue: boolean;
  tone: 'neutral' | 'good' | 'warn' | 'bad';
}

const USABLE_ASSET_STATUSES = new Set<AssetStatus>(['ready', 'assigned']);

export function assetMaintenanceOverdue(asset: AssetStatusSource, now = new Date()): boolean {
  if (typeof asset.maintenance_overdue === 'boolean') {
    return asset.maintenance_overdue;
  }

  const maintenanceDate = dateInputValue(asset.maintenance_due_at);
  return maintenanceDate !== null && maintenanceDate < dateInputValueInAmsterdam(now);
}

export function assetEffectiveStatus(asset: AssetStatusSource, now = new Date()): AssetStatus {
  if (asset.effective_status !== undefined) {
    return asset.effective_status;
  }

  return assetMaintenanceOverdue(asset, now) && USABLE_ASSET_STATUSES.has(asset.status)
    ? 'maintenance'
    : asset.status;
}

export function assetIsEffectivelyReady(asset: AssetStatusSource, now = new Date()): boolean {
  if (typeof asset.is_effectively_ready === 'boolean') {
    return asset.is_effectively_ready;
  }

  return USABLE_ASSET_STATUSES.has(assetEffectiveStatus(asset, now));
}

export function assetStatusPresentation(asset: AssetStatusSource, now = new Date()): AssetStatusPresentation {
  const maintenanceOverdue = assetMaintenanceOverdue(asset, now);
  const effectiveStatus = assetEffectiveStatus(asset, now);

  if (maintenanceOverdue && effectiveStatus === 'maintenance') {
    return {
      effectiveStatus,
      label: statusLabel('maintenance_overdue'),
      maintenanceOverdue,
      tone: 'bad',
    };
  }

  return {
    effectiveStatus,
    label: statusLabel(effectiveStatus),
    maintenanceOverdue,
    tone: effectiveStatus === 'ready'
      ? 'good'
      : effectiveStatus === 'maintenance'
        ? 'warn'
        : effectiveStatus === 'unavailable' || effectiveStatus === 'retired'
          ? 'bad'
          : 'neutral',
  };
}

function dateInputValue(value?: string | null): string | null {
  if (!value) {
    return null;
  }

  const dateOnly = value.match(/^(\d{4}-\d{2}-\d{2})/);
  if (dateOnly !== null) {
    return dateOnly[1];
  }

  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? null : dateInputValueInAmsterdam(parsed);
}
