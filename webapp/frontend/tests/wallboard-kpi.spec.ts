import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type { WallboardKpiKey, WallboardPage } from '../src/types/api';
import {
  normalizeWallboardKpiState,
  wallboardKpiGridColumns,
  wallboardKpiHasDistribution,
} from '../src/features/wallboards/WallboardDisplayPage';
import {
  DEFAULT_WALLBOARD_KPI_VISUALIZATIONS,
  DEFAULT_WALLBOARD_KPI_VISIBLE_METRICS,
  MAX_WALLBOARD_KPI_CHARTS,
  WALLBOARD_KPI_DEFINITIONS,
  WALLBOARD_KPI_KEYS,
  createWallboardPage,
  normalizeWallboardKpiPageOptions,
  wallboardKpiDefaultVisualization,
  wallboardKpiSupportedVisualizations,
} from '../src/features/wallboards/wallboardPresentation';

const CORE_METRICS = [
  'pilots_available',
  'pilots_unavailable',
  'pilots_total',
  'pilot_availability_rate',
  'incidents_total',
  'incidents_active',
  'incidents_dispatching',
  'incidents_in_progress',
  'incidents_low',
  'incidents_normal',
  'incidents_high',
  'incidents_critical',
  'assets_total',
  'assets_ready',
  'assets_maintenance',
  'assets_unavailable',
  'assets_issues',
  'responses_targeted',
  'responses_contacted',
  'responses_pending',
  'responses_accepted',
  'responses_declined',
  'responses_no_response',
] as const satisfies readonly WallboardKpiKey[];

const ALL_METRICS = [
  'pilots_available',
  'pilots_unavailable',
  'pilots_total',
  'pilot_availability_rate',
  'pilots_en_route',
  'pilots_on_scene',
  'pilots_push_disabled',
  'incidents_total',
  'incidents_registered_total',
  'incidents_active',
  'incidents_dispatching',
  'incidents_in_progress',
  'incidents_low',
  'incidents_normal',
  'incidents_high',
  'incidents_critical',
  'incidents_opened_today',
  'incidents_resolved_today',
  'incidents_cancelled_today',
  'incidents_resolved_total',
  'incidents_cancelled_total',
  'assets_total',
  'assets_ready',
  'assets_maintenance',
  'assets_unavailable',
  'assets_issues',
  'drones_total',
  'drones_ready',
  'responses_targeted',
  'responses_contacted',
  'responses_pending',
  'responses_accepted',
  'responses_declined',
  'responses_no_response',
  'dispatches_active',
  'dispatch_acceptance_rate',
  'flight_reports_this_month',
  'flight_minutes_this_month',
  'average_flight_minutes_this_month',
  'drones_flown_distribution',
  'incidents_by_province',
  'incidents_by_country',
] as const satisfies readonly WallboardKpiKey[];

const PROVINCE_SEGMENTS = [
  { label: 'Drenthe', value: 1 },
  { label: 'Flevoland', value: 2 },
  { label: 'Friesland', value: 3 },
  { label: 'Gelderland', value: 4 },
  { label: 'Groningen', value: 5 },
  { label: 'Limburg', value: 6 },
  { label: 'Noord-Brabant', value: 7 },
  { label: 'Noord-Holland', value: 8 },
  { label: 'Overijssel', value: 9 },
  { label: 'Utrecht', value: 10 },
  { label: 'Zeeland', value: 11 },
  { label: 'Zuid-Holland', value: 12 },
  { label: 'Onbekend', value: 3 },
] as const;

function kpiPage(
  visibleMetrics?: WallboardKpiKey[],
  metricVisualizations?: WallboardPage['options']['metric_visualizations'],
): WallboardPage {
  return {
    id: 'kpi-main',
    type: 'kpi',
    name: 'Operationele KPI’s',
    duration_seconds: 30,
    options: {
      ...(visibleMetrics === undefined ? {} : { visible_metrics: visibleMetrics }),
      ...(metricVisualizations === undefined ? {} : { metric_visualizations: metricVisualizations }),
    },
  };
}

test('keeps all KPI switches in canonical order, while new pages start with the readable core set', () => {
  expect(WALLBOARD_KPI_KEYS).toEqual(ALL_METRICS);
  expect(WALLBOARD_KPI_DEFINITIONS.map((definition) => definition.key)).toEqual(ALL_METRICS);
  expect(new Set(WALLBOARD_KPI_KEYS).size).toBe(42);
  expect(DEFAULT_WALLBOARD_KPI_VISIBLE_METRICS).toEqual(CORE_METRICS);
  expect(normalizeWallboardKpiPageOptions(kpiPage()).visible_metrics).toEqual(ALL_METRICS);
  expect(DEFAULT_WALLBOARD_KPI_VISUALIZATIONS.drones_flown_distribution).toBe('pie');
  expect(DEFAULT_WALLBOARD_KPI_VISUALIZATIONS.incidents_by_province).toBe('bar');
  expect(DEFAULT_WALLBOARD_KPI_VISUALIZATIONS.incidents_by_country).toBe('bar');
  expect(DEFAULT_WALLBOARD_KPI_VISUALIZATIONS.pilots_available).toBe('counter');
  expect(MAX_WALLBOARD_KPI_CHARTS).toBe(6);
  expect(createWallboardPage('kpi', 1)).toMatchObject({
    type: 'kpi',
    name: 'KPI-overzicht',
    options: { visible_metrics: CORE_METRICS },
  });
});

test('normalizes each semantic KPI visualization without inventing a denominator', () => {
  const normalized = normalizeWallboardKpiPageOptions(kpiPage(
    ['pilots_available', 'incidents_registered_total', 'incidents_by_country'],
    {
      pilots_available: 'ring',
      incidents_registered_total: 'pie',
      incidents_by_country: 'ring',
    },
  ));
  expect(normalized.metric_visualizations).toMatchObject({
    pilots_available: 'ring',
    incidents_registered_total: 'counter',
    incidents_by_country: 'ring',
  });
  expect(wallboardKpiSupportedVisualizations('pilots_available')).toEqual(['counter', 'bar', 'pie', 'ring']);
  expect(wallboardKpiSupportedVisualizations('incidents_registered_total')).toEqual(['counter']);
  expect(wallboardKpiDefaultVisualization('drones_flown_distribution')).toBe('pie');
});

test('treats a zero-denominator bar as unavailable rather than known zero data', () => {
  expect(wallboardKpiHasDistribution([
    { label: 'Beschikbaar', value: 0 },
    { label: 'Niet beschikbaar', value: 0 },
  ])).toBe(false);
  expect(wallboardKpiHasDistribution([
    { label: 'Beschikbaar', value: 1 },
    { label: 'Niet beschikbaar', value: 0 },
  ])).toBe(true);
});

test('preserves an explicit empty selection and canonicalizes unknown or duplicate metric keys', () => {
  expect(normalizeWallboardKpiPageOptions(kpiPage([])).visible_metrics).toEqual([]);

  const untrustedMetrics = [
    'dispatch_acceptance_rate',
    'unknown_metric',
    'pilots_available',
    'dispatch_acceptance_rate',
  ] as unknown as WallboardKpiKey[];
  expect(normalizeWallboardKpiPageOptions(kpiPage(untrustedMetrics)).visible_metrics).toEqual([
    'pilots_available',
    'dispatch_acceptance_rate',
  ]);
});

test('uses even grid widths so distribution charts pack without dead columns', () => {
  expect(wallboardKpiGridColumns([{ visualization: 'pie' }])).toBe(2);
  expect(wallboardKpiGridColumns([{ visualization: 'bar' }])).toBe(2);
  expect(wallboardKpiGridColumns([{ visualization: 'ring' }])).toBe(2);
  expect(wallboardKpiGridColumns([{ visualization: 'counter' }])).toBe(1);
  expect(wallboardKpiGridColumns(Array.from({ length: 2 }, () => ({ visualization: 'pie' as const })))).toBe(4);
  expect(wallboardKpiGridColumns(Array.from({ length: 4 }, () => ({ visualization: 'bar' as const })))).toBe(4);
  expect(wallboardKpiGridColumns(Array.from({ length: 6 }, () => ({ visualization: 'ring' as const })))).toBe(6);
});

test('lays out two and six charts side-by-side in complete rows', async ({ page }) => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

  for (const chartCount of [2, 6]) {
    const columns = wallboardKpiGridColumns(
      Array.from({ length: chartCount }, () => ({ visualization: 'pie' as const })),
    );
    const cards = Array.from(
      { length: chartCount },
      (_, index) => `<article class="wallboard-display__kpi-card wallboard-display__kpi-card--flight wallboard-display__kpi-card--pie">Diagram ${index + 1}</article>`,
    ).join('');

    await page.setViewportSize({ width: 1280, height: 800 });
    await page.setContent(`
      <style>${styles} html, body { width: 100%; height: 100%; min-width: 0; margin: 0; } .wallboard-display__kpi-grid { width: 1200px; height: 700px; }</style>
      <div class="wallboard-display__kpi-grid" style="--wallboard-kpi-columns:${columns}">${cards}</div>
    `);

    const positions = await page.locator('.wallboard-display__kpi-card').evaluateAll((elements) => elements.map((element) => {
      const rect = element.getBoundingClientRect();
      return { left: Math.round(rect.left), top: Math.round(rect.top) };
    }));
    const rowCount = new Set(positions.map(({ top }) => top)).size;
    const cardsPerFirstRow = positions.filter(({ top }) => top === positions[0].top).length;

    expect(rowCount).toBe(chartCount === 2 ? 1 : 2);
    expect(cardsPerFirstRow).toBe(chartCount === 2 ? 2 : 3);
  }
});

test('normalizes numeric, unknown, pie and thirteen-segment bar KPI payloads defensively', () => {
  expect(normalizeWallboardKpiState({
    generated_at: '2026-07-20T10:30:00Z',
    pages: {
      'kpi-main': {
        metrics: [
          { key: 'pilots_available', label: ' Beschikbaar ', value: 12, unit: null, category: 'pilots' },
          { key: 'assets_issues', label: 'Middelproblemen', value: null, unit: null, category: 'assets' },
          {
            key: 'drones_flown_distribution',
            label: 'Gevlogen per drone',
            value: 12,
            unit: 'vluchten',
            category: 'flight',
            context: 'Deze maand',
            visualization: 'pie',
            segments: [
              { label: 'DJI M30T', value: 7 },
              { label: 'DJI Mavic 3T', value: 5 },
              { label: '', value: 4 },
              { label: 'Ongeldig', value: -1 },
            ],
          },
          {
            key: 'incidents_by_province',
            label: 'Incidenten per provincie',
            value: 81,
            unit: 'incidenten',
            category: 'incidents',
            context: 'Alle registraties',
            visualization: 'bar',
            segments: [
              { label: '', value: 99 },
              ...PROVINCE_SEGMENTS,
              { label: 'Buiten grens', value: 99 },
            ],
          },
          { key: 'pilots_available', label: 'Dubbel', value: 99, unit: null, category: 'pilots' },
          { key: 'incidents_active', label: 'Verkeerde categorie', value: 1, unit: null, category: 'assets' },
          { key: 'not_supported', label: 'Onbekend', value: 1, unit: null, category: 'assets' },
        ],
      },
    },
  })).toEqual({
    generated_at: '2026-07-20T10:30:00Z',
    pages: {
      'kpi-main': {
        metrics: [
          { key: 'pilots_available', label: 'Beschikbaar', value: 12, unit: null, category: 'pilots', context: null, visualization: 'counter' },
          { key: 'assets_issues', label: 'Middelproblemen', value: null, unit: null, category: 'assets', context: null, visualization: 'counter' },
          {
            key: 'drones_flown_distribution',
            label: 'Gevlogen per drone',
            value: 12,
            unit: 'vluchten',
            category: 'flight',
            context: 'Deze maand',
            visualization: 'pie',
            segments: [
              { label: 'DJI M30T', value: 7 },
              { label: 'DJI Mavic 3T', value: 5 },
            ],
          },
          {
            key: 'incidents_by_province',
            label: 'Incidenten per provincie',
            value: 81,
            unit: 'incidenten',
            category: 'incidents',
            context: 'Alle registraties',
            visualization: 'bar',
            segments: PROVINCE_SEGMENTS,
          },
        ],
      },
    },
  });
  expect(normalizeWallboardKpiState(undefined)).toEqual({ generated_at: '', pages: {} });
});

test('uses native independently controlled KPI checkboxes and the shared live renderer', () => {
  const editor = readFileSync(
    new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url),
    'utf8',
  );
  const display = readFileSync(
    new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url),
    'utf8',
  );
  const preview = readFileSync(
    new URL('../src/features/wallboards/WallboardPlaylistPreview.tsx', import.meta.url),
    'utf8',
  );

  expect(editor).toContain("{ value: 'kpi', label: 'KPI-overzicht' }");
  expect(editor).toContain('WALLBOARD_KPI_DEFINITIONS.filter');
  expect(editor).toContain('type="checkbox"');
  expect(editor).toContain('checked={enabled}');
  expect(editor).toContain('kies de gewenste weergave');
  expect(editor).toContain("counter: 'Teller'");
  expect(editor).toContain("bar: 'Staafdiagram'");
  expect(editor).toContain("pie: 'Taartdiagram'");
  expect(editor).toContain("ring: 'Ringdiagram'");
  expect(editor).toContain('MAX_WALLBOARD_KPI_CHARTS');
  expect(editor).toContain('Alle KPI&apos;s staan uit.');
  expect(editor).not.toContain('role="switch"');
  expect(display).toContain("if (page.type === 'kpi')");
  expect(display).toContain('<WallboardKpiPage');
  expect(display).toContain("metric.value === null ? '—'");
  expect(display).toContain("metric.visualization === 'pie'");
  expect(display).toContain("metric.visualization === 'bar'");
  expect(display).toContain("metric.visualization === 'ring'");
  expect(display).toContain('wallboard-display__kpi-pie-marker');
  expect(display).toContain('wallboard-display__kpi-pie-legend--multicolumn');
  expect(display).toContain('wallboard-display__kpi-bar-list');
  expect(display).toContain('wallboard-display__kpi-card--${metric.category}');
  expect(preview).toContain('<WallboardPlaylistPageFrame');
});

test('fits all forty-two KPI cards without clipping at compact, Full HD and Ultra HD profiles', async ({ page }) => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');
  const pieSegments = PROVINCE_SEGMENTS.map((segment, index) => `
    <li>
      <i style="border-color:hsl(${index * 28} 72% 48%)">${index + 1}</i>
      <span>${segment.label}</span>
      <strong>${segment.value}</strong>
    </li>
  `).join('');
  const droneSegments = PROVINCE_SEGMENTS.slice(0, 10).map((segment, index) => `
    <li>
      <i style="border-color:hsl(${index * 32} 72% 48%)">${index + 1}</i>
      <span>Dronefabrikant ${segment.label}</span>
      <strong>${segment.value}</strong>
    </li>
  `).join('');
  const provinceBars = PROVINCE_SEGMENTS.map((segment, index) => `
    <li>
      <span>${segment.label}</span>
      <i><b style="background:hsl(${index * 28} 72% 48%);width:${(segment.value / 12) * 100}%"></b></i>
      <strong>${segment.value}</strong>
    </li>
  `).join('');
  const cards = WALLBOARD_KPI_DEFINITIONS.map((metric, index) => {
    const pie = metric.key === 'drones_flown_distribution' || metric.key === 'incidents_by_province';
    const ring = ['pilots_available', 'pilot_availability_rate', 'assets_ready'].includes(metric.key);
    const bar = metric.key === 'incidents_by_country';
    const legendSegments = metric.key === 'incidents_by_province'
      ? pieSegments
      : metric.key === 'drones_flown_distribution'
        ? droneSegments
        : '<li><i style="border-color:#38bdf8">1</i><span>Beschikbaar</span><strong>10</strong></li><li><i style="border-color:#a78bfa">2</i><span>Overig</span><strong>6</strong></li>';
    const legendCount = metric.key === 'incidents_by_province'
      ? PROVINCE_SEGMENTS.length
      : metric.key === 'drones_flown_distribution'
        ? 10
        : 2;
    return `
      <article class="wallboard-display__kpi-card wallboard-display__kpi-card--${metric.category}${pie ? ' wallboard-display__kpi-card--pie' : ''}${ring ? ' wallboard-display__kpi-card--ring' : ''}${bar ? ' wallboard-display__kpi-card--bar' : ''}">
        <header><span></span><div><small>${metric.category}</small><strong>${metric.label}</strong></div></header>
        ${pie || ring ? `
          <div class="wallboard-display__kpi-pie-layout">
            <div class="wallboard-display__kpi-pie${ring ? ' wallboard-display__kpi-pie--ring' : ''}" style="background: conic-gradient(#38bdf8 0 50%, #a78bfa 50% 80%, #34d399 80% 100%)">${ring ? '<span><strong>50</strong><small>%</small></span>' : ''}</div>
            <ul class="wallboard-display__kpi-pie-legend${legendCount > 5 ? ' wallboard-display__kpi-pie-legend--multicolumn' : ''}">${legendSegments}</ul>
          </div>
          <p class="wallboard-display__kpi-context">${ring ? 'Deel van totaal' : 'Deze maand'}</p>
        ` : bar ? `
          <ul class="wallboard-display__kpi-bar-list" style="--wallboard-kpi-segments:13">${provinceBars}</ul>
          <p class="wallboard-display__kpi-context">Alle registraties</p>
        ` : `<p class="wallboard-display__kpi-value"><strong>${index * 17}</strong>${metric.key.includes('rate') ? '<span>%</span>' : ''}</p>`}
      </article>
    `;
  }).join('');

  for (const screen of [
    { profile: 'auto', theme: 'dark', width: 1280, height: 800 },
    { profile: 'auto', theme: 'light', width: 1280, height: 800 },
    { profile: '1080p', theme: 'dark', width: 1920, height: 1080 },
    { profile: '4k', theme: 'dark', width: 3840, height: 2160 },
  ] as const) {
    await page.setViewportSize({ width: screen.width, height: screen.height });
    await page.setContent(`
      <style>${styles} html, body { width: 100%; height: 100%; min-width: 0; margin: 0; overflow: hidden; }</style>
      <main class="wallboard-display wallboard-display--${screen.theme} wallboard-display--profile-${screen.profile}" data-display-profile="${screen.profile}">
        <header class="wallboard-display__header"><div><span class="wallboard-display__titles"><h1>Operationele KPI’s</h1></span></div><time class="wallboard-display__clock"><span>12:34:56</span><small>maandag 20 juli 2026</small></time></header>
        <section class="wallboard-display__page">
          <section class="wallboard-display__kpi-page">
            <header class="wallboard-display__kpi-heading"><span>Live operationele kengetallen</span><time>Bijgewerkt 12:34</time></header>
            <div class="wallboard-display__kpi-grid wallboard-display__kpi-grid--dense" style="--wallboard-kpi-columns: 8">${cards}</div>
          </section>
        </section>
        <footer class="wallboard-display__footer"><span>Pagina 1 van 1</span></footer>
      </main>
    `);

    const metrics = await page.locator('.wallboard-display').evaluate((element) => {
      const grid = element.querySelector<HTMLElement>('.wallboard-display__kpi-grid')!;
      const cards = [...grid.querySelectorAll<HTMLElement>('.wallboard-display__kpi-card')];
      const gridRect = grid.getBoundingClientRect();
      return {
        pageOverflow: element.scrollWidth > element.clientWidth + 1 || element.scrollHeight > element.clientHeight + 1,
        gridOverflow: grid.scrollWidth > grid.clientWidth + 1 || grid.scrollHeight > grid.clientHeight + 1,
        cardsInside: cards.every((card) => {
          const rect = card.getBoundingClientRect();
          return rect.left >= gridRect.left - 1 && rect.right <= gridRect.right + 1
            && rect.top >= gridRect.top - 1 && rect.bottom <= gridRect.bottom + 1;
        }),
        cardsUnclipped: cards.every((card) => card.scrollHeight <= card.clientHeight + 1),
        counterContentSeparated: cards
          .filter((card) => !card.matches('.wallboard-display__kpi-card--pie, .wallboard-display__kpi-card--ring, .wallboard-display__kpi-card--bar'))
          .every((card) => {
            const header = card.querySelector('header')?.getBoundingClientRect();
            const value = card.querySelector('.wallboard-display__kpi-value')?.getBoundingClientRect();
            return header !== undefined && value !== undefined && header.bottom <= value.top + 1;
          }),
        distributionSegments: element.querySelectorAll('.wallboard-display__kpi-bar-list li').length,
        legendsFit: [...element.querySelectorAll<HTMLElement>('.wallboard-display__kpi-pie-legend')]
          .every((legend) => legend.scrollHeight <= legend.clientHeight + 1
            && [...legend.querySelectorAll<HTMLElement>('li')].every((item) => {
              const legendRect = legend.getBoundingClientRect();
              const itemRect = item.getBoundingClientRect();
              return itemRect.top >= legendRect.top - 1
                && itemRect.bottom <= legendRect.bottom + 1
                && itemRect.left >= legendRect.left - 1
                && itemRect.right <= legendRect.right + 1;
            })),
        unreadableLegendLabels: [...element.querySelectorAll<HTMLElement>('.wallboard-display__kpi-pie-legend--multicolumn span')]
          .flatMap((label) => {
            const textRange = document.createRange();
            textRange.selectNodeContents(label);
            const lineCount = new Set(
              [...textRange.getClientRects()]
                .filter((rect) => rect.width > 0 && rect.height > 0)
                .map((rect) => Math.round(rect.top * 10)),
            ).size;
            const readable = label.scrollWidth <= label.clientWidth + 1
              && label.scrollHeight <= label.clientHeight + 1
              && lineCount <= 2;

            return readable ? [] : [{
              text: label.textContent?.trim() ?? '',
              lineCount,
              clientWidth: label.clientWidth,
              scrollWidth: label.scrollWidth,
              clientHeight: label.clientHeight,
              scrollHeight: label.scrollHeight,
            }];
          }),
      };
    });

    expect(metrics, `${screen.width}x${screen.height} ${screen.profile}/${screen.theme}`).toEqual({
      pageOverflow: false,
      gridOverflow: false,
      cardsInside: true,
      cardsUnclipped: true,
      counterContentSeparated: true,
      distributionSegments: 13,
      legendsFit: true,
      unreadableLegendLabels: [],
    });

    if (screen.profile === '4k') {
      const chartSizing = await page.locator('.wallboard-display').evaluate((element) => {
        const barLabel = element.querySelector<HTMLElement>('.wallboard-display__kpi-bar-list li');
        const pie = element.querySelector<HTMLElement>('.wallboard-display__kpi-pie');

        return {
          barFontSize: barLabel === null ? 0 : Number.parseFloat(getComputedStyle(barLabel).fontSize),
          pieWidth: pie?.getBoundingClientRect().width ?? 0,
        };
      });
      expect(chartSizing.barFontSize).toBeGreaterThanOrEqual(16);
      expect(chartSizing.pieWidth).toBeGreaterThanOrEqual(240);
    }

    if (screen.theme === 'light') {
      await expect(page.locator('.wallboard-display__kpi-card--pilots header small').first()).toHaveCSS('color', 'rgb(3, 105, 161)');
    }
  }
});
