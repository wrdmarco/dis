import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { defineConfig, devices } from 'playwright/test';

export default defineConfig({
  testDir: './tests',
  outputDir: process.env.DIS_E2E_OUTPUT_DIR ?? join(tmpdir(), 'dis-playwright-results'),
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: process.env.DIS_E2E_BASE_URL ?? 'http://127.0.0.1:3000',
    ignoreHTTPSErrors: process.env.DIS_E2E_IGNORE_HTTPS_ERRORS === 'true',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
