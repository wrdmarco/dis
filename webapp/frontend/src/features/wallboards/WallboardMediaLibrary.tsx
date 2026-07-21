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
  GripVertical,
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
  moveWallboardMediaPlaylistItem,
  reorderWallboardMediaPlaylistItem,
} from './wallboardMedia';
import styles from './WallboardMediaLibrary.module.css';

type LibraryView = 'assets' | 'playlists';
type FolderFilter = 'all' | 'unfiled' | string;
type MediaKindFilter = WallboardMediaAsset['kind'];

const EMPTY_PAGINATION = { current_page: 1, last_page: 1, per_page: 25, total: 0 };
const PROCESSING_POLL_INTERVAL_MS = 4_000;
const PROCESSING_POLL_MAX_ATTEMPTS = Math.ceil((60 * 60 * 1_000) / PROCESSING_POLL_INTERVAL_MS);

type UploadStatus = 'pending' | 'uploading' | 'completed' | 'failed';

interface UploadQueueItem {
  id: string;
  file: File;
  displayName: string;
  validationMessage: string | null;
  status: UploadStatus;
  uploadProgress: number | null;
  uploadError: string | null;
}

export function WallboardMediaLibrary() {
  const { api } = useAuth();
  const [view, setView] = useState<LibraryView>('assets');
  const [folders, setFolders] = useState<WallboardMediaFolder[]>([]);
  const [assetsPage, setAssetsPage] = useState<WallboardMediaAssetPage>({ items: [], pagination: EMPTY_PAGINATION });
  const [playlists, setPlaylists] = useState<WallboardMediaPlaylist[]>([]);
  const [folderFilter, setFolderFilter] = useState<FolderFilter>('all');
  const [mediaKindFilter, setMediaKindFilter] = useState<MediaKindFilter>('image');
  const [search, setSearch] = useState('');
  const [assetPageNumber, setAssetPageNumber] = useState(1);
  const [selectedAssetIds, setSelectedAssetIds] = useState<Set<string>>(() => new Set());
  const [knownAssets, setKnownAssets] = useState<Map<string, WallboardMediaAsset>>(() => new Map());
  const [selectedPlaylistId, setSelectedPlaylistId] = useState<string | null>(null);
  const [playlistName, setPlaylistName] = useState('');
  const [playlistAssetIds, setPlaylistAssetIds] = useState<string[]>([]);
  const [draggedPlaylistAssetId, setDraggedPlaylistAssetId] = useState<string | null>(null);
  const [dragOverPlaylistAssetId, setDragOverPlaylistAssetId] = useState<string | null>(null);
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
  const processingPollAttemptsRef = useRef(0);
  const processingPollInFlightRef = useRef(false);
  const foregroundAssetRequestsRef = useRef(0);

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

  const loadAssets = useCallback(async (
    filter: FolderFilter,
    query: string,
    page: number,
    kind: MediaKindFilter,
    silent = false,
  ) => {
    const requestId = assetRequestRef.current + 1;
    assetRequestRef.current = requestId;
    if (!silent) {
      foregroundAssetRequestsRef.current += 1;
      setLoadingAssets(true);
      setError(null);
    }
    const parameters = new URLSearchParams({ per_page: '25', page: String(page) });
    parameters.set('kind', kind);
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
      setAssetsPage((current) => {
        const next = { items: response.data, pagination };
        return silent && wallboardMediaAssetPageMatches(current, next) ? current : next;
      });
      setKnownAssets((current) => {
        const next = new Map(current);
        let changed = false;
        for (const asset of response.data) {
          const known = current.get(asset.id);
          if (known !== undefined && wallboardMediaAssetMatches(known, asset)) continue;
          next.set(asset.id, asset);
          changed = true;
        }
        return changed ? next : current;
      });
    } catch (loadError) {
      if (!silent && requestId === assetRequestRef.current) {
        setError(errorMessage(loadError, `${kind === 'video' ? "Video's" : "Foto's"} konden niet worden geladen.`));
      }
    } finally {
      if (!silent) {
        foregroundAssetRequestsRef.current = Math.max(0, foregroundAssetRequestsRef.current - 1);
        if (requestId === assetRequestRef.current) setLoadingAssets(false);
      }
    }
  }, [api]);

  useEffect(() => {
    void loadLibrary();
  }, [loadLibrary]);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void loadAssets(folderFilter, search, assetPageNumber, mediaKindFilter);
    }, search.trim() === '' ? 0 : 250);
    return () => window.clearTimeout(timer);
  }, [assetPageNumber, folderFilter, loadAssets, mediaKindFilter, search]);

  useEffect(() => {
    processingPollAttemptsRef.current = 0;
  }, [assetPageNumber, folderFilter, mediaKindFilter, search]);

  const hasProcessingAssets = assetsPage.items.some((asset) => asset.status === 'processing');
  useEffect(() => {
    if (view !== 'assets' || mediaKindFilter !== 'video' || !hasProcessingAssets) {
      if (!hasProcessingAssets) processingPollAttemptsRef.current = 0;
      return;
    }

    let interval: number | null = null;
    const poll = async () => {
      if (
        document.visibilityState !== 'visible'
        || processingPollInFlightRef.current
        || foregroundAssetRequestsRef.current > 0
        || processingPollAttemptsRef.current >= PROCESSING_POLL_MAX_ATTEMPTS
      ) return;

      processingPollAttemptsRef.current += 1;
      processingPollInFlightRef.current = true;
      try {
        await loadAssets(folderFilter, search, assetPageNumber, mediaKindFilter, true);
      } finally {
        processingPollInFlightRef.current = false;
      }
    };
    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') void poll();
    };

    interval = window.setInterval(() => void poll(), PROCESSING_POLL_INTERVAL_MS);
    document.addEventListener('visibilitychange', handleVisibilityChange);
    return () => {
      if (interval !== null) window.clearInterval(interval);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [assetPageNumber, folderFilter, hasProcessingAssets, loadAssets, mediaKindFilter, search, view]);

  useEffect(() => {
    if (selectedPlaylist === null) return;
    setPlaylistName(selectedPlaylist.name);
    setPlaylistAssetIds(wallboardMediaAssetIds(selectedPlaylist));
  }, [selectedPlaylist]);

  async function refreshAll() {
    await Promise.all([
      loadLibrary(true),
      loadAssets(folderFilter, search, assetPageNumber, mediaKindFilter),
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
          uploadProgress: null,
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
    const uploadedKinds = new Set<MediaKindFilter>();
    let failedCount = 0;

    for (const item of pendingItems) {
      setUploadItems((current) => current.map((candidate) => candidate.id === item.id
        ? { ...candidate, status: 'uploading', uploadProgress: 0, uploadError: null }
        : candidate));
      const payload = new FormData();
      payload.set('file', item.file);
      if (isFolderId(folderFilter)) payload.set('folder_id', folderFilter);
      if (item.displayName.trim() !== '') payload.set('display_name', item.displayName.trim());
      try {
        const response = await api.postForm<WallboardMediaAsset>(
          '/admin/wallboard-media/assets',
          payload,
          ({ percentage }) => setUploadItems((current) => {
            const candidate = current.find((entry) => entry.id === item.id);
            if (candidate === undefined || candidate.uploadProgress === percentage) return current;
            return current.map((entry) => entry.id === item.id
              ? { ...entry, uploadProgress: percentage }
              : entry);
          }),
        );
        completedIds.add(item.id);
        uploadedKinds.add(response.data.kind);
        setUploadItems((current) => current.map((candidate) => candidate.id === item.id
          ? { ...candidate, status: 'completed', uploadProgress: 100, uploadError: null }
          : candidate));
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
      const includesVideo = uploadedKinds.has('video');
      const nextKind = includesVideo ? 'video' : mediaKindFilter;
      showMediaKind(nextKind);
      setNotice(includesVideo
        ? `${completedIds.size} bestand${completedIds.size === 1 ? '' : 'en'} geüpload. Video's worden nu gecontroleerd en zo nodig omgezet.`
        : `${completedIds.size} foto${completedIds.size === 1 ? '' : "'s"} geüpload en toegevoegd.`);
      await Promise.all([loadLibrary(true), loadAssets(folderFilter, search, 1, nextKind)]);
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
      setNotice(`${asset.kind === 'video' ? 'Video' : 'Foto'} verplaatst.`);
      await Promise.all([loadLibrary(true), loadAssets(folderFilter, search, assetPageNumber, mediaKindFilter)]);
    } catch (mutationError) {
      setError(errorMessage(mutationError, 'Het mediabestand kon niet worden verplaatst.'));
    } finally {
      setBusy(null);
    }
  }

  async function deleteAsset(asset: WallboardMediaAsset) {
    if (!window.confirm(`${asset.kind === 'video' ? 'Video' : 'Foto'} "${asset.display_name}" verwijderen?`)) return;
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
      setNotice(`${asset.kind === 'video' ? 'Video' : 'Foto'} verwijderd.`);
      await Promise.all([loadLibrary(true), loadAssets(folderFilter, search, assetPageNumber, mediaKindFilter)]);
    } catch (mutationError) {
      setError(errorMessage(mutationError, 'Dit mediabestand wordt nog gebruikt of is intussen gewijzigd.'));
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

  function showMediaKind(nextKind: MediaKindFilter) {
    if (nextKind !== mediaKindFilter) {
      assetRequestRef.current += 1;
      setAssetsPage({ items: [], pagination: EMPTY_PAGINATION });
      setLoadingAssets(true);
      setMediaKindFilter(nextKind);
    }
    setAssetPageNumber(1);
  }

  return (
    <section className={styles.root} aria-labelledby="wallboard-media-title">
      <header className={styles.heading}>
        <div>
          <span className={styles.eyebrow}>Wallboardinhoud</span>
          <h2 id="wallboard-media-title">Media</h2>
          <p>Beheer foto&apos;s en video&apos;s afzonderlijk en volg de verwerking van geüploade video&apos;s.</p>
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
                label={mediaKindFilter === 'video' ? "Alle video's" : "Alle foto's"}
                active={folderFilter === 'all'}
                onClick={() => { setFolderFilter('all'); setAssetPageNumber(1); }}
              />
              <FolderButton
                label={mediaKindFilter === 'video' ? "Video's zonder map" : "Foto's zonder map"}
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
            <div className={styles.mediaKindSwitcher} role="group" aria-label="Mediatype tonen">
              <button
                type="button"
                className={mediaKindFilter === 'image' ? styles.activeMediaKind : undefined}
                aria-pressed={mediaKindFilter === 'image'}
                onClick={() => showMediaKind('image')}
              >
                <ImageIcon size={20} aria-hidden />
                <span><strong>Foto&apos;s</strong><small>Voor fotoplaylists</small></span>
              </button>
              <button
                type="button"
                className={mediaKindFilter === 'video' ? styles.activeMediaKind : undefined}
                aria-pressed={mediaKindFilter === 'video'}
                onClick={() => showMediaKind('video')}
              >
                <FileVideo2 size={20} aria-hidden />
                <span><strong>Video&apos;s</strong><small>Voor videokaarten</small></span>
              </button>
            </div>

            <div className={styles.assetToolbar}>
              <label className={styles.searchField}>
                <Search size={17} aria-hidden />
                <span className={styles.srOnly}>{mediaKindFilter === 'video' ? "Video's zoeken" : "Foto's zoeken"}</span>
                <input
                  type="search"
                  value={search}
                  maxLength={100}
                  placeholder={mediaKindFilter === 'video' ? "Zoek video's op naam..." : "Zoek foto's op naam..."}
                  onChange={(event) => { setSearch(event.target.value); setAssetPageNumber(1); }}
                />
              </label>
              {mediaKindFilter === 'image' ? (
                <>
                  <span>{selectedAssetIds.size} geselecteerd</span>
                  <button type="button" className={styles.secondaryButton} disabled={selectedAssetIds.size === 0} onClick={addSelectedAssetsToPlaylist}>
                    <Plus size={16} aria-hidden /> Aan fotoplaylist toevoegen
                  </button>
                </>
              ) : <span>Video&apos;s zijn beschikbaar zodra de verwerking klaar is.</span>}
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
                <span>JPEG, PNG en WebP tot 15 MB; MP4 tot 512 MB. Eerst zie je de echte uploadvoortgang. Een video wordt daarna gecontroleerd en zo nodig omgezet naar H.264 op maximaal 1080p.</span>
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
                      const uploadProgress = normalizedPercentage(item.uploadProgress);
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
                          <span className={styles.uploadQueueStatus}>
                            <span className={item.status === 'completed' ? styles.uploadQueueStatusCompleted : undefined}>
                              {item.status === 'uploading' ? <Loader2 className={styles.spinning} size={15} aria-hidden /> : null}
                              {item.status === 'completed' ? <Check size={15} aria-hidden /> : null}
                              {uploadQueueStatusLabel(item, itemError)}
                            </span>
                            {item.status === 'uploading' ? (
                              <span
                                className={styles.progressTrack}
                                role="progressbar"
                                aria-label={`Uploadvoortgang van ${item.file.name}`}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-valuenow={uploadProgress ?? undefined}
                                aria-valuetext={uploadProgress === null ? 'Uploadvoortgang wordt bepaald' : `${uploadProgress} procent geüpload`}
                              >
                                <span
                                  className={uploadProgress === null ? styles.indeterminateProgress : styles.progressFill}
                                  style={uploadProgress === null ? undefined : { width: `${uploadProgress}%` }}
                                />
                              </span>
                            ) : null}
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
              <div className={styles.loading} role="status"><Loader2 size={20} aria-hidden /> {mediaKindFilter === 'video' ? "Video's" : "Foto's"} laden...</div>
            ) : assetsPage.items.length === 0 ? (
              <div className={styles.emptyState}>
                {mediaKindFilter === 'video' ? <FileVideo2 size={34} aria-hidden /> : <ImageIcon size={34} aria-hidden />}
                <strong>{mediaKindFilter === 'video' ? "Geen video's gevonden" : "Geen foto's gevonden"}</strong>
                <span>Upload een eerste {mediaKindFilter === 'video' ? 'MP4-video' : 'foto'}, of kies een andere map.</span>
              </div>
            ) : (
              <ul className={styles.assetGrid} aria-label={mediaKindFilter === 'video' ? "Video's" : "Foto's"}>
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
              kind={mediaKindFilter}
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
                  const itemClassName = [
                    draggedPlaylistAssetId === assetId ? styles.draggingPlaylistItem : '',
                    dragOverPlaylistAssetId === assetId && draggedPlaylistAssetId !== assetId
                      ? styles.playlistDropTarget
                      : '',
                  ].filter(Boolean).join(' ');
                  return (
                    <li
                      key={assetId}
                      className={itemClassName}
                      onDragOver={(event) => {
                        if (draggedPlaylistAssetId === null || draggedPlaylistAssetId === assetId) return;
                        event.preventDefault();
                        event.dataTransfer.dropEffect = 'move';
                        setDragOverPlaylistAssetId(assetId);
                      }}
                      onDragLeave={(event) => {
                        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
                          setDragOverPlaylistAssetId((current) => current === assetId ? null : current);
                        }
                      }}
                      onDrop={(event) => {
                        event.preventDefault();
                        const sourceId = draggedPlaylistAssetId ?? event.dataTransfer.getData('text/plain');
                        setPlaylistAssetIds((current) => reorderWallboardMediaPlaylistItem(
                          current,
                          sourceId,
                          assetId,
                        ));
                        setDraggedPlaylistAssetId(null);
                        setDragOverPlaylistAssetId(null);
                      }}
                    >
                      <span
                        className={styles.dragHandle}
                        draggable
                        title={`${asset.display_name} verslepen`}
                        onDragStart={(event) => {
                          event.dataTransfer.effectAllowed = 'move';
                          event.dataTransfer.setData('text/plain', assetId);
                          setDraggedPlaylistAssetId(assetId);
                        }}
                        onDragEnd={() => {
                          setDraggedPlaylistAssetId(null);
                          setDragOverPlaylistAssetId(null);
                        }}
                      >
                        <GripVertical size={18} aria-hidden />
                      </span>
                      <span className={styles.orderNumber}>{index + 1}</span>
                      {imageUrl === null ? <ImageIcon size={24} aria-hidden /> : <img src={imageUrl} alt="" />}
                      <span className={styles.assetIdentity}><strong>{asset.display_name}</strong><small>{asset.width} x {asset.height}</small></span>
                      <button type="button" aria-label={`${asset.display_name} omhoog`} disabled={index === 0} onClick={() => setPlaylistAssetIds((current) => moveWallboardMediaPlaylistItem(current, index, -1))}><ArrowUp size={16} aria-hidden /></button>
                      <button type="button" aria-label={`${asset.display_name} omlaag`} disabled={index === playlistAssetIds.length - 1} onClick={() => setPlaylistAssetIds((current) => moveWallboardMediaPlaylistItem(current, index, 1))}><ArrowDown size={16} aria-hidden /></button>
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
  const fallbackPreviewUrl = wallboardMediaImageUrl(asset.content_url);
  const [activePreviewUrl, setActivePreviewUrl] = useState(previewUrl);
  const [previewFailed, setPreviewFailed] = useState(false);
  const isImage = asset.kind === 'image';
  const ready = asset.status === 'ready';
  useEffect(() => {
    setActivePreviewUrl(previewUrl);
    setPreviewFailed(false);
  }, [asset.id, asset.version, fallbackPreviewUrl, previewUrl]);

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
        ) : activePreviewUrl === null || previewFailed ? (
          <span className={styles.previewUnavailable}><ImageIcon size={34} aria-hidden /><small>Voorbeeld niet beschikbaar</small></span>
        ) : (
          <img
            src={activePreviewUrl}
            alt={asset.display_name}
            loading="lazy"
            onError={() => {
              if (fallbackPreviewUrl !== null && activePreviewUrl !== fallbackPreviewUrl) {
                setActivePreviewUrl(fallbackPreviewUrl);
                return;
              }
              setPreviewFailed(true);
            }}
          />
        )}
        <span className={styles.mediaKind}>{isImage ? 'Foto' : 'Video'}</span>
        {isImage ? <span className={styles.selectionMark}>{selected ? <Check size={16} aria-hidden /> : null}</span> : null}
      </button>
      <div className={styles.assetDetails}>
        <strong title={asset.display_name}>{asset.display_name}</strong>
        <span>{wallboardMediaAssetMetadata(asset)} · {wallboardMediaFormatBytes(asset.byte_size)}</span>
        {isImage ? null : <MediaAssetStatus asset={asset} />}
      </div>
      <div className={styles.assetControls}>
        <label>
          <span className={styles.srOnly}>Map voor {asset.display_name}</span>
          <select value={asset.folder_id ?? ''} disabled={busy} onChange={(event) => onMove(event.target.value === '' ? null : event.target.value)}>
            <option value="">Zonder map</option>
            {folders.map((folder) => <option key={folder.id} value={folder.id}>{'- '.repeat(folder.depth)}{folder.name}</option>)}
          </select>
        </label>
        <button type="button" aria-label={`${asset.display_name} verwijderen`} onClick={onDelete} disabled={busy || asset.playlist_references_count > 0} title={asset.playlist_references_count > 0 ? 'Dit mediabestand wordt nog gebruikt.' : undefined}>
          <Trash2 size={15} aria-hidden />
        </button>
      </div>
    </li>
  );
}

function MediaAssetStatus({ asset }: { asset: WallboardMediaAsset }) {
  const progress = normalizedPercentage(asset.processing_progress);
  if (asset.status === 'ready') {
    return <span className={`${styles.assetStatus} ${styles.assetStatusReady}`}><Check size={14} aria-hidden /> Beschikbaar</span>;
  }
  if (asset.status === 'failed') {
    return <span className={`${styles.assetStatus} ${styles.assetStatusFailed}`}><X size={14} aria-hidden /> Verwerking mislukt</span>;
  }

  return (
    <span className={`${styles.assetStatus} ${styles.assetStatusProcessing}`} role="status">
      <span className={styles.assetStatusLabel}>
        <Loader2 className={styles.spinning} size={14} aria-hidden />
        {progress === null ? 'Wacht op verwerking' : `Video verwerken · ${progress}%`}
      </span>
      <span
        className={styles.progressTrack}
        role="progressbar"
        aria-label={`Verwerkingsvoortgang van ${asset.display_name}`}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={progress ?? undefined}
        aria-valuetext={progress === null ? 'Wacht op verwerking' : `${progress} procent verwerkt`}
      >
        <span
          className={progress === null ? styles.indeterminateProgress : styles.progressFill}
          style={progress === null ? undefined : { width: `${progress}%` }}
        />
      </span>
    </span>
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
  kind: MediaKindFilter;
  onPage: (page: number) => void;
}

function Pagination({ currentPage, lastPage, total, kind, onPage }: PaginationProps) {
  const singular = kind === 'video' ? 'video' : 'foto';
  const plural = kind === 'video' ? "video's" : "foto's";
  const totalLabel = total === 1 ? singular : plural;
  if (lastPage <= 1) return <p className={styles.resultCount}>{total} {totalLabel}</p>;
  return (
    <nav className={styles.pagination} aria-label={`${plural} pagina's`}>
      <button type="button" disabled={currentPage <= 1} onClick={() => onPage(currentPage - 1)}>Vorige</button>
      <span>Pagina {currentPage} van {lastPage} · {total} {totalLabel}</span>
      <button type="button" disabled={currentPage >= lastPage} onClick={() => onPage(currentPage + 1)}>Volgende</button>
    </nav>
  );
}

function uploadQueueStatusLabel(item: UploadQueueItem, itemError: string | null): string {
  if (item.status === 'failed') return 'Mislukt';
  if (item.status === 'completed') return 'Geüpload';
  if (item.status === 'uploading') {
    const progress = normalizedPercentage(item.uploadProgress);
    return progress === null ? 'Uploaden…' : progress >= 100 ? '100% · opslaan…' : `${progress}% uploaden`;
  }
  return itemError === null ? 'Wacht op upload' : 'Controleer bestand';
}

function wallboardMediaAssetPageMatches(
  current: WallboardMediaAssetPage,
  next: WallboardMediaAssetPage,
): boolean {
  return current.pagination.current_page === next.pagination.current_page
    && current.pagination.last_page === next.pagination.last_page
    && current.pagination.per_page === next.pagination.per_page
    && current.pagination.total === next.pagination.total
    && current.items.length === next.items.length
    && current.items.every((asset, index) => {
      const candidate = next.items[index];
      return candidate !== undefined && wallboardMediaAssetMatches(asset, candidate);
    });
}

function wallboardMediaAssetMatches(left: WallboardMediaAsset, right: WallboardMediaAsset): boolean {
  return left.id === right.id
    && left.version === right.version
    && left.status === right.status
    && left.processing_progress === right.processing_progress;
}

function normalizedPercentage(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value)
    ? Math.min(100, Math.max(0, Math.round(value)))
    : null;
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

function isFolderId(filter: FolderFilter): filter is string {
  return filter !== 'all' && filter !== 'unfiled';
}

function errorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiClientError ? error.message : fallback;
}
