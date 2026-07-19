import { type Dispatch, type SetStateAction, useEffect, useState } from 'react';
import {
  ArrowDown,
  ArrowUp,
  BarChart3,
  List,
  Map,
  MessageSquareText,
  PauseCircle,
  Plus,
  Rss,
  Trash2,
} from 'lucide-react';
import type {
  WallboardConfiguration,
  WallboardMapConfiguration,
  WallboardPage,
  WallboardPageType,
  WallboardTickerSource,
  WallboardTickerSourceType,
} from '../../types/api';
import {
  MAX_WALLBOARD_PAGE_DURATION_SECONDS,
  MAX_WALLBOARD_REFRESH_SECONDS,
  MAX_WALLBOARD_TICKER_SOURCES,
  MIN_WALLBOARD_PAGE_DURATION_SECONDS,
  MIN_WALLBOARD_REFRESH_SECONDS,
  clampWallboardPageDuration,
  createWallboardPage,
  createWallboardTickerSource,
  wallboardPageTypeLabel,
} from './wallboardPresentation';

const MAP_OPTION_LABELS: Array<{ key: keyof WallboardMapConfiguration; label: string; help: string }> = [
  { key: 'show_active_incidents', label: 'Actieve incidenten', help: 'Toon open operationele meldingen.' },
  { key: 'show_test_incidents', label: 'Proefmeldingen', help: 'Neem ook als test gemarkeerde incidenten op.' },
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
                    <label>
                      <span>HTTPS RSS-adres</span>
                      <input
                        type="url"
                        value={source.url ?? ''}
                        onChange={(event) => updateTickerSource(source.id, (current) => ({ ...current, url: event.target.value }))}
                        maxLength={2048}
                        pattern="https://.*"
                        placeholder="https://voorbeeld.nl/feed.xml"
                        required
                      />
                    </label>
                  ) : (
                    <label className="wallboard-ticker-source__message">
                      <span>Bericht</span>
                      <textarea
                        value={source.text ?? ''}
                        onChange={(event) => updateTickerSource(source.id, (current) => ({ ...current, text: event.target.value }))}
                        maxLength={500}
                        rows={3}
                        placeholder="Tekst die onderin het wallboard loopt."
                        required
                      />
                      <small>{(source.text ?? '').length}/500 tekens</small>
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

      <fieldset className="wallboard-incident-override">
        <legend>Automatisch bij een actief incident</legend>
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
          <span><strong>Incidentpagina vastzetten</strong><small>De rotatie pauzeert zolang minimaal één operationeel incident actief is.</small></span>
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
        <p><PauseCircle size={16} aria-hidden /> Een actief incident heeft voorrang; daarna keert ieder gekoppeld scherm terug naar de handmatige pagina of rotatie.</p>
      </fieldset>
    </div>
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
      ) : (
        <div className="wallboard-page-editor__page-options">
          <p className="wallboard-page-editor__hint">Deze pagina gebruikt de geselecteerde gegevens en kaartlagen van de playlist.</p>
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

export function WallboardPageTypeIcon({ type }: { type: WallboardPageType }) {
  switch (type) {
    case 'map': return <Map size={18} aria-hidden />;
    case 'incident_list': return <List size={18} aria-hidden />;
    case 'summary': return <BarChart3 size={18} aria-hidden />;
    case 'message': return <MessageSquareText size={18} aria-hidden />;
  }
}
