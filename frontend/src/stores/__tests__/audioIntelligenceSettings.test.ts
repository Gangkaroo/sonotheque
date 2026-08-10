import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useAudioIntelligenceSettingsStore } from '@/stores/audioIntelligenceSettings'

describe('audio intelligence settings store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads, enables, and prepares a validation sample without starting analysis', async () => {
    const disabled = {
      enabled: false,
      validationSampleSize: 200,
      eligibleTrackCount: 1200,
      fingerprintedTrackCount: 50,
      eligibleRoots: [],
      analyzerStatus: 'not_configured',
      analyzer: {
        status: 'not_configured',
        message: null,
        profile: null,
      },
      analyzerSelection: {
        selected: 'cpu',
        recommended: null,
        methods: { cpu: 'unchecked', cuda: 'unchecked' },
      },
      latestCollectionRun: null,
      latestValidationRun: null,
      activeRun: null,
    }
    const enabled = { ...disabled, enabled: true }
    const prepared = {
      ...enabled,
      latestValidationRun: {
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
    await store.prepareValidationSample()

    expect(store.settings.latestValidationRun?.selectedTrackCount).toBe(200)
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/settings/audio-intelligence', expect.objectContaining({
      method: 'PATCH',
      body: JSON.stringify({
        enabled: true,
        validationSampleSize: 200,
        accelerator: 'cpu',
        reranking: {
          enabled: false,
          tempoInfluence: 5,
          keyInfluence: 3,
          intensityInfluence: 4,
        },
        personalization: { enabled: false },
      }),
    }))
    expect(fetchMock).toHaveBeenNthCalledWith(3, '/api/settings/audio-intelligence/validation-runs', expect.objectContaining({
      method: 'POST',
    }))
  })

  it('requests cancellation for a running analysis', async () => {
    const running = {
      enabled: true,
      validationSampleSize: 50,
      eligibleTrackCount: 111,
      fingerprintedTrackCount: 111,
      analyzerStatus: 'ready',
      analyzer: { status: 'ready', message: 'Ready', profile: null },
      latestValidationRun: {
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
      latestValidationRun: {
        ...running.latestValidationRun,
        cancelRequestedAt: '2026-07-17T10:02:00+00:00',
      },
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(cancelling), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useAudioIntelligenceSettingsStore()

    await store.cancelRun(4)

    expect(store.settings.latestValidationRun?.cancelRequestedAt).not.toBeNull()
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/settings/audio-intelligence/runs/4/cancel',
      expect.objectContaining({ method: 'POST' }),
    )
  })

  it('prepares an analyzed-pool expansion with an explicit target', async () => {
    const expansion = {
      enabled: true,
      validationSampleSize: 200,
      eligibleTrackCount: 1200,
      fingerprintedTrackCount: 275,
      analyzerStatus: 'ready',
      analyzer: { status: 'ready', message: 'Ready', profile: null },
      latestValidationRun: {
        id: 8,
        phase: 'preparation',
        status: 'fingerprinting',
        requestedTrackCount: 500,
        selectedTrackCount: 0,
        summary: {
          mode: 'expansion',
          baselineAnalyzedTrackCount: 250,
          newTrackTargetCount: 250,
        },
        resumable: false,
        profile: null,
        startedAt: null,
        finishedAt: null,
        cancelRequestedAt: null,
        createdAt: '2026-07-22T20:00:00+00:00',
      },
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(expansion), { status: 202 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useAudioIntelligenceSettingsStore()

    await store.expandPool(500)

    expect(store.settings.latestValidationRun?.summary.baselineAnalyzedTrackCount).toBe(250)
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/settings/audio-intelligence/expansions',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ targetTrackCount: 500 }),
      }),
    )
  })

  it('prepares a root-scoped collection run and can pause it', async () => {
    const collectionRun = {
      id: 12,
      kind: 'collection',
      phase: 'preparation',
      status: 'fingerprinting',
      requestedTrackCount: 800,
      selectedTrackCount: 600,
      summary: {
        mode: 'collection',
        baselineAnalyzedTrackCount: 500,
        candidateTrackCount: 800,
      },
      resumable: false,
      libraryRoot: { id: 7, name: 'Archive' },
      profile: null,
      startedAt: '2026-07-23T20:00:00+00:00',
      finishedAt: null,
      cancelRequestedAt: null,
      pauseRequestedAt: null,
      createdAt: '2026-07-23T19:59:00+00:00',
    }
    const collection = {
      enabled: true,
      validationSampleSize: 200,
      eligibleTrackCount: 1200,
      fingerprintedTrackCount: 900,
      eligibleRoots: [
        {
          id: 7,
          name: 'Archive',
          eligibleTrackCount: 800,
          fingerprintedTrackCount: 600,
        },
      ],
      analyzerStatus: 'ready',
      analyzer: { status: 'ready', message: 'Ready', profile: null },
      collectionRuns: [collectionRun],
      latestCollectionRun: collectionRun,
    }
    const pausingRun = {
      ...collectionRun,
      pauseRequestedAt: '2026-07-23T20:05:00+00:00',
    }
    const pausing = {
      ...collection,
      collectionRuns: [pausingRun],
      latestCollectionRun: pausingRun,
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(collection), { status: 202 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(pausing), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useAudioIntelligenceSettingsStore()

    await store.prepareCollection(7)
    await store.pauseRun(12)

    expect(store.settings.latestCollectionRun?.libraryRoot?.id).toBe(7)
    expect(store.settings.latestCollectionRun?.pauseRequestedAt).not.toBeNull()
    expect(store.collectionRunForScope(7)?.pauseRequestedAt).not.toBeNull()
    expect(store.collectionRunForScope(null)).toBeNull()
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/settings/audio-intelligence/collections',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ libraryRootId: 7 }),
      }),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/settings/audio-intelligence/runs/12/pause',
      expect.objectContaining({ method: 'POST' }),
    )
  })

  it('refreshes active analysis progress without showing the initial loader', async () => {
    const running = {
      enabled: true,
      validationSampleSize: 200,
      eligibleTrackCount: 1200,
      fingerprintedTrackCount: 500,
      analyzerStatus: 'ready',
      analyzer: { status: 'ready', message: 'Ready', profile: null },
      latestValidationRun: {
        id: 8,
        phase: 'analysis',
        status: 'running',
        requestedTrackCount: 500,
        selectedTrackCount: 500,
        summary: {
          mode: 'expansion',
          baselineAnalyzedTrackCount: 250,
          newTrackTargetCount: 250,
          processedTrackCount: 325,
        },
        resumable: false,
        profile: null,
        startedAt: '2026-07-23T10:00:00+00:00',
        finishedAt: null,
        cancelRequestedAt: null,
        createdAt: '2026-07-23T09:59:00+00:00',
      },
    }
    let resolveRequest: ((response: Response) => void) | undefined
    const fetchMock = vi.fn().mockReturnValue(new Promise<Response>((resolve) => {
      resolveRequest = resolve
    }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useAudioIntelligenceSettingsStore()

    const refresh = store.load({ silent: true })

    expect(store.loading).toBe(false)
    resolveRequest?.(new Response(JSON.stringify(running), { status: 200 }))
    await refresh

    expect(store.loading).toBe(false)
    expect(store.settings.latestValidationRun?.summary.processedTrackCount).toBe(325)
  })

  it('resumes an interrupted analysis without preparing a replacement run', async () => {
    const resumed = {
      enabled: true,
      validationSampleSize: 50,
      eligibleTrackCount: 111,
      fingerprintedTrackCount: 111,
      analyzerStatus: 'ready',
      analyzer: { status: 'ready', message: 'Ready', profile: null },
      latestValidationRun: {
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

    await store.resumeRun(7)

    expect(store.settings.latestValidationRun?.id).toBe(7)
    expect(store.settings.latestValidationRun?.summary.reusedTrackCount).toBe(10)
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/settings/audio-intelligence/runs/7/resume',
      expect.objectContaining({ method: 'POST' }),
    )
  })

  it('starts and cancels an analyzer benchmark', async () => {
    const running = {
      enabled: true,
      validationSampleSize: 200,
      eligibleTrackCount: 1200,
      fingerprintedTrackCount: 1200,
      eligibleRoots: [],
      analyzerStatus: 'ready',
      analyzer: { status: 'ready', message: 'Ready', profile: null },
      latestCollectionRun: null,
      latestValidationRun: null,
      activeRun: null,
      latestBenchmark: {
        id: 4,
        status: 'running',
        sampleSize: 15,
        sampleTrackIds: [],
        results: [],
        recommendation: null,
        completedConfigurationCount: 0,
        totalConfigurationCount: 6,
        error: null,
        cancelRequestedAt: null,
        startedAt: '2026-07-24T10:00:00+00:00',
        finishedAt: null,
        createdAt: '2026-07-24T10:00:00+00:00',
      },
    }
    const cancelled = {
      ...running,
      latestBenchmark: {
        ...running.latestBenchmark,
        status: 'cancelled',
        cancelRequestedAt: '2026-07-24T10:01:00+00:00',
        finishedAt: '2026-07-24T10:01:00+00:00',
      },
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(running), { status: 202 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(cancelled), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useAudioIntelligenceSettingsStore()

    await store.startBenchmark()
    await store.cancelBenchmark(4)

    expect(store.settings.latestBenchmark?.status).toBe('cancelled')
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/settings/audio-intelligence/benchmarks',
      expect.objectContaining({ method: 'POST' }),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/settings/audio-intelligence/benchmarks/4/cancel',
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
      ranking: {
        method: 'embedding',
        candidatePoolSize: 1,
        preferences: {
          enabled: false,
          tempoInfluence: 5,
          keyInfluence: 3,
          intensityInfluence: 4,
        },
        personalization: {
          enabled: false,
          applied: false,
          adjustments: { tempo: 0, key: 0, intensity: 0 },
          trainedAt: null,
        },
      },
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
