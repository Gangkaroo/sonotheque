import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useDiscogsSettingsStore } from '@/stores/discogsSettings'

describe('Discogs settings store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('connects and disconnects an account', async () => {
    const connected = {
      connected: true,
      username: 'collector',
      userId: 12345,
      connectedAt: '2026-07-15T12:00:00Z',
    }
    const disconnected = {
      connected: false,
      username: null,
      userId: null,
      connectedAt: null,
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(connected), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(disconnected), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useDiscogsSettingsStore()

    await store.connect('personal-token')
    expect(store.settings.username).toBe('collector')
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/settings/discogs/connect', expect.objectContaining({
      method: 'POST',
    }))

    await store.disconnect()
    expect(store.settings.connected).toBe(false)
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/settings/discogs', expect.objectContaining({
      method: 'DELETE',
    }))
  })
})
