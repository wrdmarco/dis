import { readFileSync } from 'node:fs';
import { expect, test, type Page } from 'playwright/test';

const globalStyles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

const mobileViewports = [
  { width: 320, height: 568 },
  { width: 390, height: 844 },
  { width: 430, height: 932 },
] as const;

const desktopViewports = [
  { width: 1920, height: 1080 },
  { width: 3840, height: 2160 },
] as const;

test('shared application surfaces stay contained from 320px through 4K', async ({ page }) => {
  for (const viewport of [...mobileViewports, ...desktopViewports]) {
    await page.setViewportSize(viewport);
    await page.setContent(applicationSurfaceDocument());

    const layout = await page.evaluate(() => {
      const tableWrap = document.querySelector<HTMLElement>('[data-testid="table-wrap"]');
      const liveMap = document.querySelector<HTMLElement>('.live-map');
      const canvas = document.querySelector<HTMLElement>('.live-map__canvas');
      const controls = Array.from(document.querySelectorAll<HTMLElement>('[data-touch-target]'));

      if (!tableWrap || !liveMap || !canvas) {
        throw new Error('Responsive test fixture is incomplete.');
      }

      const liveMapRect = liveMap.getBoundingClientRect();
      const canvasRect = canvas.getBoundingClientRect();

      return {
        documentFits: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
        tableScrollsLocally: tableWrap.scrollWidth > tableWrap.clientWidth,
        tableOverflow: getComputedStyle(tableWrap).overflowX,
        controls: controls.map((control) => ({
          height: control.getBoundingClientRect().height,
          label: `${control.tagName.toLowerCase()}.${control.className || 'unclassed'}`,
        })),
        canvasFits: canvasRect.left >= liveMapRect.left
          && canvasRect.right <= liveMapRect.right + 1
          && canvasRect.width <= liveMap.clientWidth,
      };
    });

    expect(layout.documentFits, `${viewport.width}px document overflow`).toBe(true);
    expect(layout.canvasFits, `${viewport.width}px live map overflow`).toBe(true);

    if (viewport.width <= 430) {
      expect(layout.tableScrollsLocally, `${viewport.width}px table should scroll inside its wrapper`).toBe(true);
      expect(['auto', 'scroll']).toContain(layout.tableOverflow);
      expect(layout.controls.length).toBeGreaterThan(0);
      for (const control of layout.controls) {
        expect(control.height, `${viewport.width}px ${control.label} touch-target height`).toBeGreaterThanOrEqual(44);
      }
    }
  }
});

for (const viewport of mobileViewports) {
  test(`wallboard calendar becomes one scrollable column at ${viewport.width}px`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await page.setContent(wallboardCalendarDocument());

    const calendar = page.locator('.wallboard-display__calendar');
    const nextEvent = page.locator('.wallboard-display__calendar-next');
    const upcoming = page.locator('.wallboard-display__calendar-upcoming');

    const layout = await calendar.evaluate((element) => {
      const nextRect = element.querySelector('.wallboard-display__calendar-next')?.getBoundingClientRect();
      const upcomingRect = element.querySelector('.wallboard-display__calendar-upcoming')?.getBoundingClientRect();

      if (!nextRect || !upcomingRect) {
        throw new Error('Calendar columns are missing.');
      }

      return {
        documentFits: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
        overflowY: getComputedStyle(element).overflowY,
        hasScrollableContent: element.scrollHeight > element.clientHeight,
        upcomingFollowsNext: upcomingRect.top >= nextRect.bottom - 1,
      };
    });

    await expect(nextEvent).toBeVisible();
    await expect(upcoming).toBeVisible();
    expect(layout.documentFits).toBe(true);
    expect(['auto', 'scroll']).toContain(layout.overflowY);
    expect(layout.hasScrollableContent).toBe(true);
    expect(layout.upcomingFollowsNext).toBe(true);
  });
}

test('login and wallboard pairing controls scale materially from Full HD to 4K', async ({ page }) => {
  const fullHd = await measureEntrySurfaces(page, desktopViewports[0]);
  const ultraHd = await measureEntrySurfaces(page, desktopViewports[1]);

  expect(fullHd.documentFits).toBe(true);
  expect(ultraHd.documentFits).toBe(true);
  expect(ultraHd.loginPanelWidth).toBeGreaterThanOrEqual(fullHd.loginPanelWidth * 1.3);
  expect(ultraHd.loginInputHeight).toBeGreaterThanOrEqual(fullHd.loginInputHeight + 8);
  expect(ultraHd.loginButtonHeight).toBeGreaterThanOrEqual(fullHd.loginButtonHeight + 6);
  expect(ultraHd.pairingCardWidth).toBeGreaterThanOrEqual(fullHd.pairingCardWidth * 1.4);
  expect(ultraHd.pairingCodeFontSize).toBeGreaterThanOrEqual(fullHd.pairingCodeFontSize * 1.35);
});

test('4K wallboard profiles scale without leaking into an explicit 1080p forecast', async ({ page }) => {
  await page.setViewportSize(desktopViewports[1]);
  await page.setContent(wallboardProfileDocument());

  const profileSizing = await page.evaluate(() => {
    const numericStyle = (selector: string, property: 'fontSize' | 'paddingTop' | 'rowGap') => {
      const element = document.querySelector<HTMLElement>(selector);
      if (!element) {
        throw new Error(`Missing ${selector}`);
      }

      return Number.parseFloat(getComputedStyle(element)[property]);
    };

    return {
      message1080FontSize: numericStyle('[data-profile="1080p"] .wallboard-display__message h2', 'fontSize'),
      message4kFontSize: numericStyle('[data-profile="4k"] .wallboard-display__message h2', 'fontSize'),
      forecast1080Padding: numericStyle('[data-profile="1080p"] .wallboard-display__forecast', 'paddingTop'),
      forecast1080Gap: numericStyle('[data-profile="1080p"] .wallboard-display__forecast', 'rowGap'),
      forecast4kPadding: numericStyle('[data-profile="4k"] .wallboard-display__forecast', 'paddingTop'),
      forecast4kGap: numericStyle('[data-profile="4k"] .wallboard-display__forecast', 'rowGap'),
      forecastAutoPadding: numericStyle('[data-profile="auto"] .wallboard-display__forecast', 'paddingTop'),
      forecastAutoGap: numericStyle('[data-profile="auto"] .wallboard-display__forecast', 'rowGap'),
    };
  });

  expect(profileSizing.message4kFontSize).toBeGreaterThanOrEqual(profileSizing.message1080FontSize * 1.2);
  expect(profileSizing.forecast1080Padding).toBeLessThan(profileSizing.forecast4kPadding);
  expect(profileSizing.forecast1080Padding).toBeLessThan(profileSizing.forecastAutoPadding);
  expect(profileSizing.forecast1080Gap).toBeLessThan(profileSizing.forecast4kGap);
  expect(profileSizing.forecast1080Gap).toBeLessThan(profileSizing.forecastAutoGap);
  expect(profileSizing.forecast1080Padding).toBeLessThanOrEqual(20);
  expect(profileSizing.forecast1080Gap).toBeLessThanOrEqual(14);
});

async function measureEntrySurfaces(
  page: Page,
  viewport: { width: number; height: number },
): Promise<{
  documentFits: boolean;
  loginPanelWidth: number;
  loginInputHeight: number;
  loginButtonHeight: number;
  pairingCardWidth: number;
  pairingCodeFontSize: number;
}> {
  await page.setViewportSize(viewport);
  await page.setContent(entrySurfacesDocument());

  return page.evaluate(() => {
    const boxWidth = (selector: string) => {
      const element = document.querySelector<HTMLElement>(selector);
      if (!element) {
        throw new Error(`Missing ${selector}`);
      }
      return element.getBoundingClientRect().width;
    };
    const boxHeight = (selector: string) => {
      const element = document.querySelector<HTMLElement>(selector);
      if (!element) {
        throw new Error(`Missing ${selector}`);
      }
      return element.getBoundingClientRect().height;
    };
    const fontSize = (selector: string) => {
      const element = document.querySelector<HTMLElement>(selector);
      if (!element) {
        throw new Error(`Missing ${selector}`);
      }
      return Number.parseFloat(getComputedStyle(element).fontSize);
    };

    return {
      documentFits: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
      loginPanelWidth: boxWidth('.login-panel'),
      loginInputHeight: boxHeight('.login-panel input'),
      loginButtonHeight: boxHeight('.login-panel .primary-button'),
      pairingCardWidth: boxWidth('.wallboard-pairing-card'),
      pairingCodeFontSize: fontSize('.wallboard-pairing-code-display'),
    };
  });
}

function documentShell(body: string): string {
  return `
    <!doctype html>
    <html lang="nl">
      <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <style>${globalStyles}</style>
      </head>
      <body>${body}</body>
    </html>
  `;
}

function applicationSurfaceDocument(): string {
  const columns = Array.from({ length: 8 }, (_, index) => `<th>Kolom ${index + 1}</th>`).join('');
  const cells = Array.from({ length: 8 }, (_, index) => `<td>Operationele waarde ${index + 1}</td>`).join('');

  return documentShell(`
    <div class="command-layout">
      <aside class="sidebar" aria-hidden="true"></aside>
      <div class="workspace">
        <header class="topbar"><h1>Responsieve werkruimte</h1></header>
        <main class="content">
          <div class="page-stack">
            <section class="panel">
              <header class="panel__header"><div><h2>Formulier en bediening</h2></div></header>
              <div class="panel-body">
                <form class="form">
                  <label>Naam<input data-touch-target value="Drone inzet" /></label>
                  <label>Type<select data-touch-target><option>Operationeel</option></select></label>
                  <label>Notitie<textarea data-touch-target>Veilige testinhoud</textarea></label>
                  <button class="primary-button" data-touch-target type="button">Opslaan</button>
                  <button class="secondary-button" data-touch-target type="button">Annuleren</button>
                </form>
              </div>
            </section>
            <section class="panel live-map">
              <div class="live-map__canvas" role="img" aria-label="Operationele kaart">
                <svg class="live-map__viewport" viewBox="0 0 960 380" aria-hidden="true"></svg>
              </div>
              <div class="table-wrap" data-testid="table-wrap">
                <table class="data-table" style="min-width: 900px">
                  <thead><tr>${columns}</tr></thead>
                  <tbody><tr>${cells}</tr></tbody>
                </table>
              </div>
            </section>
          </div>
        </main>
      </div>
    </div>
  `);
}

function wallboardCalendarDocument(): string {
  const timeline = Array.from({ length: 9 }, (_, index) => `
    <li>
      <time><strong>${String(index + 8).padStart(2, '0')}:00</strong><span>Vandaag</span></time>
      <div><h3>Operationele kalenderactiviteit ${index + 1}</h3><p>Team Noord</p></div>
    </li>
  `).join('');

  return documentShell(`
    <main class="wallboard-display wallboard-display--profile-auto">
      <section class="wallboard-display__calendar">
        <header class="wallboard-display__calendar-heading">
          <span class="wallboard-display__calendar-heading-icon" aria-hidden="true"></span>
          <div><span>Planning</span><strong>Operationele kalender</strong></div>
        </header>
        <div class="wallboard-display__calendar-layout">
          <article class="wallboard-display__calendar-next">
            <header><span>Volgende activiteit</span><strong>Vandaag</strong></header>
            <p class="wallboard-display__calendar-date">woensdag 5 augustus</p>
            <h2>Teamtraining</h2>
            <dl class="wallboard-display__calendar-details">
              <div><dt>Tijd</dt><dd>08:00 - 10:00</dd></div>
              <div><dt>Locatie</dt><dd>Operationeel centrum</dd></div>
            </dl>
          </article>
          <aside class="wallboard-display__calendar-upcoming">
            <header><span>Hierna</span><strong>9 activiteiten</strong></header>
            <ol class="wallboard-display__calendar-timeline">${timeline}</ol>
          </aside>
        </div>
      </section>
    </main>
  `);
}

function entrySurfacesDocument(): string {
  return documentShell(`
    <main>
      <section class="login-shell" style="min-height: 100vh">
        <article class="login-panel">
          <div class="login-panel__brand"><span class="login-panel__mark"></span><h1>Inloggen</h1></div>
          <form class="form"><label>E-mailadres<input value="beheer@example.test" /></label><button class="primary-button" type="button">Doorgaan</button></form>
        </article>
      </section>
      <section class="wallboard-pairing-screen">
        <article class="wallboard-pairing-card">
          <span class="wallboard-pairing-card__icon"></span>
          <h1>Scherm koppelen</h1>
          <p>Voer deze code in bij het schermbeheer.</p>
          <div class="wallboard-pairing-code-display"><span>123</span><span>456</span></div>
          <div class="wallboard-pairing-waiting"><span><i></i>Wacht op koppeling</span><time>00:30</time></div>
        </article>
      </section>
    </main>
  `);
}

function wallboardProfileDocument(): string {
  const profile = (name: '1080p' | '4k' | 'auto') => `
    <section class="wallboard-display wallboard-display--profile-${name}" data-profile="${name}">
      <div class="wallboard-display__message"><div class="wallboard-display__message-content"><h2>Operationeel bericht</h2></div></div>
      <div class="wallboard-display__forecast"><div></div><div></div></div>
    </section>
  `;

  return documentShell(`<main>${profile('1080p')}${profile('4k')}${profile('auto')}</main>`);
}
