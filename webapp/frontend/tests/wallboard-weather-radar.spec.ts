import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type {
  OperationalWeatherRadarLayer,
  WallboardPage,
} from '../src/types/api';
import { normalizeOperationalWeatherRadarState } from '../src/features/weather/forecastNormalization';
import {
  DEFAULT_WALLBOARD_CONFIGURATION,
  createWallboardPage,
  normalizeWallboardWeatherRadarKind,
  wallboardConfigurationCopy,
  wallboardPageTypeLabel,
} from '../src/features/wallboards/wallboardPresentation';

const DISPLAY_SOURCE = readFileSync(
  new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url),
  'utf8',
);
const EDITOR_SOURCE = readFileSync(
  new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url),
  'utf8',
);
const RADAR_SOURCE = readFileSync(
  new URL('../src/features/weather/WeatherRadarSection.tsx', import.meta.url),
  'utf8',
);
const PLAYBACK_SOURCE = readFileSync(
  new URL('../src/features/weather/useWeatherRadarPlayback.ts', import.meta.url),
  'utf8',
);
const WEATHER_PAGE_SOURCE = readFileSync(
  new URL('../src/features/weather/WeatherPage.tsx', import.meta.url),
  'utf8',
);
const RADAR_CSS = readFileSync(
  new URL('../src/features/weather/OperationalForecast.module.css', import.meta.url),
  'utf8',
);

test('creates and normalizes independently configurable wallboard radar pages', () => {
  const precipitation = createWallboardPage('weather_radar', 1);
  const lightning: WallboardPage = {
    ...createWallboardPage('weather_radar', 2),
    options: { radar_kind: 'lightning' },
  };

  expect(precipitation.options).toEqual({ radar_kind: 'precipitation' });
  expect(wallboardPageTypeLabel('weather_radar')).toBe('Weerradar');
  expect(normalizeWallboardWeatherRadarKind('lightning')).toBe('lightning');
  expect(normalizeWallboardWeatherRadarKind('invalid')).toBe('precipitation');

  const normalized = wallboardConfigurationCopy({
    ...DEFAULT_WALLBOARD_CONFIGURATION,
    pages: [
      { ...precipitation, options: { radar_kind: 'invalid' as never } },
      lightning,
    ],
  });
  expect(normalized.pages).toHaveLength(2);
  expect(normalized.pages.map((page) => page.options.radar_kind)).toEqual([
    'precipitation',
    'lightning',
  ]);
});

test('normalizes only immutable first-party radar atlas contracts for both authenticated renderers', () => {
  const precipitation = precipitationLayer(
    '/api/wallboard/weather-radar/precipitation/20260723T120000Z-0123456789abcdef.png',
  );
  const lightning = lightningLayer(
    '/api/operational-weather/radar/lightning/20260723T120000Z-fedcba9876543210.png',
  );

  expect(normalizeOperationalWeatherRadarState({ precipitation, lightning })).toMatchObject({
    precipitation: { status: 'available', atlas_url: precipitation.atlas_url },
    lightning: { status: 'available', atlas_url: lightning.atlas_url },
  });
  expect(normalizeOperationalWeatherRadarState(null)).toEqual({
    precipitation: null,
    lightning: null,
  });

  const external = normalizeOperationalWeatherRadarState({
    precipitation: { ...precipitation, atlas_url: 'https://radar.example/atlas.png' },
    lightning: { ...lightning, atlas_url: precipitation.atlas_url },
  });
  expect(external.precipitation).toMatchObject({ status: 'unavailable', atlas_url: null, frames: [] });
  expect(external.lightning).toMatchObject({ status: 'unavailable', atlas_url: null, frames: [] });
});

test('uses one locked autoplay renderer on wallboards while preserving interactive weather tabs', () => {
  expect(EDITOR_SOURCE).toContain("{ value: 'weather_radar', label: 'Weerradar' }");
  expect(EDITOR_SOURCE).toContain('<option value="precipitation">Buien · KNMI-neerslagverwachting</option>');
  expect(EDITOR_SOURCE).toContain('<option value="lightning">Bliksem · EUMETSAT-detecties</option>');

  expect(DISPLAY_SOURCE).toContain("if (page.type === 'weather_radar')");
  expect(DISPLAY_SOURCE).toContain('lockedKind={normalizeWallboardWeatherRadarKind(page.options.radar_kind)}');
  expect(DISPLAY_SOURCE).toContain('active={running}');
  expect(DISPLAY_SOURCE).toContain('weather_radar: normalizeOperationalWeatherRadarState(rawState.weather_radar)');
  expect(DISPLAY_SOURCE).not.toContain("wallboardApi.get<OperationalWeatherRadarState>('/operational-weather')");

  expect(RADAR_SOURCE).toContain('const playback = useWeatherRadarPlayback(layer, active, autoPlay);');
  expect(RADAR_SOURCE).toContain('key={activeKind}');
  expect(RADAR_SOURCE).toContain('{readOnly ? null : (');
  expect(RADAR_SOURCE).toContain('{readOnly ? (');
  expect(RADAR_SOURCE).toContain('role={readOnly ?');
  expect(WEATHER_PAGE_SOURCE).toContain('<WeatherRadarSection radar={weather.radar} />');
  expect(RADAR_CSS).toContain('.radarWorkbenchWallboard');
  expect(RADAR_CSS).toContain('height: 100%;');
  expect(RADAR_CSS).toContain('grid-template-rows: auto minmax(0, 1fr) auto;');
  expect(PLAYBACK_SOURCE).toContain("layer?.status !== 'available'");
  expect(PLAYBACK_SOURCE).toContain('setFramePosition(radarReferenceFramePosition(displayLayer));');
  expect(PLAYBACK_SOURCE).toContain('RADAR_WALLBOARD_RETRY_MS');
});

for (const scenario of [
  { width: 1920, height: 1080, kind: 'precipitation', aspect: '140 / 153' },
  { width: 1920, height: 1080, kind: 'lightning', aspect: '640 / 384' },
  { width: 3840, height: 2160, kind: 'precipitation', aspect: '140 / 153' },
  { width: 3840, height: 2160, kind: 'lightning', aspect: '640 / 384' },
] as const) {
  test(`locked ${scenario.kind} radar fits ${scenario.width}x${scenario.height}`, async ({ page }) => {
    await page.setViewportSize({ width: scenario.width, height: scenario.height });
    await page.setContent(`
      <style>
        :root {
          --dis-bg: #070b10;
          --dis-surface: #111923;
          --dis-field: #0c141d;
          --dis-border: #293747;
          --dis-text-strong: #f8fafc;
          --dis-muted: #8ea0b5;
          --dis-muted-strong: #b8c4d2;
          --dis-blue: #7dd3fc;
          --dis-warning: #fbbf24;
          --dis-warning-soft: rgba(251, 191, 36, 0.12);
        }
        * { box-sizing: border-box; }
        html, body { width: 100%; height: 100%; margin: 0; overflow: hidden; }
        body { display: flex; background: var(--dis-bg); padding: 24px; font-family: Arial, sans-serif; }
        ${RADAR_CSS}
      </style>
      <section class="radarWorkbench radarWorkbenchWallboard" data-radar-kind="${scenario.kind}">
        <header class="radarHeader">
          <div class="radarHeading">
            <span class="sectionIcon"></span>
            <div>
              <span class="sectionKicker">Automatische beeldreeks uit lokaal opgeslagen brondata</span>
              <h2>${scenario.kind === 'precipitation' ? 'Buienradar' : 'Bliksemradar'}</h2>
              <p>De gekozen laag speelt zonder bediening af. Er wordt geen externe kaart ingebed.</p>
            </div>
          </div>
        </header>
        <div class="radarPanel">
          <div class="radarVisualColumn">
            <div class="radarStatusRow">
              <div><strong>Actuele radarreeks</strong><span>Gevalideerde broninformatie voor operationele oriëntatie</span></div>
              <span class="radarStatusBadge radarStatus_available">Actueel</span>
            </div>
            <div class="radarStage">
              <div class="radarCanvas" style="aspect-ratio: ${scenario.aspect}"></div>
            </div>
            <div class="radarLegend">
              <div><strong>Legenda</strong><span>Gevalideerde schaal</span></div>
              <ol><li>Laag</li><li>Matig</li><li>Hoog</li></ol>
              <small>Transparant betekent geen weergegeven detectie.</small>
            </div>
          </div>
          <aside class="radarControls">
            <div class="radarTimeReadout"><span>Detectievenster</span><strong>14:25</strong><small>23 juli 2026 14:25</small></div>
            <div class="radarAutoplayStatus"><span><strong>Beeldreeks speelt automatisch</strong><small>Tijdstap 5 van 25</small></span></div>
            <dl class="radarFacts">
              <div><dt>Actualiteit</dt><dd>2 minuten oud</dd></div>
              <div><dt>Referentietijd</dt><dd>23 juli 2026 14:00</dd></div>
              <div><dt>Bronvertraging</dt><dd>minder dan 1 minuut</dd></div>
              <div><dt>Bron</dt><dd>${scenario.kind === 'precipitation' ? 'KNMI' : 'EUMETSAT'}</dd></div>
              <div><dt>Licentie</dt><dd>Open data</dd></div>
            </dl>
          </aside>
        </div>
      </section>
    `);

    const measurements = await page.evaluate(() => {
      const root = document.querySelector<HTMLElement>('.radarWorkbenchWallboard');
      const panel = document.querySelector<HTMLElement>('.radarPanel');
      const stage = document.querySelector<HTMLElement>('.radarStage');
      const canvas = document.querySelector<HTMLElement>('.radarCanvas');
      if (root === null || panel === null || stage === null || canvas === null) return null;
      const rootRect = root.getBoundingClientRect();
      const panelRect = panel.getBoundingClientRect();
      const stageRect = stage.getBoundingClientRect();
      const canvasRect = canvas.getBoundingClientRect();
      return {
        rootBottom: rootRect.bottom,
        panelBottom: panelRect.bottom,
        viewportHeight: window.innerHeight,
        rootOverflowX: root.scrollWidth - root.clientWidth,
        rootOverflowY: root.scrollHeight - root.clientHeight,
        panelOverflowX: panel.scrollWidth - panel.clientWidth,
        panelOverflowY: panel.scrollHeight - panel.clientHeight,
        canvasWidth: canvasRect.width,
        canvasHeight: canvasRect.height,
        canvasInsideStage: canvasRect.left >= stageRect.left - 1
          && canvasRect.right <= stageRect.right + 1
          && canvasRect.top >= stageRect.top - 1
          && canvasRect.bottom <= stageRect.bottom + 1,
      };
    });

    expect(measurements).not.toBeNull();
    if (measurements === null) throw new Error('Radar layout was not rendered.');
    expect(measurements.rootBottom).toBeLessThanOrEqual(measurements.viewportHeight);
    expect(measurements.panelBottom).toBeLessThanOrEqual(measurements.rootBottom);
    expect(measurements.rootOverflowX).toBeLessThanOrEqual(1);
    expect(measurements.rootOverflowY).toBeLessThanOrEqual(1);
    expect(measurements.panelOverflowX).toBeLessThanOrEqual(1);
    expect(measurements.panelOverflowY).toBeLessThanOrEqual(1);
    expect(measurements.canvasWidth).toBeGreaterThan(100);
    expect(measurements.canvasHeight).toBeGreaterThan(100);
    expect(measurements.canvasInsideStage).toBe(true);
  });
}

function precipitationLayer(atlasUrl: string): OperationalWeatherRadarLayer {
  const reference = Date.parse('2026-07-23T12:00:00Z');
  return {
    status: 'available',
    reference_time: '2026-07-23T12:00:00Z',
    observed_period_end: null,
    age_seconds: 120,
    lag_seconds: 35,
    refreshed_at: '2026-07-23T12:01:00Z',
    atlas_url: atlasUrl,
    atlas_columns: 5,
    atlas_rows: 5,
    frame_width: 140,
    frame_height: 153,
    frames: Array.from({ length: 25 }, (_, index) => ({
      index,
      valid_at: new Date(reference + index * 5 * 60_000).toISOString(),
      lead_minutes: index * 5,
    })),
    source: { name: 'KNMI', url: 'https://www.knmi.nl', license: 'KNMI Open Data' },
    availability_note: null,
  };
}

function lightningLayer(atlasUrl: string): OperationalWeatherRadarLayer {
  return {
    status: 'available',
    reference_time: '2026-07-23T12:00:00Z',
    observed_period_end: '2026-07-23T12:05:00Z',
    age_seconds: 45,
    lag_seconds: 20,
    refreshed_at: '2026-07-23T12:06:00Z',
    atlas_url: atlasUrl,
    atlas_columns: 4,
    atlas_rows: 2,
    frame_width: 640,
    frame_height: 384,
    frames: [
      { index: 0, valid_at: '2026-07-23T11:55:00Z', lead_minutes: -5 },
      { index: 1, valid_at: '2026-07-23T12:00:00Z', lead_minutes: 0 },
    ],
    source: { name: 'EUMETSAT', url: 'https://www.eumetsat.int', license: 'EUMETSAT data policy' },
    availability_note: null,
  };
}
