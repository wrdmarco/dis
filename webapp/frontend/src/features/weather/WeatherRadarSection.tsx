import {
  ChevronLeft,
  ChevronRight,
  CloudRain,
  Pause,
  Play,
  RadioTower,
  Zap,
} from 'lucide-react';
import { useState, type CSSProperties, type KeyboardEvent } from 'react';
import { formatDateTime } from '../../lib/dateTime';
import type {
  OperationalWeatherRadarLayer,
  OperationalWeatherRadarState,
} from '../../types/api';
import styles from './OperationalForecast.module.css';
import { useWeatherRadarPlayback, type WeatherRadarPlayback } from './useWeatherRadarPlayback';

type RadarKind = 'precipitation' | 'lightning';

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

export function WeatherRadarSection({ radar }: { radar: OperationalWeatherRadarState }) {
  const [activeKind, setActiveKind] = useState<RadarKind>('precipitation');
  const precipitation = useWeatherRadarPlayback(radar.precipitation, activeKind === 'precipitation');
  const lightning = useWeatherRadarPlayback(radar.lightning, activeKind === 'lightning');
  const layer = activeKind === 'precipitation' ? radar.precipitation : radar.lightning;
  const playback = activeKind === 'precipitation' ? precipitation : lightning;

  function switchTab(kind: RadarKind) {
    setActiveKind(kind);
  }

  function handleTabKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    event.preventDefault();
    const nextKind = activeKind === 'precipitation' ? 'lightning' : 'precipitation';
    setActiveKind(nextKind);
    document.getElementById(`weather-radar-tab-${nextKind}`)?.focus();
  }

  return (
    <section className={styles.radarWorkbench} aria-labelledby="weather-radar-title">
      <header className={styles.radarHeader}>
        <div className={styles.radarHeading}>
          <span className={styles.sectionIcon} aria-hidden><RadioTower size={21} /></span>
          <div>
            <span className={styles.sectionKicker}>Beeldreeks uit lokaal opgeslagen brondata</span>
            <h2 id="weather-radar-title">Buien- en bliksemradar</h2>
            <p>Bekijk één tijdstap of speel de reeks gecontroleerd af. Er wordt geen externe kaart ingebed.</p>
          </div>
        </div>

        <div className={styles.radarTabs} role="tablist" aria-label="Radarlaag kiezen">
          <button
            id="weather-radar-tab-precipitation"
            type="button"
            role="tab"
            aria-controls="weather-radar-panel"
            aria-selected={activeKind === 'precipitation'}
            tabIndex={activeKind === 'precipitation' ? 0 : -1}
            className={activeKind === 'precipitation' ? styles.radarTabActive : undefined}
            onClick={() => switchTab('precipitation')}
            onKeyDown={handleTabKeyDown}
          >
            <CloudRain aria-hidden size={18} /> Buien
          </button>
          <button
            id="weather-radar-tab-lightning"
            type="button"
            role="tab"
            aria-controls="weather-radar-panel"
            aria-selected={activeKind === 'lightning'}
            tabIndex={activeKind === 'lightning' ? 0 : -1}
            className={activeKind === 'lightning' ? styles.radarTabActive : undefined}
            onClick={() => switchTab('lightning')}
            onKeyDown={handleTabKeyDown}
          >
            <Zap aria-hidden size={18} /> Bliksem
          </button>
        </div>
      </header>

      <div
        id="weather-radar-panel"
        role="tabpanel"
        aria-labelledby={`weather-radar-tab-${activeKind}`}
        className={styles.radarPanel}
      >
        <RadarViewport kind={activeKind} layer={layer} playback={playback} />
        <RadarTimeline kind={activeKind} layer={layer} playback={playback} />
      </div>
    </section>
  );
}

function RadarViewport({
  kind,
  layer,
  playback,
}: {
  kind: RadarKind;
  layer: OperationalWeatherRadarLayer | null;
  playback: WeatherRadarPlayback;
}) {
  const { displayLayer, frame } = playback;
  const frameStyle = displayLayer === null || frame === null || displayLayer.atlas_url === null
    ? undefined
    : radarFrameStyle(displayLayer, frame.index);
  const frameLabel = kind === 'precipitation'
    ? `KNMI-neerslagradar, geldig ${formatDateTime(frame?.valid_at ?? null)}`
    : `EUMETSAT total-lightningbeeld, geldig ${formatDateTime(frame?.valid_at ?? null)}`;
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
            <span className={styles.radarBasemapNote}>Oriëntatie · 12 beheerde provinciepunten</span>
          </div>
          {playback.loadingAtlas ? (
            <div className={styles.radarOverlay} role="status">Nieuwe radarreeks laden…</div>
          ) : playback.atlasFailed ? (
            <div className={styles.radarOverlay} role="alert">De nieuwe radarafbeelding kon niet worden geladen.</div>
          ) : layer?.status === 'stale' ? (
            <div className={styles.radarOverlay} role="status">Verouderd beeld — niet als actuele situatie gebruiken</div>
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
                ? 'Ververs de pagina of probeer het later opnieuw.'
                : layer?.availability_note ?? 'Er is geen actuele, gevalideerde atlas voor deze laag beschikbaar.'}</span>
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
}: {
  kind: RadarKind;
  layer: OperationalWeatherRadarLayer | null;
  playback: WeatherRadarPlayback;
}) {
  const displayLayer = playback.displayLayer;
  const frameCount = displayLayer?.frames.length ?? 0;
  const controlsDisabled = frameCount === 0 || playback.loadingAtlas || playback.atlasFailed;
  const atNewest = frameCount === 0 || playback.framePosition === frameCount - 1;

  return (
    <aside className={styles.radarControls} aria-label="Radartijdlijn">
      <div className={styles.radarTimeReadout} aria-live="polite">
        <span>{kind === 'precipitation' ? radarLeadLabel(playback.frame?.lead_minutes ?? null) : 'Detectievenster'}</span>
        <strong>{formatRadarClock(playback.frame?.valid_at ?? null)}</strong>
        <small>{formatDateTime(playback.frame?.valid_at ?? null)}</small>
      </div>

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
          onClick={playback.previous}
        >
          <ChevronLeft aria-hidden size={18} /> Vorige
        </button>
        <button
          type="button"
          className="secondary-button"
          disabled={controlsDisabled || atNewest}
          onClick={playback.next}
        >
          Volgende <ChevronRight aria-hidden size={18} />
        </button>
      </div>

      <label className={styles.radarRangeLabel} htmlFor={`weather-radar-range-${kind}`}>
        <span>Tijdstap</span>
        <strong>{frameCount === 0 ? '0 / 0' : `${playback.framePosition + 1} / ${frameCount}`}</strong>
      </label>
      <input
        id={`weather-radar-range-${kind}`}
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
        disabled={controlsDisabled || atNewest}
        onClick={playback.goToNewest}
      >
        Naar nieuwste
      </button>

      {playback.reducedMotion ? (
        <p className={styles.radarMotionNote}>Automatisch afspelen is uitgeschakeld vanwege de instelling voor minder beweging.</p>
      ) : null}

      <dl className={styles.radarFacts}>
        <div><dt>Referentietijd</dt><dd>{formatDateTime(displayLayer?.reference_time ?? layer?.reference_time ?? null)}</dd></div>
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

function radarFrameStyle(layer: OperationalWeatherRadarLayer, frameIndex: number): CSSProperties {
  const column = frameIndex % layer.atlas_columns;
  const row = Math.floor(frameIndex / layer.atlas_columns);
  const x = layer.atlas_columns === 1 ? 0 : (column / (layer.atlas_columns - 1)) * 100;
  const y = layer.atlas_rows === 1 ? 0 : (row / (layer.atlas_rows - 1)) * 100;
  return {
    backgroundImage: `url("${layer.atlas_url}")`,
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
    latitudeLines,
    longitudeLines,
    referencePoints: RADAR_REFERENCE_POINTS.map((point) => ({
      ...point,
      ...projectRadarPoint(point, kind),
    })),
  };
}

function radarLinePoints(points: GeographicPoint[], kind: RadarKind): string {
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

  const semiMajorAxis = 6_378_140;
  const semiMinorAxis = 6_356_750;
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
  if (playback.atlasFailed) return { label: 'Afbeelding mislukt', tone: 'unavailable' };
  if (playback.loadingAtlas) return { label: 'Nieuwe reeks laden', tone: 'stale' };
  if (layer?.status === 'available') return { label: 'Actueel', tone: 'available' };
  if (layer?.status === 'stale') return { label: 'Verouderd', tone: 'stale' };
  return { label: 'Niet beschikbaar', tone: 'unavailable' };
}

function radarLeadLabel(leadMinutes: number | null): string {
  if (leadMinutes === null) return 'Tijdstap onbekend';
  return leadMinutes === 0 ? 'Nu' : `+${leadMinutes} minuten`;
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
