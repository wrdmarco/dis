export type WallboardPlaylistPageLayerRole = 'current' | 'outgoing' | 'preloaded';

export interface WallboardPlaylistPageLayer<TPage extends { id: string }> {
  page: TPage;
  role: WallboardPlaylistPageLayerRole;
  visible: boolean;
  running: boolean;
}

export function wallboardPlaylistPageLayers<TPage extends { id: string }>(
  pages: readonly TPage[],
  currentPageId: string,
  previousPageId: string | null,
  transitionActive: boolean,
  playlistActive: boolean,
  hasLiveFeed: boolean,
  runningPageId: string = currentPageId,
): WallboardPlaylistPageLayer<TPage>[] {
  return pages.map((page) => {
    const role: WallboardPlaylistPageLayerRole = page.id === currentPageId
      ? 'current'
      : transitionActive && page.id === previousPageId
        ? 'outgoing'
        : 'preloaded';

    return {
      page,
      role,
      visible: playlistActive && role !== 'preloaded',
      running: playlistActive && hasLiveFeed && page.id === runningPageId,
    };
  });
}
