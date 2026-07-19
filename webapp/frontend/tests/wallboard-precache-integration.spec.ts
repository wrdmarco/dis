import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';

test('gates normal playback on complete cache activation and automatically recovers', () => {
  const source = readFileSync(
    new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url),
    'utf8',
  );

  const effect = source.slice(
    source.indexOf('const precacheContentVersion'),
    source.indexOf('const wallboardTheme'),
  );
  const render = source.slice(
    source.indexOf('const precacheReady'),
    source.indexOf('interface WallboardPlaylistPageFrameProps'),
  );

  expect(effect).toContain('await registerWallboardPrecacheWorker()');
  expect(effect).toContain('await precacheWallboard(manifest');
  expect(effect).toContain('if (!result.ready)');
  expect(effect).toContain('await activateWallboardPrecacheWorker(');
  expect(effect).toContain('precacheClientSessionTokenRef.current');
  expect(effect).toContain('precacheCommandGenerationRef.current');
  expect(source).toContain('useRef(Date.now())');
  expect(effect.indexOf('if (!result.ready)')).toBeLessThan(
    effect.indexOf('await activateWallboardPrecacheWorker('),
  );
  expect(effect).toContain('setPrecacheRetryGeneration');
  expect(effect).toContain("sessionStatus !== 'unpaired'");
  expect(effect).toContain('disableWallboardPrecache({');
  expect(effect).toContain('wallboardKey: lastPrecacheWallboardKeyRef.current');
  expect(effect).toContain('clientSessionToken: previousClientSessionToken');
  expect(effect).toContain('commandGeneration: precacheCommandGenerationRef.current');

  expect(render).toContain('wallboardPrecacheBlocksPlaylist');
  expect(render).toContain('<main className="wallboard-preload-root" ref={rootRef}>');
  expect(render).toContain('<WallboardPreloadScreen');
  expect(render).toContain('onlineOnlyPages={preload.onlineOnlyPages}');
  expect(render.indexOf('if (precacheBlocksPlaylist)')).toBeLessThan(
    render.indexOf('className={`wallboard-display'),
  );
  expect(source).not.toContain('WallboardNextPagePreload');
  expect(source).not.toContain('selectWallboardNextPreloadPage');
  expect(source).toContain('<video');
  expect(source).toContain('wallboardCacheableAssetUrl(page.options.url)');
});
