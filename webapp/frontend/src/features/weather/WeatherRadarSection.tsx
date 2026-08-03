'use client';

import dynamic from 'next/dynamic';
import {
  AlertTriangle,
  ChevronLeft,
  ChevronRight,
  CloudRain,
  Crosshair,
  Layers3,
  Pause,
  Play,
  RadioTower,
  RefreshCw,
  Zap,
} from 'lucide-react';
import {
  useId,
  useState,
  type CSSProperties,
  type KeyboardEvent,
  type ReactNode,
} from 'react';
import { formatDateTime } from '../../lib/dateTime';
import type {
  OperationalWeatherRadarLayer,
  OperationalWeatherRadarKind,
  OperationalWeatherRadarState,
  OperationalWeatherRadarSource,
  WallboardForecastLocationMode,
} from '../../types/api';
import styles from './OperationalForecast.module.css';
import { useWeatherRadarPlayback, type WeatherRadarPlayback } from './useWeatherRadarPlayback';

const LiveRadarMap = dynamic(() => import('./LiveRadarMap'), {
  ssr: false,
  loading: () => (
    <div className={styles.radarMapPlaceholder} role="status">
      <span className={styles.stateSpinner} aria-hidden />
      <span>Interactieve kaart laden</span>
    </div>
  ),
});

export type RadarKind = OperationalWeatherRadarKind;

export interface WeatherRadarSectionProps {
  radar: OperationalWeatherRadarState;
  lockedKind?: RadarKind;
  active?: boolean;
  wallboard?: boolean;
  location?: {
    mode: WallboardForecastLocationMode;
    label: string;
    latitude: number | null;
    longitude: number | null;
  } | null;
  locationControl?: ReactNode;
}

const PRECIPITATION_LEGEND = [
  { label: '0–0,2', color: '#b8f1ff' },
  { label: '0,2–0,5', color: '#63d5ff' },
  { label: '0,5–1', color: '#2786ee' },
  { label: '1–2', color: '#2449c7' },
  { label: '2–5', color: '#35b85a' },
  { label: '5–10', color: '#ebd62f' },
  { label: '10–20', color: '#f7942c' },
  { label: '20–40', color: '#df3b35' },
  { label: '>40', color: '#9c2f9f' },
] as const;

export function WeatherRadarSection({
  radar,
  lockedKind,
  active = true,
  wallboard = false,
  location = null,
  locationControl,
}: WeatherRadarSectionProps) {
  const [selectedKind, setSelectedKind] = useState<RadarKind>('precipitation');
  const activeKind = lockedKind ?? selectedKind;
  const readOnly = lockedKind !== undefined;
  const instanceId = useId().replace(/:/g, '');
  const titleId = `weather-radar-title-${instanceId}`;
  const panelId = `weather-radar-panel-${instanceId}`;
  const tabId = (kind: RadarKind) => `weather-radar-tab-${kind}-${instanceId}`;
  const layer = activeKind === 'precipitation' ? radar.precipitation : radar.lightning;

  function switchTab(kind: RadarKind) {
    if (!readOnly) setSelectedKind(kind);
  }

  function handleTabKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    if (readOnly || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight')) return;
    event.preventDefault();
    const nextKind = activeKind === 'precipitation' ? 'lightning' : 'precipitation';
    setSelectedKind(nextKind);
    document.getElementById(tabId(nextKind))?.focus();
  }

  const layerSwitcher = readOnly ? null : (
    <div className={styles.radarTabs} role="tablist" aria-label="Radarlaag kiezen">
      <button
        id={tabId('precipitation')}
        type="button"
        role="tab"
        aria-controls={panelId}
        aria-selected={activeKind === 'precipitation'}
        tabIndex={activeKind === 'precipitation' ? 0 : -1}
        className={activeKind === 'precipitation' ? styles.radarTabActive : undefined}
        onClick={() => switchTab('precipitation')}
        onKeyDown={handleTabKeyDown}
      >
        <CloudRain aria-hidden size={18} /> Regen
      </button>
      <button
        id={tabId('lightning')}
        type="button"
        role="tab"
        aria-controls={panelId}
        aria-selected={activeKind === 'lightning'}
        tabIndex={activeKind === 'lightning' ? 0 : -1}
        className={activeKind === 'lightning' ? styles.radarTabActive : undefined}
        onClick={() => switchTab('lightning')}
        onKeyDown={handleTabKeyDown}
      >
        <Zap aria-hidden size={18} /> Bliksem
      </button>
    </div>
  );

  return (
    <section
      className={`${styles.radarWorkbench}${wallboard ? ` ${styles.radarWorkbenchWallboard}` : ''}`}
      aria-labelledby={titleId}
      data-radar-kind={activeKind}
      data-radar-read-only={readOnly ? 'true' : 'false'}
    >
      <header className={styles.radarHeader}>
        <div className={styles.radarHeading}>
          <span className={styles.sectionIcon} aria-hidden><RadioTower size={21} /></span>
          <div>
            <span className={styles.sectionKicker}>
              {readOnly ? 'Live kaartbeeld' : 'Live weer · kaart en tijdlijn'}
            </span>
            <h2 id={titleId}>
              {readOnly
                ? activeKind === 'precipitation' ? 'Buienradar' : 'Bliksemradar'
                : 'Buien- en bliksemradar'}
            </h2>
            <p>
              {activeKind === 'precipitation'
                ? 'Waarnemingen en verwachting zijn zichtbaar als afzonderlijke delen van de tijdlijn.'
                : 'Recente bliksemdetectie voor operationele oriëntatie.'}
            </p>
          </div>
        </div>
        <div className={styles.radarHeaderSource}>
          <RadarSourceLinks source={layer?.source ?? null} />
          <strong>{radarActualityLabel(layer)}</strong>
        </div>
      </header>

      <RadarLayerPanel
        key={activeKind}
        id={panelId}
        labelledBy={readOnly ? titleId : tabId(activeKind)}
        kind={activeKind}
        layer={layer}
        active={active}
        autoPlay={readOnly || layer?.status === 'available'}
        readOnly={readOnly}
        wallboard={wallboard}
        location={location}
        locationControl={readOnly ? null : locationControl}
        layerSwitcher={layerSwitcher}
        rangeId={`weather-radar-range-${activeKind}-${instanceId}`}
      />
    </section>
  );
}

function RadarLayerPanel({
  id,
  labelledBy,
  kind,
  layer,
  active,
  autoPlay,
  readOnly,
  wallboard,
  location,
  locationControl,
  layerSwitcher,
  rangeId,
}: {
  id: string;
  labelledBy: string;
  kind: RadarKind;
  layer: OperationalWeatherRadarLayer | null;
  active: boolean;
  autoPlay: boolean;
  readOnly: boolean;
  wallboard: boolean;
  location: WeatherRadarSectionProps['location'];
  locationControl: ReactNode;
  layerSwitcher: ReactNode;
  rangeId: string;
}) {
  const playback = useWeatherRadarPlayback(layer, active, autoPlay);
  const status = radarLayerStatus(layer, playback);

  return (
    <div
      id={id}
      role={readOnly ? 'region' : 'tabpanel'}
      aria-labelledby={labelledBy}
      className={styles.radarPanel}
    >
      {!readOnly ? (
        <div className={styles.radarToolbar} aria-label="Weerkaartbediening">
          {layerSwitcher ? (
            <div className={styles.radarToolbarLayers} data-radar-layers>{layerSwitcher}</div>
          ) : null}
          {locationControl ? (
            <div className={styles.radarToolbarLocation}>{locationControl}</div>
          ) : null}
          <span
            className={`${styles.radarStatusBadge} ${styles[`radarStatus_${status.tone}`]}`}
            data-radar-status
          >
            {status.label}
          </span>
        </div>
      ) : null}
      <RadarViewport
        kind={kind}
        layer={layer}
        playback={playback}
        readOnly={readOnly}
        wallboard={wallboard}
        location={location ?? null}
      />
      <RadarTimeline
        kind={kind}
        layer={layer}
        playback={playback}
        readOnly={readOnly}
        rangeId={rangeId}
      />
    </div>
  );
}

function RadarViewport({
  kind,
  layer,
  playback,
  readOnly,
  wallboard,
  location,
}: {
  kind: RadarKind;
  layer: OperationalWeatherRadarLayer | null;
  playback: WeatherRadarPlayback;
  readOnly: boolean;
  wallboard: boolean;
  location: NonNullable<WeatherRadarSectionProps['location']> | null;
}) {
  const { displayLayer, frame } = playback;
  const liveFrame = displayLayer?.render_mode === 'image_frames'
    && displayLayer.bounds !== null
    && displayLayer.bounds !== undefined
    && typeof frame?.image_url === 'string'
    ? { bounds: displayLayer.bounds, imageUrl: frame.image_url }
    : null;
  const legacyFrameStyle = displayLayer === null
    || frame === null
    || playback.atlasRenderUrl === null
    || displayLayer.render_mode === 'image_frames'
    ? undefined
    : radarFrameStyle(displayLayer, playback.atlasRenderUrl, frame.index);
  const frameLabel = kind === 'precipitation'
    ? `${displayLayer?.source.name ?? layer?.source.name ?? 'Live bron'} · neerslagradar · geldig ${formatDateTime(frame?.valid_at ?? null)}`
    : `Bliksembeeld, detectievenster ${formatRadarFrameClock(kind, frame?.valid_at ?? null)}`;
  const status = radarLayerStatus(layer, playback);
  const hasRenderableFrame = liveFrame !== null || legacyFrameStyle !== undefined;

  return (
    <div className={styles.radarVisualColumn}>
      {hasRenderableFrame && displayLayer !== null && frame !== null ? (
        <div className={styles.radarStage}>
          {liveFrame !== null ? (
            <LiveRadarMap
              ariaLabel={`${displayLayer.source.name} · ${kind === 'precipitation' ? 'neerslag' : 'bliksem'} · ${formatDateTime(frame.valid_at)}`}
              bounds={liveFrame.bounds}
              imageUrl={liveFrame.imageUrl}
              interactive={!readOnly}
              kind={kind}
              location={location}
            />
          ) : (
            <div
              className={styles.radarCanvas}
              style={{ aspectRatio: `${displayLayer.frame_width} / ${displayLayer.frame_height}` }}
            >
              <LegacyRadarBasemap />
              <div className={styles.radarFrame} role="img" aria-label={frameLabel} style={legacyFrameStyle} />
              <span className={styles.radarBasemapNote}>Overgangsweergave</span>
            </div>
          )}

          <div
            className={styles.radarMapMoment}
            data-radar-map-moment
            aria-live={readOnly ? 'off' : 'polite'}
          >
            <span>{radarFrameMomentLabel(kind, frame.lead_minutes)}</span>
            <strong>{formatRadarFrameClock(kind, frame.valid_at)}</strong>
          </div>
          {readOnly ? (
            <span
              className={`${styles.radarStatusBadge} ${styles.radarStatusFloat} ${styles[`radarStatus_${status.tone}`]}`}
              data-radar-status
            >
              {status.label}
            </span>
          ) : null}
          <details className={styles.radarLegendPopover} data-radar-legend open={wallboard}>
            <summary><Layers3 aria-hidden size={17} /> Legenda</summary>
            {kind === 'precipitation' ? <PrecipitationLegend /> : <LightningLegend />}
          </details>

          {playback.atlasFailed ? (
            <div
              className={`${styles.radarOverlay} ${styles.radarOverlayError}`}
              data-radar-overlay
              role="alert"
            >
              <AlertTriangle aria-hidden size={19} />
              <span>
                <strong>Nieuwe beeldreeks niet geladen</strong>
                <small>{playback.showingPreviousAtlas
                  ? 'Het vorige geldige beeld blijft beschikbaar.'
                  : 'De kaartafbeelding is tijdelijk niet beschikbaar.'}</small>
              </span>
              {readOnly ? null : (
                <button type="button" onClick={playback.retryAtlas}>
                  <RefreshCw aria-hidden size={16} /> Opnieuw laden
                </button>
              )}
            </div>
          ) : layer?.status === 'stale' ? (
            <div
              className={`${styles.radarOverlay} ${styles.radarOverlayWarning}`}
              data-radar-overlay
              role="status"
            >
              <AlertTriangle aria-hidden size={19} />
              <span>
                <strong>Verouderde bronreeks</strong>
                <small>{radarActualityLabel(layer)} · niet als actuele situatie gebruiken</small>
              </span>
            </div>
          ) : null}
        </div>
      ) : (
        <div className={styles.radarEmpty} role={playback.atlasFailed ? 'alert' : 'status'}>
          {playback.loadingAtlas ? (
            <><span className={styles.stateSpinner} aria-hidden /> <strong>Radarbeeld laden</strong></>
          ) : (
            <>
              {kind === 'precipitation' ? <CloudRain aria-hidden size={28} /> : <Zap aria-hidden size={28} />}
              <strong>{playback.atlasFailed ? 'Radarafbeelding niet geladen' : 'Geen bruikbare radarreeks'}</strong>
              <span>{playback.atlasFailed
                ? 'Probeer de kaartafbeelding opnieuw te laden.'
                : layer?.availability_note ?? 'Er is geen actueel kaartbeeld voor deze laag beschikbaar.'}</span>
              {playback.atlasFailed && !readOnly ? (
                <button type="button" className={styles.radarRetryButton} onClick={playback.retryAtlas}>
                  <RefreshCw aria-hidden size={16} /> Opnieuw laden
                </button>
              ) : null}
            </>
          )}
        </div>
      )}
    </div>
  );
}

function RadarTimeline({
  kind,
  layer,
  playback,
  readOnly,
  rangeId,
}: {
  kind: RadarKind;
  layer: OperationalWeatherRadarLayer | null;
  playback: WeatherRadarPlayback;
  readOnly: boolean;
  rangeId: string;
}) {
  const displayLayer = playback.displayLayer;
  const frameCount = displayLayer?.frames.length ?? 0;
  const controlsDisabled = !playback.seriesReady
    || frameCount === 0
    || !radarFrameIsRenderable(displayLayer, playback.framePosition, playback.atlasRenderUrl);
  const atReference = frameCount === 0 || playback.framePosition === playback.referenceFramePosition;
  const playbackStatus = radarPlaybackStatus(layer, playback);
  const nowPosition = frameCount <= 1 ? 0 : playback.referenceFramePosition / (frameCount - 1) * 100;
  const firstFrame = displayLayer?.frames[0] ?? null;
  const lastFrame = displayLayer?.frames[frameCount - 1] ?? null;
  const hasObservations = displayLayer?.frames.some((frame) => frame.phase === 'observation' || frame.lead_minutes < 0) ?? false;
  const hasForecast = displayLayer?.frames.some((frame) => frame.phase === 'forecast' || frame.lead_minutes > 0) ?? false;

  return (
    <aside className={styles.radarControls} aria-label="Radartijdlijn">
      <div className={styles.radarTimelineHeader}>
        <div className={styles.radarTimeReadout} aria-live={readOnly ? 'off' : 'polite'}>
          <span>{radarFrameMomentLabel(kind, playback.frame?.lead_minutes ?? null)}</span>
          <strong>{formatRadarFrameClock(kind, playback.frame?.valid_at ?? null)}</strong>
          <small>{formatDateTime(playback.frame?.valid_at ?? null)}</small>
        </div>

        {readOnly ? (
          <div className={`${styles.radarAutoplayStatus} ${styles[`radarPlayback_${playbackStatus.tone}`]}`}>
            {playback.playing ? <Play aria-hidden size={18} /> : <Pause aria-hidden size={18} />}
            <span>
              <strong>{playbackStatus.label}</strong>
              <small>{frameCount === 0 ? 'Geen tijdstappen' : `${playback.framePosition + 1} van ${frameCount}`}</small>
            </span>
          </div>
        ) : (
          <div className={styles.radarTransport}>
            <button
              type="button"
              disabled={controlsDisabled || playback.framePosition === 0}
              aria-label="Vorige"
              onClick={playback.previous}
            >
              <ChevronLeft aria-hidden size={19} />
            </button>
            <button
              type="button"
              className={styles.radarPlayButton}
              disabled={!playback.playing && !playback.canRequestPlayback}
              aria-label={playback.playing
                ? 'Radaranimatie pauzeren'
                : playback.seriesDeferred
                  ? 'Radaranimatie laden en afspelen'
                  : 'Radaranimatie afspelen'}
              aria-pressed={playback.playing}
              onClick={playback.playing ? playback.pause : playback.play}
            >
              {playback.playing ? <Pause aria-hidden size={18} /> : <Play aria-hidden size={18} />}
              {playback.playing ? 'Pauze' : 'Afspelen'}
            </button>
            <button
              type="button"
              disabled={controlsDisabled || playback.framePosition === frameCount - 1}
              aria-label="Volgende"
              onClick={playback.next}
            >
              <ChevronRight aria-hidden size={19} />
            </button>
            <button
              type="button"
              className={styles.latestButton}
              disabled={controlsDisabled || atReference}
              aria-label="Naar nu"
              onClick={playback.goToReference}
            >
              <Crosshair aria-hidden size={17} /> Nu
            </button>
          </div>
        )}
      </div>

      <div className={styles.radarTimelineBody}>
        <div className={styles.radarPhaseLabels} aria-hidden>
          <span>{hasObservations ? 'Gemeten' : 'Start'}</span>
          <span>{hasForecast ? 'Verwachting' : 'Historie'}</span>
        </div>
        <div className={styles.radarRangeTrack}>
          <span
            className={styles.radarNowSeam}
            style={{ '--radar-now-position': `${nowPosition}%` } as CSSProperties}
            aria-hidden
          >
            <em>NU</em>
          </span>
          <label className="sr-only" htmlFor={rangeId}>Tijdstap van de radar</label>
          <input
            id={rangeId}
            className={styles.radarRange}
            type="range"
            min={0}
            max={Math.max(0, frameCount - 1)}
            step={1}
            value={Math.min(playback.framePosition, Math.max(0, frameCount - 1))}
            disabled={controlsDisabled}
            aria-valuetext={playback.frame === null ? 'Geen tijdstap beschikbaar' : formatDateTime(playback.frame.valid_at)}
            onChange={(event) => playback.seek(Number(event.currentTarget.value))}
          />
        </div>
        <div className={styles.radarTimelineTicks} aria-hidden>
          <span>{formatRadarClock(firstFrame?.valid_at ?? null)}</span>
          <strong>{formatRadarClock(playback.frame?.valid_at ?? null)}</strong>
          <span>{formatRadarClock(lastFrame?.valid_at ?? null)}</span>
        </div>
      </div>

      {playback.reducedMotion && !readOnly ? (
        <p className={styles.radarMotionNote}>Automatisch afspelen is uitgeschakeld vanwege de instelling voor minder beweging.</p>
      ) : null}

      {playback.seriesLoading ? (
        <p className={styles.radarSeriesStatus} role="status" aria-live={readOnly ? 'off' : 'polite'}>
          <span className={styles.stateSpinner} aria-hidden />
          Animatie voorbereiden · {playback.loadedFrameCount} van {playback.totalFrameCount} beelden
        </p>
      ) : playback.seriesDeferred ? (
        <p className={styles.radarSeriesStatus} role="status">
          <Play aria-hidden size={17} />
          Animatiebeelden laden pas wanneer u Afspelen kiest.
        </p>
      ) : playback.seriesFailed ? (
        <div className={`${styles.radarSeriesStatus} ${styles.radarSeriesStatusWarning}`} role="status">
          <AlertTriangle aria-hidden size={17} />
          <span>Actueel beeld beschikbaar; animatie is niet compleet.</span>
          {readOnly ? null : (
            <button type="button" onClick={playback.retryAtlas}>
              <RefreshCw aria-hidden size={16} /> Opnieuw laden
            </button>
          )}
        </div>
      ) : null}

      <dl className={styles.radarFacts}>
        <div>
          <dt>Bron</dt>
          <dd><RadarSourceLinks source={displayLayer?.source ?? layer?.source ?? null} /></dd>
        </div>
        <div>
          <dt>Licentie</dt>
          <dd><RadarLicenseLink source={displayLayer?.source ?? layer?.source ?? null} /></dd>
        </div>
        <div><dt>Referentie</dt><dd>{formatRadarReferencePeriod(kind, displayLayer ?? layer)}</dd></div>
        <div><dt>Vertraging</dt><dd>{radarLagLabel(displayLayer ?? layer)}</dd></div>
      </dl>
      <RadarProcessingAttribution source={displayLayer?.source ?? layer?.source ?? null} />
    </aside>
  );
}

function RadarSourceLinks({ source }: { source: OperationalWeatherRadarSource | null }) {
  if (source === null) return <span>Bron niet beschikbaar</span>;
  return source.url ? (
    <a href={source.url} rel="noreferrer noopener" target="_blank">{source.name}</a>
  ) : <span>{source.name}</span>;
}

function RadarLicenseLink({ source }: { source: OperationalWeatherRadarSource | null }) {
  if (source === null) return <span>Onbekend</span>;
  return source.license_url ? (
    <a href={source.license_url} rel="noreferrer noopener" target="_blank">{source.license}</a>
  ) : <span>{source.license}</span>;
}

function RadarProcessingAttribution({ source }: { source: OperationalWeatherRadarSource | null }) {
  if (source === null || (source.attribution === undefined && !(source.modified && source.processed_by))) return null;
  return (
    <p className={styles.radarDataAttribution}>
      {source.attribution}
      {source.modified && source.processed_by ? ` · Verwerkt door ${source.processed_by}` : ''}
    </p>
  );
}

function PrecipitationLegend() {
  return (
    <div className={styles.radarLegend} aria-label="Legenda neerslagintensiteit in millimeter per uur">
      <div><strong>Neerslag</strong><span>mm/u</span></div>
      <ol>
        {PRECIPITATION_LEGEND.map((item) => (
          <li key={item.label}>
            <span
              className={styles.radarLegendSwatch}
              style={{ '--radar-legend-color': item.color } as CSSProperties}
              aria-hidden
            />
            {item.label}
          </li>
        ))}
      </ol>
    </div>
  );
}

function LightningLegend() {
  return (
    <div className={`${styles.radarLegend} ${styles.lightningLegend}`} aria-label="Legenda bliksembeeld">
      <div><strong>Bliksem</strong><span>detectie</span></div>
      <p><span className={styles.lightningLegendMark} aria-hidden /> Gebied met recente flitsactiviteit</p>
    </div>
  );
}

function LegacyRadarBasemap() {
  return (
    <svg
      className={styles.radarBasemap}
      viewBox="0 0 1000 1000"
      preserveAspectRatio="none"
      aria-hidden
      focusable="false"
    >
      <rect className={styles.radarSea} width="1000" height="1000" />
      <path
        className={styles.radarLand}
        d="M650 80 L810 160 L820 410 L740 520 L790 700 L670 920 L470 900 L390 760 L260 680 L350 550 L310 360 L420 170 Z"
      />
      <path className={styles.radarGridLine} d="M0 250 H1000 M0 500 H1000 M0 750 H1000 M250 0 V1000 M500 0 V1000 M750 0 V1000" />
      <text className={styles.radarSeaLabel} x="90" y="470">Noordzee</text>
    </svg>
  );
}

function radarFrameStyle(
  layer: OperationalWeatherRadarLayer,
  atlasRenderUrl: string,
  frameIndex: number,
): CSSProperties {
  const column = frameIndex % layer.atlas_columns;
  const row = Math.floor(frameIndex / layer.atlas_columns);
  const x = layer.atlas_columns === 1 ? 0 : (column / (layer.atlas_columns - 1)) * 100;
  const y = layer.atlas_rows === 1 ? 0 : (row / (layer.atlas_rows - 1)) * 100;
  return {
    backgroundImage: `url("${atlasRenderUrl}")`,
    backgroundPosition: `${x}% ${y}%`,
    backgroundSize: `${layer.atlas_columns * 100}% ${layer.atlas_rows * 100}%`,
  };
}

function radarFrameIsRenderable(
  layer: OperationalWeatherRadarLayer | null,
  framePosition: number,
  atlasRenderUrl: string | null,
): boolean {
  if (layer === null) return false;
  if (layer.render_mode === 'image_frames') {
    return typeof layer.frames[framePosition]?.image_url === 'string';
  }
  return atlasRenderUrl !== null;
}

function radarLayerStatus(
  layer: OperationalWeatherRadarLayer | null,
  playback: WeatherRadarPlayback,
): { label: string; tone: 'available' | 'stale' | 'unavailable' } {
  if (playback.atlasFailed && playback.showingPreviousAtlas) return { label: 'Vorige reeks', tone: 'stale' };
  if (playback.atlasFailed) return { label: 'Afbeelding mislukt', tone: 'unavailable' };
  if (playback.loadingAtlas) return { label: 'Beeld laden', tone: 'stale' };
  if (layer?.status === 'available') return { label: 'Actueel', tone: 'available' };
  if (layer?.status === 'stale') return { label: 'Verouderd', tone: 'stale' };
  return { label: 'Niet beschikbaar', tone: 'unavailable' };
}

function radarPlaybackStatus(
  layer: OperationalWeatherRadarLayer | null,
  playback: WeatherRadarPlayback,
): { label: string; tone: 'available' | 'stale' | 'unavailable' } {
  if (playback.atlasFailed && playback.showingPreviousAtlas) return { label: 'Vorige geldige reeks', tone: 'stale' };
  if (playback.atlasFailed) return { label: 'Kaartbeeld niet geladen', tone: 'unavailable' };
  if (playback.loadingAtlas) return { label: 'Nieuwe beeldreeks laden', tone: 'stale' };
  if (playback.seriesLoading) return { label: 'Animatie wordt voorbereid', tone: 'stale' };
  if (playback.seriesFailed) return { label: 'Actueel beeld; animatie onvolledig', tone: 'stale' };
  if (layer?.status === 'stale') return { label: 'Verouderde reeks staat stil', tone: 'stale' };
  if (playback.reducedMotion) return { label: 'Stilstaand actueel beeld', tone: 'available' };
  if (playback.playing) return { label: 'Beeldreeks speelt automatisch', tone: 'available' };
  if (playback.displayLayer !== null) return { label: 'Stilstaand radarbeeld', tone: 'available' };
  return { label: 'Geen beeldreeks beschikbaar', tone: 'unavailable' };
}

function radarFrameMomentLabel(kind: RadarKind, leadMinutes: number | null): string {
  if (leadMinutes === null) return 'Tijdstap onbekend';
  if (leadMinutes === 0) return kind === 'precipitation' ? 'Nu' : 'Nu · waarneming';
  if (leadMinutes < 0) return `−${Math.abs(leadMinutes)} min · gemeten`;
  return `+${leadMinutes} min · verwachting`;
}

function radarActualityLabel(layer: OperationalWeatherRadarLayer | null): string {
  if (layer?.age_seconds === null || layer?.age_seconds === undefined) return 'Actualiteit onbekend';
  return `${formatRadarDuration(layer.age_seconds)} oud`;
}

function radarLagLabel(layer: OperationalWeatherRadarLayer | null): string {
  if (layer?.lag_seconds === null || layer?.lag_seconds === undefined) return 'Niet gerapporteerd';
  return formatRadarDuration(layer.lag_seconds);
}

function formatRadarDuration(seconds: number): string {
  if (seconds < 60) return 'minder dan 1 minuut';
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes} ${minutes === 1 ? 'minuut' : 'minuten'}`;
  const hours = Math.floor(minutes / 60);
  const remainingMinutes = minutes % 60;
  if (hours < 24) return remainingMinutes === 0 ? `${hours} uur` : `${hours}u ${remainingMinutes}m`;
  const days = Math.floor(hours / 24);
  return `${days} ${days === 1 ? 'dag' : 'dagen'}`;
}

function formatRadarFrameClock(kind: RadarKind, value: string | null): string {
  const from = formatRadarClock(value);
  if (kind === 'precipitation' || value === null || from === '--:--') return from;
  const timestamp = new Date(value);
  const until = new Date(timestamp.getTime() + 5 * 60_000).toISOString();
  return `${from}–${formatRadarClock(until)}`;
}

function formatRadarReferencePeriod(
  kind: RadarKind,
  layer: OperationalWeatherRadarLayer | null,
): string {
  if (layer === null) return 'Onbekend';
  if (kind === 'precipitation' || layer.observed_period_end === null) return formatDateTime(layer.reference_time);
  return `${formatDateTime(layer.reference_time)} – ${formatRadarClock(layer.observed_period_end)}`;
}

function formatRadarClock(value: string | null): string {
  if (value === null) return '--:--';
  const timestamp = new Date(value);
  if (!Number.isFinite(timestamp.getTime())) return '--:--';
  return new Intl.DateTimeFormat('nl-NL', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: 'Europe/Amsterdam',
  }).format(timestamp);
}
