import type { WallboardPlaylistDataMode } from '../../types/api';

export function normalizeWallboardPlaylistDataMode(mode: unknown): WallboardPlaylistDataMode {
  return mode === 'demo' ? 'demo' : 'live';
}

export function wallboardPlaylistDataModeNeedsRefresh(
  loadedMode: unknown,
  targetMode: unknown,
): boolean {
  return normalizeWallboardPlaylistDataMode(loadedMode)
    !== normalizeWallboardPlaylistDataMode(targetMode);
}

export function wallboardPlaylistDataModeLabel(
  mode: WallboardPlaylistDataMode | null | undefined,
): 'DEMO' | 'LIVE DATA' {
  return normalizeWallboardPlaylistDataMode(mode) === 'demo' ? 'DEMO' : 'LIVE DATA';
}

export function wallboardPlaylistOptionLabel(playlist: {
  name: string;
  data_mode?: WallboardPlaylistDataMode | null;
}): string {
  return `${playlist.name} · ${wallboardPlaylistDataModeLabel(playlist.data_mode)}`;
}
