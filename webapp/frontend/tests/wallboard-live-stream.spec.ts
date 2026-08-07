import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  WALLBOARD_ADMIN_LIVE_STREAM_MANIFEST_PATH,
  WALLBOARD_LIVE_STREAM_MANIFEST_PATH,
  wallboardLiveStreamManifestPath,
} from '../src/features/wallboards/wallboardLiveStream';

const PLAYER_SOURCE = readFileSync(
  new URL('../src/features/wallboards/WallboardLiveStreamPage.tsx', import.meta.url),
  'utf8',
);
const EDITOR_SOURCE = readFileSync(
  new URL('../src/features/wallboards/WallboardLiveStreamPageEditor.tsx', import.meta.url),
  'utf8',
);
const API_TYPES_SOURCE = readFileSync(
  new URL('../src/types/api.ts', import.meta.url),
  'utf8',
);
const DISPLAY_SOURCE = readFileSync(
  new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url),
  'utf8',
);
const CONFIGURATION_SOURCE = readFileSync(
  new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url),
  'utf8',
);
const PLAYER_STYLES = readFileSync(
  new URL('../src/features/wallboards/WallboardLiveStreamPage.module.css', import.meta.url),
  'utf8',
);
const PACKAGE = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')) as {
  dependencies?: Record<string, string>;
};

test('uses separate same-origin HLS manifests for kiosk and authenticated admin preview', () => {
  expect(WALLBOARD_LIVE_STREAM_MANIFEST_PATH).toBe('/api/wallboard/live-stream/manifest.m3u8');
  expect(WALLBOARD_ADMIN_LIVE_STREAM_MANIFEST_PATH)
    .toBe('/api/admin/wallboard-live-stream/manifest.m3u8');
  expect(wallboardLiveStreamManifestPath(false)).toBe(WALLBOARD_LIVE_STREAM_MANIFEST_PATH);
  expect(wallboardLiveStreamManifestPath(true)).toBe(WALLBOARD_ADMIN_LIVE_STREAM_MANIFEST_PATH);
  expect(PLAYER_SOURCE).not.toContain('rtp://');
});

test('loads the HLS runtime only for an active real-data page and tears playback down', () => {
  const activeGuard = PLAYER_SOURCE.indexOf('if (!running || demoMode)');
  const hlsImport = PLAYER_SOURCE.indexOf("await import('hls.js')");
  expect(activeGuard).toBeGreaterThan(-1);
  expect(hlsImport).toBeGreaterThan(activeGuard);
  expect(PLAYER_SOURCE).toContain('if (video === null) return undefined;');
  expect(PLAYER_SOURCE).toContain('hls.stopLoad();');
  expect(PLAYER_SOURCE).toContain('hls.destroy();');
  expect(PLAYER_SOURCE).toContain("video.removeAttribute('src');");
  expect(PLAYER_SOURCE).toContain('generation !== playbackGeneration');
  expect(PLAYER_SOURCE).toContain('enableWorker: false');
  expect(PLAYER_SOURCE).toContain('instance.startLoad(-1);');
  expect(PLAYER_SOURCE).toContain('instance.recoverMediaError();');
  expect(PLAYER_SOURCE).toContain('muted');
  expect(PLAYER_SOURCE).toContain('preload="none"');
  expect(PACKAGE.dependencies?.['hls.js']).toBe('1.6.17');
});

test('keeps demo playlists away from the real stream and exposes restrained live status', () => {
  expect(DISPLAY_SOURCE).toContain("import('./WallboardLiveStreamPage')");
  expect(DISPLAY_SOURCE).toContain("page.type === 'live_stream'");
  expect(DISPLAY_SOURCE).toContain("normalizeWallboardPlaylistDataMode(state.wallboard.data_mode) === 'demo'");
  expect(PLAYER_SOURCE).toContain('Een demoplaylist maakt nooit verbinding met het echte OBS-signaal.');
  expect(PLAYER_SOURCE).toContain('aria-label="Live-uitzending actief"');
  expect(PLAYER_SOURCE).toContain("status === 'live'");
  expect(PLAYER_STYLES).toContain('@media (prefers-reduced-motion: reduce)');
  expect(PLAYER_STYLES).toContain('.tally i');
});

test('shows the RTMPS server and retrieves the Stream Key only through an explicit action', () => {
  expect(CONFIGURATION_SOURCE).toContain("{ value: 'live_stream', label: 'Live-uitzending' }");
  expect(CONFIGURATION_SOURCE).toContain('<WallboardLiveStreamPageEditor />');
  expect(EDITOR_SOURCE).toContain("'/admin/wallboard-live-stream/status'");
  expect(EDITOR_SOURCE).toContain('OBS RTMPS-ingang');
  expect(EDITOR_SOURCE).toContain('status.server_url');
  expect(EDITOR_SOURCE).toContain('status.stream_key_configured');
  expect(EDITOR_SOURCE).toContain('service Custom...');
  expect(EDITOR_SOURCE).toContain('in bij Stream Key');
  expect(EDITOR_SOURCE).toContain('H.264');
  expect(EDITOR_SOURCE).toContain('AAC');
  expect(EDITOR_SOURCE).toContain('keyframe-interval op 2 seconden');
  expect(EDITOR_SOURCE).toContain('const { api } = useAuth();');
  expect(EDITOR_SOURCE).toContain('api.post<WallboardLiveStreamStreamKey>(');
  expect(EDITOR_SOURCE).toContain("'/admin/wallboard-live-stream/stream-key/reveal'");
  expect(EDITOR_SOURCE).toContain('response.data.stream_key_version');
  expect(EDITOR_SOURCE).toContain('const keyManagementReady = status !== null && activeStreamKeyVersion !== null;');
  expect(EDITOR_SOURCE).toContain('if (status === null || streamKey === null || streamKeyVersion === null');
  expect(EDITOR_SOURCE).toContain('disabled={controlsBusy || !keyManagementReady || status?.stream_key_configured !== true}');
  expect(EDITOR_SOURCE).toContain('Stream Key tonen');
  expect(EDITOR_SOURCE).toContain('readOnly');
  expect(EDITOR_SOURCE).toContain('onFocus={(event) => event.currentTarget.select()}');
  expect(EDITOR_SOURCE).toContain('navigator.clipboard.writeText(visibleStreamKey)');
  expect(EDITOR_SOURCE).toContain('Stream Key gekopieerd.');
  expect(EDITOR_SOURCE).toContain('setStreamKey(null)');
  expect(EDITOR_SOURCE).not.toContain('localStorage');
  expect(EDITOR_SOURCE).not.toContain('sessionStorage');
  expect(EDITOR_SOURCE).not.toContain('&lt;CODE&gt;');
  expect(EDITOR_SOURCE).not.toContain('status.source_ip');
  expect(EDITOR_SOURCE).not.toContain('RTP/MPEG-TS');

  const statusContractStart = API_TYPES_SOURCE.indexOf('export interface WallboardLiveStreamAdminStatus');
  const secretContractStart = API_TYPES_SOURCE.indexOf('export interface WallboardLiveStreamStreamKey');
  expect(statusContractStart).toBeGreaterThan(-1);
  expect(secretContractStart).toBeGreaterThan(statusContractStart);
  expect(API_TYPES_SOURCE.slice(statusContractStart, secretContractStart)).not.toContain('stream_key: string');
  expect(API_TYPES_SOURCE).toContain('stream_key: string;');
  expect(API_TYPES_SOURCE).toContain('stream_key_version: string;');
});

test('configures the RTMPS ingress from the admin panel without accepting a browser-supplied Stream Key', () => {
  expect(EDITOR_SOURCE).toContain('Verbindingsinstellingen');
  expect(EDITOR_SOURCE).toContain("'/admin/wallboard-live-stream/configuration'");
  expect(EDITOR_SOURCE).toContain("updateConfiguration('enabled', event.currentTarget.checked)");
  expect(EDITOR_SOURCE).toContain("updateConfiguration('publicHost', event.currentTarget.value)");
  expect(EDITOR_SOURCE).toContain("updateConfiguration('rtmpsBindAddress', event.currentTarget.value)");
  expect(EDITOR_SOURCE).toContain("updateConfiguration('rtmpsPort', event.currentTarget.value)");
  expect(EDITOR_SOURCE).toContain("updateConfiguration('tlsCertificatePath', event.currentTarget.value)");
  expect(EDITOR_SOURCE).toContain("updateConfiguration('tlsPrivateKeyPath', event.currentTarget.value)");
  expect(EDITOR_SOURCE).toContain('OBS-ingang uitschakelen?');
  expect(EDITOR_SOURCE).toContain('OBS-ingang opnieuw configureren?');
  expect(EDITOR_SOURCE).toContain('eerste Stream Key aangemaakt');
  expect(EDITOR_SOURCE).toContain('configuration_revision: revision');
  expect(EDITOR_SOURCE).toContain('configurationBaselineRevision !== status.configuration_revision');
  expect(EDITOR_SOURCE).toContain('De configuratie is intussen door een andere beheerder gewijzigd.');
  expect(EDITOR_SOURCE).toContain('const configurationCanSubmit = configurationHasChanges || configurationNeedsKey;');
  expect(EDITOR_SOURCE).toContain('!configurationCanSubmit || configurationIsStale || controlsBusy');
  expect(EDITOR_SOURCE).toContain('Eerste Stream Key aanmaken');
  expect(EDITOR_SOURCE).toContain('includeEnabled && current.enabled !== next.enabled');
  expect(EDITOR_SOURCE).toContain("value.startsWith('/etc/letsencrypt/live/')");
  expect(EDITOR_SOURCE).toContain("value.startsWith('/etc/ssl/')");
  expect(EDITOR_SOURCE).toContain('Beperk de ingest-poort in de firewall tot het OBS-adres of vertrouwde VPN.');
  expect(EDITOR_SOURCE).not.toContain('<form');

  const configurationPayloadStart = EDITOR_SOURCE.indexOf('function configurationPayload(');
  const configurationPayloadEnd = EDITOR_SOURCE.indexOf('function connectionConfigurationChanged(');
  expect(configurationPayloadStart).toBeGreaterThan(-1);
  expect(configurationPayloadEnd).toBeGreaterThan(configurationPayloadStart);
  expect(EDITOR_SOURCE.slice(configurationPayloadStart, configurationPayloadEnd)).not.toContain('stream_key');

  expect(API_TYPES_SOURCE).toContain('export interface WallboardLiveStreamConfiguration');
  expect(API_TYPES_SOURCE).toContain('configuration: WallboardLiveStreamConfiguration;');
  expect(API_TYPES_SOURCE).toContain('configuration_revision: string;');
  expect(API_TYPES_SOURCE).toContain('export interface WallboardLiveStreamConfigurationRequest');
  expect(API_TYPES_SOURCE).toContain('key_created: boolean;');
  expect(API_TYPES_SOURCE).toContain('configuration_changed: boolean;');
});

test('rotates the Stream Key behind a danger confirmation and gives explicit OBS recovery guidance', () => {
  expect(EDITOR_SOURCE).toContain('const confirmAction = useConfirmDialog();');
  expect(EDITOR_SOURCE).toContain("title: 'Stream Key wisselen?'");
  expect(EDITOR_SOURCE).toContain('De oude Stream Key stopt direct met werken en een actieve OBS-stream wordt onderbroken.');
  expect(EDITOR_SOURCE).toContain("confirmLabel: 'Ja, Stream Key wisselen'");
  expect(EDITOR_SOURCE).toContain("intent: 'danger'");
  expect(EDITOR_SOURCE).toContain('api.post<WallboardLiveStreamStreamKeyRotation>(');
  expect(EDITOR_SOURCE).toContain("'/admin/wallboard-live-stream/stream-key/rotate'");
  expect(EDITOR_SOURCE).toContain("{ confirmation: 'WISSELEN' }");
  expect(EDITOR_SOURCE).toContain('setStreamKey(null);');
  expect(EDITOR_SOURCE).toContain('Haal voor gebruik de actuele Stream Key opnieuw op.');
  expect(EDITOR_SOURCE).toContain('activeStreamKeyVersion === streamKeyVersion');
  expect(EDITOR_SOURCE).toContain('setRotationNotice(null);');
  expect(EDITOR_SOURCE).toContain('stream_key_version: response.data.stream_key_version');
  expect(EDITOR_SOURCE).toContain('disabled={controlsBusy || !keyManagementReady}');
  expect(EDITOR_SOURCE).toContain('response.data.rotated_at');
  expect(EDITOR_SOURCE).toContain('response.data.previous_key_revoked');
  expect(EDITOR_SOURCE).toContain('response.data.obs_reconnect_required');
  expect(EDITOR_SOURCE).toContain('className={styles.rotationAlert} role="alert"');
  expect(EDITOR_SOURCE).toContain('Nieuwe Stream Key actief');
  expect(EDITOR_SOURCE).toContain('De oude Stream Key werkt niet meer. Werk de nieuwe key nu bij in OBS en start de stream opnieuw.');
  expect(API_TYPES_SOURCE).toContain('rotated_at: string;');
  expect(API_TYPES_SOURCE).toContain('previous_key_revoked: boolean;');
  expect(API_TYPES_SOURCE).toContain('obs_reconnect_required: boolean;');
});
