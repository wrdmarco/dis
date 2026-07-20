'use client';

import { type CSSProperties, useEffect, useMemo, useRef, useState } from 'react';
import { ImageOff } from 'lucide-react';
import type {
  WallboardFlipDirection,
  WallboardItemTransition,
} from '../../types/api';
import type { WallboardMediaPageState, WallboardMediaPageStateItem } from './wallboardMedia';
import {
  wallboardAdminMediaVersionedUrl,
  wallboardMediaImageUrl,
  wallboardPhotoItemDurationSeconds,
} from './wallboardMedia';
import {
  DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION_DURATION_MS,
  clampWallboardTransitionDurationMs,
  normalizeWallboardFlipDirection,
  normalizeWallboardNewsItemTransition,
  resolveWallboardFlipDirection,
  type WallboardResolvedFlipDirection,
} from './wallboardPresentation';
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
  transition?: WallboardItemTransition | null;
  transitionDurationMs?: number | null;
  flipDirection?: WallboardFlipDirection | null;
  variant?: 'wallboard' | 'preview';
  showCaption?: boolean;
  className?: string;
}

interface DisplayablePhoto extends WallboardMediaPageStateItem {
  imageUrl: string;
}

interface PhotoTransitionState {
  signature: string;
  current: DisplayablePhoto | null;
  previous: DisplayablePhoto | null;
  sequence: number;
}

const TRANSITION_CLASSES: Record<WallboardItemTransition, string> = {
  fade: styles.transitionFade,
  dissolve: styles.transitionDissolve,
  slide: styles.transitionSlide,
  flip: styles.transitionFlip,
  zoom: styles.transitionZoom,
  wipe: styles.transitionWipe,
  none: styles.transitionNone,
};

const FLIP_DIRECTION_CLASSES: Record<WallboardResolvedFlipDirection, string> = {
  left_to_right: styles.flipLeftToRight,
  top_to_bottom: styles.flipTopToBottom,
  bottom_to_top: styles.flipBottomToTop,
};

export function WallboardPhotoCarousel({
  media,
  running,
  anchor,
  transition,
  transitionDurationMs,
  flipDirection,
  variant = 'wallboard',
  showCaption = false,
  className,
}: WallboardPhotoCarouselProps) {
  const photos = useMemo(() => displayablePhotos(media?.items ?? []), [media?.items]);
  const itemDurationSeconds = wallboardPhotoItemDurationSeconds(media?.item_duration_seconds);
  const normalizedTransition = normalizeWallboardNewsItemTransition(transition);
  const normalizedTransitionDurationMs = clampWallboardTransitionDurationMs(
    transitionDurationMs,
    DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION_DURATION_MS,
  );
  const normalizedFlipDirection = normalizeWallboardFlipDirection(flipDirection);
  const signature = `${anchorSignature(anchor)}:${media?.media_playlist_id ?? 'empty'}:${media?.media_playlist_version ?? 0}`;
  const signatureRef = useRef(signature);
  const rootRef = useRef<HTMLElement | null>(null);
  const [clock, setClock] = useState<WallboardPhotoCarouselClock>(() => (
    wallboardPhotoCarouselClock(anchor, running)
  ));
  const index = wallboardPhotoCarouselIndex(clock, photos.length, itemDurationSeconds);
  const photo = photos[index] ?? null;
  const [visual, setVisual] = useState<PhotoTransitionState>(() => ({
    signature,
    current: photo,
    previous: null,
    sequence: 0,
  }));

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

  useEffect(() => {
    setVisual((current) => {
      if (current.signature !== signature) {
        return { signature, current: photo, previous: null, sequence: 0 };
      }
      if (current.current?.id === photo?.id) {
        return !running && current.previous !== null
          ? { ...current, previous: null }
          : current;
      }
      return {
        signature,
        current: photo,
        previous: running
          && normalizedTransition !== 'none'
          && !wallboardGlobalPageTransitionIsActive(rootRef.current)
          ? current.current
          : null,
        sequence: current.sequence + 1,
      };
    });
  }, [normalizedTransition, photo, running, signature]);

  useEffect(() => {
    if (!running || visual.previous === null) return undefined;
    const sequence = visual.sequence;
    const timer = window.setTimeout(() => {
      setVisual((current) => current.sequence === sequence
        ? { ...current, previous: null }
        : current);
    }, normalizedTransitionDurationMs + 80);
    return () => window.clearTimeout(timer);
  }, [normalizedTransitionDurationMs, running, visual.previous, visual.sequence]);

  const rootClassName = [
    styles.root,
    variant === 'preview' ? styles.preview : styles.wallboard,
    !running ? styles.paused : '',
    className ?? '',
  ].filter(Boolean).join(' ');

  if (photo === null) {
    return (
      <div ref={(element) => { rootRef.current = element; }} className={`${rootClassName} ${styles.empty}`} role="status">
        <ImageOff size={variant === 'wallboard' ? 52 : 32} aria-hidden />
        <strong>Geen foto beschikbaar</strong>
        <span>Controleer de gekozen fotoplaylist in wallboardbeheer.</span>
      </div>
    );
  }

  const effectiveVisual = visual.signature === signature
    ? visual
    : { signature, current: photo, previous: null, sequence: 0 };
  const currentPhoto = effectiveVisual.current ?? photo;
  const currentIndex = Math.max(0, photos.findIndex((candidate) => candidate.id === currentPhoto.id));
  const paired = running
    && normalizedTransition !== 'none'
    && effectiveVisual.previous !== null;
  const resolvedFlipDirection = resolveWallboardFlipDirection(
    normalizedFlipDirection,
    `${currentPhoto.id}:${effectiveVisual.sequence}`,
  );
  const stageClassName = [
    styles.stage,
    paired ? styles.transitioning : styles.settled,
    TRANSITION_CLASSES[normalizedTransition],
    FLIP_DIRECTION_CLASSES[resolvedFlipDirection],
  ].filter(Boolean).join(' ');
  const stageStyle = {
    '--wallboard-photo-transition-duration': `${normalizedTransitionDurationMs}ms`,
  } as CSSProperties;

  return (
    <figure ref={(element) => { rootRef.current = element; }} className={rootClassName} aria-label={`Foto ${currentIndex + 1} van ${photos.length}`}>
      <div
        key={`photo-transition-${effectiveVisual.sequence}`}
        className={stageClassName}
        style={stageStyle}
        data-photo-transition={paired ? normalizedTransition : 'settled'}
      >
        {paired && effectiveVisual.previous !== null ? (
          <PhotoPane photo={effectiveVisual.previous} position="outgoing" />
        ) : null}
        <PhotoPane photo={currentPhoto} position={paired ? 'incoming' : 'settled'} />
      </div>
      {showCaption ? (
        <figcaption className={styles.caption}>
          <strong>{currentPhoto.name}</strong>
          <span>{currentIndex + 1} / {photos.length}</span>
        </figcaption>
      ) : null}
    </figure>
  );
}

function PhotoPane({
  photo,
  position,
}: {
  photo: DisplayablePhoto;
  position: 'incoming' | 'outgoing' | 'settled';
}) {
  const paneClassName = [
    styles.pane,
    position === 'incoming' ? styles.incoming : '',
    position === 'outgoing' ? styles.outgoing : '',
    position === 'settled' ? styles.paneSettled : '',
  ].filter(Boolean).join(' ');

  return (
    <div className={paneClassName} aria-hidden={position === 'outgoing' ? 'true' : undefined}>
      <img
        className={styles.backdrop}
        src={photo.imageUrl}
        alt=""
        aria-hidden="true"
        draggable={false}
      />
      <img
        className={styles.photo}
        src={photo.imageUrl}
        alt={position === 'outgoing' ? '' : photo.name}
        width={photo.width}
        height={photo.height}
        draggable={false}
      />
    </div>
  );
}

function displayablePhotos(items: readonly WallboardMediaPageStateItem[]): DisplayablePhoto[] {
  return items.flatMap((item) => {
    const imageUrl = wallboardMediaImageUrl(item.image_url);
    return imageUrl === null ? [] : [{
      ...item,
      imageUrl: wallboardAdminMediaVersionedUrl(imageUrl, item.media_asset_version),
    }];
  });
}

function anchorSignature(anchor: string | number | Date | null | undefined): string {
  if (anchor instanceof Date) return String(anchor.getTime());
  return anchor === null || anchor === undefined ? 'mount' : String(anchor);
}

function wallboardGlobalPageTransitionIsActive(root: HTMLElement | null): boolean {
  return root !== null && root.closest(
    '.wallboard-display__page-card-stage:not(.wallboard-display__page-card-stage--settled)',
  ) !== null;
}
