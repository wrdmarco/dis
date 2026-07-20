import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import { WallboardDemoDataIndicator } from '../src/features/wallboards/WallboardDisplayPage';
import {
  normalizeWallboardPlaylistDataMode,
  wallboardPlaylistDataModeNeedsRefresh,
  wallboardPlaylistDataModeLabel,
  wallboardPlaylistOptionLabel,
} from '../src/features/wallboards/wallboardPlaylistDataMode';

test('normalizes legacy and malformed modes to live while preserving explicit demo mode', () => {
  expect(normalizeWallboardPlaylistDataMode('demo')).toBe('demo');
  expect(normalizeWallboardPlaylistDataMode('live')).toBe('live');
  expect(normalizeWallboardPlaylistDataMode(undefined)).toBe('live');
  expect(normalizeWallboardPlaylistDataMode('preview')).toBe('live');
  expect(wallboardPlaylistDataModeNeedsRefresh('live', 'demo')).toBe(true);
  expect(wallboardPlaylistDataModeNeedsRefresh('demo', 'live')).toBe(true);
  expect(wallboardPlaylistDataModeNeedsRefresh(undefined, 'live')).toBe(false);
  expect(wallboardPlaylistDataModeNeedsRefresh('demo', 'demo')).toBe(false);
  expect(wallboardPlaylistDataModeLabel('demo')).toBe('DEMO');
  expect(wallboardPlaylistDataModeLabel('live')).toBe('LIVE DATA');
  expect(wallboardPlaylistOptionLabel({ name: 'Meldkamer', data_mode: 'demo' })).toBe('Meldkamer · DEMO');
  expect(wallboardPlaylistOptionLabel({ name: 'Legacy' })).toBe('Legacy · LIVE DATA');
});

test('renders orange demo and green live pills with text, and marks only demo wallboards', () => {
  const pill = readFileSync(
    new URL('../src/features/wallboards/WallboardPlaylistDataModePill.tsx', import.meta.url),
    'utf8',
  );
  const display = readFileSync(
    new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url),
    'utf8',
  );

  expect(pill).toContain('wallboard-playlist-data-mode-pill--${normalizedMode}');
  expect(pill).toContain("tone={normalizedMode === 'demo' ? 'warn' : 'good'}");
  expect(pill).toContain('wallboardPlaylistDataModeLabel(normalizedMode)');
  expect(WallboardDemoDataIndicator({ dataMode: 'demo' })).not.toBeNull();
  expect(WallboardDemoDataIndicator({ dataMode: 'live' })).toBeNull();
  expect(WallboardDemoDataIndicator({ dataMode: 'demo', suppressed: true })).toBeNull();
  expect(display).toContain('className="wallboard-display__demo-indicator"');
  expect(display).toContain('<strong>DEMO</strong>');
  expect(display).toContain('<small>FICTIEVE DATA</small>');
});

test('sends the selected mode through create, update and the server-rendered concept preview', () => {
  const admin = readFileSync(
    new URL('../src/features/wallboards/WallboardsAdminPage.tsx', import.meta.url),
    'utf8',
  );
  const preview = readFileSync(
    new URL('../src/features/wallboards/WallboardPlaylistPreview.tsx', import.meta.url),
    'utf8',
  );
  const display = readFileSync(
    new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url),
    'utf8',
  );
  const apiTypes = readFileSync(new URL('../src/types/api.ts', import.meta.url), 'utf8');

  const createRequest = admin.slice(
    admin.indexOf("api.post<WallboardPlaylist>('/admin/wallboard-playlists'"),
    admin.indexOf('function replaceWallboard'),
  );
  const updateRequest = admin.slice(
    admin.indexOf('async function savePlaylist'),
    admin.indexOf('async function deletePlaylist'),
  );
  const previewRequest = admin.slice(
    admin.indexOf('const loadPreviewState'),
    admin.indexOf('return (', admin.indexOf('const loadPreviewState')),
  );

  expect(apiTypes).toContain("export type WallboardPlaylistDataMode = 'live' | 'demo';");
  expect(apiTypes).toContain('data_mode?: WallboardPlaylistDataMode;');
  expect(createRequest).toContain('data_mode: newPlaylistDataMode');
  expect(updateRequest).toContain('data_mode: draftDataMode');
  expect(previewRequest).toContain('data_mode: draftDataMode');
  expect(previewRequest).toContain('return response.data;');
  expect(admin).toContain('disabled={playlist.data_mode === \'demo\'}');
  expect(admin).toContain('niet beschikbaar voor actieve inzet');
  expect(preview).toContain("previewDataMode === 'demo' ? 'Demo-conceptpreview' : 'Live conceptpreview'");
  expect(preview).toContain('normalizeWallboardPlaylistDataMode(state?.wallboard.data_mode ?? dataMode)');
  expect(preview).toContain('<WallboardDemoDataIndicator dataMode={stageDataMode} />');
  expect(preview).toContain("stageDataMode === 'demo' ? 'Demo preview' : 'Live preview'");
  expect(preview).toContain('<WallboardPlaylistPageFrame');
  expect(display).toContain("data_mode: normalizeWallboardPlaylistDataMode(rawWallboard.data_mode)");
  expect(display).toContain('suppressed={maintenance !== null}');

  const modeTransitionGuard = display.slice(
    display.indexOf('if (dataModeTransitionPending && maintenance === null)'),
    display.indexOf('const displayProfile', display.indexOf('if (dataModeTransitionPending && maintenance === null)')),
  );
  expect(modeTransitionGuard).toContain('<WallboardDemoDataIndicator dataMode={targetDataMode} />');
  expect(modeTransitionGuard).toContain('<WallboardPreloadScreen');
  expect(modeTransitionGuard).toContain('status="loading"');
  expect(modeTransitionGuard).toContain('data-mode-transition="pending"');
  expect(modeTransitionGuard).not.toContain('<WallboardPlaylistPageFrame');
  expect(modeTransitionGuard).not.toContain('state.wallboard.data_mode');
});

test('keeps provenance pills and the persistent demo warning inside mobile, Full HD and 4K layouts', async ({ page }) => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

  for (const viewport of [
    { width: 375, height: 812, profile: 'auto' },
    { width: 1920, height: 1080, profile: '1080p' },
    { width: 3840, height: 2160, profile: '4k' },
  ]) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.setContent(`
      <style>${styles} html, body { width: 100%; min-width: 0; margin: 0; overflow: hidden; }</style>
      <main class="wallboard-display wallboard-display--dark wallboard-display--profile-${viewport.profile}">
        <span class="wallboard-display__demo-indicator" aria-label="Demomodus: fictieve gegevens">
          <svg width="18" height="18" aria-hidden="true"></svg><strong>DEMO</strong><small>FICTIEVE DATA</small>
        </span>
        <header class="wallboard-display__header"><div><span class="wallboard-display__titles"><h1>Operationeel overzicht met een lange titel</h1></span></div><div class="wallboard-display__controls"><time class="wallboard-display__clock"><span>12:34:56</span><small>maandag 20 juli 2026</small></time></div></header>
        <section style="min-height:0;flex:1"></section><footer class="wallboard-display__footer"><span>Pagina 1 van 4</span></footer>
      </main>
      <section style="position:fixed;left:12px;bottom:12px;width:min(351px,calc(100vw - 24px))">
        <button class="wallboard-list-card" type="button">
          <span class="wallboard-list-card__icon"></span>
          <span class="wallboard-list-card__copy"><strong>Operationeel standaardprogramma met een zeer lange naam</strong><small>12 pagina's · versie 7</small></span>
          <span class="wallboard-list-card__pills">
            <span class="wallboard-playlist-data-mode-pill wallboard-playlist-data-mode-pill--demo"><span class="status-pill status-pill--warn">DEMO</span></span>
            <span class="wallboard-playlist-data-mode-pill wallboard-playlist-data-mode-pill--live"><span class="status-pill status-pill--good">LIVE DATA</span></span>
            <span class="status-pill status-pill--neutral">3 schermen</span>
          </span>
        </button>
      </section>
    `);

    const measurement = await page.evaluate(() => {
      const root = document.querySelector('.wallboard-display')!.getBoundingClientRect();
      const indicator = document.querySelector('.wallboard-display__demo-indicator')!.getBoundingClientRect();
      const header = document.querySelector('.wallboard-display__header')!.getBoundingClientRect();
      const card = document.querySelector('.wallboard-list-card') as HTMLElement;
      const pills = document.querySelector('.wallboard-list-card__pills')!.getBoundingClientRect();
      const demoPill = document.querySelector('.wallboard-playlist-data-mode-pill--demo .status-pill') as HTMLElement;
      const livePill = document.querySelector('.wallboard-playlist-data-mode-pill--live .status-pill') as HTMLElement;
      return {
        documentOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        cardOverflow: card.scrollWidth > card.clientWidth,
        indicatorInside: indicator.left >= root.left
          && indicator.top >= root.top
          && indicator.right <= root.right
          && indicator.bottom <= root.bottom,
        indicatorBelowHeader: indicator.top >= header.bottom,
        pillsInside: pills.right <= card.getBoundingClientRect().right,
        demoBackground: getComputedStyle(demoPill).backgroundColor,
        demoBorder: getComputedStyle(demoPill).borderTopColor,
        liveBackground: getComputedStyle(livePill).backgroundColor,
        liveColor: getComputedStyle(livePill).color,
      };
    });

    expect(measurement.documentOverflow).toBe(false);
    expect(measurement.cardOverflow).toBe(false);
    expect(measurement.indicatorInside).toBe(true);
    expect(measurement.indicatorBelowHeader).toBe(true);
    expect(measurement.pillsInside).toBe(true);
    expect(measurement.demoBackground).toBe('rgb(124, 45, 18)');
    expect(measurement.demoBorder).toBe('rgb(251, 146, 60)');
    expect(measurement.liveBackground).toBe('rgb(17, 51, 39)');
    expect(measurement.liveColor).toBe('rgb(167, 243, 208)');
  }

  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
  const lightDemoColors = await page.locator('.wallboard-playlist-data-mode-pill--demo .status-pill').evaluate((element) => ({
    background: getComputedStyle(element).backgroundColor,
    border: getComputedStyle(element).borderTopColor,
    color: getComputedStyle(element).color,
  }));
  expect(lightDemoColors).toEqual({
    background: 'rgb(255, 237, 213)',
    border: 'rgb(249, 115, 22)',
    color: 'rgb(154, 52, 18)',
  });
});
