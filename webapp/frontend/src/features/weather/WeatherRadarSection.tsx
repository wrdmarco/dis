import {
  AlertTriangle,
  ChevronLeft,
  ChevronRight,
  CloudRain,
  Pause,
  Play,
  RadioTower,
  RefreshCw,
  Zap,
} from 'lucide-react';
import { useId, useState, type CSSProperties, type KeyboardEvent } from 'react';
import { formatDateTime } from '../../lib/dateTime';
import type {
  OperationalWeatherRadarLayer,
  OperationalWeatherRadarKind,
  OperationalWeatherRadarState,
} from '../../types/api';
import styles from './OperationalForecast.module.css';
import { useWeatherRadarPlayback, type WeatherRadarPlayback } from './useWeatherRadarPlayback';

export type RadarKind = OperationalWeatherRadarKind;

export interface WeatherRadarSectionProps {
  radar: OperationalWeatherRadarState;
  lockedKind?: RadarKind;
  active?: boolean;
  wallboard?: boolean;
}

interface GeographicPoint {
  longitude: number;
  latitude: number;
}

// Mirrors the twelve managed operational sampling points in backend/config/dis.php.
// They provide a source-controlled geographic reference without implying an exact border map.
const RADAR_REFERENCE_POINTS = [
  { name: 'Drenthe', shortName: 'DR', latitude: 52.9928, longitude: 6.5642 },
  { name: 'Flevoland', shortName: 'FL', latitude: 52.5185, longitude: 5.4714 },
  { name: 'Friesland', shortName: 'FR', latitude: 53.2012, longitude: 5.7999 },
  { name: 'Gelderland', shortName: 'GE', latitude: 51.9851, longitude: 5.8987 },
  { name: 'Groningen', shortName: 'GR', latitude: 53.2194, longitude: 6.5665 },
  { name: 'Limburg', shortName: 'LI', latitude: 50.8514, longitude: 5.6910 },
  { name: 'Noord-Brabant', shortName: 'NB', latitude: 51.6978, longitude: 5.3037 },
  { name: 'Noord-Holland', shortName: 'NH', latitude: 52.3874, longitude: 4.6462 },
  { name: 'Overijssel', shortName: 'OV', latitude: 52.5168, longitude: 6.0830 },
  { name: 'Utrecht', shortName: 'UT', latitude: 52.0907, longitude: 5.1214 },
  { name: 'Zeeland', shortName: 'ZE', latitude: 51.4988, longitude: 3.6100 },
  { name: 'Zuid-Holland', shortName: 'ZH', latitude: 52.0705, longitude: 4.3007 },
] as const;

// Simplified from PDOK/Kadaster Bestuurlijke Gebieden 2026, collection
// `landgebied` (CC BY 4.0). Kept local so a wallboard never depends on a live
// third-party basemap request.
const NETHERLANDS_OUTLINE: readonly (readonly [longitude: number, latitude: number])[] = [
  [5.9533, 51.748], [5.9921, 51.7702], [5.963, 51.8369], [6.1666, 51.8407],
  [6.1179, 51.9017], [6.4018, 51.8273], [6.3906, 51.874], [6.7325, 51.8987],
  [6.8304, 51.9862], [6.6947, 52.0698], [7.0613, 52.2347], [7.0265, 52.292],
  [7.0722, 52.3736], [6.9919, 52.4673], [6.7527, 52.4641], [6.6809, 52.5533],
  [6.7667, 52.5616], [6.71, 52.6275], [6.7526, 52.6481], [7.0557, 52.6434],
  [7.0873, 52.8499], [7.2174, 53.007], [7.1876, 53.3322], [7.0059, 53.3265],
  [6.9159, 53.4574], [6.6371, 53.5764], [5.1767, 53.4086], [4.8406, 53.2323],
  [4.6871, 53.0024], [4.613, 52.9795], [4.6295, 52.8897], [4.6938, 52.8834],
  [4.54, 52.4279], [4.391, 52.2252], [4.1102, 52.0044], [3.9531, 51.9891],
  [3.9531, 51.85], [3.6854, 51.7336], [3.5709, 51.6047], [3.3873, 51.5925],
  [3.3079, 51.4334], [3.4275, 51.2447], [3.59, 51.306], [3.8863, 51.2002],
  [4.1661, 51.2929], [4.2176, 51.3739], [4.4314, 51.3639], [4.3793, 51.4468],
  [4.476, 51.4781], [4.6413, 51.422], [4.7788, 51.5045], [4.8419, 51.4807],
  [4.8415, 51.4224], [4.7712, 51.4149], [4.921, 51.3937], [5.0388, 51.487],
  [5.1344, 51.3155], [5.2422, 51.3052], [5.2379, 51.2614], [5.5158, 51.2952],
  [5.5605, 51.2223], [5.8327, 51.1624], [5.7198, 50.9615], [5.7588, 50.9517],
  [5.6392, 50.8463], [5.6885, 50.7557], [6.021, 50.7543], [5.9757, 50.8024],
  [6.0742, 50.8465], [6.0941, 50.9207], [6.0182, 50.9347], [6.0265, 50.9833],
  [5.8971, 50.9749], [5.8663, 51.0511], [5.9578, 51.0347], [6.1754, 51.1585],
  [6.0822, 51.1716], [6.068, 51.2206], [6.2264, 51.3603], [6.2236, 51.475],
  [6.0914, 51.6058], [6.1181, 51.656], [5.9533, 51.7479], [5.9533, 51.748],
];

const RADAR_BASEMAP_GEOMETRY = {
  precipitation: createRadarBasemapGeometry('precipitation'),
  lightning: createRadarBasemapGeometry('lightning'),
} as const;

const PRECIPITATION_LEGEND = [
  { label: '0,1–0,5', color: '#53d3ff' },
  { label: '0,5–1', color: '#2f8bff' },
  { label: '1–2', color: '#2650d6' },
  { label: '2–5', color: '#2abe5c' },
  { label: '5–10', color: '#ebda34' },
  { label: '10–20', color: '#ff9a2b' },
  { label: '20–40', color: '#e73e37' },
  { label: '40–80', color: '#aa31ae' },
  { label: '≥ 80', color: '#5d1c80' },
] as const;

export function WeatherRadarSection({
  radar,
  lockedKind,
  active = true,
  wallboard = false,
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
              {readOnly
                ? 'Automatische beeldreeks uit lokaal opgeslagen brondata'
                : 'Beeldreeks uit lokaal opgeslagen brondata'}
            </span>
            <h2 id={titleId}>
              {readOnly
                ? activeKind === 'precipitation' ? 'Buienradar' : 'Bliksemradar'
                : 'Buien- en bliksemradar'}
            </h2>
            <p>
              {readOnly
                ? 'De gekozen laag speelt zonder bediening af. Er wordt geen externe kaart ingebed.'
                : 'Bekijk één tijdstap of speel de reeks gecontroleerd af. Er wordt geen externe kaart ingebed.'}
            </p>
          </div>
        </div>

        {readOnly ? null : (
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
              <CloudRain aria-hidden size={18} /> Buien
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
        )}
      </header>

      <RadarLayerPanel
        key={activeKind}
        id={panelId}
        labelledBy={readOnly ? titleId : tabId(activeKind)}
        kind={activeKind}
        layer={layer}
        active={active}
        autoPlay={readOnly}
        readOnly={readOnly}
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
  rangeId,
}: {
  id: string;
  labelledBy: string;
  kind: RadarKind;
  layer: OperationalWeatherRadarLayer | null;
  active: boolean;
  autoPlay: boolean;
  readOnly: boolean;
  rangeId: string;
}) {
  const playback = useWeatherRadarPlayback(layer, active, autoPlay);

  return (
    <div
      id={id}
      role={readOnly ? 'region' : 'tabpanel'}
      aria-labelledby={labelledBy}
      className={styles.radarPanel}
    >
      <RadarViewport kind={kind} layer={layer} playback={playback} readOnly={readOnly} />
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
}: {
  kind: RadarKind;
  layer: OperationalWeatherRadarLayer | null;
  playback: WeatherRadarPlayback;
  readOnly: boolean;
}) {
  const { displayLayer, frame } = playback;
  const frameStyle = displayLayer === null || frame === null || playback.atlasRenderUrl === null
    ? undefined
    : radarFrameStyle(displayLayer, playback.atlasRenderUrl, frame.index);
  const frameLabel = kind === 'precipitation'
    ? `KNMI-neerslagradar, geldig ${formatDateTime(frame?.valid_at ?? null)}`
    : `EUMETSAT total-lightningbeeld, detectievenster ${formatRadarFrameClock(kind, frame?.valid_at ?? null)}`;
  const status = radarLayerStatus(layer, playback);

  return (
    <div className={styles.radarVisualColumn}>
      <div className={styles.radarStatusRow}>
        <div>
          <strong>{kind === 'precipitation' ? 'Neerslagintensiteit' : 'Geaccumuleerd bliksemflitsgebied'}</strong>
          <span>{kind === 'precipitation'
            ? 'KNMI-radarverwachting van nu tot +120 minuten'
            : 'EUMETSAT LI total lightning; geen onderscheid tussen wolk-grond en wolk-wolk'}</span>
        </div>
        <span className={`${styles.radarStatusBadge} ${styles[`radarStatus_${status.tone}`]}`}>
          {status.label}
        </span>
      </div>

      {displayLayer !== null && frame !== null && frameStyle !== undefined ? (
        <div className={styles.radarStage}>
          <div
            className={styles.radarCanvas}
            style={{ aspectRatio: `${displayLayer.frame_width} / ${displayLayer.frame_height}` }}
          >
            <RadarBasemap kind={kind} />
            <div className={styles.radarFrame} role="img" aria-label={frameLabel} style={frameStyle} />
            <span className={styles.radarBasemapNote}>Grenzen · PDOK/Kadaster 2026 · CC BY 4.0</span>
          </div>
          {playback.loadingAtlas ? (
            <div className={styles.radarOverlay} role="status">
              <span className={styles.stateSpinner} aria-hidden />
              <span>
                <strong>Nieuwe beeldreeks laden</strong>
                <small>Het laatst gevalideerde beeld blijft zichtbaar.</small>
              </span>
            </div>
          ) : playback.atlasFailed ? (
            <div className={`${styles.radarOverlay} ${styles.radarOverlayError}`} role="alert">
              <AlertTriangle aria-hidden size={19} />
              <span>
                <strong>Nieuwe beeldreeks niet geladen</strong>
                <small>
                  {playback.showingPreviousAtlas
                    ? 'Het vorige gevalideerde beeld blijft beschikbaar.'
                    : 'De kaartafbeelding is tijdelijk niet beschikbaar.'}
                </small>
              </span>
              {readOnly ? null : (
                <button type="button" onClick={playback.retryAtlas}>
                  <RefreshCw aria-hidden size={16} /> Opnieuw laden
                </button>
              )}
            </div>
          ) : layer?.status === 'stale' ? (
            <div className={`${styles.radarOverlay} ${styles.radarOverlayWarning}`} role="status">
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
                : layer?.availability_note ?? 'Er is geen actuele, gevalideerde atlas voor deze laag beschikbaar.'}</span>
              {playback.atlasFailed && !readOnly ? (
                <button type="button" className={styles.radarRetryButton} onClick={playback.retryAtlas}>
                  <RefreshCw aria-hidden size={16} /> Opnieuw laden
                </button>
              ) : null}
            </>
          )}
        </div>
      )}

      {kind === 'precipitation' ? <PrecipitationLegend /> : <LightningLegend />}
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
  const controlsDisabled = frameCount === 0 || playback.atlasRenderUrl === null;
  const atReference = frameCount === 0 || playback.framePosition === playback.referenceFramePosition;
  const playbackStatus = radarPlaybackStatus(layer, playback);

  return (
    <aside className={styles.radarControls} aria-label="Radartijdlijn">
      <div className={styles.radarTimeReadout} aria-live={readOnly ? 'off' : 'polite'}>
        <span>{radarFrameMomentLabel(kind, playback.frame?.lead_minutes ?? null)}</span>
        <strong>{formatRadarFrameClock(kind, playback.frame?.valid_at ?? null)}</strong>
        <small>
          {kind === 'precipitation' ? 'Geldig' : 'Detectievenster'} · {formatDateTime(playback.frame?.valid_at ?? null)}
        </small>
      </div>

      {readOnly ? (
        <div className={`${styles.radarAutoplayStatus} ${styles[`radarPlayback_${playbackStatus.tone}`]}`}>
          {playback.playing ? <Play aria-hidden size={18} /> : <Pause aria-hidden size={18} />}
          <span>
            <strong>{playbackStatus.label}</strong>
            <small>
              {frameCount === 0
                ? 'Geen tijdstappen beschikbaar'
                : `Tijdstap ${playback.framePosition + 1} van ${frameCount}`}
            </small>
          </span>
        </div>
      ) : (
        <>
          <div className={styles.radarTransport}>
            <button
              type="button"
              className="secondary-button"
              disabled={!playback.playing && !playback.canPlay}
              aria-label={playback.playing ? 'Radaranimatie pauzeren' : 'Radaranimatie afspelen'}
              aria-pressed={playback.playing}
              onClick={playback.playing ? playback.pause : playback.play}
            >
              {playback.playing ? <Pause aria-hidden size={17} /> : <Play aria-hidden size={17} />}
              {playback.playing ? 'Pauzeren' : 'Afspelen'}
            </button>
            <button
              type="button"
              className="secondary-button"
              disabled={controlsDisabled || playback.framePosition === 0}
              aria-label="Vorige radartijdstap"
              onClick={playback.previous}
            >
              <ChevronLeft aria-hidden size={18} /> Vorige
            </button>
            <button
              type="button"
              className="secondary-button"
              disabled={controlsDisabled || playback.framePosition === frameCount - 1}
              aria-label="Volgende radartijdstap"
              onClick={playback.next}
            >
              Volgende <ChevronRight aria-hidden size={18} />
            </button>
          </div>

          <label className={styles.radarRangeLabel} htmlFor={rangeId}>
            <span>Tijdstap</span>
            <strong>{frameCount === 0 ? '0 / 0' : `${playback.framePosition + 1} / ${frameCount}`}</strong>
          </label>
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
          <button
            type="button"
            className={styles.latestButton}
            disabled={controlsDisabled || atReference}
            onClick={playback.goToReference}
          >
            Naar nu
          </button>
        </>
      )}

      {playback.reducedMotion ? (
        <p className={styles.radarMotionNote}>Automatisch afspelen is uitgeschakeld vanwege de instelling voor minder beweging.</p>
      ) : null}

      <dl className={styles.radarFacts}>
        <div><dt>Actualiteit</dt><dd>{radarActualityLabel(displayLayer ?? layer)}</dd></div>
        <div>
          <dt>{kind === 'precipitation' ? 'Modelreferentie' : 'Laatste detectievenster'}</dt>
          <dd>{formatRadarReferencePeriod(kind, displayLayer ?? layer)}</dd>
        </div>
        <div><dt>Bronvertraging</dt><dd>{radarLagLabel(displayLayer ?? layer)}</dd></div>
        <div><dt>Bron</dt><dd>{displayLayer?.source.name ?? layer?.source.name ?? 'Onbekend'}</dd></div>
        <div><dt>Licentie</dt><dd>{displayLayer?.source.license ?? layer?.source.license ?? 'Onbekend'}</dd></div>
      </dl>
    </aside>
  );
}

function PrecipitationLegend() {
  return (
    <div className={styles.radarLegend} aria-label="Legenda neerslagintensiteit in millimeter per uur">
      <div><strong>Intensiteit</strong><span>mm/u</span></div>
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
      <small>Transparant betekent minder dan 0,1 mm/u of geen bruikbare rasterwaarde.</small>
    </div>
  );
}

function LightningLegend() {
  return (
    <div className={`${styles.radarLegend} ${styles.lightningLegend}`} aria-label="Legenda bliksembeeld">
      <div><strong>EUMETSAT LI</strong><span>Total lightning</span></div>
      <p><span className={styles.lightningLegendMark} aria-hidden /> Geaccumuleerd bliksemflitsgebied in het getoonde detectievenster</p>
      <small>Transparant betekent geen weergegeven detectie in dit frame. Het product onderscheidt wolk-grond en wolk-wolk niet.</small>
    </div>
  );
}

function RadarBasemap({ kind }: { kind: RadarKind }) {
  const geometry = RADAR_BASEMAP_GEOMETRY[kind];
  return (
    <svg
      className={styles.radarBasemap}
      viewBox="0 0 1000 1000"
      preserveAspectRatio="none"
      aria-hidden
      focusable="false"
    >
      <rect className={styles.radarSea} width="1000" height="1000" />
      <polygon className={styles.radarLand} points={geometry.landOutline} />
      {geometry.latitudeLines.map((points, index) => (
        <polyline key={`latitude-${index}`} className={styles.radarGridLine} points={points} />
      ))}
      {geometry.longitudeLines.map((points, index) => (
        <polyline key={`longitude-${index}`} className={styles.radarGridLine} points={points} />
      ))}
      {geometry.referencePoints.map((point) => (
        <g key={point.name} transform={`translate(${point.x} ${point.y})`}>
          <circle className={styles.radarReferenceDot} r="5" />
          <text className={styles.radarReferenceLabel} x="10" y="-8">{point.shortName}</text>
        </g>
      ))}
      <text className={styles.radarSeaLabel} x="105" y="475">Noordzee</text>
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

function createRadarBasemapGeometry(kind: RadarKind) {
  const latitudeLines = [51, 52, 53].map((latitude) => radarLinePoints(
    Array.from({ length: 54 }, (_, index) => ({ longitude: 2.5 + index * 0.1, latitude })),
    kind,
  ));
  const longitudeLines = [3, 4, 5, 6, 7].map((longitude) => radarLinePoints(
    Array.from({ length: 33 }, (_, index) => ({ longitude, latitude: 50.5 + index * 0.1 })),
    kind,
  ));
  return {
    landOutline: radarLinePoints(
      NETHERLANDS_OUTLINE.map(([longitude, latitude]) => ({ longitude, latitude })),
      kind,
    ),
    latitudeLines,
    longitudeLines,
    referencePoints: RADAR_REFERENCE_POINTS.map((point) => ({
      ...point,
      ...projectRadarPoint(point, kind),
    })),
  };
}

function radarLinePoints(points: readonly GeographicPoint[], kind: RadarKind): string {
  return points.map((point) => {
    const projected = projectRadarPoint(point, kind);
    return `${projected.x.toFixed(2)},${projected.y.toFixed(2)}`;
  }).join(' ');
}

function projectRadarPoint(point: GeographicPoint, kind: RadarKind): { x: number; y: number } {
  if (kind === 'lightning') {
    return {
      x: ((point.longitude - 2.5) / (7.8 - 2.5)) * 1_000,
      y: ((53.7 - point.latitude) / (53.7 - 50.5)) * 1_000,
    };
  }

  const semiMajorAxis = 6_378_137;
  const semiMinorAxis = 6_356_752;
  const eccentricity = Math.sqrt(1 - (semiMinorAxis * semiMinorAxis) / (semiMajorAxis * semiMajorAxis));
  const latitude = degreesToRadians(point.latitude);
  const longitude = degreesToRadians(point.longitude);
  const latitudeTrueScale = degreesToRadians(60);
  const t = polarStereographicT(latitude, eccentricity);
  const trueScaleT = polarStereographicT(latitudeTrueScale, eccentricity);
  const trueScaleM = Math.cos(latitudeTrueScale)
    / Math.sqrt(1 - eccentricity * eccentricity * Math.sin(latitudeTrueScale) ** 2);
  const radius = semiMajorAxis * trueScaleM * t / trueScaleT;
  const projectedX = radius * Math.sin(longitude);
  const projectedY = -radius * Math.cos(longitude);
  const xMinimum = 0;
  const xMaximum = 700_001.829086;
  const yTop = -3_649_995.4110607;
  const yBottom = -4_414_999.28997026;
  return {
    x: ((projectedX - xMinimum) / (xMaximum - xMinimum)) * 1_000,
    y: ((yTop - projectedY) / (yTop - yBottom)) * 1_000,
  };
}

function polarStereographicT(latitude: number, eccentricity: number): number {
  const eccentricitySinLatitude = eccentricity * Math.sin(latitude);
  return Math.tan(Math.PI / 4 - latitude / 2)
    / ((1 - eccentricitySinLatitude) / (1 + eccentricitySinLatitude)) ** (eccentricity / 2);
}

function degreesToRadians(value: number): number {
  return value * Math.PI / 180;
}

function radarLayerStatus(
  layer: OperationalWeatherRadarLayer | null,
  playback: WeatherRadarPlayback,
): { label: string; tone: 'available' | 'stale' | 'unavailable' } {
  if (playback.atlasFailed && playback.showingPreviousAtlas) {
    return { label: 'Vorige reeks', tone: 'stale' };
  }
  if (playback.atlasFailed) return { label: 'Afbeelding mislukt', tone: 'unavailable' };
  if (playback.loadingAtlas && playback.displayLayer !== null) {
    return { label: 'Reeks vernieuwen', tone: 'stale' };
  }
  if (playback.loadingAtlas) return { label: 'Beeld laden', tone: 'stale' };
  if (layer?.status === 'available') return { label: 'Actueel', tone: 'available' };
  if (layer?.status === 'stale') return { label: 'Verouderd', tone: 'stale' };
  return { label: 'Niet beschikbaar', tone: 'unavailable' };
}

function radarPlaybackStatus(
  layer: OperationalWeatherRadarLayer | null,
  playback: WeatherRadarPlayback,
): { label: string; tone: 'available' | 'stale' | 'unavailable' } {
  if (playback.atlasFailed && playback.showingPreviousAtlas) {
    return { label: 'Vorige gevalideerde reeks', tone: 'stale' };
  }
  if (playback.atlasFailed) return { label: 'Kaartbeeld niet geladen', tone: 'unavailable' };
  if (playback.loadingAtlas) return { label: 'Nieuwe beeldreeks laden', tone: 'stale' };
  if (layer?.status === 'stale') return { label: 'Verouderde reeks staat stil', tone: 'stale' };
  if (playback.reducedMotion) return { label: 'Stilstaand actueel beeld', tone: 'available' };
  if (playback.playing) return { label: 'Beeldreeks speelt automatisch', tone: 'available' };
  if (playback.displayLayer !== null) return { label: 'Stilstaand radarbeeld', tone: 'available' };
  return { label: 'Geen beeldreeks beschikbaar', tone: 'unavailable' };
}

function radarFrameMomentLabel(kind: RadarKind, leadMinutes: number | null): string {
  if (leadMinutes === null) return 'Tijdstap onbekend';
  if (leadMinutes === 0) return kind === 'precipitation' ? 'Nu' : 'Nu · waarneming';
  if (leadMinutes < 0) return `−${Math.abs(leadMinutes)} min · waarneming`;
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
  if (hours < 24) {
    return remainingMinutes === 0
      ? `${hours} uur`
      : `${hours}u ${remainingMinutes}m`;
  }
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
  if (kind === 'precipitation' || layer.observed_period_end === null) {
    return formatDateTime(layer.reference_time);
  }
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
