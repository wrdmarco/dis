'use client';

import { Loader2 } from 'lucide-react';
import type { WallboardFlipDirection, WallboardItemTransition } from '../../types/api';
import { useApiResource } from '../../lib/useApiResource';
import { WallboardPhotoCarousel } from './WallboardPhotoCarousel';
import {
  type WallboardMediaPlaylist,
  wallboardMediaPageStateFromPlaylist,
} from './wallboardMedia';
import styles from './WallboardPhotoPlaylistPreview.module.css';

export interface WallboardPhotoPlaylistPreviewProps {
  mediaPlaylistId: string | null | undefined;
  itemDurationSeconds: number | null | undefined;
  transition?: WallboardItemTransition | null;
  transitionDurationMs?: number | null;
  flipDirection?: WallboardFlipDirection | null;
  running?: boolean;
  className?: string;
}

export function WallboardPhotoPlaylistPreview({
  mediaPlaylistId,
  itemDurationSeconds,
  transition,
  transitionDurationMs,
  flipDirection,
  running = true,
  className,
}: WallboardPhotoPlaylistPreviewProps) {
  const enabled = typeof mediaPlaylistId === 'string' && mediaPlaylistId.trim() !== '';
  const resource = useApiResource<WallboardMediaPlaylist>(
    enabled ? `/admin/wallboard-media/playlists/${mediaPlaylistId}` : '/admin/wallboard-media/playlists/unselected',
    enabled,
  );

  if (!enabled) {
    return <div className={`${styles.status} ${className ?? ''}`} role="status">Kies eerst een fotoplaylist.</div>;
  }
  if (resource.loading) {
    return <div className={`${styles.status} ${className ?? ''}`} role="status"><Loader2 size={20} aria-hidden /> Fotovoorbeeld laden...</div>;
  }
  if (resource.error !== null || resource.data === null) {
    return <div className={`${styles.status} ${styles.error} ${className ?? ''}`} role="alert">{resource.error ?? 'Fotovoorbeeld is niet beschikbaar.'}</div>;
  }

  return (
    <WallboardPhotoCarousel
      media={wallboardMediaPageStateFromPlaylist(resource.data, itemDurationSeconds)}
      running={running}
      anchor={null}
      transition={transition}
      transitionDurationMs={transitionDurationMs}
      flipDirection={flipDirection}
      variant="preview"
      showCaption
      className={className}
    />
  );
}
