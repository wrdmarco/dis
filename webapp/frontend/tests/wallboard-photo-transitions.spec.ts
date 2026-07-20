import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  moveWallboardMediaPlaylistItem,
  reorderWallboardMediaPlaylistItem,
} from '../src/features/wallboards/wallboardMedia';
import {
  createWallboardPage,
  DEFAULT_WALLBOARD_FLIP_DIRECTION,
  DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION,
  DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION_DURATION_MS,
} from '../src/features/wallboards/wallboardPresentation';

test('new photo pages receive explicit, safe item transition defaults', () => {
  const page = createWallboardPage('photo_carousel', 1);

  expect(page.options.item_transition).toBe(DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION);
  expect(page.options.item_transition_duration_ms)
    .toBe(DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION_DURATION_MS);
  expect(page.options.item_flip_direction).toBe(DEFAULT_WALLBOARD_FLIP_DIRECTION);
});

test('photo playlist ordering supports buttons and drag-and-drop without mutating state', () => {
  const original = ['alpha', 'bravo', 'charlie'];

  expect(moveWallboardMediaPlaylistItem(original, 1, -1))
    .toEqual(['bravo', 'alpha', 'charlie']);
  expect(reorderWallboardMediaPlaylistItem(original, 'charlie', 'alpha'))
    .toEqual(['charlie', 'alpha', 'bravo']);
  expect(reorderWallboardMediaPlaylistItem(original, 'missing', 'alpha')).toEqual(original);
  expect(original).toEqual(['alpha', 'bravo', 'charlie']);
});

test('photo carousel renders paired cards for real transitions and avoids stacking with a page transition', () => {
  const component = readFileSync(
    new URL('../src/features/wallboards/WallboardPhotoCarousel.tsx', import.meta.url),
    'utf8',
  );
  const css = readFileSync(
    new URL('../src/features/wallboards/WallboardPhotoCarousel.module.css', import.meta.url),
    'utf8',
  );

  expect(component).toContain('<PhotoPane photo={effectiveVisual.previous} position="outgoing" />');
  expect(component).toContain('<PhotoPane photo={currentPhoto} position={paired ? \'incoming\' : \'settled\'} />');
  expect(component).toContain('resolveWallboardFlipDirection');
  expect(component).toContain('wallboardGlobalPageTransitionIsActive(rootRef.current)');
  expect(component).toContain('data-photo-transition');
  expect(css).toContain('@keyframes photo-flip-horizontal-out');
  expect(css).toContain('@keyframes photo-flip-top-in');
  expect(css).toContain('@keyframes photo-slide-out');
  expect(css).toContain('.wallboard-display__page-card-stage:not(.wallboard-display__page-card-stage--settled)');
  expect(css).toContain('@media (prefers-reduced-motion: reduce)');
});

test('photo ordering remains accessible on mobile and news counts use the shared stepper', () => {
  const library = readFileSync(
    new URL('../src/features/wallboards/WallboardMediaLibrary.tsx', import.meta.url),
    'utf8',
  );
  const editor = readFileSync(
    new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url),
    'utf8',
  );

  expect(library).toContain('reorderWallboardMediaPlaylistItem');
  expect(library).toContain('draggable');
  expect(library).toContain('omhoog`');
  expect(library).toContain('omlaag`');
  expect(editor).toContain('id={`wallboard-news-${page.id}-max-items`}');
  expect(editor).toContain('label="Maximum aantal berichten"');
  expect(editor).toContain('unit="st."');
  expect(editor).toContain('unitLabel="berichten"');
});
