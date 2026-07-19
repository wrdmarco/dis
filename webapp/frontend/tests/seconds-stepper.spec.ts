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
