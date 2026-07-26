import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { usePlaylistExportSettingsStore } from '@/stores/playlistExportSettings'

describe('playlist export settings store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads settings and updates export defaults', async () => {
    const synchronization = {
      playlistCount: 0,
      syncedCount: 0,
      failedCount: 0,
      pendingCount: 0,
    }
    const initial = {
      defaultFormat: 'm3u8',
      synchronizePlaylists: false,
      synchronization,
      locations: [],
    }
    const updated = {
      defaultFormat: 'm3u',
      synchronizePlaylists: true,
      synchronization,
      locations: [],
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse(initial))
      .mockResolvedValueOnce(jsonResponse(updated))
    vi.stubGlobal('fetch', fetchMock)
    const store = usePlaylistExportSettingsStore()

    await store.load()
    await store.saveDefaults('m3u', true)

    expect(store.settings).toEqual(updated)
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/settings/playlist-exports', expect.objectContaining({
      method: 'PATCH',
      body: JSON.stringify({ defaultFormat: 'm3u', synchronizePlaylists: true }),
    }))
  })

  it('creates a named location and refreshes the ordered location list', async () => {
    const location = {
      id: 1,
      name: 'Portable player',
      path: 'G:/Playlists',
      isDefault: true,
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({
        defaultFormat: 'm3u8',
        synchronizePlaylists: false,
        synchronization: {
          playlistCount: 0,
          syncedCount: 0,
          failedCount: 0,
          pendingCount: 0,
        },
        locations: [location],
      }, 201))
    vi.stubGlobal('fetch', fetchMock)
    const store = usePlaylistExportSettingsStore()

    await store.createLocation({
      name: 'Portable player',
      path: 'G:/Playlists',
      makeDefault: true,
      createSubfolder: false,
    })

    expect(store.settings.locations).toEqual([location])
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/settings/playlist-exports/locations',
      expect.objectContaining({ method: 'POST' }),
    )
  })

  it('refreshes synchronization progress without entering the loading state', async () => {
    const refreshed = {
      defaultFormat: 'm3u8',
      synchronizePlaylists: true,
      synchronization: {
        playlistCount: 13,
        syncedCount: 7,
        failedCount: 0,
        pendingCount: 6,
      },
      locations: [],
    }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(jsonResponse(refreshed)))
    const store = usePlaylistExportSettingsStore()

    await store.refreshSynchronization()

    expect(store.loading).toBe(false)
    expect(store.settings.synchronization).toEqual(refreshed.synchronization)
  })
})

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}
