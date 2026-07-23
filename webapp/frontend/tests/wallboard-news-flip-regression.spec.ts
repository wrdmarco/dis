import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';

const displaySource = readFileSync(
  new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url),
  'utf8',
);
const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');
const allTransitions = ['fade', 'dissolve', 'slide', 'flip', 'zoom', 'wipe', 'none'] as const;
const nonFlipAnimatedTransitions = ['fade', 'dissolve', 'slide', 'zoom', 'wipe'] as const;

test('pauses the internal news-card transition while the playlist page transitions', () => {
  expect(displaySource).toMatch(/<WallboardNewsCardTransition[\s\S]*?running=\{running\}[\s\S]*?\/>/);
  expect(displaySource).toContain("const usesPairedCards = running && transition !== 'none';");
  expect(displaySource).toContain('if (!running || visual.previous === null) return undefined;');
  expect(displaySource).toContain('previous: running ? current.current : null');
  expect(displaySource).toContain("const pageFlipSceneClass = visual.transition === 'flip'");
  expect(displaySource).toContain("const pageFlipStageClass = visual.transition === 'flip'");
  expect(displaySource).toContain("const newsFlipSceneClass = transition === 'flip'");
  expect(displaySource).toContain("const newsFlipStageClass = transition === 'flip'");
});

test('keeps every non-flip global and news transition two-dimensional', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'no-preference' });

  for (const transition of nonFlipAnimatedTransitions) {
    await page.setContent(`
      <style>${styles}</style>
      <section class="wallboard-display__page wallboard-display__page--card-scene wallboard-display__page--card-${transition} wallboard-display__page--flip-left_to_right" style="width: 800px; height: 400px; --wallboard-page-transition-duration: 1s">
        <div class="wallboard-display__page-card-stage wallboard-display__page-card-stage--${transition} wallboard-display__page-card-stage--left_to_right">
          <div class="wallboard-display__page-card-pane wallboard-display__page-card-pane--outgoing">Oude pagina</div>
          <div class="wallboard-display__page-card-pane wallboard-display__page-card-pane--incoming">Nieuwe pagina</div>
        </div>
      </section>
      <div class="wallboard-display__news-card-scene wallboard-display__news-card-scene--${transition} wallboard-display__news-card-scene--flip-left_to_right" style="width: 800px; height: 400px; --wallboard-news-transition-duration: 1s">
        <div class="wallboard-display__news-card-stage wallboard-display__news-card-stage--${transition} wallboard-display__news-card-stage--flip-left_to_right">
          <div class="wallboard-display__news-card-pane wallboard-display__news-card-pane--outgoing"><article class="wallboard-display__news-article">Oud nieuws</article></div>
          <div class="wallboard-display__news-card-pane wallboard-display__news-card-pane--incoming"><article class="wallboard-display__news-article">Nieuw nieuws</article></div>
        </div>
      </div>
    `);

    const result = await page.locator('.wallboard-display__page-card-stage, .wallboard-display__news-card-stage').evaluateAll((stages) => stages.map((stage) => {
      for (const animation of stage.getAnimations({ subtree: true })) {
        animation.pause();
        animation.currentTime = 500;
      }
      const panes = [...stage.querySelectorAll<HTMLElement>('[class*="-card-pane"]')];
      return {
        stageTransform: getComputedStyle(stage).transform,
        stageTransformStyle: getComputedStyle(stage).transformStyle,
        paneTransforms: panes.map((pane) => getComputedStyle(pane).transform),
        paneTransformStyles: panes.map((pane) => getComputedStyle(pane).transformStyle),
        paneBackfaces: panes.map((pane) => getComputedStyle(pane).backfaceVisibility),
      };
    }));

    expect(result).toHaveLength(2);
    for (const layer of result) {
      expect(layer.stageTransform).toBe('none');
      expect(layer.stageTransformStyle).toBe('flat');
      expect(layer.paneTransforms.every((transform) => !transform.startsWith('matrix3d'))).toBe(true);
      expect(layer.paneTransformStyles).toEqual(['flat', 'flat']);
      expect(layer.paneBackfaces).toEqual(['visible', 'visible']);
    }
  }
});

test('neutralizes every internal news transition during every global transition', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'no-preference' });

  for (const globalTransition of allTransitions) {
    for (const newsTransition of allTransitions) {
      const globalFlipClasses = globalTransition === 'flip'
        ? ' wallboard-display__page--flip-left_to_right'
        : '';
      const globalStageFlipClass = globalTransition === 'flip'
        ? ' wallboard-display__page-card-stage--left_to_right'
        : '';
      const newsFlipClasses = newsTransition === 'flip'
        ? ' wallboard-display__news-card-scene--flip-left_to_right'
        : '';
      const newsStageFlipClass = newsTransition === 'flip'
        ? ' wallboard-display__news-card-stage--flip-left_to_right'
        : '';

      await page.setContent(`
        <style>${styles}</style>
        <section class="wallboard-display__page wallboard-display__page--card-scene wallboard-display__page--card-${globalTransition}${globalFlipClasses}" style="width: 800px; height: 400px; --wallboard-page-transition-duration: 1s">
          <div class="wallboard-display__page-card-stage wallboard-display__page-card-stage--${globalTransition}${globalStageFlipClass}">
            <div class="wallboard-display__page-card-pane wallboard-display__page-card-pane--outgoing">Oude pagina</div>
            <div class="wallboard-display__page-card-pane wallboard-display__page-card-pane--incoming">
              <div class="wallboard-display__news-carousel wallboard-display__news-carousel--paused">
                <div class="wallboard-display__news-card-scene wallboard-display__news-card-scene--${newsTransition}${newsFlipClasses}">
                  <div class="wallboard-display__news-card-stage wallboard-display__news-card-stage--${newsTransition}${newsStageFlipClass}" style="--wallboard-news-transition-duration: 1s">
                    <div class="wallboard-display__news-card-pane wallboard-display__news-card-pane--outgoing"><article class="wallboard-display__news-article">Oud nieuws</article></div>
                    <div class="wallboard-display__news-card-pane wallboard-display__news-card-pane--incoming"><article class="wallboard-display__news-article">Nieuw nieuws</article></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      `);

      const neutral = await page.locator('.wallboard-display__news-card-stage').evaluate((stage) => {
        const outgoing = stage.querySelector<HTMLElement>('.wallboard-display__news-card-pane--outgoing')!;
        const incoming = stage.querySelector<HTMLElement>('.wallboard-display__news-card-pane--incoming')!;
        return {
          stageAnimation: getComputedStyle(stage).animationName,
          stageTransform: getComputedStyle(stage).transform,
          stageTransformStyle: getComputedStyle(stage).transformStyle,
          outgoingDisplay: getComputedStyle(outgoing).display,
          incomingAnimation: getComputedStyle(incoming).animationName,
          incomingTransform: getComputedStyle(incoming).transform,
          incomingBackface: getComputedStyle(incoming).backfaceVisibility,
          incomingWebkitBackface: getComputedStyle(incoming).getPropertyValue('-webkit-backface-visibility'),
        };
      });

      expect(neutral, `${globalTransition} + ${newsTransition}`).toEqual({
        stageAnimation: 'none',
        stageTransform: 'none',
        stageTransformStyle: 'flat',
        outgoingDisplay: 'none',
        incomingAnimation: 'none',
        incomingTransform: 'none',
        incomingBackface: 'visible',
        incomingWebkitBackface: 'visible',
      });
    }
  }
});

test('settles every news flip direction as a non-mirrored card', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'no-preference' });

  for (const direction of ['left_to_right', 'top_to_bottom', 'bottom_to_top'] as const) {
    await page.setContent(`
      <style>${styles}</style>
      <div class="wallboard-display__news-card-scene wallboard-display__news-card-scene--flip wallboard-display__news-card-scene--flip-${direction}" style="width: 800px; height: 400px">
        <div class="wallboard-display__news-card-stage wallboard-display__news-card-stage--flip wallboard-display__news-card-stage--flip-${direction}" style="--wallboard-news-transition-duration: 1.4s">
          <div class="wallboard-display__news-card-pane wallboard-display__news-card-pane--outgoing"><article class="wallboard-display__news-article">Oud</article></div>
          <div class="wallboard-display__news-card-pane wallboard-display__news-card-pane--incoming"><article class="wallboard-display__news-article">Nieuw</article></div>
        </div>
      </div>
      <div class="wallboard-display__news-card-scene wallboard-display__news-card-scene--flip wallboard-display__news-card-scene--flip-${direction}" style="width: 800px; height: 400px">
        <div class="wallboard-display__news-card-stage wallboard-display__news-card-stage--flip wallboard-display__news-card-stage--flip-${direction} wallboard-display__news-card-stage--settled">
          <div class="wallboard-display__news-card-pane wallboard-display__news-card-pane--incoming"><article class="wallboard-display__news-article">Nieuw en leesbaar</article></div>
        </div>
      </div>
    `);

    const active = await page.locator('.wallboard-display__news-card-stage:not(.wallboard-display__news-card-stage--settled)').evaluate((stage) => {
      const panes = [...stage.querySelectorAll<HTMLElement>('.wallboard-display__news-card-pane')];
      return {
        stageOrigin: getComputedStyle(stage).transformOrigin,
        paneOrigins: panes.map((pane) => getComputedStyle(pane).transformOrigin),
        backfaces: panes.map((pane) => getComputedStyle(pane).backfaceVisibility),
        webkitBackfaces: panes.map((pane) => getComputedStyle(pane).getPropertyValue('-webkit-backface-visibility')),
      };
    });

    expect(active.stageOrigin).toBe('400px 200px');
    expect(active.paneOrigins).toEqual(['400px 200px', '400px 200px']);
    expect(active.backfaces).toEqual(['hidden', 'hidden']);
    expect(active.webkitBackfaces).toEqual(['hidden', 'hidden']);

    const settled = await page.locator('.wallboard-display__news-card-stage--settled').evaluate((stage) => {
      const incoming = stage.querySelector<HTMLElement>('.wallboard-display__news-card-pane--incoming')!;
      return {
        animation: getComputedStyle(stage).animationName,
        stageTransform: getComputedStyle(stage).transform,
        incomingTransform: getComputedStyle(incoming).transform,
        text: incoming.textContent,
      };
    });

    expect(settled).toEqual({
      animation: 'none',
      stageTransform: 'none',
      incomingTransform: 'none',
      text: 'Nieuw en leesbaar',
    });
  }
});
