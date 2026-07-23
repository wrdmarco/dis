import type {
  WallboardPlaylistDataMode,
  WallboardPlaylistPurpose,
} from '../../types/api';
import { normalizeWallboardPlaylistDataMode } from './wallboardPlaylistDataMode';

interface WallboardPlaylistPurposeCandidate {
  purpose?: WallboardPlaylistPurpose | null;
}

interface WallboardAlarmPlaylistCandidate extends WallboardPlaylistPurposeCandidate {
  data_mode?: WallboardPlaylistDataMode | null;
}

export function normalizeWallboardPlaylistPurpose(purpose: unknown): WallboardPlaylistPurpose {
  return purpose === 'alarm' ? 'alarm' : 'normal';
}

export function wallboardPlaylistPurposeLabel(
  purpose: WallboardPlaylistPurpose | null | undefined,
): 'NORMAAL' | 'ALARM' {
  return normalizeWallboardPlaylistPurpose(purpose) === 'alarm' ? 'ALARM' : 'NORMAAL';
}

export function wallboardPlaylistIsNormal(playlist: WallboardPlaylistPurposeCandidate): boolean {
  return normalizeWallboardPlaylistPurpose(playlist.purpose) === 'normal';
}

export function wallboardPlaylistIsSelectableAlarm(
  playlist: WallboardAlarmPlaylistCandidate,
): boolean {
  return normalizeWallboardPlaylistPurpose(playlist.purpose) === 'alarm'
    && normalizeWallboardPlaylistDataMode(playlist.data_mode) === 'live';
}
