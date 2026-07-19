'use client';

import { type FormEvent, useMemo, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft, MonitorCog } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type {
  Wallboard,
  WallboardDisplayProfile,
  WallboardPlaylist,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { wallboardPlaylistUsageCount } from './wallboardPresentation';

export interface WallboardScreenCreateValues {
  name: string;
  displayProfile: WallboardDisplayProfile;
  playlistId: string;
}

export function wallboardScreenCreatePayload(values: WallboardScreenCreateValues) {
  return {
    name: values.name.trim(),
    layout: 'fullscreen_map' as const,
    display_profile: values.displayProfile,
    is_enabled: true,
    ...(values.playlistId === '' ? {} : { playlist_id: values.playlistId }),
  };
}

export function WallboardCreatePage() {
  const { api } = useAuth();
  const router = useRouter();
  const playlistsResource = useApiResource<WallboardPlaylist[]>('/admin/wallboard-playlists');
  const playlists = useMemo(() => playlistsResource.data ?? [], [playlistsResource.data]);
  const [name, setName] = useState('');
  const [displayProfile, setDisplayProfile] = useState<WallboardDisplayProfile>('auto');
  const [playlistId, setPlaylistId] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  async function createScreen(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (name.trim() === '' || submitting) return;

    setSubmitting(true);
    setSubmitError(null);
    try {
      const response = await api.post<Wallboard>('/admin/wallboards', wallboardScreenCreatePayload({
        name,
        displayProfile,
        playlistId,
      }));
      router.replace(`/wallboards?screen=${encodeURIComponent(response.data.id)}`);
    } catch (error) {
      setSubmitError(error instanceof ApiClientError ? error.message : 'Scherm kon niet worden aangemaakt.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="page-stack wallboard-create-page">
      <header className="page-heading wallboard-create-page__heading">
        <div>
          <span className="eyebrow">Wallboards</span>
          <h1>Scherm toevoegen</h1>
          <p>Maak een beheerd scherm aan. De tv koppelt daarna zonder toetsenbord via de getoonde koppelcode.</p>
        </div>
        <Link className="secondary-button" href="/wallboards">
          <ArrowLeft size={17} aria-hidden /> Terug naar wallboards
        </Link>
      </header>

      <Panel title="Nieuw wallboardscherm">
        <div className="panel-body wallboard-create-page__body">
          <form className="wallboard-create-form wallboard-create-page__form" onSubmit={createScreen}>
            <div className="wallboard-create-form__heading">
              <strong>Scherminstellingen</strong>
              <small>Zonder playlistkeuze krijgt het scherm automatisch een eigen playlist.</small>
            </div>
            <label>
              <span>Schermnaam</span>
              <input
                value={name}
                onChange={(event) => setName(event.target.value)}
                maxLength={120}
                placeholder="Bijv. Meldkamer noord"
                autoComplete="off"
                required
              />
            </label>
            <label>
              <span>Schermprofiel</span>
              <select value={displayProfile} onChange={(event) => setDisplayProfile(event.target.value as WallboardDisplayProfile)}>
                <option value="auto">Auto (aanbevolen)</option>
                <option value="1080p">1080p (Full HD)</option>
                <option value="4k">4K (Ultra HD)</option>
              </select>
              <small>Dit schaalt de wallboardindeling; de tv-uitvoerresolutie blijft een apparaatinstelling.</small>
            </label>
            <label>
              <span>Playlist bij aanmaak</span>
              <select
                value={playlistId}
                onChange={(event) => setPlaylistId(event.target.value)}
                disabled={playlistsResource.loading}
              >
                <option value="">Nieuwe eigen playlist</option>
                {playlists.map((playlist) => {
                  const count = wallboardPlaylistUsageCount(playlist);
                  return <option key={playlist.id} value={playlist.id}>{playlist.name} · {count} {count === 1 ? 'scherm' : 'schermen'}</option>;
                })}
              </select>
              {playlistsResource.loading ? <small role="status">Playlists laden…</small> : null}
            </label>

            {playlistsResource.error ? <p className="form-error" role="alert">Playlists konden niet worden geladen: {playlistsResource.error}</p> : null}
            {submitError ? <p className="form-error" role="alert">{submitError}</p> : null}

            <div className="wallboard-create-page__actions">
              <button className="primary-button" type="submit" disabled={submitting || name.trim() === ''}>
                <MonitorCog size={17} aria-hidden /> {submitting ? 'Scherm toevoegen…' : 'Scherm toevoegen'}
              </button>
              <Link className="secondary-button" href="/wallboards">Annuleren</Link>
            </div>
          </form>
        </div>
      </Panel>
    </div>
  );
}
