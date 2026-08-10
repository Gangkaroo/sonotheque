import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useCatalogStore } from '@/stores/catalog'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'

describe('catalog store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useLibraryRootScopeStore().select(null)
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

  it('loads the root-scoped musician catalog with coverage', async () => {
    const page = {
      items: [{ id: 8, name: 'Jamie Player', albumCount: 3, trackCount: 7 }],
      total: 1,
      page: 2,
      perPage: 50,
      lastPage: 2,
      coverage: { checkedAlbums: 40, creditedAlbums: 18, totalAlbums: 100, percentage: 40 },
    }
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(page), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await store.loadMusicians({ page: 2, search: 'Jamie', initial: 'P' })

    expect(store.musicians.items[0]?.trackCount).toBe(7)
    expect(store.musicians.coverage.percentage).toBe(40)
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/catalog/musicians?page=2&search=Jamie&initial=P',
      expect.any(Object),
    )
  })

  it('loads root-scoped musician details', async () => {
    const detail = { id: 8, name: 'Jamie Player', albumCount: 3, trackCount: 7 }
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(detail), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await store.loadMusician(8)

    expect(store.musicianDetail).toEqual(detail)
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/musicians/8', expect.any(Object))
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
      store.loadAlbums({ page: 2, search: 'artist', initial: 'A', year: 1999, genre: 7, musician: 8, physicalCopy: 'owned', sort: 'year_desc' }),
      store.loadTracks({ page: 2, genre: 7, musician: 8, playStatus: 'never', physicalCopy: 'not_owned', sort: 'plays' }),
      store.loadGenres({ page: 2 }),
    ])

    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/albums?page=2&search=artist&initial=A&year=1999&genre=7&musician=8&physicalCopy=owned&sort=year_desc', expect.any(Object))
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/tracks?page=2&genre=7&musician=8&playStatus=never&physicalCopy=not_owned&sort=plays', expect.any(Object))
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/genres?page=2', expect.any(Object))
  })

  it('reuses catalog pages by query and root until the catalog is invalidated', async () => {
    const page = { items: [], total: 0, page: 1, perPage: 24, lastPage: 1 }
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(page), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    const rootScope = useLibraryRootScopeStore()
    await store.loadAlbums({ page: 1, sort: 'artist' })
    await store.loadAlbums({ page: 1, sort: 'artist' })
    expect(fetchMock).toHaveBeenCalledOnce()

    rootScope.select(7)
    await store.loadAlbums({ page: 1, sort: 'artist' })
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock).toHaveBeenLastCalledWith(
      '/api/catalog/albums?page=1&sort=artist&libraryRoot=7',
      expect.any(Object),
    )

    store.invalidateCatalog()
    await store.loadAlbums({ page: 1, sort: 'artist' })
    expect(fetchMock).toHaveBeenCalledTimes(3)
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

  it('manages album notes and independent owned copies', async () => {
    const notes = { hasPhysicalCopy: false, notes: 'Signed insert', ownedCopies: [] }
    const created = {
      ...notes,
      hasPhysicalCopy: true,
      ownedCopies: [{ id: 9, isPhysical: true, physicalFormat: 'vinyl' }],
    }
    const updated = {
      ...created,
      ownedCopies: [{ id: 9, isPhysical: true, physicalFormat: 'cd' }],
    }
    const deleted = { ...notes, ownedCopies: [] }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(notes), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(created), { status: 201 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(updated), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(deleted), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await expect(store.updateAlbumPersonalNotes(5, 'Signed insert')).resolves.toEqual(notes)
    await expect(store.createOwnedAlbumCopy(5, {
      isPhysical: true,
      physicalFormat: 'vinyl',
    })).resolves.toEqual(created)
    await expect(store.updateOwnedAlbumCopy(5, 9, {
      isPhysical: true,
      physicalFormat: 'cd',
    })).resolves.toEqual(updated)
    await expect(store.deleteOwnedAlbumCopy(5, 9)).resolves.toEqual(deleted)

    expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/albums/5/personal-notes', expect.objectContaining({
      method: 'PATCH',
      body: JSON.stringify({ notes: 'Signed insert' }),
    }))
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/albums/5/owned-copies', expect.objectContaining({
      method: 'POST',
    }))
    expect(fetchMock).toHaveBeenNthCalledWith(3, '/api/albums/5/owned-copies/9', expect.objectContaining({
      method: 'PATCH',
    }))
    expect(fetchMock).toHaveBeenNthCalledWith(4, '/api/albums/5/owned-copies/9', expect.objectContaining({
      method: 'DELETE',
    }))
  })

  it('searches, links, and unlinks Discogs releases for an owned copy', async () => {
    const candidate = {
      releaseId: 456,
      masterId: 78,
      title: 'Artist - Album',
      year: 2001,
      country: 'Germany',
      formats: ['CD'],
      labels: ['Example Records'],
      catalogNumber: 'EX-123',
      thumbnailUrl: null,
      webUrl: 'https://www.discogs.com/release/456',
      inCollection: true,
    }
    const linked = {
      hasPhysicalCopy: true,
      ownedCopies: [{ id: 9, isPhysical: true, provider: 'discogs', externalReleaseId: 456 }],
    }
    const unlinked = {
      hasPhysicalCopy: true,
      ownedCopies: [{ id: 9, isPhysical: true, provider: null, externalReleaseId: null }],
    }
    const instances = [{ instanceId: 991, folderId: 2, folderName: 'CDs' }]
    const details = {
      release: {
        id: 456,
        title: 'Album',
        artist: 'Artist',
        formats: ['CD (Album)'],
        labels: ['Example Records'],
        webUrl: 'https://www.discogs.com/release/456',
      },
      collectionInstance: instances[0],
      syncedAt: '2026-07-15T12:00:00Z',
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ items: [candidate] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ items: instances }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(linked), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(details), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(linked), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(unlinked), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await expect(store.searchDiscogsReleases(5, {
      artist: 'Artist',
      title: 'Album',
      year: 2001,
      format: 'CD',
    })).resolves.toEqual([candidate])
    await expect(store.loadDiscogsCollectionInstances(5, 456, true)).resolves.toEqual(instances)
    await expect(store.linkOwnedCopyToDiscogs(5, 9, 456, 991)).resolves.toEqual(linked)
    await expect(store.loadOwnedCopyDiscogsDetails(5, 9)).resolves.toEqual(details)
    await expect(store.refreshOwnedCopyDiscogsLink(5, 9, 991)).resolves.toEqual(linked)
    await expect(store.unlinkOwnedCopyFromDiscogs(5, 9)).resolves.toEqual(unlinked)

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/albums/5/discogs/candidates?artist=Artist&title=Album&year=2001&format=CD',
      expect.any(Object),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/albums/5/discogs/releases/456/collection-instances?refresh=1', expect.any(Object))
    expect(fetchMock).toHaveBeenNthCalledWith(3, '/api/albums/5/owned-copies/9/discogs', expect.objectContaining({
      method: 'PUT',
      body: JSON.stringify({ releaseId: 456, collectionInstanceId: 991 }),
    }))
    expect(fetchMock).toHaveBeenNthCalledWith(4, '/api/albums/5/owned-copies/9/discogs', expect.any(Object))
    expect(fetchMock).toHaveBeenNthCalledWith(5, '/api/albums/5/owned-copies/9/discogs/refresh', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ collectionInstanceId: 991 }),
    }))
    expect(fetchMock).toHaveBeenNthCalledWith(6, '/api/albums/5/owned-copies/9/discogs', expect.objectContaining({
      method: 'DELETE',
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
      musicians: 42,
      albums: 34,
      tracks: 567,
      genres: 8,
      playedAlbums: 21,
      playedTracks: 123,
    }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useCatalogStore()
    await store.loadMetrics()

    expect(store.metrics).toEqual({ artists: 12, musicians: 42, albums: 34, tracks: 567, genres: 8, playedAlbums: 21, playedTracks: 123 })
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
      musicians: 43,
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
      musicians: 42,
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
