import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useLastFmSettingsStore } from '@/stores/lastFmSettings'

describe('Last.fm settings store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('starts and completes authorization', async () => {
    const pending = {
      configured: true,
      connected: false,
      enabled: false,
      username: null,
      apiKey: 'a'.repeat(32),
      authorizationPending: true,
      authorizationExpiresAt: '2026-07-02T12:00:00Z',
      authorizationUrl: 'https://www.last.fm/api/auth/?token=token',
    }
    const connected = {
      ...pending,
      connected: true,
      enabled: true,
      username: 'listener',
      authorizationPending: false,
      authorizationExpiresAt: null,
      authorizationUrl: null,
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(pending), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(connected), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useLastFmSettingsStore()

    await store.connect('a'.repeat(32), 'b'.repeat(32))
    await store.complete()

    expect(store.settings.connected).toBe(true)
    expect(store.settings.username).toBe('listener')
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/settings/lastfm/connect', expect.objectContaining({
      method: 'POST',
    }))
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/settings/lastfm/complete', expect.objectContaining({
      method: 'POST',
    }))
  })
})
