import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  commitSecondsStepperDraft,
  moveSecondsStepperValue,
  normalizeSecondsStepperValue,
} from '../src/features/wallboards/secondsStepperValue';

test('normalizes direct numeric entry to the configured min, max and step', () => {
  expect(normalizeSecondsStepperValue(-4, 5, 30, 5)).toBe(5);
  expect(normalizeSecondsStepperValue(18, 5, 30, 5)).toBe(20);
  expect(normalizeSecondsStepperValue(99, 5, 30, 5)).toBe(30);
  expect(normalizeSecondsStepperValue(Number.NaN, 5, 30, 5)).toBe(5);
  expect(normalizeSecondsStepperValue(0.3, 0.1, 0.9, 0.2)).toBe(0.3);

  expect(() => normalizeSecondsStepperValue(10, 30, 5, 1)).toThrow(RangeError);
  expect(() => normalizeSecondsStepperValue(10, 5, 30, 0)).toThrow(RangeError);
});

test('moves exactly one step and remains within both boundaries', () => {
  expect(moveSecondsStepperValue(5, -1, 5, 30, 5)).toBe(5);
  expect(moveSecondsStepperValue(10, 1, 5, 30, 5)).toBe(15);
  expect(moveSecondsStepperValue(30, 1, 5, 30, 5)).toBe(30);
});

test('keeps multi-digit typing as a draft and validates only when committed', () => {
  expect(commitSecondsStepperDraft('2', 5, 5, 300)).toBe(5);
  expect(commitSecondsStepperDraft('20', 5, 5, 300)).toBe(20);
  expect(commitSecondsStepperDraft('', 20, 5, 300)).toBe(20);
  expect(commitSecondsStepperDraft('geen getal', 20, 5, 300)).toBe(20);
  expect(commitSecondsStepperDraft('999', 20, 5, 300)).toBe(300);
});

test('uses native spinbutton semantics for keyboard and numeric entry', async ({ page }) => {
  await page.setContent(`
    <label for="duration">Tijd per nieuwsbericht</label>
    <input id="duration" type="number" inputmode="numeric" min="5" max="30" step="5" value="10">
  `);

  const input = page.getByRole('spinbutton', { name: 'Tijd per nieuwsbericht' });
  await expect(input).toHaveAttribute('min', '5');
  await expect(input).toHaveAttribute('max', '30');
  await expect(input).toHaveAttribute('step', '5');
  await expect(input).toHaveAttribute('inputmode', 'numeric');

  await input.press('ArrowUp');
  await expect(input).toHaveValue('15');
  await input.press('ArrowDown');
  await expect(input).toHaveValue('10');
  await input.fill('25');
  await expect(input).toHaveJSProperty('valueAsNumber', 25);
});

test('keeps labels, disabled state and native numeric attributes in the component contract', () => {
  const source = readFileSync(
    new URL('../src/features/wallboards/SecondsStepper.tsx', import.meta.url),
    'utf8',
  );
  const styles = readFileSync(
    new URL('../src/features/wallboards/SecondsStepper.module.css', import.meta.url),
    'utf8',
  );

  expect(source).toContain('<label className={styles.label} htmlFor={id}>{label}</label>');
  expect(source).toContain('type="number"');
  expect(source).toContain('inputMode="numeric"');
  expect(source).toContain('min={min}');
  expect(source).toContain('max={max}');
  expect(source).toContain('step={step}');
  expect(source).toContain('disabled={disabled}');
  expect(source).toContain('disabled={disabled || actionableValue <= min}');
  expect(source).toContain('disabled={disabled || actionableValue >= maximumValue}');
  expect(source).toContain('setDraftValue(event.currentTarget.value)');
  expect(source).toContain('onBlur={commitDraft}');
  expect(source).toContain("event.key === 'Enter'");
  expect(source).toContain('aria-label={`${label} verlagen`}');
  expect(source).toContain('aria-label={`${label} verhogen`}');
  expect(styles).toContain('min-width: 44px');
  expect(styles).toContain('min-height: 44px');
  expect(styles).toContain('@media (prefers-reduced-motion: reduce)');
});

test('keeps minus, value and plus controls usable in narrow wallboard editor cards', async ({ page }) => {
  const componentStyles = readFileSync(
    new URL('../src/features/wallboards/SecondsStepper.module.css', import.meta.url),
    'utf8',
  );
  const globalStyles = readFileSync(
    new URL('../src/styles/global.css', import.meta.url),
    'utf8',
  );

  for (const { cardWidth, viewportWidth } of [
    { cardWidth: 250, viewportWidth: 360 },
    { cardWidth: 180, viewportWidth: 412 },
  ]) {
    await page.setViewportSize({ width: viewportWidth, height: 800 });
    await page.setContent(`
      <style>${componentStyles}\n${globalStyles}</style>
      <main style="width:${cardWidth}px;padding:12px;background:#07101a">
        <div class="root wallboard-focus-card__duration" data-testid="stepper">
          <label class="label" for="duration-${cardWidth}">Tijd op scherm</label>
          <div class="control">
            <button class="stepButton" type="button" aria-label="Tijd op scherm verlagen">&#8722;</button>
            <span class="valueShell">
              <input class="input" id="duration-${cardWidth}" type="number" inputmode="numeric" value="30">
              <span class="unit">sec.</span>
            </span>
            <button class="stepButton" type="button" aria-label="Tijd op scherm verhogen">+</button>
          </div>
        </div>
      </main>
    `);

    const result = await page.getByTestId('stepper').evaluate((root) => {
      const label = root.querySelector<HTMLElement>('.label')!;
      const control = root.querySelector<HTMLElement>('.control')!;
      const buttons = [...root.querySelectorAll<HTMLElement>('.stepButton')];
      const shell = root.querySelector<HTMLElement>('.valueShell')!;
      const input = root.querySelector<HTMLElement>('.input')!;
      const unit = root.querySelector<HTMLElement>('.unit')!;
      const rootRect = root.getBoundingClientRect();
      const controlRect = control.getBoundingClientRect();
      const labelRect = label.getBoundingClientRect();
      const shellRect = shell.getBoundingClientRect();
      const inputRect = input.getBoundingClientRect();
      const unitRect = unit.getBoundingClientRect();
      const buttonRects = buttons.map((button) => button.getBoundingClientRect());

      return {
        buttonsLargeEnough: buttonRects.every((rect) => rect.width >= 44 && rect.height >= 44),
        controlInsideRoot: controlRect.left >= rootRect.left - 1 && controlRect.right <= rootRect.right + 1,
        inputLargeEnough: inputRect.height >= 44,
        labelBeforeControl: labelRect.bottom <= controlRect.top + 1,
        noControlOverflow: control.scrollWidth <= control.clientWidth + 1,
        noOverlap: buttonRects[0].right <= shellRect.left + 1 && shellRect.right <= buttonRects[1].left + 1,
        noRootOverflow: root.scrollWidth <= root.clientWidth + 1,
        unitInsideValue: unitRect.left >= shellRect.left - 1 && unitRect.right <= shellRect.right + 1,
      };
    });

    expect(result).toEqual({
      buttonsLargeEnough: true,
      controlInsideRoot: true,
      inputLargeEnough: true,
      labelBeforeControl: true,
      noControlOverflow: true,
      noOverlap: true,
      noRootOverflow: true,
      unitInsideValue: true,
    });
    await page.getByRole('button', { name: 'Tijd op scherm verlagen' }).click();
    await page.getByRole('button', { name: 'Tijd op scherm verhogen' }).click();
    await page.getByRole('spinbutton', { name: 'Tijd op scherm' }).fill('120');
  }
});

test('reuses the seconds stepper for every directly configurable wallboard duration', () => {
  const configurationEditor = readFileSync(
    new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url),
    'utf8',
  );
  const photoPageEditor = readFileSync(
    new URL('../src/features/wallboards/WallboardPhotoPageEditor.tsx', import.meta.url),
    'utf8',
  );

  expect(configurationEditor.match(/<SecondsStepper/g)).toHaveLength(4);
  expect(configurationEditor).toContain('label="Data verversen"');
  expect(configurationEditor).toContain('id={`${idPrefix}-focus-${kind}-duration`}');
  expect(configurationEditor).toContain('id={`wallboard-page-${page.id}-duration`}');
  expect(configurationEditor).toContain('label="Tijd per nieuwsbericht"');
  expect(photoPageEditor).toContain('<SecondsStepper');
  expect(photoPageEditor).toContain('label="Tijd per foto"');
  expect(configurationEditor).toContain('<span>Maximum aantal berichten</span>');
});
