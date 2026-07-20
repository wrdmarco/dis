'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Clapperboard, Loader2, RefreshCw } from 'lucide-react';
import { ApiClientError } from '../../lib/apiClient';
import type { WallboardPage } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import type { WallboardMediaAsset } from './wallboardMedia';
import { normalizeWallboardMediaAssetId } from './wallboardPresentation';
import { WallboardVideoInspectionControl } from './WallboardVideoInspectionControl';
import { formatWallboardVideoDuration } from './wallboardVideoInspection';
import {
  isSelectableMp4,
  wallboardVideoPageForAsset,
  wallboardVideoPageForSource,
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

  const loadAssets = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    try {
      const path = '/admin/wallboard-media/assets?kind=video&status=ready&per_page=100&page=1';
      const first = await api.get<WallboardMediaAsset[]>(path);
      const lastPage = Math.max(1, Math.min(100, Number(first.meta?.last_page ?? 1)));
      const remaining = lastPage > 1
        ? await Promise.all(Array.from({ length: lastPage - 1 }, (_, index) => api.get<WallboardMediaAsset[]>(
          `/admin/wallboard-media/assets?kind=video&status=ready&per_page=100&page=${index + 2}`,
        )))
        : [];
      const candidates = [first, ...remaining]
        .flatMap((response) => response.data)
        .filter(isSelectableMp4);
      const unique = new Map(candidates.map((asset) => [normalizeWallboardMediaAssetId(asset.id), asset]));
      setAssets([...unique.values()].sort((left, right) => left.display_name.localeCompare(
        right.display_name,
        'nl',
        { sensitivity: 'base' },
      )));
    } catch (error) {
      setLoadError(error instanceof ApiClientError
        ? error.message
        : 'Geuploade MP4-video\'s konden niet worden geladen.');
    } finally {
      setLoading(false);
    }
  }, [api]);

  useEffect(() => {
    if (source === 'upload') void loadAssets();
  }, [loadAssets, source]);

  useEffect(() => {
    setSource(selectedAssetId === '' ? 'external' : 'upload');
  }, [page.id, selectedAssetId]);

  const selectedAsset = useMemo(
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
    const asset = assets.find((candidate) => normalizeWallboardMediaAssetId(candidate.id) === normalizedId);
    onChange(asset === undefined ? { ...page, options: {} } : wallboardVideoPageForAsset(page, asset));
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
        <div className="wallboard-video-editor__upload">
          <label>
            <span>MP4 uit mediabeheer</span>
            <select
              value={selectedAssetId}
              onChange={(event) => selectAsset(event.target.value)}
              disabled={loading}
              required
            >
              <option value="">Selecteer een gecontroleerde MP4-video</option>
              {assets.map((asset) => (
                <option key={asset.id} value={normalizeWallboardMediaAssetId(asset.id)}>
                  {asset.display_name} - {formatWallboardVideoDuration(asset.duration_seconds ?? 0)}
                </option>
              ))}
            </select>
          </label>
          {loading ? <p role="status"><Loader2 className="spin" size={16} aria-hidden /> Video&apos;s laden...</p> : null}
          {loadError !== null ? (
            <p className="form-error" role="alert">
              {loadError}{' '}
              <button type="button" className="secondary-button" onClick={() => void loadAssets()}>
                <RefreshCw size={14} aria-hidden /> Opnieuw laden
              </button>
            </p>
          ) : null}
          {!loading && loadError === null && assets.length === 0 ? (
            <p className="form-note">Upload eerst een MP4 via Mediabeheer. Video&apos;s blijven gescheiden van fotoplaylists.</p>
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
            <p className="form-error" role="alert">De geselecteerde MP4 is niet meer beschikbaar. Kies opnieuw.</p>
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
