import { expect, test } from 'playwright/test';
import type { WallboardConfiguration } from '../src/types/api';
import {
  DEFAULT_WALLBOARD_CONFIGURATION,
  normalizeWallboardMediaPlaylistId,
  wallboardConfigurationCopy,
} from '../src/features/wallboards/wallboardPresentation';

test('keeps photo playlist ULIDs in the lowercase canonical form used by the backend', () => {
  const uppercaseId = '01KXT8SBRPMQM7X2ARSMFFEMFF';
  expect(normalizeWallboardMediaPlaylistId(` ${uppercaseId} `)).toBe(uppercaseId.toLowerCase());

  const configuration: WallboardConfiguration = {
    ...DEFAULT_WALLBOARD_CONFIGURATION,
    pages: [{
      id: 'photos',
      name: 'Foto’s',
      type: 'photo_carousel',
      duration_seconds: 30,
      options: { media_playlist_id: uppercaseId, item_duration_seconds: 12 },
    }],
  };

  expect(wallboardConfigurationCopy(configuration).pages[0].options.media_playlist_id)
    .toBe(uppercaseId.toLowerCase());
});
