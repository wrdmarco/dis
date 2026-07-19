import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  safeWallboardPreloadText,
  wallboardPreloadPercentage,
} from '../src/features/wallboards/wallboardPreloadProgress';

test('calculates bounded preload progress and forces ready state to one hundred percent', () => {
  expect(wallboardPreloadPercentage({
    status: 'loading', completed: 3, total: 8, pagesReady: 1, pagesTotal: 4,
  })).toBe(38);
  expect(wallboardPreloadPercentage({
    status: 'loading', completed: 0, total: 0, pagesReady: 3, pagesTotal: 4,
  })).toBe(75);
  expect(wallboardPreloadPercentage({
    status: 'loading', completed: 99, total: 4, pagesReady: 0, pagesTotal: 0,
  })).toBe(100);
  expect(wallboardPreloadPercentage({
    status: 'idle', completed: 3, total: 4, pagesReady: 2, pagesTotal: 3,
  })).toBe(0);
  expect(wallboardPreloadPercentage({
    status: 'ready', completed: 0, total: 0, pagesReady: 0, pagesTotal: 0,
  })).toBe(100);
});

test('never reflects technical locations in wallboard preload copy', () => {
  expect(safeWallboardPreloadText('Nieuws en weer', 'Pagina voorbereiden')).toBe('Nieuws en weer');
  expect(safeWallboardPreloadText('https://cdn.example.test/video.mp4', 'Pagina voorbereiden'))
    .toBe('Pagina voorbereiden');
  expect(safeWallboardPreloadText('Download mislukt bij blob:abc', 'Automatisch opnieuw proberen'))
    .toBe('Automatisch opnieuw proberen');
});

test('renders an accessible automatic preload state without manual retry controls', () => {
  const component = readFileSync(
    new URL('../src/features/wallboards/WallboardPreloadScreen.tsx', import.meta.url),
    'utf8',
  );
  const stylesheet = readFileSync(
    new URL('../src/features/wallboards/WallboardPreloadScreen.module.css', import.meta.url),
    'utf8',
  );

  expect(component).toContain('aria-live="polite"');
  expect(component).toContain('role="progressbar"');
  expect(component).toContain('aria-valuenow={percentage}');
  expect(component).toContain('De verbinding wordt automatisch opnieuw geprobeerd');
  expect(component).not.toContain('<button');
  expect(stylesheet).toContain('min-height: 100dvh');
  expect(stylesheet).toContain('@media (prefers-reduced-motion: reduce)');
  expect(stylesheet).toContain('conic-gradient');
});
