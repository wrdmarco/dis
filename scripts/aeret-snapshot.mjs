#!/usr/bin/env node
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const [url, outputPath, widthArg, heightArg] = process.argv.slice(2);

if (!url || !outputPath) {
  console.error('Usage: aeret-snapshot.mjs <url> <output-path> [width] [height]');
  process.exit(2);
}

const scriptDir = dirname(fileURLToPath(import.meta.url));
process.env.PLAYWRIGHT_BROWSERS_PATH ||= '/opt/dis-data/playwright-browsers';
const frontendPackage = resolve(scriptDir, '../webapp/frontend/package.json');
const requireFromFrontend = createRequire(frontendPackage);
const { chromium } = requireFromFrontend('playwright');

const width = Number.parseInt(widthArg ?? '1200', 10);
const height = Number.parseInt(heightArg ?? '720', 10);

let browser;
try {
  browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage({
    viewport: {
      width: Number.isFinite(width) ? width : 1200,
      height: Number.isFinite(height) ? height : 720,
    },
    deviceScaleFactor: 1,
  });

  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => undefined);
  await page.waitForTimeout(3500);
  await cleanAeretOverlays(page);
  await page.waitForTimeout(500);
  if (!await captureAeretMap(page, outputPath)) {
    await page.screenshot({ path: outputPath, fullPage: false, type: 'png' });
  }
} finally {
  if (browser) {
    await browser.close();
  }
}

async function cleanAeretOverlays(page) {
  const closeSelectors = [
    '[aria-label*="sluit" i]',
    '[aria-label*="close" i]',
    '[title*="sluit" i]',
    '[title*="close" i]',
    'button:has-text("×")',
    'button:has-text("x")',
  ];

  for (const selector of closeSelectors) {
    for (const target of await page.locator(selector).all()) {
      await target.click({ timeout: 500 }).catch(() => undefined);
    }
  }

  await page.evaluate(() => {
    const overlayTexts = [
      'Catalogus',
      'Disclaimer Drone PreFlight',
      'Drone PreFlight Basic',
      'Proclaimer',
      'Disclaimer',
    ];

    const viewportArea = window.innerWidth * window.innerHeight;
    const elements = Array.from(document.body.querySelectorAll('*'))
      .map((element) => {
        const rect = element.getBoundingClientRect();
        const text = (element.textContent ?? '').replace(/\s+/g, ' ').trim();

        return { element, rect, text, area: rect.width * rect.height };
      })
      .filter(({ rect, text, area }) => (
        text !== '' &&
        rect.width >= 80 &&
        rect.height >= 40 &&
        area >= 4000 &&
        area <= viewportArea * 0.55 &&
        overlayTexts.some((needle) => text.toLowerCase().includes(needle.toLowerCase()))
      ))
      .sort((left, right) => right.area - left.area);

    for (const { element } of elements) {
      element.style.setProperty('display', 'none', 'important');
      element.style.setProperty('visibility', 'hidden', 'important');
      element.setAttribute('data-dis-report-hidden', 'true');
    }

    const fixedPanels = Array.from(document.body.querySelectorAll('*'))
      .filter((element) => {
        const style = window.getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        const text = (element.textContent ?? '').replace(/\s+/g, ' ').trim().toLowerCase();

        return (
          ['fixed', 'absolute', 'sticky'].includes(style.position) &&
          rect.width >= 120 &&
          rect.height >= 50 &&
          rect.width * rect.height <= viewportArea * 0.35 &&
          (
            text.includes('catalogus') ||
            text.includes('disclaimer') ||
            text.includes('drone preflight') ||
            text.includes('kaartlagen') ||
            text.includes('layers')
          )
        );
      });

    for (const element of fixedPanels) {
      element.style.setProperty('display', 'none', 'important');
      element.style.setProperty('visibility', 'hidden', 'important');
      element.setAttribute('data-dis-report-hidden', 'true');
    }
  });
}

async function captureAeretMap(page, outputPath) {
  const candidates = [
    '.leaflet-container',
    '.mapboxgl-map',
    'canvas',
  ];

  for (const selector of candidates) {
    const locator = page.locator(selector).first();
    const box = await locator.boundingBox().catch(() => null);
    if (box && box.width >= 600 && box.height >= 350) {
      await locator.screenshot({ path: outputPath, type: 'png' });
      return true;
    }
  }

  return false;
}
