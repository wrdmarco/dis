import { type Dispatch, type SetStateAction, useEffect, useState } from 'react';
import {
  ArrowDown,
  ArrowUp,
  BarChart3,
  BellRing,
  List,
  Map,
  MessageSquareText,
  Newspaper,
  PauseCircle,
  Plus,
  Radio,
  Rss,
  Siren,
  Trash2,
  UsersRound,
} from 'lucide-react';
import type {
  WallboardConfiguration,
  WallboardCustomNewsSource,
  WallboardFocusKind,
  WallboardMapConfiguration,
  WallboardNewsSource,
  WallboardPage,
  WallboardPageType,
  WallboardTickerSource,
  WallboardTickerSourceType,
} from '../../types/api';
import {
  MAX_WALLBOARD_FOCUS_DURATION_SECONDS,
  MAX_WALLBOARD_CUSTOM_NEWS_SOURCES,
  MAX_WALLBOARD_CUSTOM_NEWS_SOURCE_LABEL_LENGTH,
  MAX_WALLBOARD_CUSTOM_NEWS_SOURCE_URL_LENGTH,
  MAX_WALLBOARD_NEWS_MAX_ITEMS,
  MAX_WALLBOARD_PAGE_DURATION_SECONDS,
  MAX_WALLBOARD_REFRESH_SECONDS,
  MAX_WALLBOARD_RSS_MAX_ITEMS,
  MAX_WALLBOARD_TICKER_SOURCES,
  MIN_WALLBOARD_FOCUS_DURATION_SECONDS,
  MIN_WALLBOARD_NEWS_MAX_ITEMS,
  MIN_WALLBOARD_PAGE_DURATION_SECONDS,
  MIN_WALLBOARD_REFRESH_SECONDS,
  MIN_WALLBOARD_RSS_MAX_ITEMS,
  clampWallboardFocusDuration,
  clampWallboardNewsMaxItems,
  clampWallboardPageDuration,
  clampWallboardRssMaxItems,
  createWallboardCustomNewsSource,
  createWallboardPage,
  createWallboardTickerSource,
  normalizeWallboardNewsSources,
  wallboardPageTypeLabel,
} from './wallboardPresentation';

const MAP_OPTION_LABELS: Array<{ key: keyof WallboardMapConfiguration; label: string; help: string }> = [
  { key: 'show_active_incidents', label: 'Actieve incidenten', help: 'Toon open operationele meldingen.' },
  { key: 'show_live_locations', label: 'Live pilootlocaties', help: 'Toon alleen actuele, gedeelde locaties.' },
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
  { value: 'news', label: 'Nieuws' },
];

const NEWS_SOURCE_OPTIONS: Array<{ value: WallboardNewsSource; label: string; description: string }> = [
  {
    value: 'ndt',
    label: 'Nationaal Droneteam',
    description: 'Nieuws en publicaties uit het officiële nieuwsoverzicht.',
  },
  {
    value: 'dronewatch',
    label: 'Dronewatch',
    description: 'Actueel nieuws over drones, regelgeving en luchtvaart.',
  },
];

const FOCUS_TYPES: Array<{
  kind: WallboardFocusKind;
  label: string;
  description: string;
  icon: typeof Siren;
}> = [
  {
    kind: 'preannouncement',
    label: 'Vooraankondiging',
    description: 'Toont een vroege operationele melding voordat de daadwerkelijke alarmering start.',
    icon: BellRing,
  },
  {
    kind: 'real_alarm',
    label: 'Alarmering',
    description: 'Herhaalt servergestuurd tussen dit focusscherm en de toegewezen playlistpagina’s.',
    icon: Siren,
  },
  {
    kind: 'test_alarm',
    label: 'Proefalarmering',
    description: 'Maakt een proefalarm tijdelijk prominent zonder het als actief incident te tellen.',
    icon: Radio,
  },
];

interface WallboardConfigurationEditorProps {
  idPrefix: string;
  configuration: WallboardConfiguration;
  setConfiguration: Dispatch<SetStateAction<WallboardConfiguration>>;
}

export function WallboardConfigurationEditor({
  idPrefix,
  configuration,
  setConfiguration,
}: WallboardConfigurationEditorProps) {
  const [newPageType, setNewPageType] = useState<WallboardPageType>('map');
  const [editingPageId, setEditingPageId] = useState(() => configuration.pages[0].id);
  const editingPage = configuration.pages.find((page) => page.id === editingPageId) ?? configuration.pages[0];

  useEffect(() => {
    if (!configuration.pages.some((page) => page.id === editingPageId)) {
      setEditingPageId(configuration.pages[0].id);
    }
  }, [configuration.pages, editingPageId]);

  function addPage() {
    const page = createWallboardPage(newPageType, configuration.pages.length + 1);
    setConfiguration((current) => ({ ...current, pages: [...current.pages, page] }));
    setEditingPageId(page.id);
  }

  function updatePage(pageId: string, update: (page: WallboardPage) => WallboardPage) {
    setConfiguration((current) => ({
      ...current,
      pages: current.pages.map((page) => page.id === pageId ? update(page) : page),
    }));
  }

  function removePage(pageId: string) {
    if (configuration.pages.length <= 1) return;
    setConfiguration((current) => {
      const pages = current.pages.filter((page) => page.id !== pageId);
      const overridePageId = current.incident_override.page_id === pageId
        ? pages[0].id
        : current.incident_override.page_id;
      return {
        ...current,
        pages,
        incident_override: { ...current.incident_override, page_id: overridePageId },
      };
    });
    const nextPage = configuration.pages.find((page) => page.id !== pageId);
    if (editingPageId === pageId && nextPage) setEditingPageId(nextPage.id);
  }

  function movePage(pageId: string, direction: -1 | 1) {
    setConfiguration((current) => {
      const index = current.pages.findIndex((page) => page.id === pageId);
      const nextIndex = index + direction;
      if (index < 0 || nextIndex < 0 || nextIndex >= current.pages.length) return current;
      const pages = [...current.pages];
      [pages[index], pages[nextIndex]] = [pages[nextIndex], pages[index]];
      return { ...current, pages };
    });
  }

  function setMapOption(key: keyof WallboardMapConfiguration, checked: boolean) {
    setConfiguration((current) => ({
      ...current,
      map: {
        ...current.map,
        [key]: checked,
        ...(key === 'show_live_locations' && !checked ? { show_routes: false } : {}),
      },
    }));
  }

  function addTickerSource(type: WallboardTickerSourceType) {
    setConfiguration((current) => {
      if (current.ticker.sources.length >= MAX_WALLBOARD_TICKER_SOURCES) return current;
      return {
        ...current,
        ticker: {
          ...current.ticker,
          sources: [
            ...current.ticker.sources,
            createWallboardTickerSource(type, current.ticker.sources.length + 1),
          ],
        },
      };
    });
  }

  function updateTickerSource(sourceId: string, update: (source: WallboardTickerSource) => WallboardTickerSource) {
    setConfiguration((current) => ({
      ...current,
      ticker: {
        ...current.ticker,
        sources: current.ticker.sources.map((source) => source.id === sourceId ? update(source) : source),
      },
    }));
  }

  function removeTickerSource(sourceId: string) {
    setConfiguration((current) => ({
      ...current,
      ticker: {
        ...current.ticker,
        sources: current.ticker.sources.filter((source) => source.id !== sourceId),
      },
    }));
  }

  function moveTickerSource(sourceId: string, direction: -1 | 1) {
    setConfiguration((current) => {
      const index = current.ticker.sources.findIndex((source) => source.id === sourceId);
      const nextIndex = index + direction;
      if (index < 0 || nextIndex < 0 || nextIndex >= current.ticker.sources.length) return current;
      const sources = [...current.ticker.sources];
      [sources[index], sources[nextIndex]] = [sources[nextIndex], sources[index]];
      return { ...current, ticker: { ...current.ticker, sources } };
    });
  }

  return (
    <div className="wallboard-configuration-editor">
      <section className="wallboard-configuration-basics" aria-labelledby={`${idPrefix}-display-title`}>
        <div className="wallboard-configuration-section-heading">
          <span className="eyebrow">Basis</span>
          <h3 id={`${idPrefix}-display-title`}>Weergave en ritme</h3>
        </div>
        <div className="wallboard-editor__fields">
          <label>
            <span>Thema</span>
            <select
              value={configuration.theme}
              onChange={(event) => setConfiguration((current) => ({
                ...current,
                theme: event.target.value as WallboardConfiguration['theme'],
              }))}
            >
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
              value={configuration.refresh_seconds}
              onChange={(event) => setConfiguration((current) => ({
                ...current,
                refresh_seconds: Number(event.target.value),
              }))}
              required
            />
          </label>
          <label className="wallboard-switch-row">
            <input
              type="checkbox"
              checked={configuration.rotation_enabled}
              onChange={(event) => setConfiguration((current) => ({
                ...current,
                rotation_enabled: event.target.checked,
              }))}
            />
            <span>
              <strong>Pagina’s automatisch roteren</strong>
              <small>Iedere pagina blijft zichtbaar gedurende de ingestelde tijd.</small>
            </span>
          </label>
        </div>
      </section>

      <fieldset className="wallboard-option-grid wallboard-global-options">
        <legend>Gegevens en kaartlagen</legend>
        {MAP_OPTION_LABELS.map((option) => (
          <label key={option.key}>
            <input
              type="checkbox"
              checked={configuration.map[option.key]}
              disabled={option.key === 'show_routes' && !configuration.map.show_live_locations}
              onChange={(event) => setMapOption(option.key, event.target.checked)}
            />
            <span><strong>{option.label}</strong><small>{option.help}</small></span>
          </label>
        ))}
      </fieldset>

      <fieldset className="wallboard-ticker-editor">
        <legend>Onderticker</legend>
        <label className="wallboard-switch-row">
          <input
            type="checkbox"
            checked={configuration.ticker.enabled}
            onChange={(event) => setConfiguration((current) => ({
              ...current,
              ticker: { ...current.ticker, enabled: event.target.checked },
            }))}
          />
          <span>
            <strong>Actuele berichten onderin tonen</strong>
            <small>Nieuws- of weer-RSS en interne berichten lopen onder de pagina’s door.</small>
          </span>
        </label>

        <div className="wallboard-ticker-editor__heading">
          <span>
            <strong>Bronnen</strong>
            <small>{configuration.ticker.sources.length} van {MAX_WALLBOARD_TICKER_SOURCES}</small>
          </span>
          <span className="wallboard-ticker-editor__add">
            <button
              className="secondary-button"
              type="button"
              onClick={() => addTickerSource('rss')}
              disabled={configuration.ticker.sources.length >= MAX_WALLBOARD_TICKER_SOURCES}
            >
              <Rss size={16} aria-hidden /> RSS-bron
            </button>
            <button
              className="secondary-button"
              type="button"
              onClick={() => addTickerSource('internal')}
              disabled={configuration.ticker.sources.length >= MAX_WALLBOARD_TICKER_SOURCES}
            >
              <MessageSquareText size={16} aria-hidden /> Intern bericht
            </button>
          </span>
        </div>

        {configuration.ticker.sources.length === 0 ? (
          <p className="wallboard-ticker-editor__empty">Voeg een RSS-bron of intern bericht toe om de onderticker te vullen.</p>
        ) : (
          <ol className="wallboard-ticker-sources">
            {configuration.ticker.sources.map((source, index) => (
              <li key={source.id}>
                <header>
                  <span className="wallboard-ticker-source__type">
                    {source.type === 'rss' ? <Rss size={17} aria-hidden /> : <MessageSquareText size={17} aria-hidden />}
                    <strong>{source.type === 'rss' ? 'Nieuws- of weer-RSS' : 'Intern bericht'}</strong>
                  </span>
                  <span className="wallboard-ticker-source__actions">
                    <button type="button" onClick={() => moveTickerSource(source.id, -1)} disabled={index === 0} aria-label={`${source.label} omhoog verplaatsen`}><ArrowUp size={16} aria-hidden /></button>
                    <button type="button" onClick={() => moveTickerSource(source.id, 1)} disabled={index === configuration.ticker.sources.length - 1} aria-label={`${source.label} omlaag verplaatsen`}><ArrowDown size={16} aria-hidden /></button>
                    <button type="button" onClick={() => removeTickerSource(source.id)} aria-label={`${source.label} verwijderen`}><Trash2 size={16} aria-hidden /></button>
                  </span>
                </header>
                <div className="wallboard-ticker-source__fields">
                  <label>
                    <span>Bronlabel</span>
                    <input
                      value={source.label}
                      onChange={(event) => updateTickerSource(source.id, (current) => ({ ...current, label: event.target.value }))}
                      maxLength={80}
                      placeholder="Bijv. KNMI of Meldkamer"
                      required
                    />
                  </label>
                  {source.type === 'rss' ? (
                    <>
                      <label>
                        <span>HTTPS RSS-adres</span>
                        <input
                          type="url"
                          value={source.url}
                          onChange={(event) => updateTickerSource(source.id, (current) => current.type === 'rss'
                            ? { ...current, url: event.target.value }
                            : current)}
                          maxLength={2048}
                          pattern="https://.*"
                          placeholder="https://data.buienradar.nl/1.0/feed/xml/rssbuienradar"
                          required
                        />
                      </label>
                      <label className="wallboard-ticker-source__limit">
                        <span>Aantal berichten</span>
                        <input
                          type="number"
                          inputMode="numeric"
                          min={MIN_WALLBOARD_RSS_MAX_ITEMS}
                          max={MAX_WALLBOARD_RSS_MAX_ITEMS}
                          value={source.max_items}
                          onChange={(event) => updateTickerSource(source.id, (current) => current.type === 'rss'
                            ? { ...current, max_items: Number(event.target.value) }
                            : current)}
                          onBlur={() => updateTickerSource(source.id, (current) => current.type === 'rss'
                            ? { ...current, max_items: clampWallboardRssMaxItems(current.max_items) }
                            : current)}
                          aria-describedby={`${idPrefix}-ticker-${source.id}-limit-help`}
                          required
                        />
                        <small id={`${idPrefix}-ticker-${source.id}-limit-help`}>Per verversing, maximaal {MAX_WALLBOARD_RSS_MAX_ITEMS}.</small>
                      </label>
                    </>
                  ) : (
                    <label className="wallboard-ticker-source__message">
                      <span>Bericht</span>
                      <textarea
                        value={source.text}
                        onChange={(event) => updateTickerSource(source.id, (current) => current.type === 'internal'
                          ? { ...current, text: event.target.value }
                          : current)}
                        maxLength={500}
                        rows={3}
                        placeholder="Tekst die onderin het wallboard loopt."
                        required
                      />
                      <small>{source.text.length}/500 tekens</small>
                    </label>
                  )}
                </div>
              </li>
            ))}
          </ol>
        )}
      </fieldset>

      <section className="wallboard-page-composer" aria-labelledby={`${idPrefix}-pages-title`}>
        <div className="wallboard-page-composer__heading">
          <div>
            <span className="eyebrow">Programmering</span>
            <h3 id={`${idPrefix}-pages-title`}>Pagina’s en volgorde</h3>
          </div>
          <div className="wallboard-page-add">
            <label className="sr-only" htmlFor={`${idPrefix}-page-type`}>Nieuw paginatype</label>
            <select id={`${idPrefix}-page-type`} value={newPageType} onChange={(event) => setNewPageType(event.target.value as WallboardPageType)}>
              {PAGE_TYPE_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
            <button className="secondary-button" type="button" onClick={addPage}>
              <Plus size={17} aria-hidden /> Pagina toevoegen
            </button>
          </div>
        </div>

        <ol className="wallboard-page-sequence">
          {configuration.pages.map((page, index) => (
            <li className={editingPage.id === page.id ? 'wallboard-page-sequence__item wallboard-page-sequence__item--active' : 'wallboard-page-sequence__item'} key={page.id}>
              <button className="wallboard-page-sequence__select" type="button" onClick={() => setEditingPageId(page.id)} aria-current={editingPage.id === page.id ? 'step' : undefined}>
                <span className="wallboard-page-sequence__number">{index + 1}</span>
                <WallboardPageTypeIcon type={page.type} />
                <span><strong>{page.name}</strong><small>{wallboardPageTypeLabel(page.type)} · {page.duration_seconds} sec.</small></span>
              </button>
              <span className="wallboard-page-sequence__actions">
                <button type="button" onClick={() => movePage(page.id, -1)} disabled={index === 0} aria-label={`${page.name} omhoog verplaatsen`}><ArrowUp size={16} aria-hidden /></button>
                <button type="button" onClick={() => movePage(page.id, 1)} disabled={index === configuration.pages.length - 1} aria-label={`${page.name} omlaag verplaatsen`}><ArrowDown size={16} aria-hidden /></button>
                <button type="button" onClick={() => removePage(page.id)} disabled={configuration.pages.length <= 1} aria-label={`${page.name} verwijderen`}><Trash2 size={16} aria-hidden /></button>
              </span>
            </li>
          ))}
        </ol>

        <WallboardPageEditor page={editingPage} onChange={(next) => updatePage(editingPage.id, () => next)} />
      </section>

      <section className="wallboard-focus-editor" aria-labelledby={`${idPrefix}-focus-title`}>
        <div className="wallboard-configuration-section-heading">
          <span className="eyebrow">Alarmweergave</span>
          <h3 id={`${idPrefix}-focus-title`}>Focusschermen</h3>
          <p>Stel per type in hoelang het prominent blijft en of live reacties zichtbaar zijn.</p>
        </div>
        <div className="wallboard-focus-editor__grid">
          {FOCUS_TYPES.map((focusType) => (
            <WallboardFocusConfigurationCard
              key={focusType.kind}
              idPrefix={idPrefix}
              {...focusType}
              configuration={configuration}
              setConfiguration={setConfiguration}
            />
          ))}
        </div>
        <p className="wallboard-focus-editor__note">
          <Siren size={17} aria-hidden />
          Een echte alarmering heeft altijd voorrang. De server wisselt het focusscherm af met de toegewezen playlist; met alleen een kaartpagina ontstaat focus&nbsp;↔&nbsp;kaart.
        </p>
      </section>

      <fieldset className="wallboard-incident-override">
        <legend>Vaste incidentpagina als fallback</legend>
        <label className="wallboard-switch-row">
          <input
            type="checkbox"
            checked={configuration.incident_override.enabled}
            onChange={(event) => setConfiguration((current) => ({
              ...current,
              incident_override: {
                enabled: event.target.checked,
                page_id: current.incident_override.page_id ?? current.pages[0].id,
              },
            }))}
          />
          <span><strong>Incidentpagina vastzetten</strong><small>Alleen gebruikt wanneer het focusscherm voor echte alarmeringen uitstaat.</small></span>
        </label>
        <label>
          <span>Pagina tijdens incident</span>
          <select
            value={configuration.incident_override.page_id ?? ''}
            disabled={!configuration.incident_override.enabled}
            onChange={(event) => setConfiguration((current) => ({
              ...current,
              incident_override: { ...current.incident_override, page_id: event.target.value },
            }))}
          >
            {configuration.pages.map((page) => <option key={page.id} value={page.id}>{page.name}</option>)}
          </select>
        </label>
        <p><PauseCircle size={16} aria-hidden /> Na het incident keert het scherm terug naar de handmatige pagina of normale rotatie.</p>
      </fieldset>
    </div>
  );
}

function WallboardFocusConfigurationCard({
  idPrefix,
  kind,
  label,
  description,
  icon: Icon,
  configuration,
  setConfiguration,
}: {
  idPrefix: string;
  kind: WallboardFocusKind;
  label: string;
  description: string;
  icon: typeof Siren;
  configuration: WallboardConfiguration;
  setConfiguration: Dispatch<SetStateAction<WallboardConfiguration>>;
}) {
  const focusConfiguration = configuration.focus[kind];
  const updateFocus = (update: Partial<typeof focusConfiguration>) => setConfiguration((current) => ({
    ...current,
    focus: {
      ...current.focus,
      [kind]: { ...current.focus[kind], ...update },
    },
  }));

  return (
    <fieldset className={`wallboard-focus-card wallboard-focus-card--${kind}`}>
      <legend><Icon size={18} aria-hidden /> {label}</legend>
      <p>{description}</p>
      <label className="wallboard-switch-row">
        <input
          type="checkbox"
          checked={focusConfiguration.enabled}
          onChange={(event) => updateFocus({ enabled: event.target.checked })}
        />
        <span><strong>Focusscherm inschakelen</strong><small>De server bepaalt exact wanneer dit scherm verschijnt en verdwijnt.</small></span>
      </label>
      <label className="wallboard-focus-card__duration" htmlFor={`${idPrefix}-focus-${kind}-duration`}>
        <span>Tijd op scherm (seconden)</span>
        <input
          id={`${idPrefix}-focus-${kind}-duration`}
          type="number"
          min={MIN_WALLBOARD_FOCUS_DURATION_SECONDS}
          max={MAX_WALLBOARD_FOCUS_DURATION_SECONDS}
          value={focusConfiguration.duration_seconds}
          disabled={!focusConfiguration.enabled}
          onChange={(event) => updateFocus({
            duration_seconds: clampWallboardFocusDuration(
              Number(event.target.value),
              focusConfiguration.duration_seconds,
            ),
          })}
          required={focusConfiguration.enabled}
        />
      </label>
      <label className="wallboard-switch-row wallboard-focus-card__feed">
        <input
          type="checkbox"
          checked={focusConfiguration.show_response_feed}
          disabled={!focusConfiguration.enabled}
          onChange={(event) => updateFocus({ show_response_feed: event.target.checked })}
        />
        <span><strong><UsersRound size={16} aria-hidden /> Reactiefeed tonen</strong><small>Toon aantallen en de meest recente reacties op deze dispatch.</small></span>
      </label>
    </fieldset>
  );
}

function WallboardPageEditor({ page, onChange }: { page: WallboardPage; onChange: (page: WallboardPage) => void }) {
  const customNewsSources = Array.isArray(page.options.custom_sources) ? page.options.custom_sources : [];
  const selectedNewsSources = normalizeWallboardNewsSources(page.options.sources, true);
  const totalNewsSources = selectedNewsSources.length + customNewsSources.length;

  function updateType(type: WallboardPageType) {
    const previousDefaultTitle = wallboardPageTypeLabel(page.type);
    onChange({
      ...page,
      type,
      name: page.name === previousDefaultTitle ? wallboardPageTypeLabel(type) : page.name,
      options: type === 'message'
        ? { body: page.options.body ?? '' }
        : type === 'news'
          ? { sources: ['ndt', 'dronewatch'], custom_sources: [], max_items: 6 }
          : {},
    });
  }

  function toggleNewsSource(source: WallboardNewsSource, checked: boolean) {
    const sources = checked
      ? [...new Set([...selectedNewsSources, source])]
      : selectedNewsSources.filter((current) => current !== source);
    if (sources.length + customNewsSources.length === 0) return;
    onChange({ ...page, options: { ...page.options, sources } });
  }

  function addCustomNewsSource() {
    if (customNewsSources.length >= MAX_WALLBOARD_CUSTOM_NEWS_SOURCES) return;
    onChange({
      ...page,
      options: {
        ...page.options,
        custom_sources: [
          ...customNewsSources,
          createWallboardCustomNewsSource(customNewsSources.length + 1),
        ],
      },
    });
  }

  function updateCustomNewsSource(sourceId: string, update: Partial<WallboardCustomNewsSource>) {
    onChange({
      ...page,
      options: {
        ...page.options,
        custom_sources: customNewsSources.map((source) => source.id === sourceId
          ? { ...source, ...update }
          : source),
      },
    });
  }

  function removeCustomNewsSource(sourceId: string) {
    if (totalNewsSources <= 1) return;
    onChange({
      ...page,
      options: {
        ...page.options,
        custom_sources: customNewsSources.filter((source) => source.id !== sourceId),
      },
    });
  }

  return (
    <div className="wallboard-page-editor">
      <div className="wallboard-page-editor__heading">
        <WallboardPageTypeIcon type={page.type} />
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
      ) : page.type === 'news' ? (
        <fieldset className="wallboard-news-editor">
          <legend>Nieuwsinhoud</legend>
          <p>
            Het wallboard toont alleen berichten uit de afgelopen 7 dagen. Zijn die er niet, dan worden de
            meest recente berichten getoond tot het ingestelde maximum.
          </p>
          <div className="wallboard-news-editor__sources">
            {NEWS_SOURCE_OPTIONS.map((source) => {
              const checked = selectedNewsSources.includes(source.value);
              return (
                <label className="wallboard-switch-row" key={source.value}>
                  <input
                    type="checkbox"
                    checked={checked}
                    disabled={checked && totalNewsSources === 1}
                    onChange={(event) => toggleNewsSource(source.value, event.target.checked)}
                  />
                  <span><strong>{source.label}</strong><small>{source.description}</small></span>
                </label>
              );
            })}
          </div>
          <section className="wallboard-news-editor__custom" aria-label="Eigen RSS-bronnen">
            <header>
              <span>
                <strong>Eigen RSS-bronnen</strong>
                <small>Voeg maximaal {MAX_WALLBOARD_CUSTOM_NEWS_SOURCES} openbare HTTPS-feeds toe.</small>
              </span>
              <button
                type="button"
                className="secondary-button"
                onClick={addCustomNewsSource}
                disabled={customNewsSources.length >= MAX_WALLBOARD_CUSTOM_NEWS_SOURCES}
              >
                <Plus size={16} aria-hidden /> RSS-bron toevoegen
              </button>
            </header>
            {customNewsSources.length === 0 ? (
              <p className="wallboard-news-editor__custom-empty">Nog geen eigen RSS-bronnen toegevoegd.</p>
            ) : (
              <div className="wallboard-news-editor__custom-list">
                {customNewsSources.map((source, index) => (
                  <article className="wallboard-news-editor__custom-source" key={source.id}>
                    <header>
                      <span><Rss size={17} aria-hidden /> Eigen bron {index + 1}</span>
                      <button
                        type="button"
                        className="icon-button danger-button"
                        onClick={() => removeCustomNewsSource(source.id)}
                        disabled={totalNewsSources === 1}
                        aria-label={`${source.label || `Eigen bron ${index + 1}`} verwijderen`}
                      >
                        <Trash2 size={16} aria-hidden />
                      </button>
                    </header>
                    <div>
                      <label>
                        <span>Naam op het wallboard</span>
                        <input
                          value={source.label}
                          onChange={(event) => updateCustomNewsSource(source.id, { label: event.target.value })}
                          maxLength={MAX_WALLBOARD_CUSTOM_NEWS_SOURCE_LABEL_LENGTH}
                          placeholder="Bijvoorbeeld Luchtvaartnieuws"
                          required
                        />
                      </label>
                      <label>
                        <span>HTTPS-feed URL</span>
                        <input
                          type="url"
                          value={source.url}
                          onChange={(event) => updateCustomNewsSource(source.id, { url: event.target.value })}
                          maxLength={MAX_WALLBOARD_CUSTOM_NEWS_SOURCE_URL_LENGTH}
                          pattern="https://.+"
                          placeholder="https://voorbeeld.nl/feed.xml"
                          inputMode="url"
                          required
                        />
                      </label>
                    </div>
                  </article>
                ))}
              </div>
            )}
            <small className="wallboard-news-editor__source-total">
              {totalNewsSources} {totalNewsSources === 1 ? 'bron geselecteerd' : 'bronnen geselecteerd'} · het maximum aantal berichten geldt gecombineerd.
            </small>
          </section>
          <label className="wallboard-news-editor__count">
            <span>Maximum aantal berichten</span>
            <input
              type="number"
              min={MIN_WALLBOARD_NEWS_MAX_ITEMS}
              max={MAX_WALLBOARD_NEWS_MAX_ITEMS}
              value={clampWallboardNewsMaxItems(Number(page.options.max_items))}
              onChange={(event) => onChange({
                ...page,
                options: {
                  ...page.options,
                  max_items: clampWallboardNewsMaxItems(Number(event.target.value)),
                },
              })}
              required
            />
            <small>Begrenst zowel de recente set als de laatste berichten wanneer de 7-dagenperiode leeg is.</small>
          </label>
        </fieldset>
      ) : (
        <div className="wallboard-page-editor__page-options">
          <p className="wallboard-page-editor__hint">Deze pagina toont uitsluitend operationele incidenten; proefalarmen verschijnen alleen tijdelijk als prominente alarmmelding.</p>
        </div>
      )}
    </div>
  );
}

export function WallboardPageTypeIcon({ type }: { type: WallboardPageType }) {
  switch (type) {
    case 'map': return <Map size={18} aria-hidden />;
    case 'incident_list': return <List size={18} aria-hidden />;
    case 'summary': return <BarChart3 size={18} aria-hidden />;
    case 'message': return <MessageSquareText size={18} aria-hidden />;
    case 'news': return <Newspaper size={18} aria-hidden />;
  }
}
