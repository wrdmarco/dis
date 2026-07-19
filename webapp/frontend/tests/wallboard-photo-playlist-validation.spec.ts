import { expect, test } from 'playwright/test';
import { ApiClientError } from '../src/lib/apiClient';
import {
  isPhotoCarouselValidationFailure,
  normalizeWallboardMediaPlaylistId,
} from '../src/features/wallboards/wallboardPlaylistValidation';

test('compares media playlist ULIDs case-insensitively', () => {
  expect(normalizeWallboardMediaPlaylistId(' 01KXT8SBRPMQM7X2ARSMFFEMFF '))
    .toBe('01kxt8sbrpmqm7x2arsmffemff');
});

test('does not relabel an unrelated validation error as a missing photo playlist', () => {
  const unrelated = new ApiClientError(
    'De UAV-locatie is ongeldig.',
    422,
    'validation_failed',
  );
  const missingPlaylist = new ApiClientError(
    'Een geselecteerde fotoplaylist bestaat niet meer.',
    422,
    'validation_failed',
  );

  expect(isPhotoCarouselValidationFailure(unrelated)).toBe(false);
  expect(isPhotoCarouselValidationFailure(missingPlaylist)).toBe(true);
});
