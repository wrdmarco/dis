import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type { WallboardFocusState } from '../src/types/api';
import { wallboardTransientFocusIsComplete } from '../src/features/wallboards/WallboardDisplayPage';

function focusState(overrides: Partial<WallboardFocusState> = {}): WallboardFocusState {
  return {
    kind: 'preannouncement',
    focus_id: 'focus-1',
    dispatch_id: 'dispatch-1',
    incident_id: 'incident-1',
    reference: 'DIS-1',
    title: 'Nieuwe inzet',
    priority: 'normal',
    location_label: 'Utrecht',
    started_at: '2026-07-19T10:00:00Z',
    expires_at: '2026-07-19T10:02:00Z',
    visible: true,
    playlist_page_id: null,
    next_change_at: '2026-07-19T10:02:00Z',
    pilot_counts: { available: 2, relevant: 3, contacted: 3 },
    responses: {
      counts: { targeted: 3, contacted: 3, pending: 1, accepted: 1, declined: 1, no_response: 0 },
      items: [],
      coming: [],
    },
    is_preview: false,
    ...overrides,
  };
}

test('ends transient focus only after every selected recipient reaches a terminal response state', () => {
  expect(wallboardTransientFocusIsComplete(focusState())).toBe(false);
  expect(wallboardTransientFocusIsComplete(focusState({
    responses: {
      counts: { targeted: 3, contacted: 3, pending: 0, accepted: 2, declined: 1, no_response: 0 },
      items: [],
      coming: [],
    },
  }))).toBe(true);
  expect(wallboardTransientFocusIsComplete(focusState({
    kind: 'test_alarm',
    responses: {
      counts: { targeted: 4, contacted: 4, pending: 0, accepted: 4, declined: 0, no_response: 0 },
      items: [],
      coming: [],
    },
  }))).toBe(true);
});

test('keeps focus for zero-recipient, incomplete, preview and real-alarm states', () => {
  expect(wallboardTransientFocusIsComplete(focusState({
    responses: {
      counts: { targeted: 0, contacted: 0, pending: 0, accepted: 0, declined: 0, no_response: 0 },
      items: [],
      coming: [],
    },
  }))).toBe(false);
  expect(wallboardTransientFocusIsComplete(focusState({ responses: null }))).toBe(false);
  expect(wallboardTransientFocusIsComplete(focusState({
    is_preview: true,
    responses: {
      counts: { targeted: 3, contacted: 3, pending: 0, accepted: 3, declined: 0, no_response: 0 },
      items: [],
      coming: [],
    },
  }))).toBe(false);
  expect(wallboardTransientFocusIsComplete(focusState({
    kind: 'real_alarm',
    responses: {
      counts: { targeted: 3, contacted: 3, pending: 0, accepted: 3, declined: 0, no_response: 0 },
      items: [],
      coming: [],
    },
  }))).toBe(false);
});

test('uses split-screen only for a visible real-alarm response feed', () => {
  const source = readFileSync(new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url), 'utf8');

  expect(source).toContain("const inlineResponseKind = focus.kind === 'preannouncement' || focus.kind === 'test_alarm'");
  expect(source).toContain('const showInlineResponseSummary = inlineResponseKind !== null');
  expect(source).toContain("const showRealAlarmResponseFeed = focus.kind === 'real_alarm'");
  expect(source).toContain("${showRealAlarmResponseFeed ? ' wallboard-display__alarm--with-feed' : ''}");
  expect(source).toContain('{showRealAlarmResponseFeed ? (');
  expect(source).toContain('<WallboardFocusResponseSummary');
});

test('anchors the real-alarm feed at the top while keeping no-feed focus centered', async ({ page }) => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

  for (const screen of [
    { profile: '1080p', width: 1920, height: 1080 },
    { profile: '4k', width: 3840, height: 2160 },
  ] as const) {
    await page.setViewportSize({ width: screen.width, height: screen.height });
    await page.setContent(`
      <style>${styles} html, body { width: 100%; min-width: 0; margin: 0; overflow: hidden; }</style>
      <main class="wallboard-display wallboard-display--dark wallboard-display--profile-${screen.profile}">
        <header class="wallboard-display__header"><div><span class="wallboard-display__titles"><h1>Alarmering</h1></span></div></header>
        <section class="wallboard-display__alarm wallboard-display__alarm--focus wallboard-display__alarm--real-alarm wallboard-display__alarm--with-feed">
          <div class="wallboard-display__alarm-main"><h2>Operationele inzet</h2><p>Utrecht</p></div>
          <aside class="wallboard-display__responses">
            <header><span></span><div><small>Live stand</small><h3>Piloten onderweg</h3></div></header>
            <dl class="wallboard-display__response-counts"><div><dt>Gealarmeerd</dt><dd>8</dd></div><div><dt>Komen</dt><dd>3</dd></div></dl>
            <ol class="wallboard-display__response-list"><li class="wallboard-display__response wallboard-display__response--accepted"><span><strong>Piloot Een</strong><small>Komt</small></span><time>ETA 8 min</time></li></ol>
          </aside>
        </section>
        <footer class="wallboard-display__footer"><span>Focus actief</span></footer>
      </main>
    `);

    const split = await page.locator('.wallboard-display__alarm').evaluate((element) => {
      const focus = element.getBoundingClientRect();
      const main = element.querySelector('.wallboard-display__alarm-main')!.getBoundingClientRect();
      const feed = element.querySelector('.wallboard-display__responses')!.getBoundingClientRect();
      const computed = getComputedStyle(element);
      const contentTop = focus.top
        + Number.parseFloat(computed.borderTopWidth)
        + Number.parseFloat(computed.paddingTop);
      return {
        feedTopOffset: Math.abs(feed.top - contentTop),
        mainCenterOffset: Math.abs(((main.top + main.bottom) / 2) - ((focus.top + focus.bottom) / 2)),
        overlap: main.right > feed.left,
      };
    });

    expect(split.feedTopOffset).toBeLessThanOrEqual(2);
    expect(split.mainCenterOffset).toBeLessThanOrEqual(screen.height * 0.06);
    expect(split.overlap).toBe(false);

    await page.locator('.wallboard-display__alarm').evaluate((element) => {
      element.classList.remove('wallboard-display__alarm--with-feed');
      element.querySelector('.wallboard-display__responses')?.remove();
    });
    const centered = await page.locator('.wallboard-display__alarm').evaluate((element) => {
      const focus = element.getBoundingClientRect();
      const main = element.querySelector('.wallboard-display__alarm-main')!.getBoundingClientRect();
      return {
        horizontal: Math.abs(((main.left + main.right) / 2) - ((focus.left + focus.right) / 2)),
        vertical: Math.abs(((main.top + main.bottom) / 2) - ((focus.top + focus.bottom) / 2)),
      };
    });
    expect(centered.horizontal).toBeLessThanOrEqual(2);
    expect(centered.vertical).toBeLessThanOrEqual(2);
  }
});

test('keeps preannouncement and test-alarm response totals compact and centered', async ({ page }) => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');
  await page.setViewportSize({ width: 1920, height: 1080 });
  await page.setContent(`
    <style>${styles} html, body { width: 100%; min-width: 0; margin: 0; overflow: hidden; }</style>
    <main class="wallboard-display wallboard-display--dark wallboard-display--profile-1080p">
      <section class="wallboard-display__alarm wallboard-display__alarm--focus wallboard-display__alarm--preannouncement">
        <div class="wallboard-display__alarm-main">
          <h2>Vooraankondiging</h2>
          <dl class="wallboard-display__response-counts wallboard-display__response-counts--inline">
            <div><dt>Gealarmeerd</dt><dd>12</dd></div><div><dt>Beschikbaar</dt><dd>7</dd></div>
          </dl>
        </div>
      </section>
    </main>
  `);

  const metrics = await page.locator('.wallboard-display__alarm').evaluate((element) => {
    const focus = element.getBoundingClientRect();
    const summary = element.querySelector('.wallboard-display__response-counts--inline')!.getBoundingClientRect();
    return {
      columns: getComputedStyle(element).gridTemplateColumns.split(' ').length,
      centerOffset: Math.abs(((summary.left + summary.right) / 2) - ((focus.left + focus.right) / 2)),
      overflow: element.querySelector('.wallboard-display__response-counts--inline')!.scrollWidth
        > element.querySelector('.wallboard-display__response-counts--inline')!.clientWidth + 1,
    };
  });

  expect(metrics.columns).toBe(1);
  expect(metrics.centerOffset).toBeLessThanOrEqual(2);
  expect(metrics.overflow).toBe(false);
});

test('keeps the kiosk topbar passive and hides fullscreen controls once fullscreen is active', () => {
  const source = readFileSync(new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url), 'utf8');
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

  expect(source).not.toContain('wallboard-display__live');
  expect(source).not.toContain('Wallboard nu vernieuwen');
  expect(source).not.toContain('Scherm verlaten');
  expect(source).not.toContain('Volledig scherm verlaten');
  expect(source).toContain('{!fullscreen ? (');
  expect(source).toContain('data-wallboard-fullscreen-toggle');
  expect(source).toContain('<span>Volledig scherm</span>');
  expect(styles).not.toContain('.wallboard-display__live');
});
