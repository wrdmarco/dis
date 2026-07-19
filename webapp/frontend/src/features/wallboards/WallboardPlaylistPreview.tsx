'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import {
  BellRing,
  Clock3,
  Eye,
  Map,
  MessageSquareText,
  Newspaper,
  Radio,
  Rss,
  Siren,
  X,
} from 'lucide-react';
import type { WallboardConfiguration } from '../../types/api';
import {
  wallboardConfigurationCopy,
  wallboardFocusKindLabel,
  wallboardPageTypeLabel,
} from './wallboardPresentation';

interface WallboardPlaylistPreviewProps {
  playlistName: string;
  configuration: WallboardConfiguration;
  onClose: () => void;
}

export function WallboardPlaylistPreview({
  playlistName,
  configuration,
  onClose,
}: WallboardPlaylistPreviewProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const snapshot = useMemo(() => wallboardConfigurationCopy(configuration), [configuration]);
  const [selectedPageId, setSelectedPageId] = useState(snapshot.pages[0].id);
  const selectedPage = snapshot.pages.find((page) => page.id === selectedPageId) ?? snapshot.pages[0];
  const totalDuration = snapshot.pages.reduce((total, page) => total + page.duration_seconds, 0);

  useEffect(() => {
    const dialog = dialogRef.current;
    const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    if (dialog !== null && !dialog.open) dialog.showModal();

    return () => {
      if (dialog?.open) dialog.close();
      previousFocus?.focus();
    };
  }, []);

  return (
    <dialog
      ref={dialogRef}
      className="wallboard-playlist-preview"
      aria-labelledby="wallboard-playlist-preview-title"
      onCancel={(event) => {
        event.preventDefault();
        dialogRef.current?.close();
      }}
      onClose={onClose}
    >
      <header className="wallboard-playlist-preview__header">
        <div>
          <span className="eyebrow">Alleen-lezen voorbeeld</span>
          <h2 id="wallboard-playlist-preview-title">{playlistName || 'Naamloze playlist'}</h2>
          <p>Bekijk de huidige conceptinhoud zonder een wallboard of actieve sessie te besturen.</p>
        </div>
        <button className="icon-button" type="button" onClick={() => dialogRef.current?.close()} aria-label="Voorbeeld sluiten">
          <X size={20} aria-hidden />
        </button>
      </header>

      <div className="wallboard-playlist-preview__body">
        <section className="wallboard-playlist-preview__screen" aria-labelledby="wallboard-preview-page-title">
          <div className="wallboard-playlist-preview__screen-bar">
            <span><Eye size={16} aria-hidden /> Wallboardvoorbeeld</span>
            <span><Clock3 size={16} aria-hidden /> {selectedPage.duration_seconds} sec.</span>
          </div>
          <div className={`wallboard-playlist-preview__canvas wallboard-playlist-preview__canvas--${selectedPage.type}`}>
            {selectedPage.type === 'map'
              ? <Map size={42} aria-hidden />
              : selectedPage.type === 'news'
                ? <Newspaper size={42} aria-hidden />
                : <MessageSquareText size={42} aria-hidden />}
            <span>{wallboardPageTypeLabel(selectedPage.type)}</span>
            <h3 id="wallboard-preview-page-title">{selectedPage.name}</h3>
            {selectedPage.type === 'message' ? (
              <p>{selectedPage.options.body?.trim() || 'Nog geen mededeling ingevuld.'}</p>
            ) : selectedPage.type === 'news' ? (
              <p>
                Maximaal {selectedPage.options.max_items ?? 6} berichten uit de laatste 7 dagen en
                {' '}{(selectedPage.options.sources ?? ['ndt', 'dronewatch']).length
                  + (selectedPage.options.custom_sources ?? []).length} vaste en eigen bron(nen) samen.
                Elk bericht blijft {selectedPage.options.item_duration_seconds ?? 12} seconden zichtbaar en wisselt daarna vloeiend.
                Is die periode leeg, dan volgen de laatste publicaties met samenvatting.
              </p>
            ) : (
              <p>De live operationele gegevens verschijnen hier pas op het gekoppelde wallboard.</p>
            )}
          </div>
          {snapshot.ticker.enabled ? (
            <div className="wallboard-playlist-preview__ticker" aria-label="Voorbeeld onderticker">
              {snapshot.ticker.sources.length > 0
                ? snapshot.ticker.sources.map((source) => source.label).join('  •  ')
                : 'Onderticker ingeschakeld, nog zonder bronnen.'}
            </div>
          ) : null}
        </section>

        <aside className="wallboard-playlist-preview__details">
          <section>
            <h3>Pagina’s · {totalDuration} sec. per ronde</h3>
            <ol className="wallboard-playlist-preview__pages">
              {snapshot.pages.map((page, index) => (
                <li key={page.id}>
                  <button
                    type="button"
                    className={page.id === selectedPage.id ? 'wallboard-playlist-preview__page wallboard-playlist-preview__page--active' : 'wallboard-playlist-preview__page'}
                    onClick={() => setSelectedPageId(page.id)}
                    aria-pressed={page.id === selectedPage.id}
                  >
                    <span>{index + 1}</span>
                    <span><strong>{page.name}</strong><small>{wallboardPageTypeLabel(page.type)}</small></span>
                    <time>{page.duration_seconds} sec.</time>
                  </button>
                </li>
              ))}
            </ol>
          </section>

          <section>
            <h3>Onderticker</h3>
            <p>{snapshot.ticker.enabled ? `${snapshot.ticker.sources.length} bron(nen) actief.` : 'Uitgeschakeld.'}</p>
            {snapshot.ticker.enabled && snapshot.ticker.sources.length > 0 ? (
              <ul className="wallboard-playlist-preview__sources">
                {snapshot.ticker.sources.map((source) => (
                  <li key={source.id}>
                    {source.type === 'rss' ? <Rss size={15} aria-hidden /> : <MessageSquareText size={15} aria-hidden />}
                    <span>{source.label}</span>
                    <small>{source.type === 'rss' ? `max. ${source.max_items} berichten` : 'intern bericht'}</small>
                  </li>
                ))}
              </ul>
            ) : null}
          </section>

          <section>
            <h3>Kaart en focus</h3>
            <p>{snapshot.map.show_routes ? 'Pilootroutes zichtbaar' : 'Pilootroutes verborgen'} · {snapshot.map.show_live_locations ? 'live locaties zichtbaar' : 'live locaties verborgen'}</p>
            <ul className="wallboard-playlist-preview__focus">
              {(['preannouncement', 'real_alarm', 'test_alarm'] as const).map((kind) => {
                const focus = snapshot.focus[kind];
                const Icon = kind === 'preannouncement' ? BellRing : kind === 'real_alarm' ? Siren : Radio;
                return (
                  <li key={kind}>
                    <Icon size={16} aria-hidden />
                    <span><strong>{wallboardFocusKindLabel(kind)}</strong><small>{focus.enabled ? `${focus.duration_seconds} sec. · feed ${focus.show_response_feed ? 'aan' : 'uit'}` : 'uitgeschakeld'}</small></span>
                  </li>
                );
              })}
            </ul>
          </section>
        </aside>
      </div>
    </dialog>
  );
}
