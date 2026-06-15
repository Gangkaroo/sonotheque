import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useCatalogStore } from '@/stores/catalog'

describe('catalog store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads one filtered artist page', async () => {
    const page = {
      items: [{ id: 7, name: 'Artist', browseInitial: 'A', albumCount: 3 }],
      total: 1,
      page: 1,
      perPage: 50,
      lastPage: 1,
    }
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(page), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await store.loadArtists({ page: 1, search: 'art', initial: 'A' })

    expect(store.artists.items[0]?.albumCount).toBe(3)
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/artists?page=1&search=art&initial=A', expect.any(Object))
  })

  it('loads album, track, and genre pages independently', async () => {
    const fetchMock = vi.fn(async (url: string) => new Response(JSON.stringify({
      items: [],
      total: 0,
      page: 2,
      perPage: 50,
      lastPage: 2,
      source: url,
    }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await Promise.all([
      store.loadAlbums({ page: 2 }),
      store.loadTracks({ page: 2 }),
      store.loadGenres({ page: 2 }),
    ])

    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/albums?page=2', expect.any(Object))
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/tracks?page=2', expect.any(Object))
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/genres?page=2', expect.any(Object))
  })

  it('loads dashboard metrics without loading catalog pages', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      artists: 12,
      albums: 34,
      tracks: 567,
      genres: 8,
    }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await store.loadMetrics()

    expect(store.metrics).toEqual({ artists: 12, albums: 34, tracks: 567, genres: 8 })
    expect(store.metricsHaveCatalog).toBe(true)
    expect(fetchMock).toHaveBeenCalledOnce()
    expect(fetchMock).toHaveBeenCalledWith('/api/dashboard-metrics', expect.any(Object))
  })
})
