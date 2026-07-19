import { ApiClientError } from '../../lib/apiClient';

export function normalizeWallboardMediaPlaylistId(value: string): string {
  return value.trim().toLowerCase();
}

export function isPhotoCarouselValidationFailure(error: unknown): boolean {
  return error instanceof ApiClientError
    && error.status === 422
    && /foto(?:carrousel|playlist)/i.test(error.message);
}
