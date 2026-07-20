'use client';

import {
  type CSSProperties,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import {
  AlertTriangle,
  Clock3,
  Eye,
  Loader2,
  Pause,
  Play,
  RefreshCw,
  RotateCcw,
  RotateCw,
  SkipForward,
  X,
} from 'lucide-react';
import type { WallboardConfiguration, WallboardState } from '../../types/api';
import {
  buildWallboardMapPresentation,
  clampRefreshSeconds,
  wallboardConfigurationCopy,
  wallboardEffectivePageDuration,
  wallboardEffectivePageTransition,
  wallboardPageTypeLabel,
} from './wallboardPresentation';
import {
  formatWallboardClock,
  formatWallboardDate,
  normalizeWallboardState,
  WallboardPlaylistPageFrame,
  WallboardTicker,
} from './WallboardDisplayPage';
import {
  advanceWallboardPlaylistPreviewRotation,
  createWallboardPlaylistPreviewRotation,
  pauseWallboardPlaylistPreviewRotation,
  resumeWallboardPlaylistPreviewRotation,
  selectWallboardPlaylistPreviewPage,
  wallboardPlaylistPreviewRemainingMilliseconds,
} from './wallboardPlaylistPreviewRotation';

const PREVIEW_WIDTH = 1920;
const PREVIEW_HEIGHT = 1080;

interface WallboardPlaylistPreviewProps {
  playlistName: string;
  configuration: WallboardConfiguration;
  loadState: (configuration: WallboardConfiguration) => Promise<WallboardState>;
  onClose: () => void;
}

export function WallboardPlaylistPreview({
  playlistName,
  configuration,
  loadState,
  onClose,
}: WallboardPlaylistPreviewProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const requestSequenceRef = useRef(0);
  const snapshot = useMemo(() => wallboardConfigurationCopy(configuration), [configuration]);
  const [state, setState] = useState<WallboardState | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [clock, setClock] = useState(() => Date.now());
  const [playing, setPlaying] = useState(true);
  const [restartGeneration, setRestartGeneration] = useState(0);
  const [documentVisible, setDocumentVisible] = useState(() => (
    typeof document === 'undefined' || !document.hidden
  ));

  const refreshPreview = useCallback(async (initial = false) => {
    const requestSequence = ++requestSequenceRef.current;
    if (initial) setLoading(true);
    else setRefreshing(true);
    try {
      const rawState = await loadState(snapshot);
      const normalizedState = normalizeWallboardState(rawState);
      const nextState = {
        ...normalizedState,
        wallboard: {
          ...normalizedState.wallboard,
          configuration: preserveWallboardPlaylistPreviewAdminMedia(
            rawState.wallboard.configuration,
            normalizedState.wallboard.configuration,
          ),
        },
      };
      if (requestSequence !== requestSequenceRef.current) return;
      setState(nextState);
      setLoadError(null);
      setClock(Date.now());
    } catch (error) {
      if (requestSequence !== requestSequenceRef.current) return;
      setLoadError(previewErrorMessage(error));
    } finally {
      if (requestSequence === requestSequenceRef.current) {
        setLoading(false);
        setRefreshing(false);
      }
    }
  }, [loadState, snapshot]);

  useEffect(() => {
    const dialog = dialogRef.current;
    const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    if (dialog !== null && !dialog.open) dialog.showModal();

    return () => {
      requestSequenceRef.current += 1;
      if (dialog?.open) dialog.close();
      previousFocus?.focus();
    };
  }, []);

  useEffect(() => {
    void refreshPreview(true);
    const interval = window.setInterval(
      () => void refreshPreview(false),
      clampRefreshSeconds(snapshot.refresh_seconds) * 1000,
    );
    return () => window.clearInterval(interval);
  }, [refreshPreview, snapshot.refresh_seconds]);

  useEffect(() => {
    const interval = window.setInterval(() => setClock(Date.now()), 1000);
    return () => window.clearInterval(interval);
  }, []);

  useEffect(() => {
    const updateVisibility = () => setDocumentVisible(!document.hidden);
    document.addEventListener('visibilitychange', updateVisibility);
    return () => document.removeEventListener('visibilitychange', updateVisibility);
  }, []);

  const runtimeConfiguration = state?.wallboard.configuration ?? snapshot;
  const pages = runtimeConfiguration.pages;
  const durationSignature = pages
    .map((page) => `${page.id}:${wallboardEffectivePageDuration(page)}`)
    .join('|');
  const pageDurationsMilliseconds = pages.map((page) => wallboardEffectivePageDuration(page) * 1000);
  const pageDurationsRef = useRef(pageDurationsMilliseconds);
  pageDurationsRef.current = pageDurationsMilliseconds;
  const contentRunning = playing && documentVisible && state !== null;
  const rotationEnabled = runtimeConfiguration.rotation_enabled && pages.length > 1;
  const rotationRunning = contentRunning && rotationEnabled;
  const rotationRunningRef = useRef(rotationRunning);
  rotationRunningRef.current = rotationRunning;
  const [rotation, setRotation] = useState(() => createWallboardPlaylistPreviewRotation(
    pageDurationsMilliseconds,
    false,
  ));

  useEffect(() => {
    setRotation(createWallboardPlaylistPreviewRotation(
      pageDurationsRef.current,
      rotationRunningRef.current,
    ));
  }, [durationSignature]);

  useEffect(() => {
    setRotation((current) => rotationRunning
      ? resumeWallboardPlaylistPreviewRotation(current)
      : pauseWallboardPlaylistPreviewRotation(current));
  }, [rotationRunning]);

  useEffect(() => {
    if (!rotationRunning || rotation.deadlineEpochMilliseconds === null) return undefined;
    const delay = wallboardPlaylistPreviewRemainingMilliseconds(rotation);
    const timer = window.setTimeout(() => {
      setRotation((current) => advanceWallboardPlaylistPreviewRotation(
        current,
        pageDurationsRef.current,
        true,
      ));
    }, Math.max(1, delay));
    return () => window.clearTimeout(timer);
  }, [rotation, rotationRunning]);

  const safePageIndex = Math.min(Math.max(0, rotation.pageIndex), Math.max(0, pages.length - 1));
  const currentPage = pages[safePageIndex] ?? pages[0];
  const pageDurationMilliseconds = pageDurationsMilliseconds[safePageIndex] ?? 1;
  const remainingMilliseconds = wallboardPlaylistPreviewRemainingMilliseconds(rotation, clock);
  const remainingSeconds = Math.max(0, Math.ceil(remainingMilliseconds / 1000));
  const progress = Math.min(1, Math.max(0, 1 - (remainingMilliseconds / pageDurationMilliseconds)));
  const totalDuration = pages.reduce((total, page) => total + wallboardEffectivePageDuration(page), 0);
  const previousContentRunningRef = useRef(contentRunning);

  useEffect(() => {
    const wasRunning = previousContentRunningRef.current;
    previousContentRunningRef.current = contentRunning;
    if (!wasRunning || contentRunning || currentPage.type !== 'video') return;
    setRotation((current) => selectWallboardPlaylistPreviewPage(
      current,
      safePageIndex,
      pageDurationsRef.current,
      false,
    ));
  }, [contentRunning, currentPage.type, safePageIndex]);

  const selectPage = useCallback((pageIndex: number) => {
    setRotation((current) => selectWallboardPlaylistPreviewPage(
      current,
      pageIndex,
      pageDurationsRef.current,
      rotationRunning,
    ));
  }, [rotationRunning]);

  const nextPage = useCallback(() => {
    setRotation((current) => advanceWallboardPlaylistPreviewRotation(
      current,
      pageDurationsRef.current,
      rotationRunning,
    ));
  }, [rotationRunning]);

  const restartPreview = useCallback(() => {
    setRotation((current) => selectWallboardPlaylistPreviewPage(
      current,
      0,
      pageDurationsRef.current,
      rotationRunning,
    ));
    setRestartGeneration((current) => current + 1);
  }, [rotationRunning]);

  return (
    <dialog
      ref={dialogRef}
      className="wallboard-playlist-preview"
      aria-labelledby="wallboard-playlist-preview-title"
      onCancel={(event) => {
        event.preventDefault();
        dialogRef.current?.close();
      }}
      onClose={onClose}
    >
      <header className="wallboard-playlist-preview__header">
        <div>
          <span className="eyebrow"><i className="wallboard-playlist-preview__live-dot" aria-hidden /> Live conceptpreview</span>
          <h2 id="wallboard-playlist-preview-title">{playlistName || 'Naamloze playlist'}</h2>
          <p>Actuele inhoud, paginatijden en media zoals ze op het wallboard worden afgespeeld. Er wordt niets gepubliceerd.</p>
        </div>
        <button className="icon-button" type="button" onClick={() => dialogRef.current?.close()} aria-label="Voorbeeld sluiten">
          <X size={20} aria-hidden />
        </button>
      </header>

      <div className="wallboard-playlist-preview__body">
        <section className="wallboard-playlist-preview__screen" aria-label="Live wallboardweergave">
          <div className="wallboard-playlist-preview__screen-bar">
            <span className="wallboard-playlist-preview__runtime-status">
              <Eye size={16} aria-hidden />
              {contentRunning ? 'Live' : 'Gepauzeerd'}
            </span>
            <div className="wallboard-playlist-preview__playback-controls" aria-label="Preview bedienen">
              <button type="button" onClick={() => setPlaying((current) => !current)} aria-label={playing ? 'Preview pauzeren' : 'Preview afspelen'}>
                {playing ? <Pause size={16} aria-hidden /> : <Play size={16} aria-hidden />}
                <span>{playing ? 'Pauze' : 'Afspelen'}</span>
              </button>
              <button type="button" onClick={restartPreview} aria-label="Preview opnieuw starten">
                <RotateCcw size={16} aria-hidden />
                <span>Herstart</span>
              </button>
              <button type="button" onClick={nextPage} disabled={pages.length <= 1} aria-label="Volgende pagina tonen">
                <SkipForward size={16} aria-hidden />
                <span>Volgende</span>
              </button>
              <button type="button" onClick={() => void refreshPreview(false)} disabled={refreshing} aria-label="Actuele inhoud vernieuwen">
                <RefreshCw className={refreshing ? 'spin' : undefined} size={16} aria-hidden />
                <span>Vernieuw</span>
              </button>
            </div>
            <span className="wallboard-playlist-preview__countdown">
              <Clock3 size={16} aria-hidden /> {rotationEnabled ? `${remainingSeconds} sec.` : 'Vaste pagina'}
            </span>
          </div>

          <div className="wallboard-playlist-preview__progress" aria-hidden>
            <i key={`preview-progress-${rotation.sequence}`} style={{ transform: `scaleX(${progress})` }} />
          </div>

          {loading && state === null ? (
            <div className="wallboard-playlist-preview__loading" role="status">
              <Loader2 className="spin" size={28} aria-hidden />
              <strong>Live inhoud laden</strong>
              <span>Nieuws, vliegweer, agenda en operationele gegevens worden opgehaald.</span>
            </div>
          ) : state === null ? (
            <div className="wallboard-playlist-preview__loading wallboard-playlist-preview__loading--error" role="alert">
              <AlertTriangle size={28} aria-hidden />
              <strong>Live preview kon niet worden geladen</strong>
              <span>{loadError}</span>
              <button className="secondary-button" type="button" onClick={() => void refreshPreview(true)}>Opnieuw proberen</button>
            </div>
          ) : (
            <WallboardPreviewStage
              state={state}
              pageIndex={safePageIndex}
              running={contentRunning}
              now={clock}
              pageDeadlineAt={new Date(
                rotation.pageStartedAtEpochMilliseconds + pageDurationMilliseconds,
              ).toISOString()}
              remainingSeconds={remainingSeconds}
              restartGeneration={restartGeneration}
            />
          )}

          {loadError !== null && state !== null ? (
            <div className="wallboard-playlist-preview__stale-warning" role="status">
              <AlertTriangle size={15} aria-hidden /> Laatste geladen inhoud blijft spelen; vernieuwen lukt nu niet: {loadError}
            </div>
          ) : null}
        </section>

        <aside className="wallboard-playlist-preview__details">
          <section>
            <div className="wallboard-playlist-preview__round-heading">
              <div>
                <span className="eyebrow">Afspeelronde</span>
                <h3>Pagina&apos;s</h3>
              </div>
              <span>{totalDuration} sec.</span>
            </div>
            <ol className="wallboard-playlist-preview__pages">
              {pages.map((page, index) => (
                <li key={page.id}>
                  <button
                    type="button"
                    className={index === safePageIndex ? 'wallboard-playlist-preview__page wallboard-playlist-preview__page--active' : 'wallboard-playlist-preview__page'}
                    onClick={() => selectPage(index)}
                    aria-current={index === safePageIndex ? 'true' : undefined}
                  >
                    <span>{index + 1}</span>
                    <span><strong>{page.name}</strong><small>{wallboardPageTypeLabel(page.type)}</small></span>
                    <time>{wallboardEffectivePageDuration(page)} sec.</time>
                  </button>
                </li>
              ))}
            </ol>
          </section>

          <section className="wallboard-playlist-preview__facts">
            <h3>Deze preview gebruikt</h3>
            <dl>
              <div><dt>Rotatie</dt><dd>{runtimeConfiguration.rotation_enabled ? 'Automatisch' : 'Uitgeschakeld'}</dd></div>
              <div><dt>Overgang</dt><dd>{wallboardPageTypeLabel(currentPage.type)} · {wallboardEffectivePageTransition(runtimeConfiguration, currentPage).transition}</dd></div>
              <div><dt>Onderticker</dt><dd>{runtimeConfiguration.ticker.enabled ? `${state?.ticker.items.length ?? 0} actueel` : 'Uitgeschakeld'}</dd></div>
              <div><dt>Gegevens</dt><dd>{state === null ? 'worden geladen' : formatPreviewUpdatedAt(state.generated_at)}</dd></div>
            </dl>
          </section>
        </aside>
      </div>
    </dialog>
  );
}

function WallboardPreviewStage({
  state,
  pageIndex,
  running,
  now,
  pageDeadlineAt,
  remainingSeconds,
  restartGeneration,
}: {
  state: WallboardState;
  pageIndex: number;
  running: boolean;
  now: number;
  pageDeadlineAt: string | null;
  remainingSeconds: number;
  restartGeneration: number;
}) {
  const stageRef = useRef<HTMLDivElement>(null);
  const [scale, setScale] = useState(1);
  const configuration = state.wallboard.configuration;
  const page = configuration.pages[pageIndex] ?? configuration.pages[0];
  const presentation = useMemo(() => buildWallboardMapPresentation(state, true), [state]);
  const transition = wallboardEffectivePageTransition(configuration, page);

  useEffect(() => {
    const stage = stageRef.current;
    if (stage === null) return undefined;
    const resize = () => setScale(Math.min(
      stage.clientWidth / PREVIEW_WIDTH,
      stage.clientHeight / PREVIEW_HEIGHT,
    ));
    resize();
    const observer = new ResizeObserver(resize);
    observer.observe(stage);
    return () => observer.disconnect();
  }, []);

  const currentTitle = page.type === 'message'
    ? 'Mededeling'
    : page.type === 'safety_notice'
      ? 'Veiligheidsbericht'
      : page.name;
  const showTicker = configuration.ticker.enabled && state.ticker.items.length > 0;
  const stageStyle = { '--wallboard-preview-scale': scale } as CSSProperties;

  return (
    <div ref={stageRef} className="wallboard-playlist-preview__stage" style={stageStyle}>
      <div className="wallboard-playlist-preview__viewport" inert aria-hidden="true">
        <div
          className={`wallboard-display wallboard-display--${configuration.theme} wallboard-display--profile-1080p wallboard-display--preview`}
          data-display-profile="1080p"
        >
          <header className="wallboard-display__header">
            <div>
              <span className="wallboard-display__titles"><h1>{currentTitle}</h1></span>
              <span className="wallboard-display__mode"><RotateCw size={14} aria-hidden /> Live preview</span>
            </div>
            <div className="wallboard-display__controls">
              <time className="wallboard-display__clock" dateTime={new Date(now).toISOString()}>
                <span>{formatWallboardClock(now)}</span>
                <small>{formatWallboardDate(now)}</small>
              </time>
            </div>
          </header>

          <WallboardPlaylistPageFrame
            key={`preview-frame-${restartGeneration}`}
            active
            adminPreview
            running={running}
            page={page}
            pages={configuration.pages}
            state={state}
            presentation={presentation}
            hasLiveFeed
            now={now}
            pageDeadlineAt={pageDeadlineAt}
            transition={transition.transition}
            transitionDurationMs={transition.durationMs}
            flipDirection={transition.flipDirection}
          />

          <footer className="wallboard-display__footer">
            <span>
              Pagina {pageIndex + 1} van {configuration.pages.length} · {configuration.rotation_enabled && configuration.pages.length > 1
                ? `volgende wissel over ${remainingSeconds} sec.`
                : 'rotatie uitgeschakeld'}
            </span>
          </footer>
          {showTicker ? <WallboardTicker key={`preview-ticker-${restartGeneration}`} items={state.ticker.items} running={running} /> : null}
        </div>
      </div>
    </div>
  );
}

function formatPreviewUpdatedAt(value: string): string {
  const timestamp = Date.parse(value);
  if (!Number.isFinite(timestamp)) return 'zojuist geladen';
  return `bijgewerkt om ${new Intl.DateTimeFormat('nl-NL', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    timeZone: 'Europe/Amsterdam',
  }).format(new Date(timestamp))}`;
}

function previewErrorMessage(error: unknown): string {
  return error instanceof Error && error.message.trim() !== ''
    ? error.message
    : 'De actuele inhoud is tijdelijk niet beschikbaar.';
}

export function preserveWallboardPlaylistPreviewAdminMedia(
  source: WallboardConfiguration,
  normalized: WallboardConfiguration,
): WallboardConfiguration {
  const adminVideos = new Map<string, { url: string; mediaAssetVersion?: number }>();
  for (const page of source.pages) {
    if (page.type !== 'video' || typeof page.options.url !== 'string') continue;
    const match = /^\/api\/admin\/wallboard-media\/assets\/([0-9A-HJKMNP-TV-Z]{26})\/content$/i.exec(page.options.url.trim());
    const configuredId = typeof page.options.media_asset_id === 'string'
      ? page.options.media_asset_id.trim().toUpperCase()
      : '';
    if (match === null || match[1].toUpperCase() !== configuredId) continue;
    adminVideos.set(page.id, {
      url: page.options.url.trim(),
      ...(typeof page.options.media_asset_version === 'number'
        && Number.isSafeInteger(page.options.media_asset_version)
        && page.options.media_asset_version >= 1
        ? { mediaAssetVersion: page.options.media_asset_version }
        : {}),
    });
  }
  if (adminVideos.size === 0) return normalized;

  return {
    ...normalized,
    pages: normalized.pages.map((page) => {
      const adminVideo = page.type === 'video' ? adminVideos.get(page.id) : undefined;
      if (adminVideo === undefined) return page;
      return {
        ...page,
        options: {
          ...page.options,
          url: adminVideo.url,
          ...(adminVideo.mediaAssetVersion === undefined
            ? {}
            : { media_asset_version: adminVideo.mediaAssetVersion }),
        },
      };
    }),
  };
}
