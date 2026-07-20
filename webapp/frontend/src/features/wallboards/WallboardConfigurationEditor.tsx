import { type Dispatch, type SetStateAction, useEffect, useRef, useState } from 'react';
import {
  ArrowDown,
  ArrowUp,
  BarChart3,
  BellRing,
  CalendarDays,
  List,
  Map,
  MapPin,
  Clapperboard,
  CloudSun,
  Gauge,
  Images,
  MessageSquareText,
  Newspaper,
  PauseCircle,
  Plus,
  Radio,
  Rss,
  Quote as QuoteIcon,
  ShieldAlert,
  Siren,
  Trash2,
  UsersRound,
} from 'lucide-react';
import type {
  WallboardConfiguration,
  WallboardCustomNewsSource,
  WallboardFlipDirection,
  WallboardForecastBlockKey,
  WallboardFocusKind,
  WallboardKpiCategory,
  WallboardKpiKey,
  WallboardKpiVisualization,
  WallboardMapConfiguration,
  WallboardNewsSource,
  WallboardPage,
  WallboardPageTransition,
  WallboardPageType,
  WallboardTickerSource,
  WallboardTickerSourceType,
} from '../../types/api';
import {
  fetchLocationSuggestions,
  geocodeAddressLabel,
  lookupLocationSuggestion,
  type LocationSuggestion,
} from '../../lib/locationSearch';
import {
  MAX_WALLBOARD_FOCUS_DURATION_SECONDS,
  MAX_WALLBOARD_FORECAST_VISIBLE_BLOCKS,
  MAX_WALLBOARD_CUSTOM_NEWS_SOURCES,
  MAX_WALLBOARD_CUSTOM_NEWS_SOURCE_LABEL_LENGTH,
  MAX_WALLBOARD_CUSTOM_NEWS_SOURCE_URL_LENGTH,
  MAX_WALLBOARD_CALENDAR_MAX_ITEMS,
  MAX_WALLBOARD_NEWS_MAX_ITEMS,
  MAX_WALLBOARD_NEWS_ITEM_DURATION_SECONDS,
  MAX_WALLBOARD_KPI_CHARTS,
  MAX_WALLBOARD_QUOTES,
  MAX_WALLBOARD_QUOTE_AUTHOR_LENGTH,
  MAX_WALLBOARD_QUOTE_TEXT_LENGTH,
  MAX_WALLBOARD_PAGE_DURATION_SECONDS,
  MAX_WALLBOARD_REFRESH_SECONDS,
  MAX_WALLBOARD_RSS_MAX_ITEMS,
  MAX_WALLBOARD_TICKER_SOURCES,
  MAX_WALLBOARD_TRANSITION_DURATION_MS,
  MIN_WALLBOARD_FOCUS_DURATION_SECONDS,
  MIN_WALLBOARD_CALENDAR_MAX_ITEMS,
  MIN_WALLBOARD_NEWS_MAX_ITEMS,
  MIN_WALLBOARD_NEWS_ITEM_DURATION_SECONDS,
  MIN_WALLBOARD_PAGE_DURATION_SECONDS,
  MIN_WALLBOARD_REFRESH_SECONDS,
  MIN_WALLBOARD_RSS_MAX_ITEMS,
  MIN_WALLBOARD_TRANSITION_DURATION_MS,
  DEFAULT_WALLBOARD_FLIP_DIRECTION,
  DEFAULT_WALLBOARD_FORECAST_LOCATION_MODE,
  DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS,
  DEFAULT_WALLBOARD_KPI_VISUALIZATIONS,
  DEFAULT_WALLBOARD_KPI_VISIBLE_METRICS,
  DEFAULT_WALLBOARD_NEWS_ITEM_TRANSITION_DURATION_MS,
  DEFAULT_WALLBOARD_PAGE_TRANSITION_DURATION_MS,
  DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION,
  DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION_DURATION_MS,
  WALLBOARD_FLIP_DIRECTIONS,
  WALLBOARD_FORECAST_BLOCK_KEYS,
  WALLBOARD_KPI_DEFINITIONS,
  WALLBOARD_KPI_KEYS,
  WALLBOARD_NEWS_ITEM_TRANSITIONS,
  WALLBOARD_PAGE_TRANSITIONS,
  clampWallboardFocusDuration,
  clampWallboardCalendarMaxItems,
  clampWallboardNewsMaxItems,
  clampWallboardNewsItemDuration,
  clampWallboardPageDuration,
  clampWallboardRssMaxItems,
  clampWallboardTransitionDurationMs,
  createWallboardCustomNewsSource,
  createWallboardPage,
  createWallboardTickerSource,
  normalizeWallboardNewsSources,
  normalizeWallboardMediaPlaylistId,
  normalizeWallboardNewsItemTransition,
  normalizeWallboardFlipDirection,
  normalizeWallboardForecastPageOptions,
  normalizeWallboardKpiPageOptions,
  normalizeWallboardPageTransition,
  wallboardEffectivePageDuration,
  wallboardKpiSupportedVisualizations,
  wallboardMessageContent,
  wallboardPageTypeLabel,
  wallboardVisibleKpiKeys,
  wallboardVideoDurationFromOptions,
} from './wallboardPresentation';
import { WallboardRichTextEditor } from './WallboardRichTextEditor';
import {
  WallboardPhotoPageEditor,
  type WallboardPhotoPlaylistSource,
} from './WallboardPhotoPageEditor';
import { SecondsStepper } from './SecondsStepper';
import { WallboardVideoPageEditor } from './WallboardVideoPageEditor';
import { formatWallboardVideoDuration } from './wallboardVideoInspection';

const MIN_WALLBOARD_TRANSITION_DURATION_SECONDS = MIN_WALLBOARD_TRANSITION_DURATION_MS / 1000;
const MAX_WALLBOARD_TRANSITION_DURATION_SECONDS = MAX_WALLBOARD_TRANSITION_DURATION_MS / 1000;

function transitionDurationSeconds(durationMs: unknown, fallbackMs: number): number {
  return clampWallboardTransitionDurationMs(durationMs, fallbackMs) / 1000;
}

function transitionDurationMilliseconds(durationSeconds: number, fallbackMs: number): number {
  return clampWallboardTransitionDurationMs(Math.round(durationSeconds * 1000), fallbackMs);
}

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

const FORECAST_BLOCK_OPTIONS: ReadonlyArray<{
  key: WallboardForecastBlockKey;
  label: string;
  help: string;
}> = [
  { key: 'weather', label: 'Weer', help: 'Actuele weersituatie op vlieghoogte.' },
  { key: 'daylight', label: 'Daglicht', help: 'Zonsopkomst en zonsondergang.' },
  { key: 'temperature', label: 'Temperatuur', help: 'Temperatuur en dauwpunt.' },
  { key: 'wind_speed', label: 'Windsnelheid', help: 'Verwachte wind rond 120 meter.' },
  { key: 'wind_gust', label: 'Windstoten', help: 'Verwachte windstoten rond 120 meter.' },
  { key: 'wind_direction', label: 'Windrichting', help: 'Richting van de wind rond 120 meter.' },
  { key: 'precipitation_probability', label: 'Neerslagkans', help: 'Kans op neerslag.' },
  { key: 'precipitation_outlook', label: 'Buien +3 uur', help: 'KNMI-radar voor de eerste twee uur en neerslagkans voor het derde uur.' },
  { key: 'thunderstorm_forecast', label: 'Onweer +3 uur', help: 'WMO-modelverwachting in stappen; geen live bliksemdetectie.' },
  { key: 'cloud_cover', label: 'Bewolking', help: 'Totale bewolkingsgraad.' },
  { key: 'visibility', label: 'Zichtbaarheid', help: 'Horizontaal zicht.' },
  { key: 'gnss_visible', label: 'Zichtbare satellieten', help: 'Satellieten boven de lokale horizon.' },
  { key: 'kp_index', label: 'Kp-index', help: 'Actuele geomagnetische activiteit.' },
  { key: 'gnss_usable', label: 'Bruikbare satellieten', help: 'Satellieten bruikbaar voor navigatie.' },
];

const KPI_CATEGORIES: ReadonlyArray<{
  key: WallboardKpiCategory;
  label: string;
  description: string;
}> = [
  { key: 'pilots', label: 'Piloten', description: 'Actuele inzetbaarheid van piloten.' },
  { key: 'incidents', label: 'Incidenten', description: 'Aantallen, fasen en prioriteiten van incidenten.' },
  { key: 'assets', label: 'Middelen', description: 'Operationele gereedheid van drones en andere middelen.' },
  { key: 'responses', label: 'Reacties', description: 'Stand van de actuele uitvraag of alarmering.' },
  { key: 'flight', label: 'Vluchtgegevens', description: 'Vliegduur en gebruikte drones uit inzetrapporten.' },
];

const KPI_VISUALIZATION_LABELS: Record<WallboardKpiVisualization, string> = {
  counter: 'Teller',
  bar: 'Staafdiagram',
  pie: 'Taartdiagram',
  ring: 'Ringdiagram',
};

const PAGE_TYPE_OPTIONS: Array<{ value: WallboardPageType; label: string }> = [
  { value: 'map', label: 'Kaart' },
  { value: 'incident_list', label: 'Incidentenlijst' },
  { value: 'summary', label: 'Samenvatting' },
  { value: 'kpi', label: 'KPI-overzicht' },
  { value: 'calendar', label: 'Agenda' },
  { value: 'message', label: 'Mededeling' },
  { value: 'safety_notice', label: 'Veiligheidsbericht' },
  { value: 'quote', label: 'Quote van de dag' },
  { value: 'uav_forecast', label: 'UAV Forecast' },
  { value: 'news', label: 'Nieuws' },
  { value: 'video', label: 'Video' },
  { value: 'photo_carousel', label: 'Fotocarrousel' },
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
  photoPlaylists: WallboardPhotoPlaylistSource;
}

export function WallboardConfigurationEditor({
  idPrefix,
  configuration,
  setConfiguration,
  photoPlaylists,
}: WallboardConfigurationEditorProps) {
  const [newPageType, setNewPageType] = useState<WallboardPageType>('map');
  const [editingPageId, setEditingPageId] = useState(() => configuration.pages[0].id);
  const editingPage = configuration.pages.find((page) => page.id === editingPageId) ?? configuration.pages[0];
  const photoPlaylistIds = photoPlaylists.playlists === null
    ? null
    : new Set(photoPlaylists.playlists.map((playlist) => normalizeWallboardMediaPlaylistId(playlist.id)));
  const globalPageTransition = normalizeWallboardPageTransition(configuration.page_transition);
  const globalFlipDirection = normalizeWallboardFlipDirection(configuration.page_flip_direction);

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
          <SecondsStepper
            id={`${idPrefix}-refresh-seconds`}
            label="Data verversen"
            min={MIN_WALLBOARD_REFRESH_SECONDS}
            max={MAX_WALLBOARD_REFRESH_SECONDS}
            value={configuration.refresh_seconds}
            onChange={(refreshSeconds) => setConfiguration((current) => ({
              ...current,
              refresh_seconds: refreshSeconds,
            }))}
            required
          />
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
          <label>
            <span>Globale paginaovergang</span>
            <select
              value={globalPageTransition}
              onChange={(event) => {
                const pageTransition = normalizeWallboardPageTransition(event.target.value);
                setConfiguration((current) => ({
                  ...current,
                  page_transition: pageTransition,
                  page_fade_enabled: pageTransition !== 'none',
                }));
              }}
            >
              {WALLBOARD_PAGE_TRANSITIONS.map((transition) => (
                <option key={transition.value} value={transition.value}>{transition.label}</option>
              ))}
            </select>
            <small>Standaard voor alle pagina’s zonder eigen overgang.</small>
          </label>
          {globalPageTransition === 'flip' ? (
            <label>
              <span>Globale fliprichting</span>
              <select
                value={globalFlipDirection}
                onChange={(event) => setConfiguration((current) => ({
                  ...current,
                  page_flip_direction: normalizeWallboardFlipDirection(event.target.value),
                }))}
              >
                {WALLBOARD_FLIP_DIRECTIONS.map((direction) => (
                  <option key={direction.value} value={direction.value}>{direction.label}</option>
                ))}
              </select>
              <small>Standaard voor elke pagina die de globale flip gebruikt.</small>
            </label>
          ) : null}
          <SecondsStepper
            id={`${idPrefix}-page-transition-duration`}
            label="Duur paginaovergang"
            min={MIN_WALLBOARD_TRANSITION_DURATION_SECONDS}
            max={MAX_WALLBOARD_TRANSITION_DURATION_SECONDS}
            step={0.1}
            value={transitionDurationSeconds(
              configuration.page_transition_duration_ms,
              DEFAULT_WALLBOARD_PAGE_TRANSITION_DURATION_MS,
            )}
            disabled={globalPageTransition === 'none'}
            onChange={(durationSeconds) => setConfiguration((current) => ({
              ...current,
              page_transition_duration_ms: transitionDurationMilliseconds(
                durationSeconds,
                DEFAULT_WALLBOARD_PAGE_TRANSITION_DURATION_MS,
              ),
            }))}
            description="Hoe lang de wisselanimatie tussen pagina’s duurt."
            required={globalPageTransition !== 'none'}
          />
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
          {configuration.pages.map((page, index) => {
            const missingPhotoPlaylist = page.type === 'photo_carousel'
              && photoPlaylistIds !== null
              && typeof page.options.media_playlist_id === 'string'
              && normalizeWallboardMediaPlaylistId(page.options.media_playlist_id) !== ''
              && !photoPlaylistIds.has(normalizeWallboardMediaPlaylistId(page.options.media_playlist_id));
            const itemClassName = [
              'wallboard-page-sequence__item',
              editingPage.id === page.id ? 'wallboard-page-sequence__item--active' : '',
              missingPhotoPlaylist ? 'wallboard-page-sequence__item--invalid' : '',
            ].filter(Boolean).join(' ');

            return (
              <li className={itemClassName} key={page.id}>
                <button className="wallboard-page-sequence__select" type="button" onClick={() => setEditingPageId(page.id)} aria-current={editingPage.id === page.id ? 'step' : undefined}>
                  <span className="wallboard-page-sequence__number">{index + 1}</span>
                  <WallboardPageTypeIcon type={page.type} />
                  <span><strong>{page.name}</strong><small>{missingPhotoPlaylist ? 'Fotoplaylist ontbreekt - kies opnieuw' : `${wallboardPageTypeLabel(page.type)} · ${wallboardEffectivePageDuration(page)} sec.`}</small></span>
                </button>
                <span className="wallboard-page-sequence__actions">
                  <button type="button" onClick={() => movePage(page.id, -1)} disabled={index === 0} aria-label={`${page.name} omhoog verplaatsen`}><ArrowUp size={16} aria-hidden /></button>
                  <button type="button" onClick={() => movePage(page.id, 1)} disabled={index === configuration.pages.length - 1} aria-label={`${page.name} omlaag verplaatsen`}><ArrowDown size={16} aria-hidden /></button>
                  <button type="button" onClick={() => removePage(page.id)} disabled={configuration.pages.length <= 1} aria-label={`${page.name} verwijderen`}><Trash2 size={16} aria-hidden /></button>
                </span>
              </li>
            );
          })}
        </ol>

        <WallboardPageEditor
          page={editingPage}
          globalTransition={globalPageTransition}
          globalTransitionDurationMs={clampWallboardTransitionDurationMs(
            configuration.page_transition_duration_ms,
            DEFAULT_WALLBOARD_PAGE_TRANSITION_DURATION_MS,
          )}
          globalFlipDirection={globalFlipDirection}
          photoPlaylists={photoPlaylists}
          onChange={(next) => updatePage(editingPage.id, () => next)}
        />
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
      <SecondsStepper
        className="wallboard-focus-card__duration"
        id={`${idPrefix}-focus-${kind}-duration`}
        label="Tijd op scherm"
        min={MIN_WALLBOARD_FOCUS_DURATION_SECONDS}
        max={MAX_WALLBOARD_FOCUS_DURATION_SECONDS}
        value={focusConfiguration.duration_seconds}
        disabled={!focusConfiguration.enabled}
        onChange={(durationSeconds) => updateFocus({
            duration_seconds: clampWallboardFocusDuration(
              durationSeconds,
              focusConfiguration.duration_seconds,
            ),
          })}
        required={focusConfiguration.enabled}
      />
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

function WallboardPageEditor({
  page,
  globalTransition,
  globalTransitionDurationMs,
  globalFlipDirection,
  photoPlaylists,
  onChange,
}: {
  page: WallboardPage;
  globalTransition: WallboardPageTransition;
  globalTransitionDurationMs: number;
  globalFlipDirection: WallboardFlipDirection;
  photoPlaylists: WallboardPhotoPlaylistSource;
  onChange: (page: WallboardPage) => void;
}) {
  const customNewsSources = Array.isArray(page.options.custom_sources) ? page.options.custom_sources : [];
  const selectedNewsSources = normalizeWallboardNewsSources(page.options.sources, true);
  const totalNewsSources = selectedNewsSources.length + customNewsSources.length;
  const effectiveDurationSeconds = wallboardEffectivePageDuration(page);
  const videoDurationSeconds = wallboardVideoDurationFromOptions(page.options);
  const pageTransition = page.transition === undefined
    ? undefined
    : normalizeWallboardPageTransition(page.transition);
  const pageFlipDirection = page.flip_direction === undefined
    ? globalFlipDirection
    : normalizeWallboardFlipDirection(page.flip_direction);
  const newsItemTransition = normalizeWallboardNewsItemTransition(page.options.item_transition);
  const newsItemFlipDirection = normalizeWallboardFlipDirection(page.options.item_flip_direction);
  const globalTransitionLabel = WALLBOARD_PAGE_TRANSITIONS.find(
    (transition) => transition.value === globalTransition,
  )?.label ?? globalTransition;
  const globalTransitionDurationSeconds = transitionDurationSeconds(
    globalTransitionDurationMs,
    DEFAULT_WALLBOARD_PAGE_TRANSITION_DURATION_MS,
  ).toFixed(1).replace('.', ',');
  const normalizedForecastOptions = normalizeWallboardForecastPageOptions(page);
  const forecastLocationMode = normalizedForecastOptions.location_mode
    ?? DEFAULT_WALLBOARD_FORECAST_LOCATION_MODE;
  const forecastVisibleBlocks = normalizedForecastOptions.visible_blocks
    ?? [...DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS];
  const normalizedKpiOptions = page.type === 'kpi'
    ? normalizeWallboardKpiPageOptions(page)
    : {
      visible_metrics: [...DEFAULT_WALLBOARD_KPI_VISIBLE_METRICS],
      metric_visualizations: { ...DEFAULT_WALLBOARD_KPI_VISUALIZATIONS },
    };
  const kpiVisibleMetrics = page.type === 'kpi'
    ? wallboardVisibleKpiKeys(page)
    : [...DEFAULT_WALLBOARD_KPI_VISIBLE_METRICS];
  const kpiChartCount = kpiVisibleMetrics.filter(
    (key) => normalizedKpiOptions.metric_visualizations?.[key] !== 'counter',
  ).length;
  const forecastLocationQuery = page.type === 'uav_forecast' && forecastLocationMode === 'address'
    ? page.options.location_label ?? ''
    : '';
  const [forecastSuggestions, setForecastSuggestions] = useState<LocationSuggestion[]>([]);
  const [forecastSearchOpen, setForecastSearchOpen] = useState(false);
  const [forecastSearchLoading, setForecastSearchLoading] = useState(false);
  const [forecastSearchError, setForecastSearchError] = useState<string | null>(null);
  const forecastLookupSequence = useRef(0);

  useEffect(() => {
    const query = forecastLocationQuery.trim();
    if (page.type !== 'uav_forecast' || forecastLocationMode !== 'address' || query.length < 3) {
      setForecastSuggestions([]);
      return undefined;
    }

    const controller = new AbortController();
    const timeout = window.setTimeout(() => {
      void fetchLocationSuggestions(query, controller.signal)
        .then((suggestions) => {
          if (!controller.signal.aborted) setForecastSuggestions(suggestions);
        })
        .catch(() => {
          if (!controller.signal.aborted) setForecastSuggestions([]);
        });
    }, 220);

    return () => {
      controller.abort();
      window.clearTimeout(timeout);
    };
  }, [forecastLocationMode, forecastLocationQuery, page.type]);

  function updateType(type: WallboardPageType) {
    forecastLookupSequence.current += 1;
    const previousDefaultTitle = wallboardPageTypeLabel(page.type);
    const nextPage: WallboardPage = {
      ...page,
      type,
      name: page.name === previousDefaultTitle ? wallboardPageTypeLabel(type) : page.name,
      options: type === 'message' || type === 'safety_notice'
        ? { content: wallboardMessageContent(page.options) }
        : type === 'quote'
          ? { quotes: page.options.quotes?.length ? page.options.quotes : [{ text: '' }] }
        : type === 'uav_forecast'
          ? {
            location_mode: DEFAULT_WALLBOARD_FORECAST_LOCATION_MODE,
            visible_blocks: [...DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS],
          }
        : type === 'calendar'
          ? { max_items: clampWallboardCalendarMaxItems(Number(page.options.max_items)) }
        : type === 'kpi'
          ? {
            visible_metrics: [...DEFAULT_WALLBOARD_KPI_VISIBLE_METRICS],
            metric_visualizations: { ...DEFAULT_WALLBOARD_KPI_VISUALIZATIONS },
          }
        : type === 'news'
          ? {
            sources: ['ndt', 'dronewatch'],
            custom_sources: [],
            max_items: 6,
            item_duration_seconds: 12,
            item_transition: 'fade',
            item_transition_duration_ms: DEFAULT_WALLBOARD_NEWS_ITEM_TRANSITION_DURATION_MS,
            item_flip_direction: DEFAULT_WALLBOARD_FLIP_DIRECTION,
          }
          : type === 'video'
            ? {
              url: page.options.url ?? '',
              ...(videoDurationSeconds === null ? {} : { video_duration_seconds: videoDurationSeconds }),
            }
            : type === 'photo_carousel'
              ? {
                media_playlist_id: '',
                item_duration_seconds: 12,
                item_transition: DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION,
                item_transition_duration_ms: DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION_DURATION_MS,
                item_flip_direction: DEFAULT_WALLBOARD_FLIP_DIRECTION,
              }
          : {},
    };
    onChange({ ...nextPage, duration_seconds: wallboardEffectivePageDuration(nextPage) });
  }

  function updateForecastLocationMode(mode: 'netherlands' | 'address') {
    if (mode === forecastLocationMode) return;
    forecastLookupSequence.current += 1;
    setForecastSuggestions([]);
    setForecastSearchOpen(false);
    setForecastSearchLoading(false);
    setForecastSearchError(null);
    onChange({
      ...page,
      options: mode === 'address'
        ? { location_mode: 'address', location_label: '', visible_blocks: forecastVisibleBlocks }
        : {
          location_mode: DEFAULT_WALLBOARD_FORECAST_LOCATION_MODE,
          visible_blocks: forecastVisibleBlocks,
        },
    });
  }

  function updateForecastVisibleBlock(key: WallboardForecastBlockKey, checked: boolean) {
    const selected = new Set(forecastVisibleBlocks);
    if (checked && selected.size < MAX_WALLBOARD_FORECAST_VISIBLE_BLOCKS) selected.add(key);
    else selected.delete(key);
    onChange({
      ...page,
      options: {
        ...normalizedForecastOptions,
        visible_blocks: WALLBOARD_FORECAST_BLOCK_KEYS.filter((candidate) => selected.has(candidate)),
      },
    });
  }

  function updateKpiVisibleMetric(key: WallboardKpiKey, checked: boolean) {
    const selected = new Set(kpiVisibleMetrics);
    if (checked) selected.add(key);
    else selected.delete(key);
    const metricVisualizations = { ...normalizedKpiOptions.metric_visualizations };
    if (
      checked
      && metricVisualizations[key] !== 'counter'
      && kpiChartCount >= MAX_WALLBOARD_KPI_CHARTS
    ) {
      metricVisualizations[key] = 'counter';
    }
    onChange({
      ...page,
      options: {
        ...page.options,
        visible_metrics: WALLBOARD_KPI_KEYS.filter((candidate) => selected.has(candidate)),
        metric_visualizations: metricVisualizations,
      },
    });
  }

  function updateKpiVisualization(key: WallboardKpiKey, visualization: WallboardKpiVisualization) {
    if (!wallboardKpiSupportedVisualizations(key).includes(visualization)) return;
    const currentVisualization = normalizedKpiOptions.metric_visualizations?.[key] ?? 'counter';
    if (
      visualization !== 'counter'
      && currentVisualization === 'counter'
      && kpiVisibleMetrics.includes(key)
      && kpiChartCount >= MAX_WALLBOARD_KPI_CHARTS
    ) return;
    onChange({
      ...page,
      options: {
        ...page.options,
        visible_metrics: [...kpiVisibleMetrics],
        metric_visualizations: {
          ...normalizedKpiOptions.metric_visualizations,
          [key]: visualization,
        },
      },
    });
  }

  async function selectForecastSuggestion(suggestion: LocationSuggestion) {
    const lookupSequence = ++forecastLookupSequence.current;
    setForecastSearchOpen(false);
    setForecastSuggestions([]);
    setForecastSearchError(null);
    setForecastSearchLoading(true);
    const resolved = await lookupLocationSuggestion(suggestion).catch(() => null);
    if (lookupSequence !== forecastLookupSequence.current) return;
    setForecastSearchLoading(false);
    if (resolved === null) {
      setForecastSearchError('Deze locatie kon niet worden gecontroleerd. Kies een ander zoekresultaat.');
      return;
    }

    onChange({
      ...page,
      options: {
        location_mode: 'address',
        location_label: resolved.locationLabel.slice(0, 120),
        visible_blocks: forecastVisibleBlocks,
      },
    });
  }

  async function resolveTypedForecastLocation() {
    setForecastSearchOpen(false);
    const query = forecastLocationQuery.trim();
    if (query === '') {
      setForecastSearchError('Zoek en kies een locatie voor deze forecast.');
      return;
    }

    const exactSuggestion = forecastSuggestions.find(
      (suggestion) => suggestion.label.toLocaleLowerCase('nl-NL') === query.toLocaleLowerCase('nl-NL'),
    );
    if (exactSuggestion !== undefined) {
      await selectForecastSuggestion(exactSuggestion);
      return;
    }

    setForecastSearchError(null);
    setForecastSearchLoading(true);
    const lookupSequence = ++forecastLookupSequence.current;
    const resolved = await geocodeAddressLabel(query).catch(() => null);
    if (lookupSequence !== forecastLookupSequence.current) return;
    setForecastSearchLoading(false);
    if (resolved === null) {
      setForecastSearchError('Geen geldige locatie gevonden. Kies een resultaat uit de adreszoeker.');
      return;
    }

    onChange({
      ...page,
      options: {
        location_mode: 'address',
        location_label: resolved.locationLabel.slice(0, 120),
        visible_blocks: forecastVisibleBlocks,
      },
    });
  }

  function updatePageTransition(value: string) {
    if (value === '') {
      const nextPage = { ...page };
      delete nextPage.transition;
      delete nextPage.transition_duration_ms;
      delete nextPage.flip_direction;
      onChange(nextPage);
      return;
    }

    const transition = normalizeWallboardPageTransition(value);
    const nextPage: WallboardPage = {
      ...page,
      transition,
      transition_duration_ms: clampWallboardTransitionDurationMs(
        page.transition_duration_ms,
        globalTransitionDurationMs,
      ),
    };
    if (transition === 'flip') {
      nextPage.flip_direction = pageTransition === 'flip'
        ? pageFlipDirection
        : globalFlipDirection;
    } else {
      delete nextPage.flip_direction;
    }
    onChange(nextPage);
  }

  function updateNewsOptions(update: Partial<WallboardPage['options']>) {
    const nextPage: WallboardPage = {
      ...page,
      options: { ...page.options, ...update },
    };
    onChange({ ...nextPage, duration_seconds: wallboardEffectivePageDuration(nextPage) });
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
          <span>{page.type === 'message' ? 'Naam in beheer' : 'Titel'}</span>
          <input value={page.name} onChange={(event) => onChange({ ...page, name: event.target.value })} maxLength={120} required />
        </label>
        <label>
          <span>Type</span>
          <select value={page.type} onChange={(event) => updateType(event.target.value as WallboardPageType)}>
            {PAGE_TYPE_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </label>
        {page.type === 'news' ? (
          <div className="wallboard-page-editor__derived-duration">
            <span>Totale tijd in playlist</span>
            <strong>{effectiveDurationSeconds} seconden</strong>
            <small>
              {clampWallboardNewsMaxItems(Number(page.options.max_items))} berichten ×{' '}
              {clampWallboardNewsItemDuration(Number(page.options.item_duration_seconds))} seconden
            </small>
          </div>
        ) : page.type === 'video' ? (
          <div className="wallboard-page-editor__derived-duration">
            <span>Automatische schermtijd</span>
            <strong>{videoDurationSeconds === null ? 'Nog niet gecontroleerd' : `${effectiveDurationSeconds} seconden`}</strong>
            <small>
              {videoDurationSeconds === null
                ? 'Controleer de video voordat je de playlist opslaat.'
                : `${formatWallboardVideoDuration(videoDurationSeconds)} video + 5 seconden startmarge`}
            </small>
          </div>
        ) : page.type === 'photo_carousel' ? (
          <div className="wallboard-page-editor__derived-duration">
            <span>Automatische schermtijd</span>
            <strong>{page.duration_seconds} seconden</strong>
            <small>De volledige fotoplaylist wordt eenmaal getoond voordat de volgende pagina start.</small>
          </div>
        ) : (
          <SecondsStepper
            id={`wallboard-page-${page.id}-duration`}
            label="Tijd op scherm"
            min={MIN_WALLBOARD_PAGE_DURATION_SECONDS}
            max={MAX_WALLBOARD_PAGE_DURATION_SECONDS}
            value={page.duration_seconds}
            onChange={(durationSeconds) => onChange({
              ...page,
              duration_seconds: clampWallboardPageDuration(durationSeconds),
            })}
            required
          />
        )}
        <label>
          <span>Overgang naar deze pagina</span>
          <select
            value={pageTransition ?? ''}
            onChange={(event) => updatePageTransition(event.target.value)}
          >
            <option value="">Globale instelling</option>
            {WALLBOARD_PAGE_TRANSITIONS.map((transition) => (
              <option key={transition.value} value={transition.value}>{transition.label}</option>
            ))}
          </select>
          <small>
            {pageTransition === undefined
              ? `Globaal: ${globalTransitionLabel}, ${globalTransitionDurationSeconds} sec.`
              : 'Alleen deze pagina wijkt af van de globale instelling.'}
          </small>
        </label>
        {pageTransition === 'flip' ? (
          <label>
            <span>Eigen fliprichting</span>
            <select
              value={pageFlipDirection}
              onChange={(event) => onChange({
                ...page,
                flip_direction: normalizeWallboardFlipDirection(event.target.value),
              })}
            >
              {WALLBOARD_FLIP_DIRECTIONS.map((direction) => (
                <option key={direction.value} value={direction.value}>{direction.label}</option>
              ))}
            </select>
            <small>Bepaalt de draairichting voor alleen deze pagina.</small>
          </label>
        ) : null}
        {pageTransition === undefined ? null : (
          <SecondsStepper
            id={`wallboard-page-${page.id}-transition-duration`}
            label="Eigen animatieduur"
            min={MIN_WALLBOARD_TRANSITION_DURATION_SECONDS}
            max={MAX_WALLBOARD_TRANSITION_DURATION_SECONDS}
            step={0.1}
            value={transitionDurationSeconds(page.transition_duration_ms, globalTransitionDurationMs)}
            disabled={pageTransition === 'none'}
            onChange={(durationSeconds) => onChange({
              ...page,
              transition_duration_ms: transitionDurationMilliseconds(
                durationSeconds,
                globalTransitionDurationMs,
              ),
            })}
            description="Geldt alleen voor deze pagina."
            required={pageTransition !== 'none'}
          />
        )}
      </div>

      {page.type === 'message' || page.type === 'safety_notice' ? (
        <div className="wallboard-message-editor" role="group" aria-labelledby={`wallboard-message-editor-${page.id}-label`}>
          <div className="wallboard-message-editor__heading">
            <span id={`wallboard-message-editor-${page.id}-label`}>{page.type === 'safety_notice' ? 'Veiligheidsinhoud op het scherm' : 'Inhoud op het scherm'}</span>
            <small>De naam hierboven is alleen zichtbaar in beheer.</small>
          </div>
          <WallboardRichTextEditor
            id={`wallboard-message-editor-${page.id}`}
            value={wallboardMessageContent(page.options)}
            onChange={(content) => onChange({ ...page, options: { content } })}
          />
        </div>
      ) : page.type === 'quote' ? (
        <fieldset className="wallboard-quote-editor">
          <legend>Quotes</legend>
          <p>
            Het wallboard kiest per kalenderdag in Europe/Amsterdam steeds dezelfde quote. Voeg alleen eigen,
            gecontroleerde inhoud toe; er wordt geen externe quote opgehaald.
          </p>
          <div className="wallboard-quote-editor__list">
            {(page.options.quotes ?? []).map((quote, quoteIndex) => (
              <div className="wallboard-quote-editor__item" key={`${page.id}-quote-${quoteIndex}`}>
                <label>
                  <span>Quote {quoteIndex + 1}</span>
                  <textarea
                    value={quote.text}
                    maxLength={MAX_WALLBOARD_QUOTE_TEXT_LENGTH}
                    required
                    onChange={(event) => onChange({
                      ...page,
                      options: {
                        quotes: (page.options.quotes ?? []).map((candidate, candidateIndex) => (
                          candidateIndex === quoteIndex ? { ...candidate, text: event.target.value } : candidate
                        )),
                      },
                    })}
                  />
                </label>
                <label>
                  <span>Auteur (optioneel)</span>
                  <input
                    value={quote.author ?? ''}
                    maxLength={MAX_WALLBOARD_QUOTE_AUTHOR_LENGTH}
                    onChange={(event) => onChange({
                      ...page,
                      options: {
                        quotes: (page.options.quotes ?? []).map((candidate, candidateIndex) => (
                          candidateIndex === quoteIndex
                            ? { ...candidate, author: event.target.value }
                            : candidate
                        )),
                      },
                    })}
                  />
                </label>
                <button
                  type="button"
                  className="icon-button danger"
                  aria-label={`Quote ${quoteIndex + 1} verwijderen`}
                  disabled={(page.options.quotes?.length ?? 0) <= 1}
                  onClick={() => onChange({
                    ...page,
                    options: { quotes: (page.options.quotes ?? []).filter((_, index) => index !== quoteIndex) },
                  })}
                >
                  <Trash2 size={17} aria-hidden />
                </button>
              </div>
            ))}
          </div>
          <button
            type="button"
            className="secondary-button"
            disabled={(page.options.quotes?.length ?? 0) >= MAX_WALLBOARD_QUOTES}
            onClick={() => onChange({
              ...page,
              options: { quotes: [...(page.options.quotes ?? []), { text: '' }] },
            })}
          >
            <Plus size={16} aria-hidden /> Quote toevoegen
          </button>
          <small>Minimaal 1 en maximaal {MAX_WALLBOARD_QUOTES} quotes; maximaal {MAX_WALLBOARD_QUOTE_TEXT_LENGTH} tekens per quote.</small>
        </fieldset>
      ) : page.type === 'calendar' ? (
        <fieldset className="wallboard-page-editor__page-options">
          <legend>Agenda-inhoud</legend>
          <p>Toon de eerstvolgende algemene agenda-items uit de bestaande DIS-agenda.</p>
          <SecondsStepper
            id={`wallboard-calendar-${page.id}-max-items`}
            label="Maximum aantal agenda-items"
            min={MIN_WALLBOARD_CALENDAR_MAX_ITEMS}
            max={MAX_WALLBOARD_CALENDAR_MAX_ITEMS}
            value={clampWallboardCalendarMaxItems(Number(page.options.max_items))}
            onChange={(maxItems) => onChange({
              ...page,
              options: {
                ...page.options,
                max_items: clampWallboardCalendarMaxItems(maxItems),
              },
            })}
            unit="st."
            unitLabel="agenda-items"
            description={`Toon minimaal ${MIN_WALLBOARD_CALENDAR_MAX_ITEMS} en maximaal ${MAX_WALLBOARD_CALENDAR_MAX_ITEMS} toekomstige items.`}
            required
          />
        </fieldset>
      ) : page.type === 'kpi' ? (
        <fieldset className="wallboard-page-editor__page-options wallboard-kpi-editor">
          <legend>KPI-inhoud</legend>
          <p id={`wallboard-kpi-${page.id}-help`}>
            Zet iedere KPI onafhankelijk aan of uit en kies de gewenste weergave. Diagrammen zijn alleen beschikbaar als de gegevens een echte verdeling of verhouding bevatten.
          </p>
          <div className="wallboard-kpi-editor__categories">
            {KPI_CATEGORIES.map((category) => (
              <fieldset
                className="wallboard-option-grid wallboard-kpi-editor__category"
                aria-describedby={`wallboard-kpi-${page.id}-help`}
                key={category.key}
              >
                <legend>
                  <strong>{category.label}</strong>
                  <small>{category.description}</small>
                </legend>
                {WALLBOARD_KPI_DEFINITIONS.filter((metric) => metric.category === category.key).map((metric) => {
                  const visualizationId = `wallboard-kpi-${page.id}-${metric.key}-visualization`;
                  const enabled = kpiVisibleMetrics.includes(metric.key);
                  const selectedVisualization = normalizedKpiOptions.metric_visualizations?.[metric.key] ?? 'counter';
                  return (
                    <div className="wallboard-kpi-editor__metric" key={metric.key}>
                      <label className="wallboard-kpi-editor__metric-toggle">
                        <input
                          type="checkbox"
                          checked={enabled}
                          onChange={(event) => updateKpiVisibleMetric(metric.key, event.target.checked)}
                        />
                        <span>
                          <strong>{metric.label}</strong>
                          <small>{metric.help}</small>
                        </span>
                      </label>
                      <label className="wallboard-kpi-editor__visualization" htmlFor={visualizationId}>
                        <span>Weergave</span>
                        <select
                          id={visualizationId}
                          value={selectedVisualization}
                          disabled={!enabled}
                          onChange={(event) => updateKpiVisualization(
                            metric.key,
                            event.target.value as WallboardKpiVisualization,
                          )}
                        >
                          {wallboardKpiSupportedVisualizations(metric.key).map((visualization) => (
                            <option
                              value={visualization}
                              disabled={visualization !== 'counter'
                                && selectedVisualization === 'counter'
                                && kpiChartCount >= MAX_WALLBOARD_KPI_CHARTS}
                              key={visualization}
                            >
                              {KPI_VISUALIZATION_LABELS[visualization]}
                            </option>
                          ))}
                        </select>
                      </label>
                    </div>
                  );
                })}
              </fieldset>
            ))}
          </div>
          {kpiVisibleMetrics.length === 0 ? (
            <p className="wallboard-page-editor__note" role="status">
              Alle KPI&apos;s staan uit. Het wallboard toont voor deze pagina een lege staat.
            </p>
          ) : (
            <p className="wallboard-page-editor__note" role="status">
              {kpiVisibleMetrics.length} van {WALLBOARD_KPI_KEYS.length} KPI&apos;s zichtbaar;{' '}
              {kpiChartCount} van maximaal {MAX_WALLBOARD_KPI_CHARTS} diagrammen gebruikt.
            </p>
          )}
        </fieldset>
      ) : page.type === 'uav_forecast' ? (
        <fieldset className="wallboard-page-editor__page-options">
          <legend id={`wallboard-forecast-editor-${page.id}-label`}>Locatiegebonden vliegweer</legend>
          <p>De server haalt actuele weer- en Kp-data op. Kies het landelijke overzicht of zoek een specifieke locatie.</p>
          <div className="segmented-control" role="group" aria-labelledby={`wallboard-forecast-editor-${page.id}-label`}>
            <button
              type="button"
              className={`segmented-control__item${forecastLocationMode === 'netherlands' ? ' segmented-control__item--active' : ''}`}
              aria-pressed={forecastLocationMode === 'netherlands'}
              onClick={() => updateForecastLocationMode('netherlands')}
            >
              UAV Nederland
            </button>
            <button
              type="button"
              className={`segmented-control__item${forecastLocationMode === 'address' ? ' segmented-control__item--active' : ''}`}
              aria-pressed={forecastLocationMode === 'address'}
              onClick={() => updateForecastLocationMode('address')}
            >
              Andere locatie
            </button>
          </div>
          {forecastLocationMode === 'address' ? (
            <div className="location-picker__search">
              <label htmlFor={`wallboard-forecast-${page.id}-location`}>
                <span>Locatie zoeken</span>
              </label>
              <input
                id={`wallboard-forecast-${page.id}-location`}
                type="search"
                value={forecastLocationQuery}
                onFocus={() => setForecastSearchOpen(true)}
                onBlur={() => void resolveTypedForecastLocation()}
                onChange={(event) => {
                  forecastLookupSequence.current += 1;
                  setForecastSearchLoading(false);
                  setForecastSearchError(null);
                  setForecastSearchOpen(true);
                  onChange({
                    ...page,
                    options: {
                      location_mode: 'address',
                      location_label: event.target.value,
                      visible_blocks: forecastVisibleBlocks,
                    },
                  });
                }}
                maxLength={120}
                placeholder="Zoek op naam, plaats of adres"
                autoComplete="off"
                aria-invalid={forecastSearchError !== null}
                aria-describedby={`wallboard-forecast-${page.id}-location-help`}
                required
              />
              {forecastSearchOpen && forecastSuggestions.length > 0 ? (
                <div className="location-picker__results" aria-label="Gevonden locaties">
                  {forecastSuggestions.map((suggestion) => (
                    <button
                      key={suggestion.id}
                      type="button"
                      onMouseDown={(event) => event.preventDefault()}
                      onClick={() => void selectForecastSuggestion(suggestion)}
                    >
                      <MapPin size={14} aria-hidden />
                      <span>{suggestion.label}</span>
                    </button>
                  ))}
                </div>
              ) : null}
              {forecastSearchLoading ? <small role="status">Locatie controleren...</small> : null}
              {forecastSearchError !== null ? <p className="error-text" role="alert">{forecastSearchError}</p> : null}
              <small id={`wallboard-forecast-${page.id}-location-help`}>
                Kies een resultaat uit de bestaande DIS-adreszoeker. De server controleert de locatie bij het opslaan.
              </small>
            </div>
          ) : (
            <p className="wallboard-page-editor__note">
              UAV Nederland combineert het actuele vliegweer voor alle Nederlandse provincies tot één landelijk overzicht.
            </p>
          )}
          <fieldset
            className="wallboard-option-grid"
            aria-describedby={`wallboard-forecast-${page.id}-blocks-help`}
          >
            <legend>Zichtbare informatieblokken</legend>
            <p className="wallboard-page-editor__note">
              {forecastVisibleBlocks.length} van maximaal {MAX_WALLBOARD_FORECAST_VISIBLE_BLOCKS} zichtbaar
            </p>
            {FORECAST_BLOCK_OPTIONS.map((option) => (
              <label key={option.key}>
                <input
                  type="checkbox"
                  checked={forecastVisibleBlocks.includes(option.key)}
                  disabled={!forecastVisibleBlocks.includes(option.key)
                    && forecastVisibleBlocks.length >= MAX_WALLBOARD_FORECAST_VISIBLE_BLOCKS}
                  onChange={(event) => updateForecastVisibleBlock(option.key, event.target.checked)}
                />
                <span>
                  <strong>{option.label}</strong>
                  <small>{option.help}</small>
                </span>
              </label>
            ))}
          </fieldset>
          <p className="wallboard-page-editor__note">
            <strong>Vliegadvies blijft altijd zichtbaar.</strong>{' '}
            <span id={`wallboard-forecast-${page.id}-blocks-help`}>
              Het advies weegt alle beschikbare waarden mee, ook wanneer je een informatieblok verbergt.
              Drempels zijn centraal beveiligd en niet vanuit het scherm aanpasbaar.
            </span>
          </p>
        </fieldset>
      ) : page.type === 'video' ? (
        <WallboardVideoPageEditor page={page} onChange={onChange} />
      ) : page.type === 'photo_carousel' ? (
        <WallboardPhotoPageEditor
          idPrefix={`wallboard-photo-${page.id}`}
          source={photoPlaylists}
          value={{
            media_playlist_id: page.options.media_playlist_id,
            item_duration_seconds: page.options.item_duration_seconds,
            item_transition: page.options.item_transition,
            item_transition_duration_ms: page.options.item_transition_duration_ms,
            item_flip_direction: page.options.item_flip_direction,
          }}
          onChange={(selection) => onChange({
            ...page,
            duration_seconds: selection.pageDurationSeconds,
            options: selection.options,
          })}
        />
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
          <SecondsStepper
            className="wallboard-news-editor__count"
            id={`wallboard-news-${page.id}-max-items`}
            label="Maximum aantal berichten"
            min={MIN_WALLBOARD_NEWS_MAX_ITEMS}
            max={MAX_WALLBOARD_NEWS_MAX_ITEMS}
            value={clampWallboardNewsMaxItems(Number(page.options.max_items))}
            onChange={(maxItems) => updateNewsOptions({
              max_items: clampWallboardNewsMaxItems(maxItems),
            })}
            unit="st."
            unitLabel="berichten"
            description="Begrenst zowel de recente set als de laatste berichten wanneer de 7-dagenperiode leeg is."
            required
          />
          <SecondsStepper
            className="wallboard-news-editor__count"
            id={`wallboard-news-${page.id}-item-duration`}
            label="Tijd per nieuwsbericht"
            min={MIN_WALLBOARD_NEWS_ITEM_DURATION_SECONDS}
            max={MAX_WALLBOARD_NEWS_ITEM_DURATION_SECONDS}
            value={clampWallboardNewsItemDuration(Number(page.options.item_duration_seconds))}
            onChange={(durationSeconds) => updateNewsOptions({
              item_duration_seconds: clampWallboardNewsItemDuration(durationSeconds),
            })}
            description="Elk artikel wisselt automatisch met de gekozen overgang; de totale paginatijd wordt automatisch berekend."
            required
          />
          <label className="wallboard-news-editor__count">
            <span>Overgang tussen nieuwsberichten</span>
            <select
              value={newsItemTransition}
              onChange={(event) => {
                const itemTransition = normalizeWallboardNewsItemTransition(event.target.value);
                updateNewsOptions({
                  item_transition: itemTransition,
                  ...(itemTransition === 'flip'
                    ? { item_flip_direction: newsItemFlipDirection }
                    : {}),
                });
              }}
            >
              {WALLBOARD_NEWS_ITEM_TRANSITIONS.map((transition) => (
                <option key={transition.value} value={transition.value}>{transition.label}</option>
              ))}
            </select>
            <small>Bij minder beweging vallen Schuiven, Flip, Zachte zoom en Wipe automatisch terug op een rustige dissolve.</small>
          </label>
          {newsItemTransition === 'flip' ? (
            <label className="wallboard-news-editor__count">
              <span>Fliprichting nieuwsberichten</span>
              <select
                value={newsItemFlipDirection}
                onChange={(event) => updateNewsOptions({
                  item_flip_direction: normalizeWallboardFlipDirection(event.target.value),
                })}
              >
                {WALLBOARD_FLIP_DIRECTIONS.map((direction) => (
                  <option key={direction.value} value={direction.value}>{direction.label}</option>
                ))}
              </select>
              <small>Bepaalt hoe ieder volgend nieuwsbericht omdraait.</small>
            </label>
          ) : null}
          <SecondsStepper
            className="wallboard-news-editor__count"
            id={`wallboard-news-${page.id}-transition-duration`}
            label="Duur nieuwsberichtovergang"
            min={MIN_WALLBOARD_TRANSITION_DURATION_SECONDS}
            max={MAX_WALLBOARD_TRANSITION_DURATION_SECONDS}
            step={0.1}
            value={transitionDurationSeconds(
              page.options.item_transition_duration_ms,
              DEFAULT_WALLBOARD_NEWS_ITEM_TRANSITION_DURATION_MS,
            )}
            disabled={newsItemTransition === 'none'}
            onChange={(durationSeconds) => updateNewsOptions({
              item_transition_duration_ms: transitionDurationMilliseconds(
                durationSeconds,
                DEFAULT_WALLBOARD_NEWS_ITEM_TRANSITION_DURATION_MS,
              ),
            })}
            description="Hoe lang de wisselanimatie tussen twee nieuwsberichten duurt."
            required={newsItemTransition !== 'none'}
          />
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
    case 'kpi': return <Gauge size={18} aria-hidden />;
    case 'calendar': return <CalendarDays size={18} aria-hidden />;
    case 'message': return <MessageSquareText size={18} aria-hidden />;
    case 'safety_notice': return <ShieldAlert size={18} aria-hidden />;
    case 'quote': return <QuoteIcon size={18} aria-hidden />;
    case 'uav_forecast': return <CloudSun size={18} aria-hidden />;
    case 'news': return <Newspaper size={18} aria-hidden />;
    case 'video': return <Clapperboard size={18} aria-hidden />;
    case 'photo_carousel': return <Images size={18} aria-hidden />;
  }
}
