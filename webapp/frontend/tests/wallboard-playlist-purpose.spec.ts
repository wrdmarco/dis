import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  normalizeWallboardPlaylistPurpose,
  wallboardPlaylistIsNormal,
  wallboardPlaylistIsSelectableAlarm,
  wallboardPlaylistPurposeLabel,
} from '../src/features/wallboards/wallboardPlaylistPurpose';

test('normalizes legacy playlists and separates normal from selectable alarm playlists', () => {
  expect(normalizeWallboardPlaylistPurpose(undefined)).toBe('normal');
  expect(normalizeWallboardPlaylistPurpose('normal')).toBe('normal');
  expect(normalizeWallboardPlaylistPurpose('alarm')).toBe('alarm');
  expect(normalizeWallboardPlaylistPurpose('incident')).toBe('normal');
  expect(wallboardPlaylistPurposeLabel('normal')).toBe('NORMAAL');
  expect(wallboardPlaylistPurposeLabel('alarm')).toBe('ALARM');

  expect(wallboardPlaylistIsNormal({})).toBe(true);
  expect(wallboardPlaylistIsNormal({ purpose: 'normal' })).toBe(true);
  expect(wallboardPlaylistIsNormal({ purpose: 'alarm' })).toBe(false);
  expect(wallboardPlaylistIsSelectableAlarm({ purpose: 'alarm', data_mode: 'live' })).toBe(true);
  expect(wallboardPlaylistIsSelectableAlarm({ purpose: 'alarm', data_mode: 'demo' })).toBe(false);
  expect(wallboardPlaylistIsSelectableAlarm({ purpose: 'normal', data_mode: 'live' })).toBe(false);
});

test('sends purpose through playlist writes and keeps screen assignments purpose-specific', () => {
  const apiTypes = readFileSync(new URL('../src/types/api.ts', import.meta.url), 'utf8');
  const admin = readFileSync(
    new URL('../src/features/wallboards/WallboardsAdminPage.tsx', import.meta.url),
    'utf8',
  );
  const createPage = readFileSync(
    new URL('../src/features/wallboards/WallboardCreatePage.tsx', import.meta.url),
    'utf8',
  );
  const configurationEditor = readFileSync(
    new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url),
    'utf8',
  );

  const createRequest = admin.slice(
    admin.indexOf("api.post<WallboardPlaylist>('/admin/wallboard-playlists'"),
    admin.indexOf('function replaceWallboard'),
  );
  const updateRequest = admin.slice(
    admin.indexOf('async function savePlaylist'),
    admin.indexOf('async function deletePlaylist'),
  );
  const saveScreen = admin.slice(
    admin.indexOf('async function saveScreen'),
    admin.indexOf('async function controlDisplay'),
  );
  const screenSettings = admin.slice(
    admin.indexOf('<section className="wallboard-screen-settings"'),
    admin.indexOf('{actionError ?'),
  );

  expect(apiTypes).toContain("export type WallboardPlaylistPurpose = 'normal' | 'alarm';");
  expect(apiTypes).toContain('purpose?: WallboardPlaylistPurpose;');
  expect(apiTypes).toContain('runtime_playlist_purpose?: WallboardPlaylistPurpose;');
  expect(createRequest).toContain('purpose: newPlaylistPurpose');
  expect(updateRequest).toContain('purpose: draftPurpose');
  expect(admin).toContain('<option value="normal">Normale playlist</option>');
  expect(admin).toContain('<option value="alarm">Alarmplaylist</option>');
  expect(admin).toContain('const normalPlaylists = playlists.filter(wallboardPlaylistIsNormal);');
  expect(admin).toContain('const selectableAlarmPlaylists = playlists.filter(wallboardPlaylistIsSelectableAlarm);');
  expect(admin).toContain('<strong>Alarmplaylist gebruiken</strong>');
  expect(admin).toContain('De volledige alarmplaylist roteert tijdens de inzet; er is geen verplichte kaartpagina.');
  expect(saveScreen).toContain('active_incident_playlist_id: draftActiveIncidentPlaylistId === \'\'');
  expect(saveScreen).toContain('? null');
  expect(screenSettings).toContain('checked={draftActiveIncidentPlaylistId !== \'\'}');
  expect(screenSettings).toContain('disabled={draftActiveIncidentPlaylistId === \'\'}');
  expect(screenSettings.match(/<WallboardPlaylistPurposePill/g)).toHaveLength(2);
  expect(createPage).toContain('.filter(wallboardPlaylistIsNormal)');
  expect(configurationEditor).not.toContain('Vaste incidentpagina als fallback');
  expect(configurationEditor).not.toContain('Incidentpagina vastzetten');
  expect(configurationEditor).toContain('een ingestelde alarmplaylist draait daarbij volledig');
});
