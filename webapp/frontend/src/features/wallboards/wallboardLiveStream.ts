export const WALLBOARD_LIVE_STREAM_MANIFEST_PATH = '/api/wallboard/live-stream/manifest.m3u8';
export const WALLBOARD_ADMIN_LIVE_STREAM_MANIFEST_PATH = '/api/admin/wallboard-live-stream/manifest.m3u8';

export function wallboardLiveStreamManifestPath(adminPreview: boolean): string {
  return adminPreview
    ? WALLBOARD_ADMIN_LIVE_STREAM_MANIFEST_PATH
    : WALLBOARD_LIVE_STREAM_MANIFEST_PATH;
}
