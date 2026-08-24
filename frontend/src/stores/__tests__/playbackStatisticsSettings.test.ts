import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { usePlaybackStatisticsSettingsStore } from '@/stores/playbackStatisticsSettings'

describe('playback statistics settings store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads and updates the file-tag synchronization setting', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({
        synchronizeWithFileTags: false,
        synchronizeRatingsWithFileTags: false,
        supportedExportFormats: ['mp3'],
      }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        synchronizeWithFileTags: true,
        synchronizeRatingsWithFileTags: false,
        supportedExportFormats: ['mp3'],
      }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = usePlaybackStatisticsSettingsStore()

    await store.load()
    await store.setSynchronizeWithFileTags(true)

    expect(store.settings.synchronizeWithFileTags).toBe(true)
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/settings/playback-statistics', expect.objectContaining({
      method: 'PATCH',
      body: JSON.stringify({ synchronizeWithFileTags: true }),
    }))
  })

  it('updates rating synchronization independently', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      synchronizeWithFileTags: false,
      synchronizeRatingsWithFileTags: true,
      supportedExportFormats: ['mp3'],
    }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = usePlaybackStatisticsSettingsStore()

    await store.setSynchronizeRatingsWithFileTags(true)

    expect(store.settings.synchronizeRatingsWithFileTags).toBe(true)
    expect(fetchMock).toHaveBeenCalledWith('/api/settings/playback-statistics', expect.objectContaining({
      method: 'PATCH',
      body: JSON.stringify({ synchronizeRatingsWithFileTags: true }),
    }))
  })
})
