import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useLibraryFoldersStore } from '@/stores/libraryFolders'

const listing = {
  libraryRoot: { id: 3, name: 'Archive' },
  path: 'Artist/Album',
  parentPath: 'Artist',
  breadcrumbs: [],
  directories: [],
  files: [],
}

describe('library folders store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads encoded root-relative folders and recursive action tracks', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(listing), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        path: 'Artist/Album',
        total: 1,
        tracks: [{ id: 8, title: 'Track' }],
      }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useLibraryFoldersStore()
    await store.load(3, 'Artist/Album')
    const tracks = await store.loadTracks(3, 'Artist/Album')

    expect(store.listing?.path).toBe('Artist/Album')
    expect(tracks.total).toBe(1)
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/catalog/library-roots/3/folders?path=Artist%2FAlbum',
      expect.any(Object),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/catalog/library-roots/3/folder-tracks?path=Artist%2FAlbum',
      expect.any(Object),
    )
  })
})
