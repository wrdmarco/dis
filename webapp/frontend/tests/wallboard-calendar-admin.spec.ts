import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type { WallboardCalendarState } from '../src/types/api';
import {
  DEFAULT_WALLBOARD_CALENDAR_MAX_ITEMS,
  MAX_WALLBOARD_CALENDAR_MAX_ITEMS,
  MIN_WALLBOARD_CALENDAR_MAX_ITEMS,
  clampWallboardCalendarMaxItems,
  createWallboardPage,
  wallboardConfigurationCopy,
  wallboardPageTypeLabel,
} from '../src/features/wallboards/wallboardPresentation';

test('creates an agenda page with bounded defaults and a stable label', () => {
  const page = createWallboardPage('calendar', 1);

  expect(wallboardPageTypeLabel('calendar')).toBe('Agenda');
  expect(page).toMatchObject({
    type: 'calendar',
    name: 'Agenda',
    options: { max_items: DEFAULT_WALLBOARD_CALENDAR_MAX_ITEMS },
  });
  expect(clampWallboardCalendarMaxItems(-10)).toBe(MIN_WALLBOARD_CALENDAR_MAX_ITEMS);
  expect(clampWallboardCalendarMaxItems(999)).toBe(MAX_WALLBOARD_CALENDAR_MAX_ITEMS);
  expect(clampWallboardCalendarMaxItems(Number.NaN)).toBe(DEFAULT_WALLBOARD_CALENDAR_MAX_ITEMS);
});

test('normalizes agenda page options before an admin saves them again', () => {
  const configuration = wallboardConfigurationCopy({
    theme: 'dark',
    refresh_seconds: 10,
    map: {
      show_active_incidents: true,
      show_test_incidents: false,
      show_live_locations: true,
      show_routes: true,
      show_command_centers: true,
      show_historical_incidents: false,
      show_summary: true,
      show_incident_list: true,
      show_route_legend: true,
      auto_fit: true,
    },
    ticker: { enabled: false, sources: [] },
    focus: {
      preannouncement: { enabled: true, duration_seconds: 120, show_response_feed: true },
      real_alarm: { enabled: true, duration_seconds: 30, show_response_feed: true },
      test_alarm: { enabled: true, duration_seconds: 300, show_response_feed: true },
    },
    pages: [{
      id: 'agenda',
      type: 'calendar',
      name: '',
      duration_seconds: 30,
      options: { max_items: 100, body: 'niet voor de agenda' },
    }],
    rotation_enabled: true,
    page_transition: 'fade',
    page_transition_duration_ms: 320,
    page_flip_direction: 'left_to_right',
    page_fade_enabled: true,
    incident_override: { enabled: false, page_id: 'agenda' },
  });

  expect(configuration.pages[0]).toMatchObject({
    type: 'calendar',
    name: 'Agenda',
    options: { max_items: MAX_WALLBOARD_CALENDAR_MAX_ITEMS },
  });
  expect(configuration.pages[0].options.body).toBeUndefined();
});

test('exposes agenda management with a bounded item count and a dedicated icon', () => {
  const editor = readFileSync(
    new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url),
    'utf8',
  );

  expect(editor).toContain("{ value: 'calendar', label: 'Agenda' }");
  expect(editor).toContain('Maximum aantal agenda-items');
  expect(editor).toContain('MIN_WALLBOARD_CALENDAR_MAX_ITEMS');
  expect(editor).toContain('MAX_WALLBOARD_CALENDAR_MAX_ITEMS');
  expect(editor).toContain('unit="st."');
  expect(editor).toContain("case 'calendar': return <CalendarDays");
});

test('types the server state as page-specific agenda content', () => {
  const calendar: WallboardCalendarState = {
    generated_at: '2026-07-19T21:00:00Z',
    pages: {
      agenda: {
        items: [{
          id: 'event-1',
          title: 'Vliegoefening',
          type: 'exercise',
          starts_at: '2026-07-21T08:00:00Z',
          ends_at: null,
          location_label: 'Woerden',
          description: null,
          team: { id: 'team-1', code: 'OCP', name: 'OCP', type: 'base' },
        }],
      },
    },
  };

  expect(calendar.pages.agenda.items[0].title).toBe('Vliegoefening');
});
