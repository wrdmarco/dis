import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  wallboardPhotoCarouselAnchorFromDeadline,
  wallboardPhotoCarouselClock,
  wallboardPhotoCarouselElapsedMs,
  wallboardPhotoCarouselIndex,
  wallboardPhotoCarouselNextDelayMs,
  wallboardPhotoCarouselTransition,
} from '../src/features/wallboards/wallboardPhotoRotation';

test('derives a stable photo anchor from the server page deadline', () => {
  expect(wallboardPhotoCarouselAnchorFromDeadline('2026-07-19T12:02:00Z', 120))
    .toBe(Date.parse('2026-07-19T12:00:00Z'));
  expect(wallboardPhotoCarouselAnchorFromDeadline(null, 120)).toBeNull();
  expect(wallboardPhotoCarouselAnchorFromDeadline('invalid', 120)).toBeNull();
});

test('uses an absolute anchor while the wallboard remains online', () => {
  let clock = wallboardPhotoCarouselClock(1_000, true, 1_000);
  clock = wallboardPhotoCarouselTransition(clock, true, 26_100);

  expect(wallboardPhotoCarouselElapsedMs(clock)).toBe(25_100);
  expect(wallboardPhotoCarouselIndex(clock, 4, 10)).toBe(2);
  expect(wallboardPhotoCarouselNextDelayMs(clock, 10)).toBe(4_900);
});

test('freezes completely offline and resumes at the remaining point without counting downtime', () => {
  let clock = wallboardPhotoCarouselClock(1_000, true, 1_000);
  clock = wallboardPhotoCarouselTransition(clock, true, 7_000);
  expect(wallboardPhotoCarouselIndex(clock, 4, 10)).toBe(0);

  clock = wallboardPhotoCarouselTransition(clock, false, 7_000);
  clock = wallboardPhotoCarouselTransition(clock, false, 47_000);
  expect(wallboardPhotoCarouselElapsedMs(clock)).toBe(6_000);
  expect(wallboardPhotoCarouselIndex(clock, 4, 10)).toBe(0);

  clock = wallboardPhotoCarouselTransition(clock, true, 47_000);
  clock = wallboardPhotoCarouselTransition(clock, true, 52_000);
  expect(wallboardPhotoCarouselElapsedMs(clock)).toBe(11_000);
  expect(wallboardPhotoCarouselIndex(clock, 4, 10)).toBe(1);
});

test('keeps one cleaned-up timeout and renders only validated image paths', () => {
  const source = readFileSync(
    new URL('../src/features/wallboards/WallboardPhotoCarousel.tsx', import.meta.url),
    'utf8',
  );

  expect(source).toContain('window.setTimeout');
  expect(source).toContain('window.clearTimeout(timer)');
  expect(source).not.toContain('setInterval');
  expect(source).toContain('wallboardMediaImageUrl(item.image_url)');
  expect(source).not.toContain('dangerouslySetInnerHTML');
});
