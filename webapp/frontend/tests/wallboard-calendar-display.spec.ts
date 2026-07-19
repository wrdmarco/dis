import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  normalizeWallboardCalendarItems,
  wallboardCalendarRelativeDayLabel,
  wallboardCalendarTimeRange,
} from '../src/features/wallboards/WallboardDisplayPage';

test('normalizes and sorts bounded wallboard calendar state without trusting malformed items', () => {
  const items = normalizeWallboardCalendarItems([
    {
      id: 'later',
      title: '  Teamtraining  ',
      type: 'training',
      starts_at: '2026-07-21T18:00:00+02:00',
      ends_at: '2026-07-21T20:00:00+02:00',
      location_label: '  Vliegveld Noord  ',
      description: '',
      team: { id: 'team-1', code: 'OCP', name: 'Open categorie', type: 'operational' },
    },
    {
      id: 'first',
      title: 'Briefing',
      type: 'meeting',
      starts_at: '2026-07-20T08:30:00+02:00',
      ends_at: null,
      location_label: null,
      team: null,
    },
    { id: 'invalid', title: '', starts_at: 'geen-datum' },
  ]);

  expect(items.map((item) => item.id)).toEqual(['first', 'later']);
  expect(items[1]).toMatchObject({
    title: 'Teamtraining',
    location_label: 'Vliegveld Noord',
    description: null,
    team: { name: 'Open categorie' },
  });
  expect(normalizeWallboardCalendarItems(undefined)).toEqual([]);
});

test('uses Europe Amsterdam calendar days across the daylight-saving boundary', () => {
  const now = Date.parse('2026-03-29T00:30:00Z');

  expect(wallboardCalendarRelativeDayLabel('2026-03-29T18:00:00Z', now)).toBe('Vandaag');
  expect(wallboardCalendarRelativeDayLabel('2026-03-29T23:30:00Z', now)).toBe('Morgen');
  expect(wallboardCalendarRelativeDayLabel('2026-03-31T10:00:00Z', now)).toBe('Over 2 dagen');
});

test('formats same-day and overnight event ranges explicitly', () => {
  expect(wallboardCalendarTimeRange({
    starts_at: '2026-07-20T08:30:00+02:00',
    ends_at: '2026-07-20T10:00:00+02:00',
  })).toBe('08:30 – 10:00');

  expect(wallboardCalendarTimeRange({
    starts_at: '2026-07-20T23:30:00+02:00',
    ends_at: '2026-07-21T01:00:00+02:00',
  })).toContain('di 21 jul');
});

test('calendar display keeps the page frame and safely falls back when old state has no calendar payload', () => {
  const source = readFileSync(
    new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url),
    'utf8',
  );
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

  expect(source).toContain("if (page.type === 'calendar')");
  expect(source).toContain('calendarState?.pages?.[page.id]?.items');
  expect(source).toContain('<WallboardCalendarPage');
  expect(source).toContain('wallboard-display__calendar-next');
  expect(styles).toContain('.wallboard-display__calendar-layout');
  expect(styles).toContain('.wallboard-display__calendar-timeline--dense');
  expect(styles).toContain('@media (max-width: 1100px)');
});
