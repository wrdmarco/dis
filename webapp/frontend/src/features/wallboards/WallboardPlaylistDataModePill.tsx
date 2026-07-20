import { StatusPill } from '../../components/StatusPill';
import type { WallboardPlaylistDataMode } from '../../types/api';
import {
  normalizeWallboardPlaylistDataMode,
  wallboardPlaylistDataModeLabel,
} from './wallboardPlaylistDataMode';

interface WallboardPlaylistDataModePillProps {
  mode: WallboardPlaylistDataMode | null | undefined;
}

export function WallboardPlaylistDataModePill({ mode }: WallboardPlaylistDataModePillProps) {
  const normalizedMode = normalizeWallboardPlaylistDataMode(mode);

  return (
    <span
      className={`wallboard-playlist-data-mode-pill wallboard-playlist-data-mode-pill--${normalizedMode}`}
      data-data-mode={normalizedMode}
    >
      <StatusPill
        value={wallboardPlaylistDataModeLabel(normalizedMode)}
        tone={normalizedMode === 'demo' ? 'warn' : 'good'}
      />
    </span>
  );
}
