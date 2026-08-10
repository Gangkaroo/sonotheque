import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface AudioAnalysisRunSummary {
  mode?: 'expansion' | 'collection'
  baselineAnalyzedTrackCount?: number
  newTrackTargetCount?: number
  eligibleTrackCount: number
  eligibleRootCount: number
  candidateTrackCount?: number
  candidateRootCount?: number
  candidateGenreCount?: number
  candidateArtistCount?: number
  fingerprintedTrackCount?: number
  fingerprintFailedTrackCount?: number
  processedFingerprintTrackCount?: number
  selectedRootCount?: number
  selectedGenreCount?: number
  selectedArtistCount?: number
  unclassifiedTrackCount?: number
  analyzedTrackCount?: number
  reusedTrackCount?: number
  failedTrackCount?: number
  staleTrackCount?: number
  cancelledTrackCount?: number
  processedTrackCount?: number
  runtimeMs?: number
  analysisMeasuredTrackCount?: number
  analysisElapsedMs?: number
  analysisError?: string
  candidatesEnumerated?: boolean
}

export interface AudioAnalysisRun {
  id: number
  kind: 'validation' | 'expansion' | 'collection'
  phase: 'preparation' | 'analysis'
  status: 'fingerprinting' | 'prepared' | 'queued' | 'running' | 'paused' | 'completed' | 'partial' | 'failed' | 'cancelled'
  requestedTrackCount: number
  selectedTrackCount: number
  summary: AudioAnalysisRunSummary
  resumable: boolean
  libraryRoot: { id: number, name: string } | null
  profile: AudioAnalyzerProfile | null
  startedAt: string | null
  finishedAt: string | null
  cancelRequestedAt: string | null
  pauseRequestedAt: string | null
  createdAt: string
}

export interface AudioIntelligenceEligibleRoot {
  id: number
  name: string
  eligibleTrackCount: number
  fingerprintedTrackCount: number
}

export interface AudioAnalyzerProfile {
  analyzerName: string
  analyzerVersion: string
  analyzerLicense: string
  modelName: string
  modelVersion: string
  modelChecksum: string
  modelLicense: string
  embeddingDimensions: number
}

export interface AudioAnalyzerBenchmarkResult {
  accelerator: 'cpu' | 'cuda'
  preparationWorkers: number
  chunkSize: number
  status: 'completed' | 'unavailable' | 'failed' | 'cancelled'
  trackCount?: number
  wallTimeMs?: number
  tracksPerMinute?: number
  averageTimings?: {
    decodeMs: number
    featureExtractionMs: number
    embeddingMs: number
  }
  equivalent?: boolean
  minimumCosine?: number
  featuresMatch?: boolean
  error?: string | null
}

export interface AudioAnalyzerBenchmark {
  id: number
  status: 'queued' | 'running' | 'completed' | 'partial' | 'failed' | 'cancelled'
  sampleSize: number
  sampleTrackIds: number[]
  results: AudioAnalyzerBenchmarkResult[]
  recommendation: {
    accelerator: 'cpu' | 'cuda'
    preparationWorkers: number
    chunkSize: number
    tracksPerMinute: number
    speedupVsCpu: number | null
  } | null
  completedConfigurationCount: number
  totalConfigurationCount: number
  error: string | null
  cancelRequestedAt: string | null
  startedAt: string | null
  finishedAt: string | null
  createdAt: string
}

export type AudioAnalyzerStatus =
  | 'not_configured'
  | 'unchecked'
  | 'dependency_missing'
  | 'model_missing'
  | 'ready'
  | 'incompatible'
  | 'error'

export type AudioAnalyzerAccelerator = 'cpu' | 'cuda'

export type AudioAnalyzerAcceleratorStatus = 'available' | 'unavailable' | 'unchecked'

export interface AudioSimilarityRerankingSettings {
  enabled: boolean
  tempoInfluence: number
  keyInfluence: number
  intensityInfluence: number
}

export interface AudioSimilarityPersonalizationSettings {
  enabled: boolean
  ready: boolean
  applied: boolean
  canTrain: boolean
  minimumFeedbackCount: number
  minimumVerdictCount: number
  feedbackCount: number
  relevantCount: number
  irrelevantCount: number
  profileId: number | null
  adjustments: {
    tempo: number
    key: number
    intensity: number
  }
  featureStatistics: Record<string, {
    relevantSampleCount: number
    irrelevantSampleCount: number
    relevantMean: number | null
    irrelevantMean: number | null
  }>
  trainedAt: string | null
}

export interface AudioIntelligenceSettings {
  enabled: boolean
  validationSampleSize: number
  eligibleTrackCount: number
  fingerprintedTrackCount: number
  eligibleRoots: AudioIntelligenceEligibleRoot[]
  analyzerStatus: AudioAnalyzerStatus
  analyzer: {
    status: AudioAnalyzerStatus
    message: string | null
    profile: AudioAnalyzerProfile | null
  }
  analyzerSelection: {
    selected: AudioAnalyzerAccelerator
    recommended: AudioAnalyzerAccelerator | null
    methods: Record<AudioAnalyzerAccelerator, AudioAnalyzerAcceleratorStatus>
  }
  reranking: AudioSimilarityRerankingSettings
  personalization: AudioSimilarityPersonalizationSettings
  vectorIndex: {
    status: 'empty' | 'ready' | 'incomplete' | 'unsupported'
    dimensions: number
    indexedArtifactCount: number
    eligibleArtifactCount: number
  }
  collectionRuns: AudioAnalysisRun[]
  latestCollectionRun: AudioAnalysisRun | null
  latestValidationRun: AudioAnalysisRun | null
  activeRun: AudioAnalysisRun | null
  latestBenchmark: AudioAnalyzerBenchmark | null
}

export interface AudioSimilarityTrack {
  id: number
  title: string
  streamUrl: string
  durationMs: number | null
  label: string
  artistName: string
  artists: Array<{ id: number, name: string }>
  albumId: number | null
  albumTitle: string
  albumOriginalReleaseYear: number | null
  albumArtworkThumbnailUrl: string | null
  year: number | null
  discNumber: number | null
  trackNumber: number | null
  libraryRootId: number | null
  libraryRootName: string
  genreIds: number[]
  features: {
    bpm?: number
    danceability?: number
    dynamicComplexity?: number
    loudness?: number
    key?: string
    scale?: string
    keyStrength?: number
  }
}

export interface AudioFeatureDistribution {
  count: number
  minimum: number
  maximum: number
  mean: number
  median: number
  lowerQuartile: number
  upperQuartile: number
  bins: Array<{
    minimum: number
    maximum: number
    count: number
  }>
}

export interface AudioSimilarityFeedbackSummary {
  relevant: number
  irrelevant: number
}

export type AudioSimilarityConfiguration =
  | 'all'
  | 'exclude_album'
  | 'exclude_artist'
  | 'exclude_album_artist'

export interface AudioSimilaritySourceProgress {
  required: number
  rated: number
  relevant: number
  irrelevant: number
  complete: boolean
}

export interface AudioSimilarityQualityMetrics {
  startedSourceCount: number
  completedSourceCount: number
  ratedMatchCount: number
  relevant: number
  irrelevant: number
  relevanceRate: number | null
  meanRelevantShare: number | null
}

export interface AudioSimilarityReview {
  targetSourceCount: number
  matchCount: number
  sources: Array<AudioSimilarityTrack & {
    configurations: Record<AudioSimilarityConfiguration, AudioSimilaritySourceProgress>
  }>
  quality: Record<AudioSimilarityConfiguration, AudioSimilarityQualityMetrics>
}

export interface AudioSimilarityOverview {
  profile: AudioAnalyzerProfile | null
  analyzedTrackCount: number
  coverage: {
    rootCount: number
    artistCount: number
    albumCount: number
  }
  distributions: Record<string, AudioFeatureDistribution>
  feedbackSummary: AudioSimilarityFeedbackSummary
  review: AudioSimilarityReview
  tracks: AudioSimilarityTrack[]
}

export interface AudioSimilarityEvaluation {
  profile: AudioAnalyzerProfile
  source: AudioSimilarityTrack
  candidateCount: number
  calculationMs: number
  filters: {
    excludeSameAlbum: boolean
    excludeSameArtist: boolean
  }
  ranking: {
    method: 'embedding' | 'feature_reranking' | 'personalized'
    candidatePoolSize: number
    preferences: AudioSimilarityRerankingSettings
    personalization: {
      enabled: boolean
      applied: boolean
      adjustments: AudioSimilarityPersonalizationSettings['adjustments']
      trainedAt: string | null
    }
  }
  matches: Array<AudioSimilarityTrack & {
    similarity: number
    rankingScore: number
    featureCompatibility: {
      tempo: number | null
      key: number | null
      intensity: number | null
    }
    feedback: 'relevant' | 'irrelevant' | null
  }>
}

const defaults: AudioIntelligenceSettings = {
  enabled: false,
  validationSampleSize: 200,
  eligibleTrackCount: 0,
  fingerprintedTrackCount: 0,
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
    methods: {
      cpu: 'unchecked',
      cuda: 'unchecked',
    },
  },
  reranking: {
    enabled: false,
    tempoInfluence: 5,
    keyInfluence: 3,
    intensityInfluence: 4,
  },
  personalization: {
    enabled: false,
    ready: false,
    applied: false,
    canTrain: false,
    minimumFeedbackCount: 20,
    minimumVerdictCount: 5,
    feedbackCount: 0,
    relevantCount: 0,
    irrelevantCount: 0,
    profileId: null,
    adjustments: { tempo: 0, key: 0, intensity: 0 },
    featureStatistics: {},
    trainedAt: null,
  },
  vectorIndex: {
    status: 'empty',
    dimensions: 1280,
    indexedArtifactCount: 0,
    eligibleArtifactCount: 0,
  },
  collectionRuns: [],
  latestCollectionRun: null,
  latestValidationRun: null,
  activeRun: null,
  latestBenchmark: null,
}

const emptyQualityMetrics = (): AudioSimilarityQualityMetrics => ({
  startedSourceCount: 0,
  completedSourceCount: 0,
  ratedMatchCount: 0,
  relevant: 0,
  irrelevant: 0,
  relevanceRate: null,
  meanRelevantShare: null,
})

const emptyReview = (): AudioSimilarityReview => ({
  targetSourceCount: 0,
  matchCount: 10,
  sources: [],
  quality: {
    all: emptyQualityMetrics(),
    exclude_album: emptyQualityMetrics(),
    exclude_artist: emptyQualityMetrics(),
    exclude_album_artist: emptyQualityMetrics(),
  },
})

export const useAudioIntelligenceSettingsStore = defineStore('audioIntelligenceSettings', () => {
  const settings = ref<AudioIntelligenceSettings>({ ...defaults })
  const loading = ref(false)
  const saving = ref(false)
  const preparingValidationSample = ref(false)
  const expandingPool = ref(false)
  const preparingCollection = ref(false)
  const testingAnalyzer = ref(false)
  const startingBenchmark = ref(false)
  const cancellingBenchmark = ref(false)
  const startingRun = ref(false)
  const cancellingRun = ref(false)
  const pausingRun = ref(false)
  const resumingRun = ref(false)
  const evaluation = ref<AudioSimilarityOverview>({
    profile: null,
    analyzedTrackCount: 0,
    coverage: {
      rootCount: 0,
      artistCount: 0,
      albumCount: 0,
    },
    distributions: {},
    feedbackSummary: {
      relevant: 0,
      irrelevant: 0,
    },
    review: emptyReview(),
    tracks: [],
  })
  const evaluationResult = ref<AudioSimilarityEvaluation | null>(null)
  const loadingEvaluation = ref(false)
  const evaluatingTrack = ref(false)
  const ratingTrackId = ref<number | null>(null)
  const trainingPersonalization = ref(false)
  const resettingPersonalization = ref(false)
  const evaluationError = ref<string | null>(null)
  const error = ref<string | null>(null)

  function collectionRunForScope(libraryRootId: number | null) {
    return settings.value.collectionRuns?.find(
      run => (run.libraryRoot?.id ?? null) === libraryRootId,
    ) ?? null
  }

  async function load(options: { silent?: boolean } = {}) {
    if (!options.silent) {
      loading.value = true
    }
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>('/settings/audio-intelligence')
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      if (!options.silent) {
        loading.value = false
      }
    }
  }

  async function save(
    enabled: boolean,
    validationSampleSize: number,
    accelerator = settings.value.analyzerSelection.selected,
    reranking = settings.value.reranking ?? defaults.reranking,
    personalizationEnabled = settings.value.personalization?.enabled ?? false,
  ) {
    saving.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>('/settings/audio-intelligence', {
        method: 'PATCH',
        body: JSON.stringify({
          enabled,
          validationSampleSize,
          accelerator,
          reranking,
          personalization: { enabled: personalizationEnabled },
        }),
      })
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  async function saveReranking(reranking: AudioSimilarityRerankingSettings) {
    await save(
      settings.value.enabled,
      settings.value.validationSampleSize,
      settings.value.analyzerSelection.selected,
      reranking,
      reranking.enabled && (settings.value.personalization?.enabled ?? false),
    )
  }

  async function setPersonalizationEnabled(enabled: boolean) {
    await save(
      settings.value.enabled,
      settings.value.validationSampleSize,
      settings.value.analyzerSelection.selected,
      settings.value.reranking,
      enabled,
    )
  }

  async function trainPersonalization() {
    trainingPersonalization.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        '/settings/audio-intelligence/personalization/train',
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      trainingPersonalization.value = false
    }
  }

  async function resetPersonalization() {
    resettingPersonalization.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        '/settings/audio-intelligence/personalization',
        { method: 'DELETE' },
      )
      evaluationResult.value = null
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      resettingPersonalization.value = false
    }
  }

  async function prepareValidationSample() {
    preparingValidationSample.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        '/settings/audio-intelligence/validation-runs',
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      preparingValidationSample.value = false
    }
  }

  async function expandPool(targetTrackCount: number) {
    expandingPool.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        '/settings/audio-intelligence/expansions',
        {
          method: 'POST',
          body: JSON.stringify({ targetTrackCount }),
        },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      expandingPool.value = false
    }
  }

  async function prepareCollection(libraryRootId: number | null) {
    preparingCollection.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        '/settings/audio-intelligence/collections',
        {
          method: 'POST',
          body: JSON.stringify({ libraryRootId }),
        },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      preparingCollection.value = false
    }
  }

  async function testAnalyzer() {
    testingAnalyzer.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        '/settings/audio-intelligence/analyzer/test',
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      testingAnalyzer.value = false
    }
  }

  async function startBenchmark() {
    startingBenchmark.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        '/settings/audio-intelligence/benchmarks',
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      startingBenchmark.value = false
    }
  }

  async function cancelBenchmark(benchmarkId: number) {
    cancellingBenchmark.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        `/settings/audio-intelligence/benchmarks/${benchmarkId}/cancel`,
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      cancellingBenchmark.value = false
    }
  }

  async function startRun(runId: number) {
    startingRun.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        `/settings/audio-intelligence/runs/${runId}/start`,
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      startingRun.value = false
    }
  }

  async function cancelRun(runId: number) {
    cancellingRun.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        `/settings/audio-intelligence/runs/${runId}/cancel`,
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      cancellingRun.value = false
    }
  }

  async function pauseRun(runId: number) {
    pausingRun.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        `/settings/audio-intelligence/runs/${runId}/pause`,
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      pausingRun.value = false
    }
  }

  async function resumeRun(runId: number) {
    resumingRun.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        `/settings/audio-intelligence/runs/${runId}/resume`,
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      resumingRun.value = false
    }
  }

  async function loadEvaluation() {
    loadingEvaluation.value = true
    evaluationError.value = null
    try {
      evaluation.value = await apiRequest<AudioSimilarityOverview>(
        '/settings/audio-intelligence/evaluation',
      )
    } catch (cause) {
      evaluationError.value = errorMessage(cause)
    } finally {
      loadingEvaluation.value = false
    }
  }

  async function evaluateTrack(
    trackId: number,
    options: { excludeSameAlbum: boolean, excludeSameArtist: boolean },
  ) {
    evaluatingTrack.value = true
    evaluationError.value = null
    try {
      const query = new URLSearchParams({
        limit: '10',
        excludeSameAlbum: options.excludeSameAlbum ? '1' : '0',
        excludeSameArtist: options.excludeSameArtist ? '1' : '0',
      })
      evaluationResult.value = await apiRequest<AudioSimilarityEvaluation>(
        `/settings/audio-intelligence/evaluation/${trackId}?${query.toString()}`,
      )
    } catch (cause) {
      evaluationError.value = errorMessage(cause)
      throw cause
    } finally {
      evaluatingTrack.value = false
    }
  }

  async function setSimilarityFeedback(
    sourceTrackId: number,
    candidateTrackId: number,
    feedback: 'relevant' | 'irrelevant' | null,
    options: { excludeSameAlbum: boolean, excludeSameArtist: boolean },
  ) {
    ratingTrackId.value = candidateTrackId
    evaluationError.value = null
    try {
      const response = await apiRequest<{
        feedback: 'relevant' | 'irrelevant' | null
        feedbackSummary: AudioSimilarityFeedbackSummary
        review: AudioSimilarityReview
        personalization: AudioSimilarityPersonalizationSettings
      }>(
        `/settings/audio-intelligence/evaluation/${sourceTrackId}`
          + `/matches/${candidateTrackId}/feedback`
          + (feedback === null
            ? `?${new URLSearchParams({
                excludeSameAlbum: options.excludeSameAlbum ? '1' : '0',
                excludeSameArtist: options.excludeSameArtist ? '1' : '0',
              }).toString()}`
            : ''),
        feedback === null
          ? { method: 'DELETE' }
          : {
              method: 'PUT',
              body: JSON.stringify({
                verdict: feedback,
                excludeSameAlbum: options.excludeSameAlbum,
                excludeSameArtist: options.excludeSameArtist,
              }),
            },
      )
      evaluation.value.feedbackSummary = response.feedbackSummary
      evaluation.value.review = response.review
      settings.value.personalization = response.personalization
      const match = evaluationResult.value?.matches.find(item => item.id === candidateTrackId)
      if (match) {
        match.feedback = response.feedback
      }
    } catch (cause) {
      evaluationError.value = errorMessage(cause)
      throw cause
    } finally {
      ratingTrackId.value = null
    }
  }

  return {
    settings,
    loading,
    saving,
    preparingValidationSample,
    expandingPool,
    preparingCollection,
    testingAnalyzer,
    startingBenchmark,
    cancellingBenchmark,
    startingRun,
    cancellingRun,
    pausingRun,
    resumingRun,
    evaluation,
    evaluationResult,
    loadingEvaluation,
    evaluatingTrack,
    ratingTrackId,
    trainingPersonalization,
    resettingPersonalization,
    evaluationError,
    error,
    collectionRunForScope,
    load,
    save,
    saveReranking,
    setPersonalizationEnabled,
    trainPersonalization,
    resetPersonalization,
    prepareValidationSample,
    expandPool,
    prepareCollection,
    testAnalyzer,
    startBenchmark,
    cancelBenchmark,
    startRun,
    cancelRun,
    pauseRun,
    resumeRun,
    loadEvaluation,
    evaluateTrack,
    setSimilarityFeedback,
  }
})

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Audio intelligence settings could not be saved.'
}
