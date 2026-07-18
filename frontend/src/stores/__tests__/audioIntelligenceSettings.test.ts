import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useAudioIntelligenceSettingsStore } from '@/stores/audioIntelligenceSettings'

describe('audio intelligence settings store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads, enables, and prepares a pilot without starting analysis', async () => {
    const disabled = {
      enabled: false,
      sampleSize: 200,
      eligibleTrackCount: 1200,
      analyzerStatus: 'not_configured',
      analyzer: {
        status: 'not_configured',
        message: null,
        profile: null,
      },
      latestPilot: null,
    }
    const enabled = { ...disabled, enabled: true }
    const prepared = {
      ...enabled,
      latestPilot: {
        id: 3,
        status: 'prepared',
        requestedTrackCount: 200,
        selectedTrackCount: 200,
        summary: {
          eligibleTrackCount: 1200,
          eligibleRootCount: 2,
          selectedRootCount: 2,
          selectedGenreCount: 18,
          unclassifiedTrackCount: 4,
        },
        resumable: false,
        profile: null,
        startedAt: null,
        finishedAt: null,
        cancelRequestedAt: null,
        createdAt: '2026-07-17T10:00:00+00:00',
      },
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(disabled), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(enabled), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(prepared), { status: 201 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useAudioIntelligenceSettingsStore()

    await store.load()
    await store.save(true, 200)
    await store.preparePilot()

    expect(store.settings.latestPilot?.selectedTrackCount).toBe(200)
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/settings/audio-intelligence', expect.objectContaining({
      method: 'PATCH',
      body: JSON.stringify({ enabled: true, sampleSize: 200 }),
    }))
    expect(fetchMock).toHaveBeenNthCalledWith(3, '/api/settings/audio-intelligence/pilots', expect.objectContaining({
      method: 'POST',
    }))
  })

  it('requests cancellation for a running pilot', async () => {
    const running = {
      enabled: true,
      sampleSize: 50,
      eligibleTrackCount: 111,
      analyzerStatus: 'ready',
      analyzer: { status: 'ready', message: 'Ready', profile: null },
      latestPilot: {
        id: 4,
        status: 'running',
        requestedTrackCount: 50,
        selectedTrackCount: 50,
        summary: {
          eligibleTrackCount: 111,
          eligibleRootCount: 1,
          selectedRootCount: 1,
          selectedGenreCount: 3,
          unclassifiedTrackCount: 0,
          analyzedTrackCount: 10,
        },
        resumable: false,
        profile: null,
        startedAt: '2026-07-17T10:00:00+00:00',
        finishedAt: null,
        cancelRequestedAt: null,
        createdAt: '2026-07-17T09:59:00+00:00',
      },
    }
    const cancelling = {
      ...running,
      latestPilot: {
        ...running.latestPilot,
        cancelRequestedAt: '2026-07-17T10:02:00+00:00',
      },
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(cancelling), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useAudioIntelligenceSettingsStore()

    await store.cancelPilot(4)

    expect(store.settings.latestPilot?.cancelRequestedAt).not.toBeNull()
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/settings/audio-intelligence/pilots/4/cancel',
      expect.objectContaining({ method: 'POST' }),
    )
  })

  it('resumes an interrupted pilot without preparing a replacement run', async () => {
    const resumed = {
      enabled: true,
      sampleSize: 50,
      eligibleTrackCount: 111,
      analyzerStatus: 'ready',
      analyzer: { status: 'ready', message: 'Ready', profile: null },
      latestPilot: {
        id: 7,
        status: 'queued',
        requestedTrackCount: 50,
        selectedTrackCount: 50,
        summary: {
          analyzedTrackCount: 35,
          reusedTrackCount: 10,
          processedTrackCount: 45,
        },
        resumable: false,
        profile: null,
        startedAt: '2026-07-17T10:00:00+00:00',
        finishedAt: null,
        cancelRequestedAt: null,
        createdAt: '2026-07-17T09:59:00+00:00',
      },
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(resumed), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useAudioIntelligenceSettingsStore()

    await store.resumePilot(7)

    expect(store.settings.latestPilot?.id).toBe(7)
    expect(store.settings.latestPilot?.summary.reusedTrackCount).toBe(10)
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/settings/audio-intelligence/pilots/7/resume',
      expect.objectContaining({ method: 'POST' }),
    )
  })
})
