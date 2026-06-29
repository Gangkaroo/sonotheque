import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { usePlaybackStatisticsSettingsStore } from '@/stores/playbackStatisticsSettings'

describe('playback statistics settings store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads and updates the file-tag import setting', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({
        importFromFileTags: false,
        exportToFileTags: false,
      }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        importFromFileTags: true,
        exportToFileTags: false,
      }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = usePlaybackStatisticsSettingsStore()

    await store.load()
    await store.setImportFromFileTags(true)

    expect(store.settings.importFromFileTags).toBe(true)
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/settings/playback-statistics', expect.objectContaining({
      method: 'PATCH',
      body: JSON.stringify({ importFromFileTags: true }),
    }))
  })
})
