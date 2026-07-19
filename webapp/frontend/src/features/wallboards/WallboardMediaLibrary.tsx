'use client';

import {
  type ChangeEvent,
  type DragEvent,
  type FormEvent,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import {
  ArrowDown,
  ArrowUp,
  Check,
  Folder,
  FolderOpen,
  FolderPlus,
  FileVideo2,
  Image as ImageIcon,
  Images,
  Loader2,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  Trash2,
  Upload,
  X,
} from 'lucide-react';
import { ApiClientError } from '../../lib/apiClient';
import { useAuth } from '../auth/AuthContext';
import {
  type WallboardMediaAsset,
  type WallboardMediaAssetPage,
  type WallboardMediaFolder,
  type WallboardMediaPlaylist,
  WALLBOARD_MEDIA_MAX_PLAYLIST_ITEMS,
  WALLBOARD_MEDIA_MAX_BATCH_FILES,
  wallboardMediaAssetPreviewUrl,
  wallboardMediaAssetIds,
  wallboardMediaFileValidationMessage,
  wallboardMediaFileKind,
  wallboardMediaFolderTree,
  wallboardMediaFormatBytes,
  wallboardMediaImageUrl,
} from './wallboardMedia';
import styles from './WallboardMediaLibrary.module.css';

type LibraryView = 'assets' | 'playlists';
type FolderFilter = 'all' | 'unfiled' | string;

const EMPTY_PAGINATION = { current_page: 1, last_page: 1, per_page: 25, total: 0 };

type UploadStatus = 'pending' | 'uploading' | 'failed';

interface UploadQueueItem {
  id: string;
  file: File;
  displayName: string;
  validationMessage: string | null;
  status: UploadStatus;
  uploadError: string | null;
}

export function WallboardMediaLibrary() {
  const { api } = useAuth();
  const [view, setView] = useState<LibraryView>('assets');
  const [folders, setFolders] = useState<WallboardMediaFolder[]>([]);
  const [assetsPage, setAssetsPage] = useState<WallboardMediaAssetPage>({ items: [], pagination: EMPTY_PAGINATION });
  const [playlists, setPlaylists] = useState<WallboardMediaPlaylist[]>([]);
  const [folderFilter, setFolderFilter] = useState<FolderFilter>('all');
  const [search, setSearch] = useState('');
  const [assetPageNumber, setAssetPageNumber] = useState(1);
  const [selectedAssetIds, setSelectedAssetIds] = useState<Set<string>>(() => new Set());
  const [knownAssets, setKnownAssets] = useState<Map<string, WallboardMediaAsset>>(() => new Map());
  const [selectedPlaylistId, setSelectedPlaylistId] = useState<string | null>(null);
  const [playlistName, setPlaylistName] = useState('');
  const [playlistAssetIds, setPlaylistAssetIds] = useState<string[]>([]);
  const [newFolderName, setNewFolderName] = useState('');
  const [editingFolderId, setEditingFolderId] = useState<string | null>(null);
  const [editingFolderName, setEditingFolderName] = useState('');
  const [uploadItems, setUploadItems] = useState<UploadQueueItem[]>([]);
  const [draggingFiles, setDraggingFiles] = useState(false);
  const [loadingLibrary, setLoadingLibrary] = useState(true);
  const [loadingAssets, setLoadingAssets] = useState(true);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const assetRequestRef = useRef(0);
  const uploadInputRef = useRef<HTMLInputElement>(null);
  const uploadSequenceRef = useRef(0);
  const dragDepthRef = useRef(0);

  const folderTree = useMemo(() => wallboardMediaFolderTree(folders), [folders]);
  const selectedPlaylist = playlists.find((playlist) => playlist.id === selectedPlaylistId) ?? null;
  const assetLookup = useMemo(() => {
    const lookup = new Map<string, WallboardMediaAsset>();
    for (const playlist of playlists) {
      for (const item of playlist.items) lookup.set(item.asset.id, item.asset);
    }
    for (const asset of knownAssets.values()) lookup.set(asset.id, asset);
    return lookup;
  }, [knownAssets, playlists]);

  const loadLibrary = useCallback(async (silent = false) => {
    if (!silent) setLoadingLibrary(true);
    setError(null);
    try {
      const [folderResponse, playlistResponse] = await Promise.all([
        api.get<WallboardMediaFolder[]>('/admin/wallboard-media/folders'),
        api.get<WallboardMediaPlaylist[]>('/admin/wallboard-media/playlists'),
      ]);
      setFolders(folderResponse.data);
      setPlaylists(playlistResponse.data);
      setSelectedPlaylistId((current) => (
        current !== null && playlistResponse.data.some((playlist) => playlist.id === current)
          ? current
          : null
      ));
    } catch (loadError) {
      setError(errorMessage(loadError, 'Mediabeheer kon niet worden geladen.'));
    } finally {
      if (!silent) setLoadingLibrary(false);
    }
  }, [api]);

  const loadAssets = useCallback(async (filter: FolderFilter, query: string, page: number) => {
    const requestId = assetRequestRef.current + 1;
    assetRequestRef.current = requestId;
    setLoadingAssets(true);
    setError(null);
    const parameters = new URLSearchParams({ per_page: '25', page: String(page) });
    if (filter === 'unfiled') parameters.set('unfiled', '1');
    else if (filter !== 'all') parameters.set('folder_id', filter);
    if (query.trim() !== '') parameters.set('search', query.trim());

    try {
      const response = await api.get<WallboardMediaAsset[]>(`/admin/wallboard-media/assets?${parameters.toString()}`);
      if (requestId !== assetRequestRef.current) return;
      const meta = response.meta;
      const pagination = meta !== undefined
        && 'current_page' in meta
        && 'last_page' in meta
        && 'per_page' in meta
        && 'total' in meta
        ? {
          current_page: Number(meta.current_page),
          last_page: Number(meta.last_page),
          per_page: Number(meta.per_page),
          total: Number(meta.total),
        }
        : EMPTY_PAGINATION;
      setAssetsPage({ items: response.data, pagination });
      setKnownAssets((current) => {
        const next = new Map(current);
        for (const asset of response.data) next.set(asset.id, asset);
        return next;
      });
    } catch (loadError) {
      if (requestId === assetRequestRef.current) {
        setError(errorMessage(loadError, 'Afbeeldingen konden niet worden geladen.'));
      }
    } finally {
      if (requestId === assetRequestRef.current) setLoadingAssets(false);
    }
  }, [api]);

  useEffect(() => {
    void loadLibrary();
  }, [loadLibrary]);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void loadAssets(folderFilter, search, assetPageNumber);
    }, search.trim() === '' ? 0 : 250);
    return () => window.clearTimeout(timer);
  }, [assetPageNumber, folderFilter, loadAssets, search]);

  useEffect(() => {
    if (selectedPlaylist === null) return;
    setPlaylistName(selectedPlaylist.name);
    setPlaylistAssetIds(wallboardMediaAssetIds(selectedPlaylist));
  }, [selectedPlaylist]);

  async function refreshAll() {
    await Promise.all([
      loadLibrary(true),
      loadAssets(folderFilter, search, assetPageNumber),
    ]);
  }

  async function createFolder(event: FormEvent) {
    event.preventDefault();
    const name = newFolderName.trim();
    if (name === '') return;
    setBusy('folder-create');
    clearFeedback();
    try {
      await api.post('/admin/wallboard-media/folders', {
        name,
        parent_id: isFolderId(folderFilter) ? folderFilter : null,
      });
      setNewFolderName('');
      setNotice('Map aangemaakt.');
      await loadLibrary(true);
    } catch (mutationError) {
      setError(errorMessage(mutationError, 'Map kon niet worden aangemaakt.'));
    } finally {
      setBusy(null);
    }
  }

  async function renameFolder(event: FormEvent, folder: WallboardMediaFolder) {
    event.preventDefault();
    const name = editingFolderName.trim();
    if (name === '') return;
    setBusy(`folder-${folder.id}`);
    clearFeedback();
    try {
      await api.patch(`/admin/wallboard-media/folders/${folder.id}`, {
        expected_version: folder.version,
        name,
      });
      setEditingFolderId(null);
      setEditingFolderName('');
      setNotice('Mapnaam bijgewerkt.');
      await loadLibrary(true);
    } catch (mutationError) {
      setError(errorMessage(mutationError, 'Mapnaam kon niet worden bijgewerkt.'));
    } finally {
      setBusy(null);
    }
  }

  async function deleteFolder(folder: WallboardMediaFolder) {
    if (!window.confirm(`Map "${folder.name}" verwijderen?`)) return;
    setBusy(`folder-${folder.id}`);
    clearFeedback();
    try {
      await api.delete(`/admin/wallboard-media/folders/${folder.id}?expected_version=${folder.version}`);
      if (folderFilter === folder.id) setFolderFilter('all');
      setNotice('Map verwijderd.');
      await loadLibrary(true);
    } catch (mutationError) {
      setError(errorMessage(mutationError, 'De map is niet leeg of is intussen gewijzigd.'));
    } finally {
      setBusy(null);
    }
  }

  function queueUploadFiles(files: Iterable<File>) {
    const selectedFiles = Array.from(files);
    if (selectedFiles.length === 0) return;
    clearFeedback();
    const availableSlots = Math.max(0, WALLBOARD_MEDIA_MAX_BATCH_FILES - uploadItems.length);
    const accepted = selectedFiles.slice(0, availableSlots);
    if (selectedFiles.length > availableSlots) {
      setError(`Selecteer maximaal ${WALLBOARD_MEDIA_MAX_BATCH_FILES} bestanden per uploadronde.`);
    }
    setUploadItems((current) => {
      const additions = accepted.map((file): UploadQueueItem => {
        uploadSequenceRef.current += 1;
        return {
          id: `${file.name}:${file.size}:${file.lastModified}:${uploadSequenceRef.current}`,
          file,
          displayName: file.name.replace(/\.[^.]+$/, ''),
          validationMessage: wallboardMediaFileValidationMessage(file),
          status: 'pending',
          uploadError: null,
        };
      });
      return [...current, ...additions];
    });
  }

  function selectUploadFiles(event: ChangeEvent<HTMLInputElement>) {
    if (event.target.files !== null) queueUploadFiles(event.target.files);
    event.target.value = '';
  }

  function handleFileDragEnter(event: DragEvent<HTMLFormElement>) {
    if (!event.dataTransfer.types.includes('Files')) return;
    event.preventDefault();
    dragDepthRef.current += 1;
    setDraggingFiles(true);
  }

  function handleFileDragOver(event: DragEvent<HTMLFormElement>) {
    if (!event.dataTransfer.types.includes('Files')) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
  }

  function handleFileDragLeave(event: DragEvent<HTMLFormElement>) {
    if (!event.dataTransfer.types.includes('Files')) return;
    event.preventDefault();
    dragDepthRef.current = Math.max(0, dragDepthRef.current - 1);
    if (dragDepthRef.current === 0) setDraggingFiles(false);
  }

  function handleFileDrop(event: DragEvent<HTMLFormElement>) {
    if (!event.dataTransfer.types.includes('Files')) return;
    event.preventDefault();
    dragDepthRef.current = 0;
    setDraggingFiles(false);
    queueUploadFiles(event.dataTransfer.files);
  }

  async function uploadAssets(event: FormEvent) {
    event.preventDefault();
    const pendingItems = uploadItems.filter((item) => item.validationMessage === null);
    if (pendingItems.length === 0) {
      setError('Selecteer minimaal één geldig mediabestand.');
      return;
    }
    setBusy('upload');
    clearFeedback();
    const completedIds = new Set<string>();
    let failedCount = 0;

    for (const item of pendingItems) {
      setUploadItems((current) => current.map((candidate) => candidate.id === item.id
        ? { ...candidate, status: 'uploading', uploadError: null }
        : candidate));
      const payload = new FormData();
      payload.set('file', item.file);
      if (isFolderId(folderFilter)) payload.set('folder_id', folderFilter);
      if (item.displayName.trim() !== '') payload.set('display_name', item.displayName.trim());
      try {
        await api.postForm('/admin/wallboard-media/assets', payload);
        completedIds.add(item.id);
      } catch (mutationError) {
        failedCount += 1;
        const message = errorMessage(mutationError, `${item.file.name} kon niet worden verwerkt.`);
        setUploadItems((current) => current.map((candidate) => candidate.id === item.id
          ? { ...candidate, status: 'failed', uploadError: message }
          : candidate));
      }
    }

    setUploadItems((current) => current.filter((item) => !completedIds.has(item.id)));
    if (uploadInputRef.current !== null) uploadInputRef.current.value = '';
    if (completedIds.size > 0) {
      setNotice(`${completedIds.size} mediabestand${completedIds.size === 1 ? '' : 'en'} veilig verwerkt en toegevoegd.`);
      await Promise.all([loadLibrary(true), loadAssets(folderFilter, search, 1)]);
      setAssetPageNumber(1);
    }
    if (failedCount > 0) setError(`${failedCount} bestand${failedCount === 1 ? '' : 'en'} kon niet worden geüpload. Controleer de melding per bestand.`);
    setBusy(null);
  }

  async function moveAsset(asset: WallboardMediaAsset, folderId: string | null) {
    setBusy(`asset-${asset.id}`);
    clearFeedback();
    try {
      await api.patch(`/admin/wallboard-media/assets/${asset.id}`, {
        expected_version: asset.version,
        folder_id: folderId,
      });
      setNotice('Afbeelding verplaatst.');
      await Promise.all([loadLibrary(true), loadAssets(folderFilter, search, assetPageNumber)]);
    } catch (mutationError) {
      setError(errorMessage(mutationError, 'Afbeelding kon niet worden verplaatst.'));
    } finally {
      setBusy(null);
    }
  }

  async function deleteAsset(asset: WallboardMediaAsset) {
    if (!window.confirm(`Afbeelding "${asset.display_name}" verwijderen?`)) return;
    setBusy(`asset-${asset.id}`);
    clearFeedback();
    try {
      await api.delete(`/admin/wallboard-media/assets/${asset.id}?expected_version=${asset.version}`);
      setSelectedAssetIds((current) => withoutSetValue(current, asset.id));
      setKnownAssets((current) => {
        const next = new Map(current);
        next.delete(asset.id);
        return next;
      });
      setNotice('Afbeelding verwijderd.');
      await Promise.all([loadLibrary(true), loadAssets(folderFilter, search, assetPageNumber)]);
    } catch (mutationError) {
      setError(errorMessage(mutationError, 'Deze afbeelding wordt nog gebruikt of is intussen gewijzigd.'));
    } finally {
      setBusy(null);
    }
  }

  function startNewPlaylist() {
    setSelectedPlaylistId(null);
    setPlaylistName('');
    setPlaylistAssetIds([...selectedAssetIds]);
    setView('playlists');
    clearFeedback();
  }

  function editPlaylist(playlist: WallboardMediaPlaylist) {
    setSelectedPlaylistId(playlist.id);
    setPlaylistName(playlist.name);
    setPlaylistAssetIds(wallboardMediaAssetIds(playlist));
    clearFeedback();
  }

  function addSelectedAssetsToPlaylist() {
    setPlaylistAssetIds((current) => {
      const next = [...current];
      for (const id of selectedAssetIds) {
        if (!next.includes(id) && next.length < WALLBOARD_MEDIA_MAX_PLAYLIST_ITEMS) next.push(id);
      }
      return next;
    });
    setView('playlists');
  }

  async function savePlaylist(event: FormEvent) {
    event.preventDefault();
    const name = playlistName.trim();
    if (name === '' || playlistAssetIds.length === 0) {
      setError('Geef de fotoplaylist een naam en voeg minimaal één afbeelding toe.');
      return;
    }
    setBusy('playlist-save');
    clearFeedback();
    try {
      const response = selectedPlaylist === null
        ? await api.post<WallboardMediaPlaylist>('/admin/wallboard-media/playlists', {
          name,
          asset_ids: playlistAssetIds,
        })
        : await api.patch<WallboardMediaPlaylist>(`/admin/wallboard-media/playlists/${selectedPlaylist.id}`, {
          expected_version: selectedPlaylist.version,
          name,
          asset_ids: playlistAssetIds,
        });
      setSelectedPlaylistId(response.data.id);
      setPlaylistName(response.data.name);
      setPlaylistAssetIds(wallboardMediaAssetIds(response.data));
      setNotice(selectedPlaylist === null ? 'Fotoplaylist aangemaakt.' : 'Fotoplaylist opgeslagen.');
      await loadLibrary(true);
    } catch (mutationError) {
      setError(errorMessage(mutationError, 'Fotoplaylist kon niet worden opgeslagen.'));
    } finally {
      setBusy(null);
    }
  }

  async function deletePlaylist(playlist: WallboardMediaPlaylist) {
    if (!window.confirm(`Fotoplaylist "${playlist.name}" verwijderen?`)) return;
    setBusy('playlist-delete');
    clearFeedback();
    try {
      await api.delete(`/admin/wallboard-media/playlists/${playlist.id}?expected_version=${playlist.version}`);
      setSelectedPlaylistId(null);
      setPlaylistName('');
      setPlaylistAssetIds([]);
      setNotice('Fotoplaylist verwijderd.');
      await loadLibrary(true);
    } catch (mutationError) {
      setError(errorMessage(mutationError, 'Deze fotoplaylist wordt nog gebruikt of is intussen gewijzigd.'));
    } finally {
      setBusy(null);
    }
  }

  function clearFeedback() {
    setError(null);
    setNotice(null);
  }

  return (
    <section className={styles.root} aria-labelledby="wallboard-media-title">
      <header className={styles.heading}>
        <div>
          <span className={styles.eyebrow}>Wallboardinhoud</span>
          <h2 id="wallboard-media-title">Media</h2>
          <p>Orden beelden in mappen en stel herbruikbare fotoplaylists samen voor gekoppelde schermen.</p>
        </div>
        <button
          type="button"
          className={styles.secondaryButton}
          onClick={() => void refreshAll()}
          disabled={loadingLibrary || loadingAssets}
        >
          <RefreshCw size={17} aria-hidden /> Vernieuwen
        </button>
      </header>

      <nav className={styles.tabs} aria-label="Mediabeheer" role="tablist">
        <button
          type="button"
          role="tab"
          aria-selected={view === 'assets'}
          className={view === 'assets' ? styles.activeTab : undefined}
          onClick={() => setView('assets')}
        >
          <Images size={18} aria-hidden /> Bibliotheek
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={view === 'playlists'}
          className={view === 'playlists' ? styles.activeTab : undefined}
          onClick={() => setView('playlists')}
        >
          <ImageIcon size={18} aria-hidden /> Fotoplaylists <span>{playlists.length}</span>
        </button>
      </nav>

      {error !== null ? <div className={styles.errorBanner} role="alert">{error}</div> : null}
      {notice !== null ? <div className={styles.noticeBanner} role="status"><Check size={17} aria-hidden /> {notice}</div> : null}

      {loadingLibrary ? (
        <div className={styles.loading} role="status"><Loader2 size={22} aria-hidden /> Mediabeheer laden...</div>
      ) : view === 'assets' ? (
        <div className={styles.libraryGrid}>
          <aside className={styles.folderColumn} aria-label="Mappen">
            <div className={styles.columnTitle}><FolderOpen size={18} aria-hidden /><strong>Mappen</strong></div>
            <div className={styles.folderList}>
              <FolderButton
                label="Alle afbeeldingen"
                active={folderFilter === 'all'}
                onClick={() => { setFolderFilter('all'); setAssetPageNumber(1); }}
              />
              <FolderButton
                label="Zonder map"
                active={folderFilter === 'unfiled'}
                onClick={() => { setFolderFilter('unfiled'); setAssetPageNumber(1); }}
              />
              {folderTree.map((folder) => (
                <div key={folder.id} className={styles.folderRow} style={{ '--folder-depth': folder.depth } as React.CSSProperties}>
                  {editingFolderId === folder.id ? (
                    <form className={styles.renameFolderForm} onSubmit={(event) => void renameFolder(event, folder)}>
                      <input
                        value={editingFolderName}
                        maxLength={120}
                        aria-label={`Nieuwe naam voor ${folder.name}`}
                        onChange={(event) => setEditingFolderName(event.target.value)}
                        autoFocus
                      />
                      <button type="submit" aria-label="Mapnaam opslaan" disabled={busy === `folder-${folder.id}`}><Check size={15} aria-hidden /></button>
                      <button type="button" aria-label="Hernoemen annuleren" onClick={() => setEditingFolderId(null)}><X size={15} aria-hidden /></button>
                    </form>
                  ) : (
                    <>
                      <button
                        type="button"
                        className={folderFilter === folder.id ? styles.activeFolder : styles.folderButton}
                        onClick={() => { setFolderFilter(folder.id); setAssetPageNumber(1); }}
                      >
                        <Folder size={16} aria-hidden />
                        <span>{folder.name}</span>
                        <small>{folder.assets_count}</small>
                      </button>
                      <button
                        type="button"
                        className={styles.rowAction}
                        aria-label={`${folder.name} hernoemen`}
                        onClick={() => { setEditingFolderId(folder.id); setEditingFolderName(folder.name); }}
                      ><Pencil size={14} aria-hidden /></button>
                      <button
                        type="button"
                        className={styles.rowAction}
                        aria-label={`${folder.name} verwijderen`}
                        disabled={busy === `folder-${folder.id}`}
                        onClick={() => void deleteFolder(folder)}
                      ><Trash2 size={14} aria-hidden /></button>
                    </>
                  )}
                </div>
              ))}
            </div>
            <form className={styles.newFolderForm} onSubmit={(event) => void createFolder(event)}>
              <label htmlFor="wallboard-media-new-folder">Nieuwe map</label>
              <div>
                <input
                  id="wallboard-media-new-folder"
                  value={newFolderName}
                  maxLength={120}
                  placeholder={isFolderId(folderFilter) ? 'Submapnaam' : 'Mapnaam'}
                  onChange={(event) => setNewFolderName(event.target.value)}
                />
                <button type="submit" disabled={newFolderName.trim() === '' || busy === 'folder-create'} aria-label="Map toevoegen">
                  <FolderPlus size={17} aria-hidden />
                </button>
              </div>
            </form>
          </aside>

          <main className={styles.assetColumn}>
            <div className={styles.assetToolbar}>
              <label className={styles.searchField}>
                <Search size={17} aria-hidden />
                <span className={styles.srOnly}>Afbeeldingen zoeken</span>
                <input
                  type="search"
                  value={search}
                  maxLength={100}
                  placeholder="Zoek op naam..."
                  onChange={(event) => { setSearch(event.target.value); setAssetPageNumber(1); }}
                />
              </label>
              <span>{selectedAssetIds.size} geselecteerd</span>
              <button type="button" className={styles.secondaryButton} disabled={selectedAssetIds.size === 0} onClick={addSelectedAssetsToPlaylist}>
                <Plus size={16} aria-hidden /> Aan fotoplaylist toevoegen
              </button>
            </div>

            <form
              className={`${styles.uploadCard} ${draggingFiles ? styles.uploadCardDragging : ''}`}
              onSubmit={(event) => void uploadAssets(event)}
              onDragEnter={handleFileDragEnter}
              onDragOver={handleFileDragOver}
              onDragLeave={handleFileDragLeave}
              onDrop={handleFileDrop}
              aria-busy={busy === 'upload'}
            >
              <span className={styles.uploadIcon}><Upload size={22} aria-hidden /></span>
              <div>
                <strong>Media uploaden</strong>
                <span>Sleep één of meer bestanden hierheen, of kies ze op je toestel. JPEG, PNG en WebP maximaal 15 MB; MP4 maximaal 250 MB.</span>
              </div>
              <label className={styles.fileButton}>
                <input
                  ref={uploadInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp,video/mp4"
                  multiple
                  onChange={selectUploadFiles}
                />
                Bestanden kiezen
              </label>
              {draggingFiles ? <strong className={styles.dropPrompt}>Laat los om bestanden toe te voegen</strong> : null}
              {uploadItems.length > 0 ? (
                <div className={styles.uploadSelection}>
                  <ul className={styles.uploadQueue} aria-label="Geselecteerde mediabestanden">
                    {uploadItems.map((item) => {
                      const kind = wallboardMediaFileKind(item.file);
                      const itemError = item.validationMessage ?? item.uploadError;
                      return (
                        <li key={item.id} className={itemError === null ? undefined : styles.uploadQueueError}>
                          <span className={styles.uploadQueueIcon}>
                            {kind === 'video' ? <FileVideo2 size={20} aria-hidden /> : <ImageIcon size={20} aria-hidden />}
                          </span>
                          <span className={styles.uploadQueueFile}>
                            <strong title={item.file.name}>{item.file.name}</strong>
                            <small>{wallboardMediaFormatBytes(item.file.size)}{kind === null ? '' : ` · ${kind === 'video' ? 'MP4-video' : 'Afbeelding'}`}</small>
                            {itemError === null ? null : <em>{itemError}</em>}
                          </span>
                          <input
                            value={item.displayName}
                            maxLength={180}
                            aria-label={`Weergavenaam voor ${item.file.name}`}
                            placeholder="Weergavenaam"
                            disabled={busy === 'upload'}
                            onChange={(event) => setUploadItems((current) => current.map((candidate) => candidate.id === item.id
                              ? { ...candidate, displayName: event.target.value }
                              : candidate))}
                          />
                          <span className={styles.uploadQueueStatus} aria-live="polite">
                            {item.status === 'uploading' ? <><Loader2 className={styles.spinning} size={15} aria-hidden /> Bezig</> : item.status === 'failed' ? 'Mislukt' : 'Klaar' }
                          </span>
                          <button
                            type="button"
                            aria-label={`${item.file.name} uit uploadlijst verwijderen`}
                            disabled={busy === 'upload'}
                            onClick={() => setUploadItems((current) => current.filter((candidate) => candidate.id !== item.id))}
                          >
                            <X size={16} aria-hidden />
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                  <footer className={styles.uploadQueueFooter}>
                    <span>{uploadItems.length} van maximaal {WALLBOARD_MEDIA_MAX_BATCH_FILES} bestanden geselecteerd</span>
                    <button
                      type="button"
                      className={styles.secondaryButton}
                      disabled={busy === 'upload'}
                      onClick={() => setUploadItems([])}
                    >
                      Lijst wissen
                    </button>
                    <button
                      type="submit"
                      className={styles.primaryButton}
                      disabled={busy === 'upload' || !uploadItems.some((item) => item.validationMessage === null)}
                    >
                      {busy === 'upload' ? <Loader2 className={styles.spinning} size={16} aria-hidden /> : <Upload size={16} aria-hidden />}
                      Alles uploaden
                    </button>
                  </footer>
                </div>
              ) : null}
            </form>

            {loadingAssets ? (
              <div className={styles.loading} role="status"><Loader2 size={20} aria-hidden /> Media laden...</div>
            ) : assetsPage.items.length === 0 ? (
              <div className={styles.emptyState}>
                <ImageIcon size={34} aria-hidden />
                <strong>Geen media gevonden</strong>
                <span>Upload een eerste afbeelding of video, of kies een andere map.</span>
              </div>
            ) : (
              <ul className={styles.assetGrid} aria-label="Media">
                {assetsPage.items.map((asset) => (
                  <AssetCard
                    key={asset.id}
                    asset={asset}
                    folders={folderTree}
                    selected={selectedAssetIds.has(asset.id)}
                    busy={busy === `asset-${asset.id}`}
                    onSelect={() => setSelectedAssetIds((current) => toggledSetValue(current, asset.id))}
                    onMove={(folderId) => void moveAsset(asset, folderId)}
                    onDelete={() => void deleteAsset(asset)}
                  />
                ))}
              </ul>
            )}

            <Pagination
              currentPage={assetsPage.pagination.current_page}
              lastPage={assetsPage.pagination.last_page}
              total={assetsPage.pagination.total}
              onPage={setAssetPageNumber}
            />
          </main>
        </div>
      ) : (
        <div className={styles.playlistGrid}>
          <aside className={styles.playlistList}>
            <div className={styles.columnTitle}><Images size={18} aria-hidden /><strong>Fotoplaylists</strong></div>
            <button type="button" className={styles.newPlaylistButton} onClick={startNewPlaylist}>
              <Plus size={17} aria-hidden /> Nieuwe fotoplaylist
            </button>
            {playlists.length === 0 ? <p>Nog geen fotoplaylists.</p> : (
              <ul>
                {playlists.map((playlist) => (
                  <li key={playlist.id}>
                    <button
                      type="button"
                      className={selectedPlaylistId === playlist.id ? styles.activePlaylist : undefined}
                      onClick={() => editPlaylist(playlist)}
                    >
                      <span><strong>{playlist.name}</strong><small>{playlist.item_count} foto{playlist.item_count === 1 ? '' : "'s"}</small></span>
                      <small>{playlist.usage_count}x gebruikt</small>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </aside>

          <form className={styles.playlistEditor} onSubmit={(event) => void savePlaylist(event)}>
            <header>
              <div>
                <span className={styles.eyebrow}>{selectedPlaylist === null ? 'Nieuwe selectie' : 'Bestaande fotoplaylist'}</span>
                <h3>{selectedPlaylist === null ? 'Fotoplaylist samenstellen' : selectedPlaylist.name}</h3>
              </div>
              {selectedPlaylist !== null ? (
                <button
                  type="button"
                  className={styles.dangerButton}
                  onClick={() => void deletePlaylist(selectedPlaylist)}
                  disabled={busy === 'playlist-delete' || selectedPlaylist.usage_count > 0}
                  title={selectedPlaylist.usage_count > 0 ? 'Deze fotoplaylist wordt nog gebruikt.' : undefined}
                ><Trash2 size={16} aria-hidden /> Verwijderen</button>
              ) : null}
            </header>

            <label className={styles.playlistNameField}>
              <span>Naam</span>
              <input
                value={playlistName}
                maxLength={120}
                placeholder="Bijvoorbeeld Entree of Veilig vliegen"
                onChange={(event) => setPlaylistName(event.target.value)}
                required
              />
            </label>

            <div className={styles.playlistActions}>
              <span>{playlistAssetIds.length} van maximaal {WALLBOARD_MEDIA_MAX_PLAYLIST_ITEMS} foto&apos;s</span>
              <button type="button" className={styles.secondaryButton} disabled={selectedAssetIds.size === 0} onClick={addSelectedAssetsToPlaylist}>
                <Plus size={16} aria-hidden /> Geselecteerde beelden toevoegen
              </button>
              <button type="button" className={styles.secondaryButton} onClick={() => setView('assets')}>
                Bibliotheek openen
              </button>
            </div>

            {playlistAssetIds.length === 0 ? (
              <div className={styles.emptyState}>
                <Images size={34} aria-hidden />
                <strong>Voeg minimaal één afbeelding toe</strong>
                <span>Selecteer beelden in de bibliotheek en voeg ze hier toe.</span>
              </div>
            ) : (
              <ol className={styles.orderedAssets}>
                {playlistAssetIds.map((assetId, index) => {
                  const asset = assetLookup.get(assetId);
                  if (asset === undefined) return null;
                  const imageUrl = wallboardMediaImageUrl(asset.content_url);
                  return (
                    <li key={assetId}>
                      <span className={styles.orderNumber}>{index + 1}</span>
                      {imageUrl === null ? <ImageIcon size={24} aria-hidden /> : <img src={imageUrl} alt="" />}
                      <span><strong>{asset.display_name}</strong><small>{asset.width} x {asset.height}</small></span>
                      <button type="button" aria-label={`${asset.display_name} omhoog`} disabled={index === 0} onClick={() => setPlaylistAssetIds((current) => movedItem(current, index, -1))}><ArrowUp size={16} aria-hidden /></button>
                      <button type="button" aria-label={`${asset.display_name} omlaag`} disabled={index === playlistAssetIds.length - 1} onClick={() => setPlaylistAssetIds((current) => movedItem(current, index, 1))}><ArrowDown size={16} aria-hidden /></button>
                      <button type="button" aria-label={`${asset.display_name} uit fotoplaylist verwijderen`} onClick={() => setPlaylistAssetIds((current) => current.filter((id) => id !== assetId))}><X size={16} aria-hidden /></button>
                    </li>
                  );
                })}
              </ol>
            )}

            <footer className={styles.editorFooter}>
              <span>De volgorde hierboven is de volgorde op het wallboard.</span>
              <button
                type="submit"
                className={styles.primaryButton}
                disabled={busy === 'playlist-save' || playlistName.trim() === '' || playlistAssetIds.length === 0}
              >
                {busy === 'playlist-save' ? <Loader2 size={17} aria-hidden /> : <Check size={17} aria-hidden />}
                {selectedPlaylist === null ? 'Fotoplaylist maken' : 'Wijzigingen opslaan'}
              </button>
            </footer>
          </form>
        </div>
      )}
    </section>
  );
}

interface FolderButtonProps {
  label: string;
  count?: number;
  active: boolean;
  onClick: () => void;
}

function FolderButton({ label, count, active, onClick }: FolderButtonProps) {
  return (
    <button type="button" className={active ? styles.activeFolder : styles.folderButton} onClick={onClick}>
      <Folder size={16} aria-hidden />
      <span>{label}</span>
      {count === undefined ? null : <small>{count}</small>}
    </button>
  );
}

interface AssetCardProps {
  asset: WallboardMediaAsset;
  folders: ReturnType<typeof wallboardMediaFolderTree>;
  selected: boolean;
  busy: boolean;
  onSelect: () => void;
  onMove: (folderId: string | null) => void;
  onDelete: () => void;
}

function AssetCard({ asset, folders, selected, busy, onSelect, onMove, onDelete }: AssetCardProps) {
  const previewUrl = wallboardMediaAssetPreviewUrl(asset);
  const [previewFailed, setPreviewFailed] = useState(false);
  const isImage = asset.kind === 'image';
  const ready = asset.status === 'ready';
  useEffect(() => setPreviewFailed(false), [previewUrl]);

  return (
    <li className={selected ? styles.selectedAsset : styles.assetCard}>
      <button
        type="button"
        className={styles.assetPreview}
        onClick={onSelect}
        aria-pressed={isImage ? selected : undefined}
        disabled={!ready || !isImage}
        title={isImage ? 'Afbeelding selecteren' : 'Video’s kunnen niet aan een fotoplaylist worden toegevoegd.'}
      >
        {!isImage ? (
          <FileVideo2 size={38} aria-hidden />
        ) : previewUrl === null || previewFailed ? (
          <span className={styles.previewUnavailable}><ImageIcon size={34} aria-hidden /><small>Voorbeeld niet beschikbaar</small></span>
        ) : (
          <img
            src={previewUrl}
            alt={asset.display_name}
            loading="lazy"
            onError={() => setPreviewFailed(true)}
          />
        )}
        <span className={styles.mediaKind}>{isImage ? 'Foto' : 'Video'}</span>
        {isImage ? <span className={styles.selectionMark}>{selected ? <Check size={16} aria-hidden /> : null}</span> : null}
      </button>
      <div className={styles.assetDetails}>
        <strong title={asset.display_name}>{asset.display_name}</strong>
        <span>{wallboardMediaAssetMetadata(asset)} · {wallboardMediaFormatBytes(asset.byte_size)}</span>
      </div>
      <div className={styles.assetControls}>
        <label>
          <span className={styles.srOnly}>Map voor {asset.display_name}</span>
          <select value={asset.folder_id ?? ''} disabled={busy} onChange={(event) => onMove(event.target.value === '' ? null : event.target.value)}>
            <option value="">Zonder map</option>
            {folders.map((folder) => <option key={folder.id} value={folder.id}>{'- '.repeat(folder.depth)}{folder.name}</option>)}
          </select>
        </label>
        <button type="button" aria-label={`${asset.display_name} verwijderen`} onClick={onDelete} disabled={busy || asset.playlist_references_count > 0} title={asset.playlist_references_count > 0 ? 'Deze afbeelding staat nog in een fotoplaylist.' : undefined}>
          <Trash2 size={15} aria-hidden />
        </button>
      </div>
    </li>
  );
}

function wallboardMediaAssetMetadata(asset: WallboardMediaAsset): string {
  if (asset.kind === 'video') {
    const seconds = asset.duration_seconds;
    if (typeof seconds !== 'number' || !Number.isFinite(seconds) || seconds <= 0) return 'MP4-video';
    const rounded = Math.ceil(seconds);
    const minutes = Math.floor(rounded / 60);
    const remainder = rounded % 60;
    return minutes > 0 ? `${minutes}:${String(remainder).padStart(2, '0')} min` : `${remainder} sec.`;
  }
  return asset.width === null || asset.height === null ? 'Afbeelding' : `${asset.width} × ${asset.height}`;
}

interface PaginationProps {
  currentPage: number;
  lastPage: number;
  total: number;
  onPage: (page: number) => void;
}

function Pagination({ currentPage, lastPage, total, onPage }: PaginationProps) {
  if (lastPage <= 1) return <p className={styles.resultCount}>{total} afbeelding{total === 1 ? '' : 'en'}</p>;
  return (
    <nav className={styles.pagination} aria-label="Afbeeldingenpagina's">
      <button type="button" disabled={currentPage <= 1} onClick={() => onPage(currentPage - 1)}>Vorige</button>
      <span>Pagina {currentPage} van {lastPage} - {total} afbeeldingen</span>
      <button type="button" disabled={currentPage >= lastPage} onClick={() => onPage(currentPage + 1)}>Volgende</button>
    </nav>
  );
}

function toggledSetValue(current: Set<string>, value: string): Set<string> {
  const next = new Set(current);
  if (next.has(value)) next.delete(value);
  else next.add(value);
  return next;
}

function withoutSetValue(current: Set<string>, value: string): Set<string> {
  const next = new Set(current);
  next.delete(value);
  return next;
}

function movedItem(items: string[], index: number, direction: -1 | 1): string[] {
  const destination = index + direction;
  if (index < 0 || destination < 0 || destination >= items.length) return items;
  const next = [...items];
  [next[index], next[destination]] = [next[destination], next[index]];
  return next;
}

function isFolderId(filter: FolderFilter): filter is string {
  return filter !== 'all' && filter !== 'unfiled';
}

function errorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiClientError ? error.message : fallback;
}
