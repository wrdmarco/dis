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
  await page.screenshot({ path: outputPath, fullPage: false, type: 'png' });
} finally {
  if (browser) {
    await browser.close();
  }
}
