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
      items: [{
        id: 7,
        name: 'Artist',
        browseInitial: 'A',
        albumCount: 3,
        trackCount: 24,
        playStatistics: { playCount: 42, playedTrackCount: 8, lastPlayedAt: '2026-06-02T12:00:00Z' },
      }],
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
    expect(store.artists.items[0]?.playStatistics.playCount).toBe(42)
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/artists?page=1&search=art&initial=A', expect.any(Object))
  })

  it('loads artist details with an enrichment track anchor', async () => {
    const detail = {
      id: 7,
      name: 'Artist',
      browseInitial: 'A',
      albumCount: 3,
      trackCount: 24,
      representativeTrackId: 91,
      playStatistics: { playCount: 42, playedTrackCount: 8, lastPlayedAt: '2026-06-02T12:00:00Z' },
    }
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(detail), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await store.loadArtist(7)

    expect(store.artistDetail).toEqual(detail)
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/artists/7', expect.any(Object))
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
      store.loadAlbums({ page: 2, search: 'artist', initial: 'A', year: 1999, genre: 7, physicalCopy: 'owned' }),
      store.loadTracks({ page: 2, genre: 7, playStatus: 'never', physicalCopy: 'not_owned' }),
      store.loadGenres({ page: 2 }),
    ])

    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/albums?page=2&search=artist&initial=A&year=1999&genre=7&physicalCopy=owned', expect.any(Object))
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/tracks?page=2&genre=7&playStatus=never&physicalCopy=not_owned', expect.any(Object))
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/genres?page=2', expect.any(Object))
  })

  it('saves album personal metadata', async () => {
    const personalMetadata = {
      purchaseSource: 'Local store',
      purchaseDate: '2024-05-17',
      hasPhysicalCopy: true,
      physicalFormat: 'vinyl',
      notes: 'Gatefold',
    }
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(personalMetadata), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await expect(store.updateAlbumPersonalMetadata(5, personalMetadata)).resolves.toEqual(personalMetadata)

    expect(fetchMock).toHaveBeenCalledWith('/api/albums/5/personal-metadata', expect.objectContaining({
      method: 'PATCH',
      body: JSON.stringify(personalMetadata),
    }))
  })

  it('loads album details with playable tracks', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      id: 5,
      title: 'Album',
      originalReleaseYear: 1999,
      primaryArtist: { id: 3, name: 'Artist' },
      trackCount: 1,
      artworkThumbnailUrl: null,
      artworkUrl: '/api/albums/5/artwork/original',
      artworkWidth: 1200,
      artworkHeight: 1200,
      genres: [{ id: 4, name: 'Rock' }],
      tracks: [{
        id: 9,
        title: 'Track',
        streamUrl: '/api/tracks/9/stream',
        durationMs: 123000,
        album: { id: 5, title: 'Album' },
        artists: [{ id: 3, name: 'Artist' }],
      }],
    }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await store.loadAlbum(5)

    expect(store.albumDetail?.tracks[0]?.streamUrl).toBe('/api/tracks/9/stream')
    expect(store.albumDetail?.genres[0]?.name).toBe('Rock')
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/albums/5', expect.any(Object))
  })

  it('loads track details with media metadata', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      id: 9,
      title: 'Track',
      streamUrl: '/api/tracks/9/stream',
      durationMs: 123000,
      trackNumber: 1,
      discNumber: 1,
      year: 1999,
      album: { id: 5, title: 'Album' },
      artists: [{ id: 3, name: 'Artist' }],
      genres: [{ id: 4, name: 'Rock' }],
      mediaFile: {
        id: 11,
        relativePath: 'Artist/Album/track.mp3',
        fileSize: 123456,
        modifiedAt: '2026-01-02T03:04:05+00:00',
        mimeType: 'audio/mpeg',
        container: 'mp3',
        codec: 'mp3',
        encoder: 'LAME3.100',
        encoderSettings: 'V2',
        bitrate: 320000,
        sampleRate: 44100,
        channels: 2,
        status: 'available',
        scanError: null,
      },
    }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await store.loadTrack(9)

    expect(store.trackDetail?.album?.title).toBe('Album')
    expect(store.trackDetail?.genres[0]?.name).toBe('Rock')
    expect(store.trackDetail?.mediaFile?.codec).toBe('mp3')
    expect(store.trackDetail?.mediaFile?.encoderSettings).toBe('V2')
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/tracks/9', expect.any(Object))
  })

  it('loads dashboard metrics without loading catalog pages', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      artists: 12,
      albums: 34,
      tracks: 567,
      genres: 8,
      playedAlbums: 21,
      playedTracks: 123,
    }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await store.loadMetrics()

    expect(store.metrics).toEqual({ artists: 12, albums: 34, tracks: 567, genres: 8, playedAlbums: 21, playedTracks: 123 })
    expect(store.metricsHaveCatalog).toBe(true)
    expect(fetchMock).toHaveBeenCalledOnce()
    expect(fetchMock).toHaveBeenCalledWith('/api/dashboard-metrics', expect.any(Object))
  })

  it('keeps the newest dashboard metrics when requests overlap', async () => {
    let resolveFirst!: (response: Response) => void
    const firstResponse = new Promise<Response>((resolve) => {
      resolveFirst = resolve
    })
    const freshMetrics = {
      artists: 13,
      albums: 35,
      tracks: 570,
      genres: 9,
      playedAlbums: 22,
      playedTracks: 125,
    }
    const fetchMock = vi.fn()
      .mockImplementationOnce(() => firstResponse)
      .mockResolvedValueOnce(new Response(JSON.stringify(freshMetrics), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    const firstRequest = store.loadMetrics()
    await store.loadMetrics()
    resolveFirst(new Response(JSON.stringify({ ...freshMetrics, tracks: 100 }), { status: 200 }))
    await firstRequest

    expect(store.metrics).toEqual(freshMetrics)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('reuses valid metrics until the cache is invalidated', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      artists: 12,
      albums: 34,
      tracks: 567,
      genres: 8,
      playedAlbums: 21,
      playedTracks: 123,
    }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await store.loadMetrics()
    await store.loadMetrics()
    expect(fetchMock).toHaveBeenCalledOnce()

    store.invalidateMetrics()
    await store.loadMetrics()
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })
})
