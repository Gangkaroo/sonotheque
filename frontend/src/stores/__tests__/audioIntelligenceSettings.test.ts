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
      fingerprintedTrackCount: 50,
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
        phase: 'preparation',
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
      fingerprintedTrackCount: 111,
      analyzerStatus: 'ready',
      analyzer: { status: 'ready', message: 'Ready', profile: null },
      latestPilot: {
        id: 4,
        phase: 'analysis',
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
      fingerprintedTrackCount: 111,
      analyzerStatus: 'ready',
      analyzer: { status: 'ready', message: 'Ready', profile: null },
      latestPilot: {
        id: 7,
        phase: 'analysis',
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

  it('loads analyzed tracks and evaluates exact similarity', async () => {
    const overview = {
      profile: {
        analyzerName: 'Essentia',
        analyzerVersion: '1',
        analyzerLicense: 'AGPL',
        modelName: 'EffNet',
        modelVersion: '1',
        modelChecksum: 'abc',
        modelLicense: 'CC',
        embeddingDimensions: 1280,
      },
      analyzedTrackCount: 2,
      coverage: {
        rootCount: 1,
        artistCount: 2,
        albumCount: 2,
      },
      distributions: {
        bpm: {
          count: 2,
          minimum: 100,
          maximum: 120,
          mean: 110,
          median: 110,
          lowerQuartile: 105,
          upperQuartile: 115,
          bins: [],
        },
      },
      feedbackSummary: {
        relevant: 0,
        irrelevant: 0,
      },
      review: {
        targetSourceCount: 1,
        matchCount: 10,
        sources: [],
        quality: {
          all: {
            startedSourceCount: 0,
            completedSourceCount: 0,
            ratedMatchCount: 0,
            relevant: 0,
            irrelevant: 0,
            relevanceRate: null,
            meanRelevantShare: null,
          },
          exclude_album: {
            startedSourceCount: 0,
            completedSourceCount: 0,
            ratedMatchCount: 0,
            relevant: 0,
            irrelevant: 0,
            relevanceRate: null,
            meanRelevantShare: null,
          },
          exclude_artist: {
            startedSourceCount: 0,
            completedSourceCount: 0,
            ratedMatchCount: 0,
            relevant: 0,
            irrelevant: 0,
            relevanceRate: null,
            meanRelevantShare: null,
          },
          exclude_album_artist: {
            startedSourceCount: 0,
            completedSourceCount: 0,
            ratedMatchCount: 0,
            relevant: 0,
            irrelevant: 0,
            relevanceRate: null,
            meanRelevantShare: null,
          },
        },
      },
      tracks: [
        {
          id: 10,
          title: 'Source',
          label: 'Artist · Source · Album',
          artistName: 'Artist',
          artists: [{ id: 1, name: 'Artist' }],
          albumId: 2,
          albumTitle: 'Album',
          year: 2020,
          discNumber: 1,
          trackNumber: 1,
          libraryRootId: 1,
          libraryRootName: 'Root',
          genreIds: [],
          features: { bpm: 120 },
        },
      ],
    }
    const result = {
      profile: overview.profile,
      source: overview.tracks[0],
      candidateCount: 1,
      calculationMs: 0.25,
      filters: {
        excludeSameAlbum: true,
        excludeSameArtist: true,
      },
      matches: [
        {
          ...overview.tracks[0],
          id: 11,
          title: 'Match',
          similarity: 0.92,
          feedback: null,
        },
      ],
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(overview), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(result), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useAudioIntelligenceSettingsStore()

    await store.loadEvaluation()
    await store.evaluateTrack(10, {
      excludeSameAlbum: true,
      excludeSameArtist: true,
    })

    expect(store.evaluation.analyzedTrackCount).toBe(2)
    expect(store.evaluationResult?.matches[0]?.similarity).toBe(0.92)
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/settings/audio-intelligence/evaluation',
      expect.any(Object),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/settings/audio-intelligence/evaluation/10?limit=10&excludeSameAlbum=1&excludeSameArtist=1',
      expect.any(Object),
    )
  })

  it('stores and removes similarity feedback without reloading matches', async () => {
    const store = useAudioIntelligenceSettingsStore()
    store.evaluation.feedbackSummary = { relevant: 0, irrelevant: 0 }
    store.evaluationResult = {
      profile: {
        analyzerName: 'Test',
        analyzerVersion: '1',
        analyzerLicense: 'Test',
        modelName: 'Model',
        modelVersion: '1',
        modelChecksum: 'abc',
        modelLicense: 'Test',
        embeddingDimensions: 3,
      },
      source: { id: 1 } as never,
      candidateCount: 1,
      calculationMs: 1,
      filters: { excludeSameAlbum: true, excludeSameArtist: true },
      matches: [{
        id: 2,
        feedback: null,
        similarity: 0.9,
      } as never],
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({
        feedback: 'relevant',
        feedbackSummary: { relevant: 1, irrelevant: 0 },
        review: {
          ...store.evaluation.review,
          quality: {
            ...store.evaluation.review.quality,
            exclude_album_artist: {
              ...store.evaluation.review.quality.exclude_album_artist,
              ratedMatchCount: 1,
              relevant: 1,
              relevanceRate: 1,
            },
          },
        },
      }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        feedback: null,
        feedbackSummary: { relevant: 0, irrelevant: 0 },
        review: store.evaluation.review,
      }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    await store.setSimilarityFeedback(1, 2, 'relevant', {
      excludeSameAlbum: true,
      excludeSameArtist: true,
    })
    expect(store.evaluationResult.matches[0]?.feedback).toBe('relevant')
    expect(store.evaluation.feedbackSummary.relevant).toBe(1)
    expect(store.evaluation.review.quality.exclude_album_artist.ratedMatchCount).toBe(1)

    await store.setSimilarityFeedback(1, 2, null, {
      excludeSameAlbum: true,
      excludeSameArtist: true,
    })
    expect(store.evaluationResult.matches[0]?.feedback).toBeNull()
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/settings/audio-intelligence/evaluation/1/matches/2/feedback'
        + '?excludeSameAlbum=1&excludeSameArtist=1',
      expect.objectContaining({ method: 'DELETE' }),
    )
  })
})
