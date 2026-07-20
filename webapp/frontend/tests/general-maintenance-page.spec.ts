import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';

const template = readFileSync(
  new URL('../../backend/resources/views/errors/503.blade.php', import.meta.url),
  'utf8',
);

const metadataPlaceholder = 'data-maintenance-kind="maintenance" data-started-epoch-seconds="0" data-estimated-duration-seconds="0" data-estimated-completion-epoch-seconds="0"';

function updateDocument(nowSeconds: number, completionOffsetSeconds = 600): string {
  const durationSeconds = 900;
  const completionSeconds = nowSeconds + completionOffsetSeconds;
  const startedSeconds = completionSeconds - durationSeconds;

  return template.replace(
    metadataPlaceholder,
    `data-maintenance-kind="update" data-started-epoch-seconds="${startedSeconds}" data-estimated-duration-seconds="${durationSeconds}" data-estimated-completion-epoch-seconds="${completionSeconds}"`,
  );
}

test('shows the live update countdown without viewport overflow', async ({ page }) => {
  const nowSeconds = Math.floor(Date.now() / 1000);

  for (const viewport of [
    { width: 390, height: 844 },
    { width: 1920, height: 1080 },
    { width: 3840, height: 2160 },
  ]) {
    await page.setViewportSize(viewport);
    await page.setContent(updateDocument(nowSeconds));

    await expect(page.getByRole('heading', { name: 'Systeem wordt bijgewerkt' })).toBeVisible();
    await expect(page.getByRole('timer')).toBeVisible();
    await expect(page.locator('#countdown-value')).toHaveText(/^(?:9:\d{2}|10:00)$/);
    await expect(page.getByText('Automatisch herstel is actief')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(viewport.width);
    expect(await page.evaluate(() => document.documentElement.scrollHeight)).toBeLessThanOrEqual(viewport.height);
  }
});

test('switches an elapsed estimate to the honest waiting state', async ({ page }) => {
  const nowSeconds = Math.floor(Date.now() / 1000);
  await page.setContent(updateDocument(nowSeconds, -10));

  await expect(page.getByRole('timer')).toBeVisible();
  await expect(page.locator('#countdown-value')).toHaveText('Nog even geduld');
  await expect(page.locator('#countdown-label')).toHaveText('Serverstatus controleren');
});

test('does not show a countdown when planned maintenance has no estimate', async ({ page }) => {
  await page.setContent(template);

  await expect(page.getByRole('heading', { name: 'D.I.S. is tijdelijk in onderhoud' })).toBeVisible();
  await expect(page.getByRole('timer')).toBeHidden();
});
