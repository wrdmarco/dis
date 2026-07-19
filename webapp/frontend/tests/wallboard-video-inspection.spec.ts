import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  formatWallboardVideoDuration,
  parseWallboardInspectableVideo,
  WALLBOARD_VIDEO_STARTUP_ALLOWANCE_SECONDS,
  WALLBOARD_VIDEO_MAX_CONTENT_DURATION_SECONDS,
  wallboardVideoDurationSeconds,
  wallboardVideoRecommendedDisplayDurationSeconds,
  inspectWallboardVideo,
  youtubeInspectionFailureCode,
  vimeoInspectionFailureCode,
} from '../src/features/wallboards/wallboardVideoInspection';

test('canonicalizes supported YouTube, Shorts and Vimeo links without carrying user input into embeds', () => {
  expect(parseWallboardInspectableVideo('https://www.youtube.com/watch?v=dQw4w9WgXcQ&feature=share')).toEqual({
    provider: 'youtube',
    videoId: 'dQw4w9WgXcQ',
    canonicalUrl: 'https://www.youtube.com/embed/dQw4w9WgXcQ',
    embedUrl: 'https://www.youtube.com/embed/dQw4w9WgXcQ?enablejsapi=1&playsinline=1&rel=0',
  });
  expect(parseWallboardInspectableVideo('https://www.youtube.com/shorts/dQw4w9WgXcQ?si=tracking')).toMatchObject({
    provider: 'youtube',
    videoId: 'dQw4w9WgXcQ',
  });
  expect(parseWallboardInspectableVideo('https://youtu.be/dQw4w9WgXcQ')).toMatchObject({ provider: 'youtube' });
  expect(parseWallboardInspectableVideo('https://vimeo.com/123456789?share=copy')).toEqual({
    provider: 'vimeo',
    videoId: '123456789',
    canonicalUrl: 'https://player.vimeo.com/video/123456789',
    embedUrl: 'https://player.vimeo.com/video/123456789?autoplay=0&dnt=1&title=0',
  });
  expect(parseWallboardInspectableVideo('javascript:alert(1)')).toBeNull();
  expect(parseWallboardInspectableVideo('https://evil.example/?v=dQw4w9WgXcQ')).toBeNull();
  expect(parseWallboardInspectableVideo('https://user:pass@youtube.com/watch?v=dQw4w9WgXcQ')).toBeNull();
  expect(parseWallboardInspectableVideo('https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ')).toBeNull();
});

test('rounds provider duration up and adds an explicit startup allowance', () => {
  expect(wallboardVideoDurationSeconds(61.01)).toBe(62);
  expect(wallboardVideoDurationSeconds(0)).toBeNull();
  expect(wallboardVideoDurationSeconds(Number.NaN)).toBeNull();
  expect(wallboardVideoRecommendedDisplayDurationSeconds(61.01))
    .toBe(62 + WALLBOARD_VIDEO_STARTUP_ALLOWANCE_SECONDS);
  expect(formatWallboardVideoDuration(62)).toBe('1:02');
  expect(formatWallboardVideoDuration(3662)).toBe('1:01:02');
  expect(WALLBOARD_VIDEO_MAX_CONTENT_DURATION_SECONDS).toBe(3595);
});

test('maps official provider failures to safe operational outcomes', () => {
  expect(youtubeInspectionFailureCode(101)).toBe('not_embeddable');
  expect(youtubeInspectionFailureCode(150)).toBe('not_embeddable');
  expect(youtubeInspectionFailureCode(100)).toBe('unavailable');
  expect(youtubeInspectionFailureCode(153)).toBe('provider_error');
  expect(vimeoInspectionFailureCode({ name: 'PrivacyError' })).toBe('not_embeddable');
  expect(vimeoInspectionFailureCode(new Error('PasswordError'))).toBe('unavailable');
  expect(vimeoInspectionFailureCode('<script>alert(1)</script>')).toBe('provider_error');
});

test('rejects unsupported input before loading a browser player API', async () => {
  await expect(inspectWallboardVideo('https://example.com/video')).resolves.toEqual({
    status: 'failed',
    code: 'invalid_url',
    provider: null,
    message: 'Gebruik een geldige openbare YouTube-, YouTube Shorts- of Vimeo-link.',
  });
});

test('loads only fixed official player scripts and does not use HTML injection primitives', () => {
  const source = readFileSync(
    new URL('../src/features/wallboards/wallboardVideoInspection.ts', import.meta.url),
    'utf8',
  );
  expect(source).toContain("const YOUTUBE_IFRAME_API_URL = 'https://www.youtube.com/iframe_api'");
  expect(source).toContain("const VIMEO_PLAYER_API_URL = 'https://player.vimeo.com/api/player.js'");
  expect(source).toContain("script.referrerPolicy = 'strict-origin-when-cross-origin'");
  expect(source).toContain("left: '-10000px'");
  expect(source).not.toContain('wrapper.hidden = true');
  expect(source).not.toContain('innerHTML');
  expect(source).not.toContain('insertAdjacentHTML');
  expect(source).not.toContain('document.write');
  expect(source).not.toContain('eval(');
});
