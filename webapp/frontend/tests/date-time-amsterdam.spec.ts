import { expect, test } from 'playwright/test';
import {
  dateTimeLocalInputIsoValue,
  dateTimeLocalInputValue,
} from '../src/lib/dateTime';

const originalHostTimeZone = process.env.TZ;

test.beforeAll(() => {
  process.env.TZ = 'UTC';
});

test.afterAll(() => {
  if (originalHostTimeZone === undefined) {
    delete process.env.TZ;
    return;
  }

  process.env.TZ = originalHostTimeZone;
});

test('formats and parses Amsterdam winter time while the host runs in UTC', () => {
  expect(Intl.DateTimeFormat().resolvedOptions().timeZone).toBe('UTC');
  expect(dateTimeLocalInputValue('2026-01-15T11:30:00.000Z')).toBe('2026-01-15T12:30');
  expect(dateTimeLocalInputValue('2026-01-15T12:30')).toBe('2026-01-15T12:30');
  expect(dateTimeLocalInputIsoValue('2026-01-15T12:30')).toBe('2026-01-15T11:30:00.000Z');
});

test('formats and parses Amsterdam summer time while the host runs in UTC', () => {
  expect(dateTimeLocalInputValue('2026-07-15T10:30:00.000Z')).toBe('2026-07-15T12:30');
  expect(dateTimeLocalInputValue('2026-07-15T12:30')).toBe('2026-07-15T12:30');
  expect(dateTimeLocalInputIsoValue('2026-07-15T12:30')).toBe('2026-07-15T10:30:00.000Z');
});

test('rejects the Amsterdam spring DST gap instead of silently shifting it', () => {
  expect(dateTimeLocalInputIsoValue('2026-03-29T02:30')).toBe('');
  expect(dateTimeLocalInputValue('2026-03-29T02:30')).toBe('');
});

test('selects the earliest instant deterministically during the autumn overlap', () => {
  expect(dateTimeLocalInputIsoValue('2026-10-25T02:30')).toBe('2026-10-25T00:30:00.000Z');
  expect(dateTimeLocalInputValue('2026-10-25T00:30:00.000Z')).toBe('2026-10-25T02:30');
  expect(dateTimeLocalInputValue('2026-10-25T01:30:00.000Z')).toBe('2026-10-25T02:30');
});

test('preserves empty and invalid input contracts', () => {
  expect(dateTimeLocalInputValue(null)).toBe('');
  expect(dateTimeLocalInputValue('')).toBe('');
  expect(dateTimeLocalInputValue('geen datum')).toBe('');
  expect(dateTimeLocalInputValue('2026-02-30T12:00')).toBe('');
  expect(dateTimeLocalInputIsoValue('')).toBe('');
  expect(dateTimeLocalInputIsoValue('2026-02-30T12:00')).toBe('');
});
