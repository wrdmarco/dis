import { StatusPill } from '../../components/StatusPill';
import type { WallboardPlaylistPurpose } from '../../types/api';
import {
  normalizeWallboardPlaylistPurpose,
  wallboardPlaylistPurposeLabel,
} from './wallboardPlaylistPurpose';

interface WallboardPlaylistPurposePillProps {
  purpose: WallboardPlaylistPurpose | null | undefined;
}

export function WallboardPlaylistPurposePill({ purpose }: WallboardPlaylistPurposePillProps) {
  const normalizedPurpose = normalizeWallboardPlaylistPurpose(purpose);

  return (
    <span
      className={`wallboard-playlist-purpose-pill wallboard-playlist-purpose-pill--${normalizedPurpose}`}
      data-playlist-purpose={normalizedPurpose}
    >
      <StatusPill
        value={wallboardPlaylistPurposeLabel(normalizedPurpose)}
        tone={normalizedPurpose === 'alarm' ? 'warn' : 'neutral'}
      />
    </span>
  );
}
