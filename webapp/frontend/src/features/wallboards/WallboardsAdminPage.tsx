import { type FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import {
  AlertTriangle,
  ExternalLink,
  Eye,
  KeyRound,
  Library,
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
  WallboardPlaylist,
  WallboardPlaylistAssignment,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  DEFAULT_WALLBOARD_CONFIGURATION,
  clampRefreshSeconds,
  normalizeWallboardDisplayProfile,
  requestedWallboardScreenSelection,
  wallboardConfigurationCopy,
  wallboardConfigurationForSave,
  wallboardDisplayProfileLabel,
  wallboardIsOnline,
  wallboardPlaylistUsageCount,
} from './wallboardPresentation';
import {
  WallboardConfigurationEditor,
  WallboardPageTypeIcon,
} from './WallboardConfigurationEditor';
import { WallboardPlaylistPreview } from './WallboardPlaylistPreview';

const ADMIN_STATUS_REFRESH_MILLISECONDS = 2500;

type AdminSection = 'screens' | 'playlists';

export function WallboardsAdminPage() {
  const { api } = useAuth();
  const wallboardsResource = useApiResource<Wallboard[]>('/admin/wallboards');
  const playlistsResource = useApiResource<WallboardPlaylist[]>('/admin/wallboard-playlists');
  const { silentReload: silentReloadWallboards } = wallboardsResource;
  const { silentReload: silentReloadPlaylists } = playlistsResource;
  const [section, setSection] = useState<AdminSection>('screens');
  const [selectedScreenId, setSelectedScreenId] = useState<string | null>(null);
  const [selectedPlaylistId, setSelectedPlaylistId] = useState<string | null>(null);
  const [newPlaylistName, setNewPlaylistName] = useState('');
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
    ]);
  }, [silentReloadPlaylists, silentReloadWallboards]);

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
        configuration: wallboardConfigurationForSave(
          wallboardConfigurationCopy(DEFAULT_WALLBOARD_CONFIGURATION),
        ),
      });
      playlistsResource.mutate((current) => [...(current ?? []), response.data]);
      setSelectedPlaylistId(response.data.id);
      setNewPlaylistName('');
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
          <a className="secondary-button" href="/wallboard" target="_blank" rel="noreferrer">
            <ExternalLink size={17} aria-hidden /> Scherm openen
          </a>
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
      </nav>

      <Panel title={section === 'screens' ? 'Schermen' : 'Playlists'} action={(
        <button className="icon-button" type="button" onClick={() => void reloadAll()} aria-label="Schermen en playlists vernieuwen">
          <RefreshCw size={17} aria-hidden />
        </button>
      )}>
        <div
          className="panel-body wallboards-admin-grid"
          id={section === 'screens' ? 'wallboards-screens-panel' : 'wallboards-playlists-panel'}
          role="tabpanel"
          aria-labelledby={section === 'screens' ? 'wallboards-screens-tab' : 'wallboards-playlists-tab'}
        >
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
                        <StatusPill value={!wallboard.is_enabled ? 'Uitgeschakeld' : online ? 'Online' : 'Offline'} tone={!wallboard.is_enabled ? 'neutral' : online ? 'good' : 'warn'} />
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
                        <StatusPill value={playlistUsageLabel(playlist)} tone={usageCount > 1 ? 'warn' : usageCount === 1 ? 'good' : 'neutral'} />
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
              onReplace={replacePlaylist}
              onReloadAll={reloadAll}
              onDeleted={() => {
                playlistsResource.mutate((current) => (current ?? []).filter((playlist) => playlist.id !== selectedPlaylist.id));
                setSelectedPlaylistId(null);
              }}
            />
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
  const [tvPairingCode, setTvPairingCode] = useState('');
  const [pairingInputInvalid, setPairingInputInvalid] = useState(false);
  const [busyAction, setBusyAction] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [deleteConfirm, setDeleteConfirm] = useState(false);
  const savedConfiguration = wallboardConfigurationCopy(wallboard.configuration);
  const selectedPlaylist = playlists.find((playlist) => playlist.id === draftPlaylistId) ?? null;
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

  useEffect(() => {
    setDraftName(wallboard.name);
    setDraftEnabled(wallboard.is_enabled);
    setDraftDisplayProfile(normalizeWallboardDisplayProfile(wallboard.display_profile));
    setDraftPlaylistId(wallboard.playlist_id);
  }, [wallboard.display_profile, wallboard.is_enabled, wallboard.name, wallboard.playlist_id]);

  async function saveScreen(event: FormEvent) {
    event.preventDefault();
    const name = draftName.trim();
    if (name === '' || draftPlaylistId === '') return;
    const metadataChanged = name !== wallboard.name
      || draftEnabled !== wallboard.is_enabled
      || draftDisplayProfile !== wallboard.display_profile;
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
        <StatusPill value={wallboard.active_sessions_count > 0 ? 'Gekoppeld' : 'Niet gekoppeld'} tone={wallboard.active_sessions_count > 0 ? 'good' : 'neutral'} />
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
        <button className="secondary-button wallboard-live-control__resume" type="button" onClick={() => void controlDisplay(null)} disabled={busyAction !== null || display.mode === 'rotation' || !wallboard.is_enabled}>
          <RotateCcw size={17} aria-hidden /> Rotatie hervatten
        </button>
      </section>

      <section className="wallboard-tv-pairing" aria-labelledby={`wallboard-pair-${wallboard.id}`}>
        <span className="wallboard-tv-pairing__icon"><KeyRound size={20} aria-hidden /></span>
        <div className="wallboard-tv-pairing__copy">
          <small>Schermkoppeling</small>
          <strong id={`wallboard-pair-${wallboard.id}`}>Tv koppelen</strong>
          <span>Open <b>/wallboard</b> op de tv. Vul hier de code in die automatisch op het scherm verschijnt.</span>
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
          <KeyRound size={17} aria-hidden /> {busyAction === 'pair' ? 'Koppelen…' : 'Tv koppelen'}
        </button>
      </section>

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
            <span>Toegewezen playlist</span>
            <select value={draftPlaylistId} onChange={(event) => setDraftPlaylistId(event.target.value)} required>
              {playlists.map((playlist) => (
                <option key={playlist.id} value={playlist.id}>{playlist.name} · {playlistUsageLabel(playlist)}</option>
              ))}
              {!playlists.some((playlist) => playlist.id === wallboard.playlist_id) ? (
                <option value={wallboard.playlist_id}>{wallboard.playlist.name}</option>
              ) : null}
            </select>
            <small>
              {selectedPlaylist && wallboardPlaylistUsageCount(selectedPlaylist) > 1
                ? `Gedeeld door ${wallboardPlaylistUsageCount(selectedPlaylist)} schermen.`
                : 'Wijzig de inhoud onder Playlists; dit scherm neemt die versie direct over.'}
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
        <button className="primary-button" type="submit" disabled={busyAction !== null || draftName.trim() === '' || draftPlaylistId === ''}>
          <Save size={17} aria-hidden /> {busyAction === 'save' ? 'Opslaan…' : 'Scherm opslaan'}
        </button>
        <button className="secondary-button" type="button" onClick={() => void revokeSessions()} disabled={busyAction !== null || wallboard.active_sessions_count === 0}>
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
  onReplace: (playlist: WallboardPlaylist) => void;
  onReloadAll: () => Promise<void>;
  onDeleted: () => void;
}

function PlaylistEditor({ playlist, onReplace, onReloadAll, onDeleted }: PlaylistEditorProps) {
  const { api } = useAuth();
  const [draftName, setDraftName] = useState(playlist.name);
  const [draft, setDraft] = useState<WallboardConfiguration>(() => wallboardConfigurationCopy(playlist.configuration));
  const [busyAction, setBusyAction] = useState<'save' | 'delete' | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [deleteConfirm, setDeleteConfirm] = useState(false);
  const [previewOpen, setPreviewOpen] = useState(false);
  const usageCount = wallboardPlaylistUsageCount(playlist);

  useEffect(() => {
    setDraftName(playlist.name);
    setDraft(wallboardConfigurationCopy(playlist.configuration));
    setDeleteConfirm(false);
    setPreviewOpen(false);
  }, [playlist.configuration, playlist.name, playlist.version]);

  async function savePlaylist(event: FormEvent) {
    event.preventDefault();
    const name = draftName.trim();
    if (name === '') return;
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
          <StatusPill value={`Versie ${playlist.version}`} tone="neutral" />
        </div>
      </div>

      {previewOpen ? (
        <WallboardPlaylistPreview
          playlistName={draftName.trim() || playlist.name}
          configuration={draft}
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
      </section>

      <WallboardConfigurationEditor
        idPrefix={`playlist-${playlist.id}`}
        configuration={draft}
        setConfiguration={setDraft}
      />

      {actionError ? <p className="form-error" role="alert">{actionError}</p> : null}
      {actionMessage ? <p className="form-note" role="status">{actionMessage}</p> : null}
      {usageCount > 0 ? (
        <p className="wallboard-playlist-delete-help"><AlertTriangle size={16} aria-hidden /> Verwijderen kan pas nadat alle {usageCount === 1 ? 'scherm is' : 'schermen zijn'} toegewezen aan een andere playlist.</p>
      ) : null}

      <div className="wallboard-editor__actions">
        <button className="primary-button" type="submit" disabled={busyAction !== null || draftName.trim() === ''}>
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
