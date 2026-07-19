import { type FormEvent, useEffect, useMemo, useState } from 'react';
import {
  ArrowDown,
  ArrowUp,
  BarChart3,
  ExternalLink,
  Eye,
  KeyRound,
  List,
  Map,
  MessageSquareText,
  MonitorCog,
  PauseCircle,
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
  WallboardMapConfiguration,
  WallboardPage,
  WallboardPageType,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  DEFAULT_WALLBOARD_CONFIGURATION,
  MAX_WALLBOARD_PAGE_DURATION_SECONDS,
  MAX_WALLBOARD_REFRESH_SECONDS,
  MIN_WALLBOARD_PAGE_DURATION_SECONDS,
  MIN_WALLBOARD_REFRESH_SECONDS,
  clampRefreshSeconds,
  clampWallboardPageDuration,
  createWallboardPage,
  wallboardConfigurationCopy,
  wallboardConfigurationForSave,
  wallboardIsOnline,
  wallboardPageTypeLabel,
} from './wallboardPresentation';

const ADMIN_STATUS_REFRESH_MILLISECONDS = 2500;

const MAP_OPTION_LABELS: Array<{ key: keyof WallboardMapConfiguration; label: string; help: string }> = [
  { key: 'show_active_incidents', label: 'Actieve incidenten', help: 'Toon open operationele meldingen.' },
  { key: 'show_test_incidents', label: 'Proefmeldingen', help: 'Neem ook als test gemarkeerde incidenten op.' },
  { key: 'show_live_locations', label: 'Live piloten', help: 'Toon alleen actuele, gedeelde locaties.' },
  { key: 'show_routes', label: 'Navigatieroutes', help: 'Teken de actuele route van piloten naar het incident.' },
  { key: 'show_command_centers', label: 'Meldkamers', help: 'Toon de geconfigureerde meldkamers.' },
  { key: 'show_historical_incidents', label: 'Historische incidenten', help: 'Toon gesloten incidenten uit de wallboardfeed.' },
  { key: 'show_summary', label: 'Samenvattingsbalk', help: 'Toon aantallen boven een kaartpagina.' },
  { key: 'show_incident_list', label: 'Incidentenlijst naast kaart', help: 'Toon een compacte lijst naast een kaartpagina.' },
  { key: 'show_route_legend', label: 'Routelabels', help: 'Toon pilootnaam en route-informatie.' },
  { key: 'auto_fit', label: 'Automatisch kaderen', help: 'Houd alle zichtbare kaartpunten binnen beeld.' },
];

const PAGE_TYPE_OPTIONS: Array<{ value: WallboardPageType; label: string }> = [
  { value: 'map', label: 'Kaart' },
  { value: 'incident_list', label: 'Incidentenlijst' },
  { value: 'summary', label: 'Samenvatting' },
  { value: 'message', label: 'Mededeling' },
];

export function WallboardsAdminPage() {
  const { api } = useAuth();
  const resource = useApiResource<Wallboard[]>('/admin/wallboards');
  const { silentReload } = resource;
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [newName, setNewName] = useState('');
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);
  const wallboards = useMemo(() => resource.data ?? [], [resource.data]);
  const selected = wallboards.find((wallboard) => wallboard.id === selectedId) ?? null;

  useEffect(() => {
    if (selectedId === null && wallboards.length > 0) setSelectedId(wallboards[0].id);
    if (selectedId !== null && wallboards.length > 0 && !wallboards.some((wallboard) => wallboard.id === selectedId)) {
      setSelectedId(wallboards[0].id);
    }
  }, [selectedId, wallboards]);

  useEffect(() => {
    const timer = window.setInterval(() => void silentReload(), ADMIN_STATUS_REFRESH_MILLISECONDS);
    return () => window.clearInterval(timer);
  }, [silentReload]);

  async function createWallboard(event: FormEvent) {
    event.preventDefault();
    const name = newName.trim();
    if (name === '') return;
    setCreating(true);
    setCreateError(null);
    try {
      const response = await api.post<Wallboard>('/admin/wallboards', {
        name,
        layout: 'fullscreen_map',
        configuration: wallboardConfigurationCopy(DEFAULT_WALLBOARD_CONFIGURATION),
        is_enabled: true,
      });
      resource.mutate((current) => [...(current ?? []), response.data]);
      setSelectedId(response.data.id);
      setNewName('');
    } catch (error) {
      setCreateError(errorMessage(error, 'Wallboard kon niet worden aangemaakt.'));
    } finally {
      setCreating(false);
    }
  }

  function replaceWallboard(next: Wallboard) {
    resource.mutate((current) => (current ?? []).map((wallboard) => wallboard.id === next.id ? next : wallboard));
  }

  return (
    <div className="page-stack wallboards-admin-page">
      <header className="page-heading wallboards-admin-heading">
        <div>
          <span className="eyebrow">Beheer</span>
          <h1>Wallboards</h1>
          <p>Stel pagina’s samen, bestuur ieder scherm live en laat een incidentpagina automatisch voorrang krijgen.</p>
        </div>
        <a className="secondary-button" href="/wallboard" target="_blank" rel="noreferrer">
          <ExternalLink size={17} aria-hidden /> Scherm openen
        </a>
      </header>

      <Panel title="Wallboards" action={(
        <button className="icon-button" type="button" onClick={() => void resource.reload()} aria-label="Wallboards vernieuwen">
          <RefreshCw size={17} aria-hidden />
        </button>
      )}>
        <div className="panel-body wallboards-admin-grid">
          <div className="wallboards-admin-list-column">
            <form className="wallboard-create-form" onSubmit={createWallboard}>
              <label>
                <span>Nieuw wallboard</span>
                <span className="wallboard-create-form__row">
                  <input value={newName} onChange={(event) => setNewName(event.target.value)} maxLength={120} placeholder="Bijv. Meldkamer noord" required />
                  <button className="primary-button" type="submit" disabled={creating || newName.trim() === ''}>
                    <Plus size={17} aria-hidden /> {creating ? 'Toevoegen…' : 'Toevoegen'}
                  </button>
                </span>
              </label>
              {createError ? <p className="form-error" role="alert">{createError}</p> : null}
            </form>

            <ResourceState loading={resource.loading} error={resource.error} empty={wallboards.length === 0}>
              <div className="wallboard-list" role="list" aria-label="Geconfigureerde wallboards">
                {wallboards.map((wallboard) => {
                  const online = wallboardIsOnline(wallboard);
                  const displayLabel = displayPageLabel(wallboard);
                  return (
                    <button
                      className={`wallboard-list-card ${selected?.id === wallboard.id ? 'wallboard-list-card--active' : ''}`}
                      key={wallboard.id}
                      type="button"
                      onClick={() => setSelectedId(wallboard.id)}
                      role="listitem"
                    >
                      <span className="wallboard-list-card__icon"><MonitorCog size={20} aria-hidden /></span>
                      <span className="wallboard-list-card__copy">
                        <strong>{wallboard.name}</strong>
                        <small>{displayLabel ?? (wallboard.last_seen_at ? `Laatst gezien ${formatDateTime(wallboard.last_seen_at)}` : 'Nog niet gekoppeld')}</small>
                      </span>
                      <StatusPill value={!wallboard.is_enabled ? 'Uitgeschakeld' : online ? 'Online' : 'Offline'} tone={!wallboard.is_enabled ? 'neutral' : online ? 'good' : 'warn'} />
                    </button>
                  );
                })}
              </div>
            </ResourceState>
          </div>

          {selected === null ? (
            <div className="wallboard-editor-empty">
              <MonitorCog size={32} aria-hidden />
              <strong>Selecteer of maak een wallboard</strong>
              <span>De livebesturing en paginaopbouw verschijnen hier.</span>
            </div>
          ) : (
            <WallboardEditor
              key={`${selected.id}:${selected.config_version}`}
              wallboard={selected}
              onReplace={replaceWallboard}
              onReload={silentReload}
              onDeleted={() => {
                resource.mutate((current) => (current ?? []).filter((wallboard) => wallboard.id !== selected.id));
                setSelectedId(null);
              }}
            />
          )}
        </div>
      </Panel>
    </div>
  );
}

interface WallboardEditorProps {
  wallboard: Wallboard;
  onReplace: (wallboard: Wallboard) => void;
  onReload: () => Promise<void>;
  onDeleted: () => void;
}

function WallboardEditor({ wallboard, onReplace, onReload, onDeleted }: WallboardEditorProps) {
  const { api } = useAuth();
  const [draft, setDraft] = useState<WallboardConfiguration>(() => wallboardConfigurationCopy(wallboard.configuration));
  const [draftName, setDraftName] = useState(wallboard.name);
  const [draftEnabled, setDraftEnabled] = useState(wallboard.is_enabled);
  const [newPageType, setNewPageType] = useState<WallboardPageType>('map');
  const [editingPageId, setEditingPageId] = useState(() => draft.pages[0].id);
  const [tvPairingCode, setTvPairingCode] = useState('');
  const [pairingInputInvalid, setPairingInputInvalid] = useState(false);
  const [busyAction, setBusyAction] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [deleteConfirm, setDeleteConfirm] = useState(false);
  const savedConfiguration = wallboardConfigurationCopy(wallboard.configuration);
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
  const editingPage = draft.pages.find((page) => page.id === editingPageId) ?? draft.pages[0];

  async function saveWallboard(event: FormEvent) {
    event.preventDefault();
    if (draftName.trim() === '') return;
    setBusyAction('save');
    setActionError(null);
    setActionMessage(null);
    try {
      const configuration = wallboardConfigurationForSave({
        ...draft,
        refresh_seconds: clampRefreshSeconds(draft.refresh_seconds),
      });
      const response = await api.patch<Wallboard>(`/admin/wallboards/${wallboard.id}`, {
        name: draftName.trim(),
        layout: 'fullscreen_map',
        configuration,
        is_enabled: draftEnabled,
        expected_config_version: wallboard.config_version,
      });
      onReplace(response.data);
      setActionMessage('Pagina’s en instellingen opgeslagen. Het scherm neemt ze direct over.');
    } catch (error) {
      if (isConflict(error)) {
        await onReload();
        setActionError('De configuratie was intussen gewijzigd. De nieuwste versie is geladen; controleer je wijzigingen opnieuw.');
      } else {
        setActionError(errorMessage(error, 'Wallboard kon niet worden opgeslagen.'));
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
      else await onReload();
      setActionMessage(pageId === null ? 'De automatische paginarotatie is hervat.' : 'De gekozen pagina wordt nu op het wallboard getoond.');
    } catch (error) {
      if (isConflict(error)) {
        await onReload();
        setActionError('Een andere beheerder bestuurde dit wallboard intussen. De actuele schermstatus is opnieuw geladen.');
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
      await onReload();
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
    try {
      await api.post(`/admin/wallboards/${wallboard.id}/sessions/revoke`);
      await onReload();
      setActionMessage('De schermkoppeling is ingetrokken. Het scherm moet opnieuw worden gekoppeld.');
    } catch (error) {
      setActionError(errorMessage(error, 'Wallboardsessie kon niet worden ingetrokken.'));
    } finally {
      setBusyAction(null);
    }
  }

  async function deleteWallboard() {
    if (!deleteConfirm) return;
    setBusyAction('delete');
    setActionError(null);
    try {
      await api.delete(`/admin/wallboards/${wallboard.id}`);
      onDeleted();
    } catch (error) {
      setActionError(errorMessage(error, 'Wallboard kon niet worden verwijderd.'));
    } finally {
      setBusyAction(null);
    }
  }

  function addPage() {
    const page = createWallboardPage(newPageType, draft.pages.length + 1);
    setDraft((current) => ({ ...current, pages: [...current.pages, page] }));
    setEditingPageId(page.id);
  }

  function updatePage(pageId: string, update: (page: WallboardPage) => WallboardPage) {
    setDraft((current) => ({
      ...current,
      pages: current.pages.map((page) => page.id === pageId ? update(page) : page),
    }));
  }

  function removePage(pageId: string) {
    if (draft.pages.length <= 1) return;
    setDraft((current) => {
      const pages = current.pages.filter((page) => page.id !== pageId);
      const overridePageId = current.incident_override.page_id === pageId ? pages[0].id : current.incident_override.page_id;
      return {
        ...current,
        pages,
        incident_override: { ...current.incident_override, page_id: overridePageId },
      };
    });
    const nextPage = draft.pages.find((page) => page.id !== pageId);
    if (editingPageId === pageId && nextPage) setEditingPageId(nextPage.id);
  }

  function movePage(pageId: string, direction: -1 | 1) {
    setDraft((current) => {
      const index = current.pages.findIndex((page) => page.id === pageId);
      const nextIndex = index + direction;
      if (index < 0 || nextIndex < 0 || nextIndex >= current.pages.length) return current;
      const pages = [...current.pages];
      [pages[index], pages[nextIndex]] = [pages[nextIndex], pages[index]];
      return { ...current, pages };
    });
  }

  function setMapOption(key: keyof WallboardMapConfiguration, checked: boolean) {
    setDraft((current) => ({
      ...current,
      map: {
        ...current.map,
        [key]: checked,
        ...(key === 'show_live_locations' && !checked ? { show_routes: false } : {}),
      },
    }));
  }

  return (
    <form className="wallboard-editor" onSubmit={saveWallboard}>
      <div className="wallboard-editor__heading">
        <div>
          <span className="eyebrow">Live schermregie</span>
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
              <PageTypeIcon type={page.type} />
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

      <div className="wallboard-editor__fields">
        <label>
          <span>Naam</span>
          <input value={draftName} onChange={(event) => setDraftName(event.target.value)} maxLength={120} required />
        </label>
        <label>
          <span>Thema</span>
          <select value={draft.theme} onChange={(event) => setDraft((current) => ({ ...current, theme: event.target.value as WallboardConfiguration['theme'] }))}>
            <option value="dark">Donker</option>
            <option value="light">Licht</option>
          </select>
        </label>
        <label>
          <span>Data verversen (seconden)</span>
          <input
            type="number"
            min={MIN_WALLBOARD_REFRESH_SECONDS}
            max={MAX_WALLBOARD_REFRESH_SECONDS}
            value={draft.refresh_seconds}
            onChange={(event) => setDraft((current) => ({ ...current, refresh_seconds: Number(event.target.value) }))}
            required
          />
        </label>
        <label className="wallboard-switch-row">
          <input type="checkbox" checked={draftEnabled} onChange={(event) => setDraftEnabled(event.target.checked)} />
          <span><strong>Wallboard actief</strong><small>Uitgeschakelde schermen krijgen geen informatie meer.</small></span>
        </label>
        <label className="wallboard-switch-row">
          <input type="checkbox" checked={draft.rotation_enabled} onChange={(event) => setDraft((current) => ({ ...current, rotation_enabled: event.target.checked }))} />
          <span><strong>Pagina’s automatisch roteren</strong><small>Iedere pagina blijft zichtbaar gedurende de ingestelde tijd.</small></span>
        </label>
      </div>

      <fieldset className="wallboard-option-grid wallboard-global-options">
        <legend>Gegevens en kaartlagen</legend>
        {MAP_OPTION_LABELS.map((option) => (
          <label key={option.key}>
            <input
              type="checkbox"
              checked={draft.map[option.key]}
              disabled={option.key === 'show_routes' && !draft.map.show_live_locations}
              onChange={(event) => setMapOption(option.key, event.target.checked)}
            />
            <span><strong>{option.label}</strong><small>{option.help}</small></span>
          </label>
        ))}
      </fieldset>

      <section className="wallboard-page-composer" aria-labelledby={`wallboard-pages-${wallboard.id}`}>
        <div className="wallboard-page-composer__heading">
          <div>
            <span className="eyebrow">Programmering</span>
            <h3 id={`wallboard-pages-${wallboard.id}`}>Pagina’s en volgorde</h3>
          </div>
          <div className="wallboard-page-add">
            <label className="sr-only" htmlFor={`wallboard-page-type-${wallboard.id}`}>Nieuw paginatype</label>
            <select id={`wallboard-page-type-${wallboard.id}`} value={newPageType} onChange={(event) => setNewPageType(event.target.value as WallboardPageType)}>
              {PAGE_TYPE_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
            <button className="secondary-button" type="button" onClick={addPage}>
              <Plus size={17} aria-hidden /> Pagina toevoegen
            </button>
          </div>
        </div>

        <ol className="wallboard-page-sequence">
          {draft.pages.map((page, index) => (
            <li className={editingPage.id === page.id ? 'wallboard-page-sequence__item wallboard-page-sequence__item--active' : 'wallboard-page-sequence__item'} key={page.id}>
              <button className="wallboard-page-sequence__select" type="button" onClick={() => setEditingPageId(page.id)} aria-current={editingPage.id === page.id ? 'step' : undefined}>
                <span className="wallboard-page-sequence__number">{index + 1}</span>
                <PageTypeIcon type={page.type} />
                <span><strong>{page.name}</strong><small>{wallboardPageTypeLabel(page.type)} · {page.duration_seconds} sec.</small></span>
              </button>
              <span className="wallboard-page-sequence__actions">
                <button type="button" onClick={() => movePage(page.id, -1)} disabled={index === 0} aria-label={`${page.name} omhoog verplaatsen`}><ArrowUp size={16} aria-hidden /></button>
                <button type="button" onClick={() => movePage(page.id, 1)} disabled={index === draft.pages.length - 1} aria-label={`${page.name} omlaag verplaatsen`}><ArrowDown size={16} aria-hidden /></button>
                <button type="button" onClick={() => removePage(page.id)} disabled={draft.pages.length <= 1} aria-label={`${page.name} verwijderen`}><Trash2 size={16} aria-hidden /></button>
              </span>
            </li>
          ))}
        </ol>

        <WallboardPageEditor page={editingPage} onChange={(next) => updatePage(editingPage.id, () => next)} />
      </section>

      <fieldset className="wallboard-incident-override">
        <legend>Automatisch bij een actief incident</legend>
        <label className="wallboard-switch-row">
          <input
            type="checkbox"
            checked={draft.incident_override.enabled}
            onChange={(event) => setDraft((current) => ({
              ...current,
              incident_override: {
                enabled: event.target.checked,
                page_id: current.incident_override.page_id ?? current.pages[0].id,
              },
            }))}
          />
          <span><strong>Incidentpagina vastzetten</strong><small>De rotatie pauzeert zolang minimaal één operationeel incident actief is.</small></span>
        </label>
        <label>
          <span>Pagina tijdens incident</span>
          <select
            value={draft.incident_override.page_id ?? ''}
            disabled={!draft.incident_override.enabled}
            onChange={(event) => setDraft((current) => ({
              ...current,
              incident_override: { ...current.incident_override, page_id: event.target.value },
            }))}
          >
            {draft.pages.map((page) => <option key={page.id} value={page.id}>{page.name}</option>)}
          </select>
        </label>
        <p><PauseCircle size={16} aria-hidden /> Een actief incident heeft voorrang; daarna keert het scherm terug naar de handmatige pagina of rotatie.</p>
      </fieldset>

      {actionError ? <p className="form-error" role="alert">{actionError}</p> : null}
      {actionMessage ? <p className="form-note" role="status">{actionMessage}</p> : null}

      <div className="wallboard-editor__actions">
        <button className="primary-button" type="submit" disabled={busyAction !== null || draftName.trim() === ''}>
          <Save size={17} aria-hidden /> {busyAction === 'save' ? 'Opslaan…' : 'Alles opslaan'}
        </button>
        <button className="secondary-button" type="button" onClick={() => void revokeSessions()} disabled={busyAction !== null || wallboard.active_sessions_count === 0}>
          <ShieldOff size={17} aria-hidden /> Koppeling intrekken
        </button>
        {deleteConfirm ? (
          <span className="wallboard-delete-confirm">
            <span>Definitief verwijderen?</span>
            <button className="danger-button" type="button" onClick={() => void deleteWallboard()} disabled={busyAction !== null}>Ja, verwijderen</button>
            <button className="secondary-button" type="button" onClick={() => setDeleteConfirm(false)}>Annuleren</button>
          </span>
        ) : (
          <button className="danger-button" type="button" onClick={() => setDeleteConfirm(true)} disabled={busyAction !== null}>
            <Trash2 size={17} aria-hidden /> Verwijderen
          </button>
        )}
      </div>
    </form>
  );
}

function WallboardPageEditor({ page, onChange }: { page: WallboardPage; onChange: (page: WallboardPage) => void }) {
  function updateType(type: WallboardPageType) {
    const previousDefaultTitle = wallboardPageTypeLabel(page.type);
    onChange({
      ...page,
      type,
      name: page.name === previousDefaultTitle ? wallboardPageTypeLabel(type) : page.name,
      options: type === 'message'
        ? { body: page.options.body ?? '' }
        : ['incident_list', 'summary'].includes(type)
          ? { show_test_incidents: page.options.show_test_incidents ?? false }
          : {},
    });
  }

  return (
    <div className="wallboard-page-editor">
      <div className="wallboard-page-editor__heading">
        <PageTypeIcon type={page.type} />
        <div><small>Pagina bewerken</small><strong>{page.name}</strong></div>
      </div>
      <div className="wallboard-page-editor__fields">
        <label>
          <span>Titel</span>
          <input value={page.name} onChange={(event) => onChange({ ...page, name: event.target.value })} maxLength={120} required />
        </label>
        <label>
          <span>Type</span>
          <select value={page.type} onChange={(event) => updateType(event.target.value as WallboardPageType)}>
            {PAGE_TYPE_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </label>
        <label>
          <span>Tijd op scherm (seconden)</span>
          <input
            type="number"
            min={MIN_WALLBOARD_PAGE_DURATION_SECONDS}
            max={MAX_WALLBOARD_PAGE_DURATION_SECONDS}
            value={page.duration_seconds}
            onChange={(event) => onChange({ ...page, duration_seconds: clampWallboardPageDuration(Number(event.target.value)) })}
            required
          />
        </label>
      </div>

      {page.type === 'message' ? (
        <label className="wallboard-message-editor">
          <span>Tekst op het scherm</span>
          <textarea
            value={page.options.body ?? ''}
            onChange={(event) => onChange({ ...page, options: { ...page.options, body: event.target.value } })}
            maxLength={2000}
            rows={5}
            placeholder="Schrijf hier de mededeling voor het wallboard."
            required
          />
          <small>{(page.options.body ?? '').length}/2000 tekens · uitsluitend platte tekst</small>
        </label>
      ) : (
        <div className="wallboard-page-editor__page-options">
          <p className="wallboard-page-editor__hint">Deze pagina gebruikt de geselecteerde gegevens en kaartlagen van het wallboard.</p>
          {['incident_list', 'summary'].includes(page.type) ? (
            <label className="wallboard-switch-row">
              <input
                type="checkbox"
                checked={page.options.show_test_incidents === true}
                onChange={(event) => onChange({
                  ...page,
                  options: { ...page.options, show_test_incidents: event.target.checked },
                })}
              />
              <span><strong>Proefmeldingen op deze pagina</strong><small>Dit kan per incidentenlijst of samenvatting afwijken.</small></span>
            </label>
          ) : null}
        </div>
      )}
    </div>
  );
}

function PageTypeIcon({ type }: { type: WallboardPageType }) {
  switch (type) {
    case 'map': return <Map size={18} aria-hidden />;
    case 'incident_list': return <List size={18} aria-hidden />;
    case 'summary': return <BarChart3 size={18} aria-hidden />;
    case 'message': return <MessageSquareText size={18} aria-hidden />;
  }
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
