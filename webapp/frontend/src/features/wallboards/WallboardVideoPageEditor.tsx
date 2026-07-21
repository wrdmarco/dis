'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AlertTriangle, Clapperboard, Loader2, RefreshCw } from 'lucide-react';
import { ApiClientError } from '../../lib/apiClient';
import type { WallboardPage } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import type { WallboardMediaAsset } from './wallboardMedia';
import { normalizeWallboardMediaAssetId } from './wallboardPresentation';
import { WallboardVideoInspectionControl } from './WallboardVideoInspectionControl';
import { formatWallboardVideoDuration } from './wallboardVideoInspection';
import {
  isSelectableMp4,
  shouldPollWallboardVideoAssets,
  shouldRunWallboardVideoProcessingPoll,
  WALLBOARD_VIDEO_PROCESSING_POLL_INTERVAL_MILLISECONDS,
  wallboardVideoPageForAsset,
  wallboardVideoPageForSource,
  wallboardVideoProcessingProgress,
  type WallboardVideoSource,
} from './wallboardVideoPageEditorState';

interface WallboardVideoPageEditorProps {
  page: WallboardPage;
  onChange: (page: WallboardPage) => void;
}

export function WallboardVideoPageEditor({ page, onChange }: WallboardVideoPageEditorProps) {
  const { api } = useAuth();
  const selectedAssetId = normalizeWallboardMediaAssetId(page.options.media_asset_id);
  const [source, setSource] = useState<WallboardVideoSource>(selectedAssetId === '' ? 'external' : 'upload');
  const [assets, setAssets] = useState<WallboardMediaAsset[]>([]);
  const [loading, setLoading] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [pollGeneration, setPollGeneration] = useState(0);
  const requestSequenceRef = useRef(0);
  const foregroundAssetRequestsRef = useRef(0);
  const processingPollInFlightRef = useRef(false);
  const assetsRef = useRef<WallboardMediaAsset[]>([]);

  useEffect(() => {
    assetsRef.current = assets;
  }, [assets]);

  const fetchAssets = useCallback(async (status?: 'processing'): Promise<WallboardMediaAsset[]> => {
    const first = await api.get<WallboardMediaAsset[]>(wallboardVideoAssetPagePath(1, status));
    const lastPage = Math.max(1, Math.min(100, Number(first.meta?.last_page ?? 1)));
    const remaining = lastPage > 1
      ? await Promise.all(Array.from({ length: lastPage - 1 }, (_, index) => api.get<WallboardMediaAsset[]>(
        wallboardVideoAssetPagePath(index + 2, status),
      )))
      : [];
    const candidates = [first, ...remaining]
      .flatMap((response) => response.data)
      .filter((asset) => asset.kind === 'video' && asset.mime_type === 'video/mp4');
    const unique = new Map(candidates.map((asset) => [normalizeWallboardMediaAssetId(asset.id), asset]));
    return sortWallboardVideoAssets([...unique.values()]);
  }, [api]);

  const loadAssets = useCallback(async (silent = false): Promise<boolean | null> => {
    const requestId = requestSequenceRef.current + 1;
    requestSequenceRef.current = requestId;
    if (!silent) {
      foregroundAssetRequestsRef.current += 1;
      setLoading(true);
      setLoadError(null);
    }
    try {
      const nextAssets = await fetchAssets();
      if (requestId !== requestSequenceRef.current) return null;
      setAssets(nextAssets);
      setLoadError(null);
      return nextAssets.some((asset) => asset.status === 'processing');
    } catch (error) {
      if (requestId === requestSequenceRef.current && !silent) {
        setLoadError(error instanceof ApiClientError
          ? error.message
          : 'Geuploade MP4-video\'s konden niet worden geladen.');
      }
      return null;
    } finally {
      if (!silent) {
        foregroundAssetRequestsRef.current = Math.max(0, foregroundAssetRequestsRef.current - 1);
        if (foregroundAssetRequestsRef.current === 0) setLoading(false);
      }
    }
  }, [fetchAssets]);

  const pollProcessingAssets = useCallback(async (): Promise<boolean | null> => {
    const requestId = requestSequenceRef.current + 1;
    requestSequenceRef.current = requestId;
    try {
      const nextProcessingAssets = await fetchAssets('processing');
      if (requestId !== requestSequenceRef.current) return null;

      const currentProcessingIds = new Set(
        assetsRef.current
          .filter((asset) => asset.status === 'processing')
          .map((asset) => normalizeWallboardMediaAssetId(asset.id)),
      );
      const nextProcessingIds = new Set(
        nextProcessingAssets.map((asset) => normalizeWallboardMediaAssetId(asset.id)),
      );
      const hasTerminalTransition = [...currentProcessingIds].some((id) => !nextProcessingIds.has(id));
      if (hasTerminalTransition) return loadAssets(true);

      setAssets((current) => {
        const merged = new Map(
          current
            .filter((asset) => asset.status !== 'processing')
            .map((asset) => [normalizeWallboardMediaAssetId(asset.id), asset]),
        );
        for (const asset of nextProcessingAssets) {
          merged.set(normalizeWallboardMediaAssetId(asset.id), asset);
        }
        const next = sortWallboardVideoAssets([...merged.values()]);
        return wallboardVideoAssetListsMatch(current, next) ? current : next;
      });
      return nextProcessingAssets.length > 0;
    } catch {
      return null;
    }
  }, [fetchAssets, loadAssets]);

  useEffect(() => {
    if (source === 'upload') void loadAssets();
  }, [loadAssets, page.id, source]);

  useEffect(() => {
    setSource(selectedAssetId === '' ? 'external' : 'upload');
  }, [page.id, selectedAssetId]);

  const hasProcessingAssets = assets.some((asset) => asset.status === 'processing');

  useEffect(() => {
    if (source !== 'upload' || !shouldPollWallboardVideoAssets(hasProcessingAssets, 0)) return undefined;
    let stopped = false;
    let completedAttempts = 0;

    const stop = () => {
      stopped = true;
      window.clearInterval(interval);
    };
    const poll = async () => {
      if (stopped) return;
      if (!shouldPollWallboardVideoAssets(true, completedAttempts)) {
        stop();
        return;
      }
      if (!shouldRunWallboardVideoProcessingPoll(
        true,
        completedAttempts,
        document.visibilityState === 'visible',
        foregroundAssetRequestsRef.current,
        processingPollInFlightRef.current,
      )) return;

      completedAttempts += 1;
      processingPollInFlightRef.current = true;
      try {
        const stillProcessing = await pollProcessingAssets();
        if (stillProcessing === false) stop();
      } finally {
        processingPollInFlightRef.current = false;
      }
    };
    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') void poll();
    };

    const interval = window.setInterval(
      () => void poll(),
      WALLBOARD_VIDEO_PROCESSING_POLL_INTERVAL_MILLISECONDS,
    );
    document.addEventListener('visibilitychange', handleVisibilityChange);
    return () => {
      stop();
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [hasProcessingAssets, pollGeneration, pollProcessingAssets, source]);

  const readyAssets = useMemo(() => assets.filter(isSelectableMp4), [assets]);
  const processingAssets = useMemo(
    () => assets.filter((asset) => asset.status === 'processing'),
    [assets],
  );
  const failedAssets = useMemo(
    () => assets.filter((asset) => asset.status === 'failed'),
    [assets],
  );

  const selectedAsset = useMemo(
    () => readyAssets.find((asset) => normalizeWallboardMediaAssetId(asset.id) === selectedAssetId) ?? null,
    [readyAssets, selectedAssetId],
  );
  const selectedUnavailableAsset = useMemo(
    () => assets.find((asset) => normalizeWallboardMediaAssetId(asset.id) === selectedAssetId) ?? null,
    [assets, selectedAssetId],
  );

  function changeSource(nextSource: WallboardVideoSource) {
    if (source === nextSource) return;
    setSource(nextSource);
    onChange(wallboardVideoPageForSource(page, nextSource));
  }

  function selectAsset(assetId: string) {
    const normalizedId = normalizeWallboardMediaAssetId(assetId);
    const asset = readyAssets.find((candidate) => normalizeWallboardMediaAssetId(candidate.id) === normalizedId);
    onChange(asset === undefined ? { ...page, options: {} } : wallboardVideoPageForAsset(page, asset));
  }

  function refreshAssets() {
    setPollGeneration((current) => current + 1);
    void loadAssets();
  }

  return (
    <fieldset className="wallboard-video-editor">
      <legend>Videobron</legend>
      <div className="segmented-control" role="group" aria-label="Videobron kiezen">
        <button
          type="button"
          className={`segmented-control__item${source === 'upload' ? ' segmented-control__item--active' : ''}`}
          aria-pressed={source === 'upload'}
          onClick={() => changeSource('upload')}
        >
          Geuploade MP4
        </button>
        <button
          type="button"
          className={`segmented-control__item${source === 'external' ? ' segmented-control__item--active' : ''}`}
          aria-pressed={source === 'external'}
          onClick={() => changeSource('external')}
        >
          YouTube of Vimeo
        </button>
      </div>

      {source === 'upload' ? (
        <div className="wallboard-video-editor__upload" aria-busy={loading}>
          <div className="wallboard-video-editor__picker">
            <label>
              <span>MP4 uit mediabeheer</span>
              <select
                value={selectedAssetId}
                onChange={(event) => selectAsset(event.target.value)}
                disabled={loading && assets.length === 0}
                required
              >
                <option value="">Selecteer een verwerkte MP4-video</option>
                {readyAssets.map((asset) => (
                  <option key={asset.id} value={normalizeWallboardMediaAssetId(asset.id)}>
                    {asset.display_name} - {formatWallboardVideoDuration(asset.duration_seconds ?? 0)}
                  </option>
                ))}
              </select>
            </label>
            <button
              type="button"
              className="secondary-button wallboard-video-editor__refresh"
              onClick={refreshAssets}
              disabled={loading}
            >
              <RefreshCw className={loading ? 'spin' : undefined} size={15} aria-hidden />
              {loading ? 'Vernieuwen...' : 'Vernieuwen'}
            </button>
          </div>
          {loading ? <p role="status"><Loader2 className="spin" size={16} aria-hidden /> Video&apos;s laden...</p> : null}
          {loadError !== null ? (
            <p className="form-error" role="alert">{loadError} Gebruik Vernieuwen om het opnieuw te proberen.</p>
          ) : null}
          {!loading && loadError === null && assets.length === 0 ? (
            <p className="form-note">Upload eerst een MP4 via Mediabeheer. Video&apos;s blijven gescheiden van fotoplaylists.</p>
          ) : null}
          {processingAssets.length > 0 ? (
            <section className="wallboard-video-editor__statuses" aria-label="Video's in verwerking">
              <header>
                <Loader2 className="spin" size={17} aria-hidden />
                <span>
                  <strong>Verwerking op de server</strong>
                  <small>De lijst wordt automatisch bijgewerkt. Zodra een video klaar is, kun je hem hierboven kiezen.</small>
                </span>
              </header>
              <ul>
                {processingAssets.map((asset) => {
                  const progress = wallboardVideoProcessingProgress(asset);
                  return (
                    <li key={asset.id}>
                      <span>
                        <strong>{asset.display_name}</strong>
                        <small>{progress === null ? 'Voortgang wordt voorbereid' : `${progress}% verwerkt`}</small>
                      </span>
                      <progress
                        max={100}
                        value={progress ?? undefined}
                        aria-label={`Verwerkingsvoortgang van ${asset.display_name}`}
                      />
                      <b>{progress === null ? 'Bezig' : `${progress}%`}</b>
                    </li>
                  );
                })}
              </ul>
            </section>
          ) : null}
          {failedAssets.length > 0 ? (
            <section className="wallboard-video-editor__statuses wallboard-video-editor__statuses--failed" aria-label="Mislukte videoverwerking">
              <header>
                <AlertTriangle size={17} aria-hidden />
                <span>
                  <strong>Verwerking mislukt</strong>
                  <small>Deze video&apos;s zijn niet kiesbaar. Upload het bronbestand opnieuw of verwijder deze versie in Mediabeheer.</small>
                </span>
              </header>
              <ul>
                {failedAssets.map((asset) => (
                  <li key={asset.id}>
                    <span><strong>{asset.display_name}</strong><small>Niet beschikbaar voor het wallboard</small></span>
                    <b>Mislukt</b>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}
          {selectedAsset !== null ? (
            <div className="wallboard-video-editor__selection">
              <Clapperboard size={22} aria-hidden />
              <span>
                <strong>{selectedAsset.display_name}</strong>
                <small>
                  MP4 - {formatWallboardVideoDuration(selectedAsset.duration_seconds ?? 0)} - server-side gecontroleerd
                </small>
              </span>
            </div>
          ) : null}
          {selectedAssetId !== '' && !loading && selectedAsset === null ? (
            <p
              className={selectedUnavailableAsset?.status === 'processing' ? 'form-note' : 'form-error'}
              role={selectedUnavailableAsset?.status === 'processing' ? 'status' : 'alert'}
            >
              {selectedUnavailableAsset?.status === 'processing'
                ? 'De geselecteerde MP4 wordt nog verwerkt en wordt daarna automatisch weer kiesbaar.'
                : 'De geselecteerde MP4 is niet meer beschikbaar. Kies opnieuw.'}
            </p>
          ) : null}
        </div>
      ) : (
        <>
          <label>
            <span>YouTube-, Shorts- of Vimeo-link</span>
            <input
              type="url"
              value={page.options.url ?? ''}
              onChange={(event) => onChange({ ...page, options: { url: event.target.value } })}
              maxLength={2048}
              pattern="https://.+"
              placeholder="https://www.youtube.com/watch?v=..."
              inputMode="url"
              required
            />
            <small>Alleen openbare HTTPS-video&apos;s. De video start automatisch, gedempt en met bediening en branding zoveel mogelijk verborgen.</small>
          </label>
          <WallboardVideoInspectionControl
            url={page.options.url ?? ''}
            onInspectionStart={() => onChange({
              ...page,
              options: { url: page.options.url ?? '' },
            })}
            onVerified={(result) => onChange({
              ...page,
              duration_seconds: result.recommendedDisplayDurationSeconds,
              options: {
                url: page.options.url ?? '',
                video_duration_seconds: result.durationSeconds,
              },
            })}
          />
        </>
      )}
    </fieldset>
  );
}

function wallboardVideoAssetPagePath(page: number, status?: 'processing'): string {
  const parameters = new URLSearchParams({
    kind: 'video',
    per_page: '100',
    page: String(page),
  });
  if (status !== undefined) parameters.set('status', status);
  return `/admin/wallboard-media/assets?${parameters.toString()}`;
}

function sortWallboardVideoAssets(assets: WallboardMediaAsset[]): WallboardMediaAsset[] {
  return assets.sort((left, right) => left.display_name.localeCompare(
    right.display_name,
    'nl',
    { sensitivity: 'base' },
  ));
}

function wallboardVideoAssetListsMatch(
  left: readonly WallboardMediaAsset[],
  right: readonly WallboardMediaAsset[],
): boolean {
  return left.length === right.length && left.every((asset, index) => {
    const candidate = right[index];
    return candidate !== undefined
      && asset.id === candidate.id
      && asset.version === candidate.version
      && asset.status === candidate.status
      && asset.processing_progress === candidate.processing_progress;
  });
}
