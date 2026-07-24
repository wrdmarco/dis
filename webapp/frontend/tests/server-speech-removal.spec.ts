import { existsSync, readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';

test('removes the server-side speech route, navigation and browser capability', () => {
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
  const middleware = readFileSync(new URL('../middleware.ts', import.meta.url), 'utf8');

  expect(existsSync(new URL('../app/speech/page.tsx', import.meta.url))).toBe(false);
  expect(existsSync(new URL('../src/features/speech/SpeechAdminPage.tsx', import.meta.url))).toBe(false);
  expect(navigation).not.toContain("to: '/speech'");
  expect(navigation).not.toContain("'/speech':");
  expect(middleware).toContain('microphone=()');
  expect(middleware).not.toContain('microphone=(self)');
});

test('removes speech state from incident, queue and shared API presentation', () => {
  const types = readFileSync(new URL('../src/types/api.ts', import.meta.url), 'utf8');
  const incident = readFileSync(new URL('../src/features/incidents/IncidentDetailPage.tsx', import.meta.url), 'utf8');
  const queue = readFileSync(new URL('../src/features/queues/QueuePage.tsx', import.meta.url), 'utf8');
  const branding = readFileSync(new URL('../src/features/branding/BrandingPage.tsx', import.meta.url), 'utf8');

  for (const source of [types, incident, queue, branding]) {
    expect(source).not.toMatch(/speech|spraak|tts|serverstem/iu);
  }
  expect(types).toContain("export type QueueMonitorFilter = 'all' | 'push';");
});
