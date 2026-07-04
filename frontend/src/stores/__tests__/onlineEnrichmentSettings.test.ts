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
})
