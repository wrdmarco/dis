'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { ImageOff } from 'lucide-react';
import type { WallboardMediaPageState, WallboardMediaPageStateItem } from './wallboardMedia';
import {
  wallboardMediaImageUrl,
  wallboardPhotoItemDurationSeconds,
} from './wallboardMedia';
import {
  type WallboardPhotoCarouselClock,
  wallboardPhotoCarouselClock,
  wallboardPhotoCarouselIndex,
  wallboardPhotoCarouselNextDelayMs,
  wallboardPhotoCarouselTransition,
} from './wallboardPhotoRotation';
import styles from './WallboardPhotoCarousel.module.css';

export interface WallboardPhotoCarouselProps {
  media: WallboardMediaPageState | null | undefined;
  running: boolean;
  anchor: string | number | Date | null | undefined;
  variant?: 'wallboard' | 'preview';
  showCaption?: boolean;
  className?: string;
}

interface DisplayablePhoto extends WallboardMediaPageStateItem {
  imageUrl: string;
}

export function WallboardPhotoCarousel({
  media,
  running,
  anchor,
  variant = 'wallboard',
  showCaption = false,
  className,
}: WallboardPhotoCarouselProps) {
  const photos = useMemo(() => displayablePhotos(media?.items ?? []), [media?.items]);
  const itemDurationSeconds = wallboardPhotoItemDurationSeconds(media?.item_duration_seconds);
  const signature = `${anchorSignature(anchor)}:${media?.media_playlist_id ?? 'empty'}:${media?.media_playlist_version ?? 0}`;
  const signatureRef = useRef(signature);
  const [clock, setClock] = useState<WallboardPhotoCarouselClock>(() => (
    wallboardPhotoCarouselClock(anchor, running)
  ));
  const index = wallboardPhotoCarouselIndex(clock, photos.length, itemDurationSeconds);
  const photo = photos[index] ?? null;

  useEffect(() => {
    if (signatureRef.current === signature) return;
    signatureRef.current = signature;
    setClock(wallboardPhotoCarouselClock(anchor, running));
  }, [anchor, running, signature]);

  useEffect(() => {
    setClock((current) => wallboardPhotoCarouselTransition(current, running, Date.now()));
  }, [running]);

  useEffect(() => {
    if (!running || photos.length <= 1) return undefined;
    const timer = window.setTimeout(() => {
      setClock((current) => wallboardPhotoCarouselTransition(current, true, Date.now()));
    }, wallboardPhotoCarouselNextDelayMs(clock, itemDurationSeconds));
    return () => window.clearTimeout(timer);
  }, [clock, itemDurationSeconds, photos.length, running]);

  const rootClassName = [
    styles.root,
    variant === 'preview' ? styles.preview : styles.wallboard,
    !running ? styles.paused : '',
    className ?? '',
  ].filter(Boolean).join(' ');

  if (photo === null) {
    return (
      <div className={`${rootClassName} ${styles.empty}`} role="status">
        <ImageOff size={variant === 'wallboard' ? 52 : 32} aria-hidden />
        <strong>Geen foto beschikbaar</strong>
        <span>Controleer de gekozen fotoplaylist in wallboardbeheer.</span>
      </div>
    );
  }

  return (
    <figure className={rootClassName} aria-label={`Foto ${index + 1} van ${photos.length}`}>
      <img
        className={styles.backdrop}
        src={photo.imageUrl}
        alt=""
        aria-hidden="true"
        draggable={false}
      />
      <img
        key={`${photo.id}:${index}`}
        className={styles.photo}
        src={photo.imageUrl}
        alt={photo.name}
        width={photo.width}
        height={photo.height}
        draggable={false}
      />
      {showCaption ? (
        <figcaption className={styles.caption}>
          <strong>{photo.name}</strong>
          <span>{index + 1} / {photos.length}</span>
        </figcaption>
      ) : null}
    </figure>
  );
}

function displayablePhotos(items: readonly WallboardMediaPageStateItem[]): DisplayablePhoto[] {
  return items.flatMap((item) => {
    const imageUrl = wallboardMediaImageUrl(item.image_url);
    return imageUrl === null ? [] : [{ ...item, imageUrl }];
  });
}

function anchorSignature(anchor: string | number | Date | null | undefined): string {
  if (anchor instanceof Date) return String(anchor.getTime());
  return anchor === null || anchor === undefined ? 'mount' : String(anchor);
}
