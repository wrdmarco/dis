import { type FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import dynamic from 'next/dynamic';
import Link from 'next/link';
import {
  AlertTriangle,
  Eye,
  KeyRound,
  Library,
  Images,
  Link2,
  MonitorCog,
  Plus,
  Radio,
  RefreshCw,
  RotateCcw,
  Save,
  ShieldOff,
  Trash2,
} from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type {
  Wallboard,
  WallboardConfiguration,
  WallboardDisplayProfile,
  WallboardFocusKind,
  WallboardPlaylist,
  WallboardPlaylistAssignment,
  WallboardPlaylistDataMode,
  WallboardPlaylistPurpose,
  WallboardState,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  DEFAULT_WALLBOARD_CONFIGURATION,
  clampRefreshSeconds,
  normalizeWallboardDisplayProfile,
  requestedWallboardScreenSelection,
  wallboardConfigurationCopy,
  wallboardConfigurationForSave,
  wallboardConfigurationHasInvalidPhotoCarousels,
  wallboardConfigurationHasUnverifiedVideos,
  wallboardDisplayProfileLabel,
  wallboardIsOnline,
  wallboardPlaylistUsageCount,
} from './wallboardPresentation';
import {
  WallboardConfigurationEditor,
  WallboardPageTypeIcon,
} from './WallboardConfigurationEditor';
import { WallboardMediaLibrary } from './WallboardMediaLibrary';
import type { WallboardMediaPlaylist } from './wallboardMedia';
import {
  isPhotoCarouselValidationFailure,
  normalizeWallboardMediaPlaylistId,
} from './wallboardPlaylistValidation';
import {
  WallboardPlaylistDataModePill,
} from './WallboardPlaylistDataModePill';
import { WallboardPlaylistPurposePill } from './WallboardPlaylistPurposePill';
import {
  normalizeWallboardPlaylistDataMode,
  wallboardPlaylistOptionLabel,
} from './wallboardPlaylistDataMode';
import {
  normalizeWallboardPlaylistPurpose,
  wallboardPlaylistIsNormal,
  wallboardPlaylistIsSelectableAlarm,
} from './wallboardPlaylistPurpose';

const ADMIN_STATUS_REFRESH_MILLISECONDS = 2500;
const WallboardPlaylistPreview = dynamic(
  () => import('./WallboardPlaylistPreview').then((module) => module.WallboardPlaylistPreview),
  { ssr: false },
);
const WALLBOARD_FOCUS_PREVIEW_OPTIONS: ReadonlyArray<{
  kind: WallboardFocusKind;
  label: string;
}> = [
  { kind: 'preannouncement', label: 'Vooraankondiging' },
  { kind: 'test_alarm', label: 'Testalarm' },
  { kind: 'real_alarm', label: 'Echt alarm' },
];

type AdminSection = 'screens' | 'playlists' | 'media';

interface WallboardFocusPreviewResponse {
  wallboard_id: string;
  kind: WallboardFocusKind;
  is_preview: true;
  expires_at: string;
  duration_seconds: number;
  control_version: number;
}

interface ActiveWallboardFocusPreview {
  kind: WallboardFocusKind;
  durationSeconds: number;
  expiresAtEpoch: number;
}

export function WallboardsAdminPage() {
  const { api } = useAuth();
  const wallboardsResource = useApiResource<Wallboard[]>('/admin/wallboards');
  const playlistsResource = useApiResource<WallboardPlaylist[]>('/admin/wallboard-playlists');
  const { silentReload: silentReloadWallboards } = wallboardsResource;
  const { silentReload: silentReloadPlaylists } = playlistsResource;
  const [section, setSection] = useState<AdminSection>('screens');
  const photoPlaylistsResource = useApiResource<WallboardMediaPlaylist[]>(
    '/admin/wallboard-media/playlists',
    section === 'playlists',
  );
  const { silentReload: silentReloadPhotoPlaylists } = photoPlaylistsResource;
  const [selectedScreenId, setSelectedScreenId] = useState<string | null>(null);
  const [selectedPlaylistId, setSelectedPlaylistId] = useState<string | null>(null);
  const [newPlaylistName, setNewPlaylistName] = useState('');
  const [newPlaylistDataMode, setNewPlaylistDataMode] = useState<WallboardPlaylistDataMode>('live');
  const [newPlaylistPurpose, setNewPlaylistPurpose] = useState<WallboardPlaylistPurpose>('normal');
  const [creating, setCreating] = useState<AdminSection | null>(null);
  const [createError, setCreateError] = useState<string | null>(null);
  const wallboards = useMemo(() => wallboardsResource.data ?? [], [wallboardsResource.data]);
  const playlists = useMemo(() => playlistsResource.data ?? [], [playlistsResource.data]);
  const selectedScreen = wallboards.find((wallboard) => wallboard.id === selectedScreenId) ?? null;
  const selectedPlaylist = playlists.find((playlist) => playlist.id === selectedPlaylistId) ?? null;

  const reloadAll = useCallback(async () => {
    await Promise.all([
      silentReloadWallboards(),
      silentReloadPlaylists(),
      silentReloadPhotoPlaylists(),
    ]);
  }, [silentReloadPhotoPlaylists, silentReloadPlaylists, silentReloadWallboards]);

  useEffect(() => {
    setSelectedScreenId((current) => retainedSelection(current, wallboards));
  }, [wallboards]);

  useEffect(() => {
    const requestedScreenId = requestedWallboardScreenSelection(window.location.search, wallboards);
    if (requestedScreenId === null) return;

    setSection('screens');
    setSelectedScreenId(requestedScreenId);
    window.history.replaceState(window.history.state, '', '/wallboards');
  }, [wallboards]);

  useEffect(() => {
    setSelectedPlaylistId((current) => retainedSelection(current, playlists));
  }, [playlists]);

  useEffect(() => {
    const timer = window.setInterval(() => void silentReloadWallboards(), ADMIN_STATUS_REFRESH_MILLISECONDS);
    return () => window.clearInterval(timer);
  }, [silentReloadWallboards]);

  async function createPlaylist(event: FormEvent) {
    event.preventDefault();
    const name = newPlaylistName.trim();
    if (name === '') return;
    setCreating('playlists');
    setCreateError(null);
    try {
      const response = await api.post<WallboardPlaylist>('/admin/wallboard-playlists', {
        name,
        data_mode: newPlaylistDataMode,
        purpose: newPlaylistPurpose,
        configuration: wallboardConfigurationForSave(
          wallboardConfigurationCopy(DEFAULT_WALLBOARD_CONFIGURATION),
        ),
      });
      playlistsResource.mutate((current) => [...(current ?? []), response.data]);
      setSelectedPlaylistId(response.data.id);
      setNewPlaylistName('');
      setNewPlaylistDataMode('live');
      setNewPlaylistPurpose('normal');
      await reloadAll();
    } catch (error) {
      setCreateError(errorMessage(error, 'Playlist kon niet worden aangemaakt.'));
    } finally {
      setCreating(null);
    }
  }

  function replaceWallboard(next: Wallboard) {
    wallboardsResource.mutate((current) => (current ?? []).map((wallboard) => (
      wallboard.id === next.id ? next : wallboard
    )));
  }

  function replacePlaylist(next: WallboardPlaylist) {
    playlistsResource.mutate((current) => (current ?? []).map((playlist) => (
      playlist.id === next.id ? next : playlist
    )));
  }

  const activeResource = section === 'screens' ? wallboardsResource : playlistsResource;

  return (
    <div className="page-stack wallboards-admin-page">
      <header className="page-heading wallboards-admin-heading">
        <div>
          <span className="eyebrow">Beheer</span>
          <h1>Wallboards</h1>
          <p>Beheer schermen afzonderlijk en deel één inhoudsplaylist veilig met meerdere locaties.</p>
        </div>
        <div className="wallboards-admin-heading__actions">
          <Link className="primary-button" href="/wallboards/new">
            <Plus size={17} aria-hidden /> Scherm toevoegen
          </Link>
        </div>
      </header>

      <nav className="wallboards-admin-tabs" role="tablist" aria-label="Wallboardbeheer">
        <button
          id="wallboards-screens-tab"
          type="button"
          role="tab"
          aria-selected={section === 'screens'}
          aria-controls="wallboards-screens-panel"
          className={section === 'screens' ? 'wallboards-admin-tab wallboards-admin-tab--active' : 'wallboards-admin-tab'}
          onClick={() => {
            setSection('screens');
            setCreateError(null);
          }}
        >
          <MonitorCog size={18} aria-hidden />
          <span><strong>Schermen</strong><small>{wallboards.length} apparaten</small></span>
        </button>
        <button
          id="wallboards-playlists-tab"
          type="button"
          role="tab"
          aria-selected={section === 'playlists'}
          aria-controls="wallboards-playlists-panel"
          className={section === 'playlists' ? 'wallboards-admin-tab wallboards-admin-tab--active' : 'wallboards-admin-tab'}
          onClick={() => {
            setSection('playlists');
            setCreateError(null);
          }}
        >
          <Library size={18} aria-hidden />
          <span><strong>Playlists</strong><small>{playlists.length} programma’s</small></span>
        </button>
        <button
          id="wallboards-media-tab"
          type="button"
          role="tab"
          aria-selected={section === 'media'}
          aria-controls="wallboards-media-panel"
          className={section === 'media' ? 'wallboards-admin-tab wallboards-admin-tab--active' : 'wallboards-admin-tab'}
          onClick={() => {
            setSection('media');
            setCreateError(null);
          }}
        >
          <Images size={18} aria-hidden />
          <span><strong>Media</strong><small>Foto&apos;s en fotoplaylists</small></span>
        </button>
      </nav>

      <Panel title={section === 'screens' ? 'Schermen' : section === 'playlists' ? 'Playlists' : 'Media'} action={(
        <button className="icon-button" type="button" onClick={() => void reloadAll()} aria-label="Schermen en playlists vernieuwen">
          <RefreshCw size={17} aria-hidden />
        </button>
      )}>
        <div
          className={section === 'media' ? 'panel-body' : 'panel-body wallboards-admin-grid'}
          id={section === 'screens' ? 'wallboards-screens-panel' : section === 'playlists' ? 'wallboards-playlists-panel' : 'wallboards-media-panel'}
          role="tabpanel"
          aria-labelledby={section === 'screens' ? 'wallboards-screens-tab' : section === 'playlists' ? 'wallboards-playlists-tab' : 'wallboards-media-tab'}
        >
          {section === 'media' ? (
            <WallboardMediaLibrary />
          ) : (
            <>
          <div className="wallboards-admin-list-column">
            {section === 'playlists' ? (
              <form className="wallboard-create-form" onSubmit={createPlaylist}>
                <div className="wallboard-create-form__heading">
                  <strong>Nieuwe playlist</strong>
                  <small>Start met een veilige standaardindeling en pas daarna de inhoud aan.</small>
                </div>
                <label>
                  <span>Playlistnaam</span>
                  <span className="wallboard-create-form__row">
                    <input value={newPlaylistName} onChange={(event) => setNewPlaylistName(event.target.value)} maxLength={120} placeholder="Bijv. Operationeel standaard" required />
                    <button className="primary-button" type="submit" disabled={creating !== null || newPlaylistName.trim() === ''}>
                      <Plus size={17} aria-hidden /> {creating === 'playlists' ? 'Toevoegen…' : 'Toevoegen'}
                    </button>
                  </span>
                </label>
                <label>
                  <span>Doel</span>
                  <select
                    value={newPlaylistPurpose}
                    onChange={(event) => setNewPlaylistPurpose(event.target.value as WallboardPlaylistPurpose)}
                  >
                    <option value="normal">Normale playlist</option>
                    <option value="alarm">Alarmplaylist</option>
                  </select>
                  <small>
                    Normale playlists draaien standaard. Een alarmplaylist kan per scherm worden ingeschakeld voor een actieve inzet.
                  </small>
                </label>
                <label className="wallboard-switch-row wallboard-playlist-data-mode-control">
                  <input
                    type="checkbox"
                    checked={newPlaylistDataMode === 'demo'}
                    onChange={(event) => setNewPlaylistDataMode(event.target.checked ? 'demo' : 'live')}
                  />
                  <span>
                    <strong>Demomodus {newPlaylistDataMode === 'demo' ? 'aan' : 'uit'}</strong>
                    <small>Aan maakt dynamische operationele gegevens fictief; ingestelde teksten, foto&apos;s en video&apos;s blijven ongewijzigd. Uit gebruikt actuele operationele gegevens.</small>
                  </span>
                  <WallboardPlaylistDataModePill mode={newPlaylistDataMode} />
                </label>
                {createError ? <p className="form-error" role="alert">{createError}</p> : null}
              </form>
            ) : null}

            <ResourceState
              loading={activeResource.loading}
              error={activeResource.error}
              empty={section === 'screens' ? wallboards.length === 0 : playlists.length === 0}
            >
              {section === 'screens' ? (
                <div className="wallboard-list" role="list" aria-label="Geconfigureerde schermen">
                  {wallboards.map((wallboard) => {
                    const online = wallboardIsOnline(wallboard);
                    const displayLabel = displayPageLabel(wallboard);
                    return (
                      <button
                        className={`wallboard-list-card ${selectedScreen?.id === wallboard.id ? 'wallboard-list-card--active' : ''}`}
                        key={wallboard.id}
                        type="button"
                        onClick={() => setSelectedScreenId(wallboard.id)}
                        role="listitem"
                      >
                        <span className="wallboard-list-card__icon"><MonitorCog size={20} aria-hidden /></span>
                        <span className="wallboard-list-card__copy">
                          <strong>{wallboard.name}</strong>
                          <small>{displayLabel ?? (wallboard.last_seen_at ? `Laatst gezien ${formatDateTime(wallboard.last_seen_at)}` : 'Nog niet gekoppeld')}</small>
                          <small className="wallboard-list-card__playlist">Playlist: {wallboard.playlist.name}</small>
                          <small>Schermprofiel: {wallboardDisplayProfileLabel(normalizeWallboardDisplayProfile(wallboard.display_profile))}</small>
                        </span>
                        <span className="wallboard-list-card__pills">
                          <WallboardPlaylistPurposePill purpose={wallboard.playlist.purpose} />
                          <WallboardPlaylistDataModePill mode={wallboard.playlist.data_mode} />
                          <StatusPill value={!wallboard.is_enabled ? 'Uitgeschakeld' : online ? 'Online' : 'Offline'} tone={!wallboard.is_enabled ? 'neutral' : online ? 'good' : 'warn'} />
                        </span>
                      </button>
                    );
                  })}
                </div>
              ) : (
                <div className="wallboard-list" role="list" aria-label="Wallboardplaylists">
                  {playlists.map((playlist) => {
                    const usageCount = wallboardPlaylistUsageCount(playlist);
                    return (
                      <button
                        className={`wallboard-list-card ${selectedPlaylist?.id === playlist.id ? 'wallboard-list-card--active' : ''}`}
                        key={playlist.id}
                        type="button"
                        onClick={() => setSelectedPlaylistId(playlist.id)}
                        role="listitem"
                      >
                        <span className="wallboard-list-card__icon"><Library size={20} aria-hidden /></span>
                        <span className="wallboard-list-card__copy">
                          <strong>{playlist.name}</strong>
                          <small>{playlist.configuration.pages.length} pagina’s · versie {playlist.version}</small>
                        </span>
                        <span className="wallboard-list-card__pills">
                          <WallboardPlaylistPurposePill purpose={playlist.purpose} />
                          <WallboardPlaylistDataModePill mode={playlist.data_mode} />
                          <StatusPill value={playlistUsageLabel(playlist)} tone={usageCount > 1 ? 'warn' : usageCount === 1 ? 'good' : 'neutral'} />
                        </span>
                      </button>
                    );
                  })}
                </div>
              )}
            </ResourceState>
          </div>

          {section === 'screens' ? (
            selectedScreen === null ? (
              <EditorEmpty icon="screen" title="Selecteer of maak een scherm" description="De livebesturing, koppeling en playlisttoewijzing verschijnen hier." />
            ) : (
              <ScreenEditor
                key={selectedScreen.id}
                wallboard={selectedScreen}
                playlists={playlists}
                onReplace={replaceWallboard}
                onReloadWallboards={wallboardsResource.silentReload}
                onReloadAll={reloadAll}
                onDeleted={() => {
                  wallboardsResource.mutate((current) => (current ?? []).filter((wallboard) => wallboard.id !== selectedScreen.id));
                  setSelectedScreenId(null);
                }}
              />
            )
          ) : selectedPlaylist === null ? (
            <EditorEmpty icon="playlist" title="Selecteer of maak een playlist" description="Pagina’s, tijden, kaartlagen, ticker en incidentvoorrang worden hier als één programma beheerd." />
          ) : (
            <PlaylistEditor
              key={selectedPlaylist.id}
              playlist={selectedPlaylist}
              photoPlaylists={photoPlaylistsResource.data}
              photoPlaylistsLoading={photoPlaylistsResource.loading}
              photoPlaylistsError={photoPlaylistsResource.error}
              reloadPhotoPlaylists={photoPlaylistsResource.reload}
              onReplace={replacePlaylist}
              onReloadAll={reloadAll}
              onDeleted={() => {
                playlistsResource.mutate((current) => (current ?? []).filter((playlist) => playlist.id !== selectedPlaylist.id));
                setSelectedPlaylistId(null);
              }}
            />
          )}
            </>
          )}
        </div>
      </Panel>
    </div>
  );
}

interface ScreenEditorProps {
  wallboard: Wallboard;
  playlists: WallboardPlaylist[];
  onReplace: (wallboard: Wallboard) => void;
  onReloadWallboards: () => Promise<void>;
  onReloadAll: () => Promise<void>;
  onDeleted: () => void;
}

function ScreenEditor({
  wallboard,
  playlists,
  onReplace,
  onReloadWallboards,
  onReloadAll,
  onDeleted,
}: ScreenEditorProps) {
  const { api } = useAuth();
  const [draftName, setDraftName] = useState(wallboard.name);
  const [draftEnabled, setDraftEnabled] = useState(wallboard.is_enabled);
  const [draftDisplayProfile, setDraftDisplayProfile] = useState<WallboardDisplayProfile>(() => (
    normalizeWallboardDisplayProfile(wallboard.display_profile)
  ));
  const [draftPlaylistId, setDraftPlaylistId] = useState(wallboard.playlist_id);
  const [draftActiveIncidentPlaylistId, setDraftActiveIncidentPlaylistId] = useState(
    wallboard.active_incident_playlist_id ?? '',
  );
  const [tvPairingCode, setTvPairingCode] = useState('');
  const [pairingInputInvalid, setPairingInputInvalid] = useState(false);
  const [busyAction, setBusyAction] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [activeFocusPreview, setActiveFocusPreview] = useState<ActiveWallboardFocusPreview | null>(null);
  const [focusPreviewSecondsRemaining, setFocusPreviewSecondsRemaining] = useState(0);
  const [deleteConfirm, setDeleteConfirm] = useState(false);
  const savedConfiguration = wallboardConfigurationCopy(wallboard.configuration);
  const normalPlaylists = playlists.filter(wallboardPlaylistIsNormal);
  const selectableAlarmPlaylists = playlists.filter(wallboardPlaylistIsSelectableAlarm);
  const selectedManagedPlaylist = normalPlaylists.find((playlist) => playlist.id === draftPlaylistId) ?? null;
  const selectedPlaylist = selectedManagedPlaylist
    ?? (wallboard.playlist.id === draftPlaylistId && wallboardPlaylistIsNormal(wallboard.playlist)
      ? wallboard.playlist
      : null);
  const selectedActiveIncidentPlaylist = playlists.find(
    (playlist) => playlist.id === draftActiveIncidentPlaylistId,
  ) ?? (wallboard.active_incident_playlist?.id === draftActiveIncidentPlaylistId
    ? wallboard.active_incident_playlist
    : null);
  const selectedPlaylistIsInvalid = draftPlaylistId !== '' && selectedPlaylist === null;
  const activeIncidentPlaylistIsInvalid = draftActiveIncidentPlaylistId !== ''
    && (
      selectedActiveIncidentPlaylist === null
      || !wallboardPlaylistIsSelectableAlarm(selectedActiveIncidentPlaylist)
    );
  const display = wallboard.display ?? {
    mode: wallboard.manual_page_id
      ? 'manual' as const
      : !savedConfiguration.rotation_enabled || savedConfiguration.pages.length <= 1
        ? 'static' as const
        : 'rotation' as const,
    page_id: wallboard.manual_page_id ?? savedConfiguration.pages[0].id,
    incident_active: false,
    next_change_at: null,
  };
  const currentPage = savedConfiguration.pages.find((page) => page.id === display.page_id) ?? null;
  const isPaired = wallboard.active_sessions_count > 0;
  const pairingActionLabel = wallboard.paired_at ? 'Tv herkoppelen' : 'Tv koppelen';

  useEffect(() => {
    setDraftName(wallboard.name);
    setDraftEnabled(wallboard.is_enabled);
    setDraftDisplayProfile(normalizeWallboardDisplayProfile(wallboard.display_profile));
    setDraftPlaylistId(wallboard.playlist_id);
    setDraftActiveIncidentPlaylistId(wallboard.active_incident_playlist_id ?? '');
  }, [
    wallboard.active_incident_playlist_id,
    wallboard.display_profile,
    wallboard.is_enabled,
    wallboard.name,
    wallboard.playlist_id,
  ]);

  useEffect(() => {
    if (activeFocusPreview === null) return;

    const updateCountdown = () => {
      const secondsRemaining = Math.max(0, Math.ceil((activeFocusPreview.expiresAtEpoch - Date.now()) / 1000));
      setFocusPreviewSecondsRemaining(secondsRemaining);
      if (secondsRemaining === 0) {
        setActiveFocusPreview(null);
        setActionMessage((current) => current ?? 'De focustest van 30 seconden is afgelopen. Het scherm toont weer de bestaande weergave.');
      }
    };

    updateCountdown();
    const timer = window.setInterval(updateCountdown, 1000);
    return () => window.clearInterval(timer);
  }, [activeFocusPreview]);

  async function saveScreen(event: FormEvent) {
    event.preventDefault();
    const name = draftName.trim();
    if (name === '' || draftPlaylistId === '') return;
    if (selectedPlaylistIsInvalid) {
      setActionError('Kies een normale playlist als standaardprogramma voor dit scherm.');
      return;
    }
    if (activeIncidentPlaylistIsInvalid) {
      setActionError('Kies een alarmplaylist met LIVE DATA of schakel de alarmplaylist uit.');
      return;
    }
    const metadataChanged = name !== wallboard.name
      || draftEnabled !== wallboard.is_enabled
      || draftDisplayProfile !== wallboard.display_profile
      || draftActiveIncidentPlaylistId !== (wallboard.active_incident_playlist_id ?? '');
    const playlistChanged = draftPlaylistId !== wallboard.playlist_id;
    if (!metadataChanged && !playlistChanged) {
      setActionError(null);
      setActionMessage('Er zijn geen schermwijzigingen om op te slaan.');
      return;
    }

    setBusyAction('save');
    setActionError(null);
    setActionMessage(null);
    try {
      let currentWallboard = wallboard;
      if (metadataChanged) {
        const response = await api.patch<Wallboard>(`/admin/wallboards/${wallboard.id}`, {
          name,
          is_enabled: draftEnabled,
          display_profile: draftDisplayProfile,
          active_incident_playlist_id: draftActiveIncidentPlaylistId === ''
            ? null
            : draftActiveIncidentPlaylistId,
          expected_config_version: wallboard.config_version,
        });
        currentWallboard = response.data;
        onReplace(response.data);
      }

      if (playlistChanged) {
        await api.patch<WallboardPlaylistAssignment>(`/admin/wallboards/${wallboard.id}/playlist`, {
          playlist_id: draftPlaylistId,
          expected_config_version: currentWallboard.config_version,
        });
        await onReloadAll();
        setActionMessage('Scherminstellingen en playlisttoewijzing zijn opgeslagen. De actuele inhoud is geladen.');
      } else {
        setActionMessage('Scherminstellingen zijn opgeslagen.');
      }
    } catch (error) {
      if (isConflict(error)) {
        await onReloadAll();
        setActionError('Dit scherm is intussen gewijzigd. De nieuwste scherm- en playlistversies zijn geladen; controleer je keuze opnieuw.');
      } else {
        setActionError(errorMessage(error, 'Scherm kon niet worden opgeslagen.'));
      }
    } finally {
      setBusyAction(null);
    }
  }

  async function controlDisplay(pageId: string | null) {
    setBusyAction(pageId === null ? 'resume' : `display:${pageId}`);
    setActionError(null);
    setActionMessage(null);
    try {
      const response = await api.post<Wallboard | null>(`/admin/wallboards/${wallboard.id}/display`, {
        page_id: pageId,
        expected_control_version: wallboard.control_version ?? 1,
      });
      if (isWallboard(response.data)) onReplace(response.data);
      else await onReloadWallboards();
      setActionMessage(pageId === null ? 'De automatische paginarotatie is hervat.' : 'De gekozen pagina wordt nu op het scherm getoond.');
    } catch (error) {
      if (isConflict(error)) {
        await onReloadWallboards();
        setActionError('Een andere beheerder bestuurde dit scherm intussen. De actuele schermstatus is opnieuw geladen.');
      } else {
        setActionError(errorMessage(error, 'De livebesturing kon niet worden uitgevoerd.'));
      }
    } finally {
      setBusyAction(null);
    }
  }

  async function previewFocus(kind: WallboardFocusKind) {
    setBusyAction(`focus:${kind}`);
    setActionError(null);
    setActionMessage(null);
    try {
      const response = await api.post<WallboardFocusPreviewResponse>(`/admin/wallboards/${wallboard.id}/focus-test`, {
        kind,
        expected_control_version: wallboard.control_version ?? 1,
      });
      const expiresAtEpoch = Date.parse(response.data.expires_at);
      const durationSeconds = response.data.duration_seconds;
      onReplace({
        ...wallboard,
        control_version: response.data.control_version,
      });
      setFocusPreviewSecondsRemaining(durationSeconds);
      setActiveFocusPreview({
        kind: response.data.kind,
        durationSeconds,
        expiresAtEpoch: Number.isFinite(expiresAtEpoch)
          ? expiresAtEpoch
          : Date.now() + durationSeconds * 1000,
      });
    } catch (error) {
      if (isConflict(error)) {
        await onReloadWallboards();
        setActionError('Een andere beheerder bestuurde dit scherm intussen. De actuele schermstatus is opnieuw geladen. Start de focustest opnieuw.');
      } else {
        setActionError(errorMessage(error, 'De focustest kon niet worden gestart.'));
      }
    } finally {
      setBusyAction(null);
    }
  }

  async function pairTv() {
    const code = normalizePairingCode(tvPairingCode);
    if (!validPairingCode(code)) {
      setPairingInputInvalid(true);
      return;
    }
    setBusyAction('pair');
    setActionError(null);
    setActionMessage(null);
    try {
      await api.post(`/admin/wallboards/${wallboard.id}/pair`, { code });
      setTvPairingCode('');
      setPairingInputInvalid(false);
      await onReloadWallboards();
      setActionMessage('De tv is gekoppeld en opent het wallboard automatisch.');
    } catch (error) {
      setActionError(errorMessage(error, 'De tv kon niet worden gekoppeld. Controleer de code op het scherm.'));
    } finally {
      setBusyAction(null);
    }
  }

  async function revokeSessions() {
    setBusyAction('revoke');
    setActionError(null);
    setActionMessage(null);
    try {
      await api.post(`/admin/wallboards/${wallboard.id}/sessions/revoke`);
      await onReloadWallboards();
      setActionMessage('De schermkoppeling is ingetrokken. Het scherm moet opnieuw worden gekoppeld.');
    } catch (error) {
      setActionError(errorMessage(error, 'Wallboardsessie kon niet worden ingetrokken.'));
    } finally {
      setBusyAction(null);
    }
  }

  async function restartWallboard() {
    setBusyAction('restart');
    setActionError(null);
    setActionMessage(null);
    try {
      await api.post<{ control_version: number; refresh_version: number }>(`/admin/wallboards/${wallboard.id}/refresh`, {
        expected_control_version: wallboard.control_version ?? 1,
      });
      await onReloadWallboards();
      setActionMessage('Het gekoppelde wallboard herlaadt zodra het de herstartopdracht ontvangt.');
    } catch (error) {
      if (isConflict(error)) {
        await onReloadWallboards();
        setActionError('Een andere beheerder wijzigde dit scherm intussen. De actuele schermstatus is opnieuw geladen.');
      } else {
        setActionError(errorMessage(error, 'Het wallboard kon niet worden herstart.'));
      }
    } finally {
      setBusyAction(null);
    }
  }

  async function deleteScreen() {
    if (!deleteConfirm) return;
    setBusyAction('delete');
    setActionError(null);
    try {
      await api.delete(`/admin/wallboards/${wallboard.id}`);
      onDeleted();
      await onReloadAll();
    } catch (error) {
      setActionError(errorMessage(error, 'Scherm kon niet worden verwijderd.'));
    } finally {
      setBusyAction(null);
    }
  }

  return (
    <form className="wallboard-editor" onSubmit={saveScreen}>
      <div className="wallboard-editor__heading">
        <div>
          <span className="eyebrow">Schermregie</span>
          <h2>{wallboard.name}</h2>
        </div>
        <StatusPill value={isPaired ? 'Gekoppeld' : 'Niet gekoppeld'} tone={isPaired ? 'good' : 'neutral'} />
      </div>

      <section className="wallboard-live-control" aria-labelledby={`wallboard-live-${wallboard.id}`}>
        <div className="wallboard-live-control__status">
          <span className="wallboard-live-control__signal"><Radio size={19} aria-hidden /></span>
          <div>
            <small id={`wallboard-live-${wallboard.id}`}>Nu op het scherm</small>
            <strong>{currentPage?.name ?? 'Pagina onbekend'}</strong>
            <span>{displayModeLabel(display.mode, display.incident_active)}</span>
          </div>
          {display.next_change_at && display.mode === 'rotation' ? (
            <time dateTime={display.next_change_at}>Volgende wissel {formatTime(display.next_change_at)}</time>
          ) : (
            <time>{display.mode === 'rotation' ? 'Wacht op rotatie' : 'Blijft op deze pagina'}</time>
          )}
        </div>
        <div className="wallboard-live-control__pages" aria-label="Pagina direct tonen">
          {savedConfiguration.pages.map((page) => (
            <button
              className={display.page_id === page.id ? 'wallboard-live-page wallboard-live-page--active' : 'wallboard-live-page'}
              type="button"
              key={page.id}
              onClick={() => void controlDisplay(page.id)}
              disabled={busyAction !== null || !wallboard.is_enabled}
              aria-pressed={display.mode !== 'rotation' && display.page_id === page.id}
            >
              <WallboardPageTypeIcon type={page.type} />
              <span><strong>{page.name}</strong><small>Nu tonen</small></span>
              <Eye size={16} aria-hidden />
            </button>
          ))}
        </div>
        <div className="wallboard-live-control__actions">
          <button className="secondary-button" type="button" onClick={() => void controlDisplay(null)} disabled={busyAction !== null || display.mode === 'rotation' || !wallboard.is_enabled}>
            <RotateCcw size={17} aria-hidden /> Rotatie hervatten
          </button>
          <button className="secondary-button" type="button" onClick={() => void restartWallboard()} disabled={busyAction !== null || !isPaired || !wallboard.is_enabled}>
            <RefreshCw size={17} aria-hidden /> {busyAction === 'restart' ? 'Herstarten…' : 'Wallboard herstarten'}
          </button>
          <small>
            {!isPaired
              ? 'Koppel het scherm eerst om het op afstand te herstarten.'
              : !wallboard.is_enabled
                ? 'Activeer het scherm om een herstartopdracht te sturen.'
                : 'De opdracht blijft klaarstaan als het scherm tijdelijk offline is.'}
          </small>
        </div>
      </section>

      <section className="wallboard-focus-editor" aria-labelledby={`wallboard-focus-preview-${wallboard.id}`}>
        <div className="wallboard-configuration-section-heading">
          <span className="eyebrow">Schermvoorbeeld</span>
          <h3 id={`wallboard-focus-preview-${wallboard.id}`}>Focusscherm testen</h3>
          <p>Toont 30 seconden vaste voorbeelddata op alleen dit scherm. Er wordt geen incident, alarmering of pushbericht aangemaakt.</p>
        </div>
        <div className="wallboard-editor__heading-actions" role="group" aria-label="Focusscherm 30 seconden testen">
          {WALLBOARD_FOCUS_PREVIEW_OPTIONS.map((option) => {
            const action = `focus:${option.kind}`;
            return (
              <button
                className="secondary-button"
                type="button"
                key={option.kind}
                onClick={() => void previewFocus(option.kind)}
                disabled={busyAction !== null || !wallboard.is_enabled}
              >
                <Eye size={16} aria-hidden /> {busyAction === action ? 'Starten…' : option.label}
              </button>
            );
          })}
        </div>
        {activeFocusPreview ? (
          <p className="form-note" role="status" aria-live="polite" aria-atomic="true">
            <strong>Voorbeeld actief: {wallboardFocusPreviewLabel(activeFocusPreview.kind)}.</strong>{' '}
            Nog {focusPreviewSecondsRemaining} van {activeFocusPreview.durationSeconds} seconden; daarna hervat het scherm automatisch de bestaande weergave.
          </p>
        ) : null}
      </section>

      {isPaired ? null : (
        <section className="wallboard-tv-pairing" aria-labelledby={`wallboard-pair-${wallboard.id}`}>
          <span className="wallboard-tv-pairing__icon"><KeyRound size={20} aria-hidden /></span>
          <div className="wallboard-tv-pairing__copy">
            <small>Schermkoppeling</small>
            <strong id={`wallboard-pair-${wallboard.id}`}>{pairingActionLabel}</strong>
            <span>
              {wallboard.paired_at
                ? <>Dit scherm heeft geen actieve koppeling meer. Open <b>/wallboard</b> op de tv en vul de nieuwe code hieronder in.</>
                : <>Open <b>/wallboard</b> op de tv. Vul hier de code in die automatisch op het scherm verschijnt.</>}
            </span>
          </div>
          <label>
            <span>Code op tv</span>
            <input
              value={tvPairingCode}
              onChange={(event) => {
                const value = formatPairingCodeInput(event.target.value);
                setTvPairingCode(value);
                if (validPairingCode(normalizePairingCode(value))) setPairingInputInvalid(false);
              }}
              onKeyDown={(event) => {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                void pairTv();
              }}
              inputMode="text"
              autoComplete="off"
              autoCapitalize="characters"
              spellCheck={false}
              maxLength={9}
              pattern="[A-HJ-NP-Z2-9]{4} [A-HJ-NP-Z2-9]{4}"
              placeholder="ABCD EFGH"
              aria-invalid={pairingInputInvalid}
              aria-describedby={pairingInputInvalid ? `wallboard-pair-error-${wallboard.id}` : `wallboard-pair-help-${wallboard.id}`}
            />
            {pairingInputInvalid ? (
              <small className="wallboard-tv-pairing__error" id={`wallboard-pair-error-${wallboard.id}`} role="alert">Vul de acht tekens van de tv-code exact in.</small>
            ) : (
              <small id={`wallboard-pair-help-${wallboard.id}`}>De code is tijdelijk en kan maar één keer worden gebruikt.</small>
            )}
          </label>
          <button className="secondary-button" type="button" onClick={() => void pairTv()} disabled={busyAction !== null || !draftEnabled || !validPairingCode(normalizePairingCode(tvPairingCode))}>
            <KeyRound size={17} aria-hidden /> {busyAction === 'pair' ? 'Koppelen…' : pairingActionLabel}
          </button>
        </section>
      )}

      <section className="wallboard-screen-settings" aria-labelledby={`wallboard-screen-settings-${wallboard.id}`}>
        <div className="wallboard-configuration-section-heading">
          <span className="eyebrow">Apparaat</span>
          <h3 id={`wallboard-screen-settings-${wallboard.id}`}>Scherm en playlist</h3>
        </div>
        <div className="wallboard-editor__fields wallboard-editor__fields--screen">
          <label>
            <span>Schermnaam</span>
            <input value={draftName} onChange={(event) => setDraftName(event.target.value)} maxLength={120} required />
          </label>
          <label>
            <span>Schermprofiel</span>
            <select
              value={draftDisplayProfile}
              onChange={(event) => setDraftDisplayProfile(event.target.value as WallboardDisplayProfile)}
              aria-describedby={`wallboard-display-profile-help-${wallboard.id}`}
            >
              <option value="auto">Auto (aanbevolen)</option>
              <option value="1080p">1080p (Full HD)</option>
              <option value="4k">4K (Ultra HD)</option>
            </select>
            <small id={`wallboard-display-profile-help-${wallboard.id}`}>
              Auto past de indeling aan de beschikbare browserruimte aan. Het profiel wijzigt niet de TV-, HDMI- of OS-uitvoerresolutie; kies 1080p of 4K alleen als de tv-browser tekst structureel te groot of te klein toont.
            </small>
          </label>
          <label>
            <span className="wallboard-playlist-selector-label">
              <span>Toegewezen playlist</span>
              <span className="wallboard-playlist-selector-label__pills">
                <WallboardPlaylistPurposePill purpose={selectedPlaylist?.purpose ?? wallboard.playlist.purpose} />
                <WallboardPlaylistDataModePill mode={selectedPlaylist?.data_mode ?? wallboard.playlist.data_mode} />
              </span>
            </span>
            <select value={draftPlaylistId} onChange={(event) => setDraftPlaylistId(event.target.value)} required>
              {normalPlaylists.map((playlist) => (
                <option key={playlist.id} value={playlist.id}>{wallboardPlaylistOptionLabel(playlist)} · {playlistUsageLabel(playlist)}</option>
              ))}
              {!normalPlaylists.some((playlist) => playlist.id === wallboard.playlist_id) ? (
                <option value={wallboard.playlist_id} disabled={!wallboardPlaylistIsNormal(wallboard.playlist)}>
                  {wallboardPlaylistOptionLabel(wallboard.playlist)}
                  {!wallboardPlaylistIsNormal(wallboard.playlist) ? ' · geen normale playlist' : ''}
                </option>
              ) : null}
            </select>
            <small>
              {selectedPlaylistIsInvalid
                ? 'De huidige keuze is geen normale playlist. Kies een normale playlist voordat je opslaat.'
                : selectedManagedPlaylist && wallboardPlaylistUsageCount(selectedManagedPlaylist) > 1
                ? `Gedeeld door ${wallboardPlaylistUsageCount(selectedManagedPlaylist)} schermen.`
                : 'Alleen normale playlists zijn hier beschikbaar. Wijzig de inhoud onder Playlists.'}
            </small>
          </label>
          <label className="wallboard-switch-row">
            <input
              type="checkbox"
              checked={draftActiveIncidentPlaylistId !== ''}
              disabled={draftActiveIncidentPlaylistId === '' && selectableAlarmPlaylists.length === 0}
              onChange={(event) => {
                setDraftActiveIncidentPlaylistId(event.target.checked
                  ? selectableAlarmPlaylists[0]?.id ?? ''
                  : '');
                setActionError(null);
              }}
              aria-describedby={`wallboard-active-playlist-toggle-help-${wallboard.id}`}
            />
            <span>
              <strong>Alarmplaylist gebruiken</strong>
              <small id={`wallboard-active-playlist-toggle-help-${wallboard.id}`}>
                Schakelt tijdens een actief incident over op de gekozen alarmplaylist en keert daarna terug.
              </small>
            </span>
          </label>
          <label>
            <span className="wallboard-playlist-selector-label">
              <span>Alarmplaylist</span>
              {selectedActiveIncidentPlaylist ? (
                <span className="wallboard-playlist-selector-label__pills">
                  <WallboardPlaylistPurposePill purpose={selectedActiveIncidentPlaylist.purpose} />
                  <WallboardPlaylistDataModePill mode={selectedActiveIncidentPlaylist.data_mode} />
                </span>
              ) : null}
            </span>
            <select
              value={draftActiveIncidentPlaylistId}
              disabled={draftActiveIncidentPlaylistId === ''}
              onChange={(event) => {
                setDraftActiveIncidentPlaylistId(event.target.value);
                setActionError(null);
              }}
              aria-describedby={`wallboard-active-playlist-help-${wallboard.id}`}
            >
              <option value="" disabled>Kies een alarmplaylist</option>
              {selectableAlarmPlaylists.map((playlist) => (
                <option key={playlist.id} value={playlist.id}>
                  {wallboardPlaylistOptionLabel(playlist)}
                </option>
              ))}
              {wallboard.active_incident_playlist
                && !selectableAlarmPlaylists.some((playlist) => playlist.id === wallboard.active_incident_playlist?.id) ? (
                  <option
                    value={wallboard.active_incident_playlist.id}
                    disabled
                  >
                    {wallboardPlaylistOptionLabel(wallboard.active_incident_playlist)}
                    {' · geen geldige LIVE DATA-alarmplaylist'}
                  </option>
                ) : null}
            </select>
            <small id={`wallboard-active-playlist-help-${wallboard.id}`} className={activeIncidentPlaylistIsInvalid ? 'wallboard-playlist-selector-warning' : undefined}>
              {activeIncidentPlaylistIsInvalid
                ? 'Deze keuze is geen geldige LIVE DATA-alarmplaylist. Kies een andere playlist of schakel de optie uit.'
                : selectableAlarmPlaylists.length === 0
                  ? 'Maak onder Playlists eerst een alarmplaylist met LIVE DATA.'
                  : 'De volledige alarmplaylist roteert tijdens de inzet; er is geen verplichte kaartpagina.'}
            </small>
          </label>
          <label className="wallboard-switch-row">
            <input type="checkbox" checked={draftEnabled} onChange={(event) => setDraftEnabled(event.target.checked)} />
            <span><strong>Scherm actief</strong><small>Uitgeschakelde schermen krijgen geen informatie meer.</small></span>
          </label>
        </div>
      </section>

      {actionError ? <p className="form-error" role="alert">{actionError}</p> : null}
      {actionMessage ? <p className="form-note" role="status">{actionMessage}</p> : null}

      <div className="wallboard-editor__actions">
        <button className="primary-button" type="submit" disabled={busyAction !== null || draftName.trim() === '' || draftPlaylistId === '' || selectedPlaylistIsInvalid || activeIncidentPlaylistIsInvalid}>
          <Save size={17} aria-hidden /> {busyAction === 'save' ? 'Opslaan…' : 'Scherm opslaan'}
        </button>
        <button className="secondary-button" type="button" onClick={() => void revokeSessions()} disabled={busyAction !== null || !isPaired}>
          <ShieldOff size={17} aria-hidden /> Koppeling intrekken
        </button>
        {deleteConfirm ? (
          <span className="wallboard-delete-confirm">
            <span>Dit scherm definitief verwijderen?</span>
            <button className="danger-button" type="button" onClick={() => void deleteScreen()} disabled={busyAction !== null}>Ja, verwijderen</button>
            <button className="secondary-button" type="button" onClick={() => setDeleteConfirm(false)}>Annuleren</button>
          </span>
        ) : (
          <button className="danger-button" type="button" onClick={() => setDeleteConfirm(true)} disabled={busyAction !== null}>
            <Trash2 size={17} aria-hidden /> Scherm verwijderen
          </button>
        )}
      </div>
    </form>
  );
}

interface PlaylistEditorProps {
  playlist: WallboardPlaylist;
  photoPlaylists: WallboardMediaPlaylist[] | null;
  photoPlaylistsLoading: boolean;
  photoPlaylistsError: string | null;
  reloadPhotoPlaylists: () => Promise<void>;
  onReplace: (playlist: WallboardPlaylist) => void;
  onReloadAll: () => Promise<void>;
  onDeleted: () => void;
}

function PlaylistEditor({
  playlist,
  photoPlaylists,
  photoPlaylistsLoading,
  photoPlaylistsError,
  reloadPhotoPlaylists,
  onReplace,
  onReloadAll,
  onDeleted,
}: PlaylistEditorProps) {
  const { api } = useAuth();
  const [draftName, setDraftName] = useState(playlist.name);
  const [draftDataMode, setDraftDataMode] = useState<WallboardPlaylistDataMode>(() => (
    normalizeWallboardPlaylistDataMode(playlist.data_mode)
  ));
  const [draftPurpose, setDraftPurpose] = useState<WallboardPlaylistPurpose>(() => (
    normalizeWallboardPlaylistPurpose(playlist.purpose)
  ));
  const [draft, setDraft] = useState<WallboardConfiguration>(() => wallboardConfigurationCopy(playlist.configuration));
  const [busyAction, setBusyAction] = useState<'save' | 'delete' | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [deleteConfirm, setDeleteConfirm] = useState(false);
  const [previewOpen, setPreviewOpen] = useState(false);
  const usageCount = wallboardPlaylistUsageCount(playlist);
  const hasUnverifiedVideos = wallboardConfigurationHasUnverifiedVideos(draft);
  const photoPlaylistIds = useMemo(
    () => photoPlaylists === null
      ? null
      : new Set(photoPlaylists.map((candidate) => normalizeWallboardMediaPlaylistId(candidate.id))),
    [photoPlaylists],
  );
  const missingPhotoCarouselPages = useMemo(() => photoPlaylistIds === null
    ? []
    : draft.pages.filter((page) => page.type === 'photo_carousel'
      && typeof page.options.media_playlist_id === 'string'
      && page.options.media_playlist_id.trim() !== ''
      && !photoPlaylistIds.has(normalizeWallboardMediaPlaylistId(page.options.media_playlist_id))), [draft.pages, photoPlaylistIds]);
  const hasInvalidPhotoCarousels = wallboardConfigurationHasInvalidPhotoCarousels(draft, photoPlaylistIds);

  useEffect(() => {
    setDraftName(playlist.name);
    setDraftDataMode(normalizeWallboardPlaylistDataMode(playlist.data_mode));
    setDraftPurpose(normalizeWallboardPlaylistPurpose(playlist.purpose));
    setDraft(wallboardConfigurationCopy(playlist.configuration));
    setDeleteConfirm(false);
    setPreviewOpen(false);
  }, [playlist.configuration, playlist.data_mode, playlist.name, playlist.purpose, playlist.version]);

  async function savePlaylist(event: FormEvent) {
    event.preventDefault();
    const name = draftName.trim();
    if (name === '') return;
    if (hasUnverifiedVideos) {
      setActionError('Controleer iedere video op insluitbaarheid en speelduur voordat je de playlist opslaat.');
      return;
    }
    if (hasInvalidPhotoCarousels) {
      setActionError('Kies voor iedere fotocarrousel een geldige fotoplaylist en tijd per foto.');
      return;
    }
    setBusyAction('save');
    setActionError(null);
    setActionMessage(null);
    try {
      const configuration = wallboardConfigurationForSave({
        ...draft,
        refresh_seconds: clampRefreshSeconds(draft.refresh_seconds),
      });
      const response = await api.patch<WallboardPlaylist>(`/admin/wallboard-playlists/${playlist.id}`, {
        name,
        data_mode: draftDataMode,
        purpose: draftPurpose,
        configuration,
        expected_version: playlist.version,
      });
      onReplace(response.data);
      await onReloadAll();
      setActionMessage(usageCount > 1
        ? `Playlist opgeslagen en bijgewerkt op alle ${usageCount} gekoppelde schermen.`
        : 'Playlist opgeslagen. Gekoppelde schermen nemen de inhoud direct over.');
    } catch (error) {
      if (isConflict(error)) {
        await onReloadAll();
        setActionError('Deze playlist is intussen gewijzigd. De nieuwste playlist- en schermversies zijn geladen; controleer je wijzigingen opnieuw.');
      } else {
        if (isPhotoCarouselValidationFailure(error)) {
          await reloadPhotoPlaylists();
        }
        setActionError(errorMessage(error, 'Playlist kon niet worden opgeslagen.'));
      }
    } finally {
      setBusyAction(null);
    }
  }

  async function deletePlaylist() {
    if (!deleteConfirm || usageCount > 0) return;
    setBusyAction('delete');
    setActionError(null);
    setActionMessage(null);
    try {
      await api.delete(`/admin/wallboard-playlists/${playlist.id}?expected_version=${encodeURIComponent(String(playlist.version))}`);
      onDeleted();
      await onReloadAll();
    } catch (error) {
      await onReloadAll();
      if (isConflict(error)) {
        setActionError('De playlist is gewijzigd of inmiddels aan een scherm gekoppeld. De actuele gegevens zijn geladen; ontkoppel alle schermen voordat je verwijdert.');
      } else {
        setActionError(errorMessage(error, 'Playlist kon niet worden verwijderd.'));
      }
    } finally {
      setBusyAction(null);
    }
  }

  const loadPreviewState = useCallback(async (configuration: WallboardConfiguration): Promise<WallboardState> => {
    const response = await api.post<WallboardState>(`/admin/wallboard-playlists/${playlist.id}/preview-state`, {
      configuration,
      data_mode: draftDataMode,
    });

    return response.data;
  }, [api, draftDataMode, playlist.id]);

  return (
    <form className="wallboard-editor wallboard-playlist-editor" onSubmit={savePlaylist}>
      <div className="wallboard-editor__heading">
        <div>
          <span className="eyebrow">Gedeelde inhoud</span>
          <h2>{playlist.name}</h2>
        </div>
        <div className="wallboard-editor__heading-actions">
          <button className="secondary-button" type="button" onClick={() => setPreviewOpen(true)}>
            <Eye size={17} aria-hidden /> Voorbeeld bekijken
          </button>
          <WallboardPlaylistPurposePill purpose={draftPurpose} />
          <WallboardPlaylistDataModePill mode={draftDataMode} />
          <StatusPill value={`Versie ${playlist.version}`} tone="neutral" />
        </div>
      </div>

      {previewOpen ? (
        <WallboardPlaylistPreview
          playlistName={draftName.trim() || playlist.name}
          dataMode={draftDataMode}
          configuration={draft}
          loadState={loadPreviewState}
          onClose={() => setPreviewOpen(false)}
        />
      ) : null}

      {usageCount > 1 ? (
        <aside className="wallboard-playlist-shared" role="note" aria-label="Gedeelde playlist">
          <AlertTriangle size={22} aria-hidden />
          <div>
            <strong>Gedeelde playlist · {usageCount} schermen</strong>
            <span>Wijzigingen aan pagina’s, tijden, kaartlagen, ticker of incidentvoorrang verschijnen na opslaan op al deze schermen.</span>
          </div>
        </aside>
      ) : (
        <p className="wallboard-playlist-usage"><Link2 size={16} aria-hidden /> {usageCount === 1 ? 'Deze playlist is aan 1 scherm gekoppeld.' : 'Deze playlist is nog niet aan een scherm gekoppeld.'}</p>
      )}

      <section className="wallboard-playlist-name" aria-labelledby={`playlist-name-${playlist.id}`}>
        <div className="wallboard-configuration-section-heading">
          <span className="eyebrow">Identiteit</span>
          <h3 id={`playlist-name-${playlist.id}`}>Naam</h3>
        </div>
        <label>
          <span>Playlistnaam</span>
          <input value={draftName} onChange={(event) => setDraftName(event.target.value)} maxLength={120} required />
        </label>
        <label>
          <span>Doel</span>
          <select
            value={draftPurpose}
            onChange={(event) => {
              setDraftPurpose(event.target.value as WallboardPlaylistPurpose);
              setActionError(null);
              setActionMessage(null);
            }}
          >
            <option value="normal">Normale playlist</option>
            <option value="alarm">Alarmplaylist</option>
          </select>
          <small>
            {draftPurpose === 'alarm'
              ? 'Beschikbaar als tijdelijk programma tijdens een actieve inzet. Alle ingestelde pagina’s blijven toegestaan.'
              : 'Beschikbaar als standaardprogramma voor een wallboardscherm.'}
          </small>
        </label>
        <label className="wallboard-switch-row wallboard-playlist-data-mode-control">
          <input
            type="checkbox"
            checked={draftDataMode === 'demo'}
            onChange={(event) => {
              setDraftDataMode(event.target.checked ? 'demo' : 'live');
              setActionError(null);
              setActionMessage(null);
            }}
          />
          <span>
            <strong>Demomodus {draftDataMode === 'demo' ? 'aan' : 'uit'}</strong>
            <small>Aan maakt dynamische operationele gegevens fictief; ingestelde teksten, foto&apos;s en video&apos;s blijven ongewijzigd. Alleen een alarmplaylist met LIVE DATA kan tijdens een actieve inzet worden geselecteerd.</small>
          </span>
          <WallboardPlaylistDataModePill mode={draftDataMode} />
        </label>
      </section>

      <WallboardConfigurationEditor
        idPrefix={`playlist-${playlist.id}`}
        configuration={draft}
        setConfiguration={(next) => {
          setActionError(null);
          setActionMessage(null);
          setDraft(next);
        }}
        photoPlaylists={{
          playlists: photoPlaylists,
          loading: photoPlaylistsLoading,
          error: photoPlaylistsError,
          reload: reloadPhotoPlaylists,
        }}
      />

      {missingPhotoCarouselPages.length > 0 ? (
        <p className="form-error" role="alert">
          Fotoplaylist ontbreekt bij {missingPhotoCarouselPages.map((page) => `“${page.name}”`).join(', ')}. Open de gemarkeerde pagina en kies een bestaande fotoplaylist.
        </p>
      ) : null}
      {actionError ? <p className="form-error" role="alert">{actionError}</p> : null}
      {actionMessage ? <p className="form-note" role="status">{actionMessage}</p> : null}
      {usageCount > 0 ? (
        <p className="wallboard-playlist-delete-help"><AlertTriangle size={16} aria-hidden /> Verwijderen kan pas nadat alle {usageCount === 1 ? 'scherm is' : 'schermen zijn'} toegewezen aan een andere playlist.</p>
      ) : null}

      <div className="wallboard-editor__actions">
        <button className="primary-button" type="submit" disabled={busyAction !== null || draftName.trim() === '' || hasUnverifiedVideos || hasInvalidPhotoCarousels}>
          <Save size={17} aria-hidden /> {busyAction === 'save' ? 'Opslaan…' : 'Playlist opslaan'}
        </button>
        {deleteConfirm ? (
          <span className="wallboard-delete-confirm">
            <span>Deze ongebruikte playlist definitief verwijderen?</span>
            <button className="danger-button" type="button" onClick={() => void deletePlaylist()} disabled={busyAction !== null || usageCount > 0}>Ja, verwijderen</button>
            <button className="secondary-button" type="button" onClick={() => setDeleteConfirm(false)}>Annuleren</button>
          </span>
        ) : (
          <button className="danger-button" type="button" onClick={() => setDeleteConfirm(true)} disabled={busyAction !== null || usageCount > 0} aria-describedby={usageCount > 0 ? `playlist-delete-help-${playlist.id}` : undefined}>
            <Trash2 size={17} aria-hidden /> Playlist verwijderen
          </button>
        )}
        {usageCount > 0 ? <span className="sr-only" id={`playlist-delete-help-${playlist.id}`}>Deze playlist is nog gekoppeld en kan niet worden verwijderd.</span> : null}
      </div>
    </form>
  );
}

function EditorEmpty({ icon, title, description }: { icon: 'screen' | 'playlist'; title: string; description: string }) {
  return (
    <div className="wallboard-editor-empty">
      {icon === 'screen' ? <MonitorCog size={32} aria-hidden /> : <Library size={32} aria-hidden />}
      <strong>{title}</strong>
      <span>{description}</span>
    </div>
  );
}

function displayPageLabel(wallboard: Wallboard): string | null {
  const configuration = wallboardConfigurationCopy(wallboard.configuration);
  const pageId = wallboard.display?.page_id ?? wallboard.manual_page_id;
  if (!pageId) return null;
  const page = configuration.pages.find((candidate) => candidate.id === pageId);
  return page ? `Nu: ${page.name}` : null;
}

function displayModeLabel(mode: 'rotation' | 'static' | 'manual' | 'incident_override', incidentActive: boolean): string {
  if (mode === 'manual') return 'Handmatig vastgezet door beheer';
  if (mode === 'incident_override') return 'Vastgezet zolang het incident actief is';
  if (mode === 'static') return 'Vaste pagina';
  return incidentActive ? 'Rotatie · incident actief zonder override' : 'Automatische rotatie';
}

function playlistUsageLabel(playlist: WallboardPlaylist): string {
  const count = wallboardPlaylistUsageCount(playlist);
  return `${count} ${count === 1 ? 'scherm' : 'schermen'}`;
}

function wallboardFocusPreviewLabel(kind: WallboardFocusKind): string {
  return WALLBOARD_FOCUS_PREVIEW_OPTIONS.find((option) => option.kind === kind)?.label ?? kind;
}

function retainedSelection<T extends { id: string }>(current: string | null, items: T[]): string | null {
  if (items.length === 0) return null;
  return current !== null && items.some((item) => item.id === current) ? current : items[0].id;
}

function formatTime(value: string): string {
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) return 'onbekend';
  return new Intl.DateTimeFormat('nl-NL', { timeStyle: 'medium' }).format(date);
}

function formatDateTime(value: string): string {
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) return 'onbekend';
  return new Intl.DateTimeFormat('nl-NL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}

function normalizePairingCode(value: string): string {
  return value.toUpperCase().replace(/[^A-Z0-9]/g, '');
}

function formatPairingCodeInput(value: string): string {
  const code = normalizePairingCode(value).slice(0, 8);
  if (code.length <= 4) return code;
  return `${code.slice(0, 4)} ${code.slice(4)}`;
}

function validPairingCode(value: string): boolean {
  return /^[A-HJ-NP-Z2-9]{8}$/.test(value);
}

function isConflict(error: unknown): boolean {
  return error instanceof ApiClientError && error.status === 409;
}

function isWallboard(value: Wallboard | null): value is Wallboard {
  return value !== null && typeof value === 'object' && typeof value.id === 'string';
}

function errorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiClientError ? error.message : fallback;
}
