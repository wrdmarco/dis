'use client';

import { Image as ImageIcon, Loader2, RefreshCw, TimerReset } from 'lucide-react';
import { useApiResource } from '../../lib/useApiResource';
import {
  type WallboardMediaPlaylist,
  type WallboardPhotoPageOptions,
  WALLBOARD_PHOTO_MAX_ITEM_DURATION_SECONDS,
  WALLBOARD_PHOTO_MAX_PAGE_DURATION_SECONDS,
  WALLBOARD_PHOTO_MIN_ITEM_DURATION_SECONDS,
  wallboardPhotoItemDurationSeconds,
  wallboardPhotoPageDurationSeconds,
  wallboardPhotoPageIsWithinDurationLimit,
} from './wallboardMedia';
import { SecondsStepper } from './SecondsStepper';
import styles from './WallboardPhotoPageEditor.module.css';

export interface WallboardPhotoPageSelection {
  options: Required<Pick<WallboardPhotoPageOptions, 'media_playlist_id' | 'item_duration_seconds'>>;
  itemCount: number;
  pageDurationSeconds: number;
  valid: boolean;
}

export interface WallboardPhotoPageEditorProps {
  idPrefix: string;
  value: WallboardPhotoPageOptions;
  onChange: (selection: WallboardPhotoPageSelection) => void;
  disabled?: boolean;
}

export function WallboardPhotoPageEditor({
  idPrefix,
  value,
  onChange,
  disabled = false,
}: WallboardPhotoPageEditorProps) {
  const resource = useApiResource<WallboardMediaPlaylist[]>('/admin/wallboard-media/playlists');
  const playlists = resource.data ?? [];
  const selectedPlaylist = playlists.find((playlist) => playlist.id === value.media_playlist_id) ?? null;
  const itemDurationSeconds = wallboardPhotoItemDurationSeconds(value.item_duration_seconds);
  const pageDurationSeconds = wallboardPhotoPageDurationSeconds(
    selectedPlaylist?.item_count ?? 0,
    itemDurationSeconds,
  );
  const valid = selectedPlaylist !== null
    && wallboardPhotoPageIsWithinDurationLimit(selectedPlaylist.item_count, itemDurationSeconds);

  function emit(playlist: WallboardMediaPlaylist | null, duration: number) {
    const safeDuration = wallboardPhotoItemDurationSeconds(duration);
    const itemCount = playlist?.item_count ?? 0;
    onChange({
      options: {
        media_playlist_id: playlist?.id ?? '',
        item_duration_seconds: safeDuration,
      },
      itemCount,
      pageDurationSeconds: wallboardPhotoPageDurationSeconds(itemCount, safeDuration),
      valid: playlist !== null && wallboardPhotoPageIsWithinDurationLimit(itemCount, safeDuration),
    });
  }

  return (
    <section className={styles.root} aria-labelledby={`${idPrefix}-photo-heading`}>
      <header className={styles.header}>
        <span className={styles.icon}><ImageIcon size={18} aria-hidden /></span>
        <div>
          <h4 id={`${idPrefix}-photo-heading`}>Fotocarrousel</h4>
          <p>Kies een fotoplaylist; de totale paginatijd volgt automatisch uit het aantal foto&apos;s.</p>
        </div>
        <button
          type="button"
          className={styles.refresh}
          onClick={() => void resource.reload()}
          disabled={disabled || resource.loading}
          aria-label="Fotoplaylists vernieuwen"
        >
          <RefreshCw size={16} aria-hidden />
        </button>
      </header>

      {resource.loading ? (
        <div className={styles.status} role="status"><Loader2 className={styles.spinner} size={18} aria-hidden /> Fotoplaylists laden...</div>
      ) : resource.error !== null ? (
        <div className={`${styles.status} ${styles.error}`} role="alert">{resource.error}</div>
      ) : playlists.length === 0 ? (
        <div className={styles.status} role="status">
          Maak eerst onder <strong>Media</strong> een fotoplaylist met minimaal één afbeelding.
        </div>
      ) : (
        <div className={styles.fields}>
          <label>
            <span>Fotoplaylist</span>
            <select
              id={`${idPrefix}-media-playlist`}
              value={value.media_playlist_id ?? ''}
              disabled={disabled}
              onChange={(event) => {
                const playlist = playlists.find((candidate) => candidate.id === event.target.value) ?? null;
                emit(playlist, itemDurationSeconds);
              }}
              required
            >
              <option value="">Selecteer een fotoplaylist</option>
              {playlists.map((playlist) => (
                <option key={playlist.id} value={playlist.id}>
                  {playlist.name} ({playlist.item_count} foto{playlist.item_count === 1 ? '' : "'s"})
                </option>
              ))}
            </select>
          </label>

          <SecondsStepper
            id={`${idPrefix}-item-duration`}
            label="Tijd per foto"
            min={WALLBOARD_PHOTO_MIN_ITEM_DURATION_SECONDS}
            max={WALLBOARD_PHOTO_MAX_ITEM_DURATION_SECONDS}
            value={itemDurationSeconds}
            disabled={disabled}
            onChange={(durationSeconds) => emit(selectedPlaylist, durationSeconds)}
          />
        </div>
      )}

      <div className={valid ? styles.summary : `${styles.summary} ${styles.summaryInvalid}`} aria-live="polite">
        <TimerReset size={18} aria-hidden />
        <div>
          <strong>
            {selectedPlaylist === null
              ? 'Nog geen fotoplaylist gekozen'
              : `${selectedPlaylist.item_count} foto${selectedPlaylist.item_count === 1 ? '' : "'s"} - ${pageDurationSeconds} sec. totaal`}
          </strong>
          <span>
            {selectedPlaylist !== null && pageDurationSeconds > WALLBOARD_PHOTO_MAX_PAGE_DURATION_SECONDS
              ? `Verlaag de tijd per foto; een carrousel mag maximaal ${WALLBOARD_PHOTO_MAX_PAGE_DURATION_SECONDS} seconden duren.`
              : "De playlistpagina wisselt pas nadat alle foto's eenmaal zijn getoond."}
          </span>
        </div>
      </div>
    </section>
  );
}
