import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useOnlineEnrichmentSettingsStore } from '@/stores/onlineEnrichmentSettings'

describe('online enrichment settings store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads and saves the opt-in settings', async () => {
    const cache = { total: 0, ready: 0, notFound: 0, errors: 0, stale: 0 }
    const disabled = { informationEnabled: false, lyricsEnabled: false, cache }
    const enabled = { informationEnabled: true, lyricsEnabled: true, cache }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(disabled), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(enabled), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useOnlineEnrichmentSettingsStore()

    await store.load()
    await store.save(enabled)

    expect(store.settings).toEqual(enabled)
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/settings/online-enrichment', expect.objectContaining({
      method: 'PATCH',
      body: JSON.stringify(enabled),
    }))
  })

  it('tests providers and clears only the online content cache', async () => {
    const cache = { total: 3, ready: 2, notFound: 1, errors: 0, stale: 0 }
    const emptyCache = { total: 0, ready: 0, notFound: 0, errors: 0, stale: 0 }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({
        provider: 'lrclib',
        status: 'available',
        errorCode: null,
      }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ deleted: 3, cache: emptyCache }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useOnlineEnrichmentSettingsStore()
    store.settings = { informationEnabled: true, lyricsEnabled: true, cache }

    await store.testProvider('lrclib')
    const deleted = await store.clearCache()

    expect(store.providerTests.lrclib?.status).toBe('available')
    expect(deleted).toBe(3)
    expect(store.settings.cache).toEqual(emptyCache)
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/settings/online-enrichment/providers/lrclib/test', expect.objectContaining({ method: 'POST' }))
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/settings/online-enrichment/cache', expect.objectContaining({ method: 'DELETE' }))
  })

  it('loads and controls a root-scoped musician backfill', async () => {
    const state = {
      coverage: { checkedAlbums: 4, creditedAlbums: 3, totalAlbums: 10, percentage: 40 },
      run: null,
      activeRun: null,
    }
    const running = {
      ...state,
      run: { id: 7, status: 'queued' },
      activeRun: { id: 7, status: 'queued' },
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(state), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(running), { status: 202 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(running), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useOnlineEnrichmentSettingsStore()

    await store.loadMusicianBackfill(12)
    await store.startMusicianBackfill(12)
    await store.pauseMusicianBackfill(7)

    expect(store.musicianBackfill).toEqual(state)
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/settings/online-enrichment/musician-backfill?libraryRoot=12',
      expect.any(Object),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/settings/online-enrichment/musician-backfill?libraryRoot=12',
      expect.objectContaining({ method: 'POST' }),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      3,
      '/api/settings/online-enrichment/musician-backfill/7/pause',
      expect.objectContaining({ method: 'POST' }),
    )
  })

  it('keeps a late all-roots response from replacing the selected root', async () => {
    const allRootsResponse = deferredResponse()
    const selectedRootResponse = deferredResponse()
    const allRoots = {
      coverage: { checkedAlbums: 40, creditedAlbums: 30, totalAlbums: 100, percentage: 40 },
      run: null,
      activeRun: null,
    }
    const selectedRoot = {
      coverage: { checkedAlbums: 4, creditedAlbums: 3, totalAlbums: 10, percentage: 40 },
      run: null,
      activeRun: null,
    }
    const fetchMock = vi.fn()
      .mockReturnValueOnce(allRootsResponse.promise)
      .mockReturnValueOnce(selectedRootResponse.promise)
    vi.stubGlobal('fetch', fetchMock)
    const store = useOnlineEnrichmentSettingsStore()

    const loadingAllRoots = store.loadMusicianBackfill(null)
    const loadingSelectedRoot = store.loadMusicianBackfill(12)
    selectedRootResponse.resolve(new Response(JSON.stringify(selectedRoot), { status: 200 }))
    await loadingSelectedRoot
    allRootsResponse.resolve(new Response(JSON.stringify(allRoots), { status: 200 }))
    await loadingAllRoots

    expect(store.musicianBackfill).toEqual(selectedRoot)
    expect(store.loadingMusicianBackfill).toBe(false)
  })
})

function deferredResponse() {
  let resolve!: (response: Response) => void
  const promise = new Promise<Response>((resolvePromise) => {
    resolve = resolvePromise
  })

  return { promise, resolve }
}
