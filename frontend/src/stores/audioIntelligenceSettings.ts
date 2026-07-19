import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface AudioIntelligencePilotSummary {
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
  analysisError?: string
}

export interface AudioIntelligencePilot {
  id: number
  phase: 'preparation' | 'analysis'
  status: 'fingerprinting' | 'prepared' | 'queued' | 'running' | 'completed' | 'partial' | 'failed' | 'cancelled'
  requestedTrackCount: number
  selectedTrackCount: number
  summary: AudioIntelligencePilotSummary
  resumable: boolean
  profile: AudioAnalyzerProfile | null
  startedAt: string | null
  finishedAt: string | null
  cancelRequestedAt: string | null
  createdAt: string
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

export type AudioAnalyzerStatus =
  | 'not_configured'
  | 'dependency_missing'
  | 'model_missing'
  | 'ready'
  | 'incompatible'
  | 'error'

export interface AudioIntelligenceSettings {
  enabled: boolean
  sampleSize: number
  eligibleTrackCount: number
  fingerprintedTrackCount: number
  analyzerStatus: AudioAnalyzerStatus
  analyzer: {
    status: AudioAnalyzerStatus
    message: string | null
    profile: AudioAnalyzerProfile | null
  }
  latestPilot: AudioIntelligencePilot | null
}

export interface AudioSimilarityTrack {
  id: number
  title: string
  label: string
  artistName: string
  artists: Array<{ id: number, name: string }>
  albumId: number | null
  albumTitle: string
  year: number | null
  discNumber: number | null
  trackNumber: number | null
  libraryRootId: number | null
  libraryRootName: string
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
  matches: Array<AudioSimilarityTrack & {
    similarity: number
    feedback: 'relevant' | 'irrelevant' | null
  }>
}

const defaults: AudioIntelligenceSettings = {
  enabled: false,
  sampleSize: 200,
  eligibleTrackCount: 0,
  fingerprintedTrackCount: 0,
  analyzerStatus: 'not_configured',
  analyzer: {
    status: 'not_configured',
    message: null,
    profile: null,
  },
  latestPilot: null,
}

export const useAudioIntelligenceSettingsStore = defineStore('audioIntelligenceSettings', () => {
  const settings = ref<AudioIntelligenceSettings>({ ...defaults })
  const loading = ref(false)
  const saving = ref(false)
  const preparingPilot = ref(false)
  const testingAnalyzer = ref(false)
  const startingPilot = ref(false)
  const cancellingPilot = ref(false)
  const resumingPilot = ref(false)
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
    tracks: [],
  })
  const evaluationResult = ref<AudioSimilarityEvaluation | null>(null)
  const loadingEvaluation = ref(false)
  const evaluatingTrack = ref(false)
  const ratingTrackId = ref<number | null>(null)
  const evaluationError = ref<string | null>(null)
  const error = ref<string | null>(null)

  async function load() {
    loading.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>('/settings/audio-intelligence')
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      loading.value = false
    }
  }

  async function save(enabled: boolean, sampleSize: number) {
    saving.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>('/settings/audio-intelligence', {
        method: 'PATCH',
        body: JSON.stringify({ enabled, sampleSize }),
      })
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  async function preparePilot() {
    preparingPilot.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>('/settings/audio-intelligence/pilots', {
        method: 'POST',
      })
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      preparingPilot.value = false
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

  async function startPilot(pilotId: number) {
    startingPilot.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        `/settings/audio-intelligence/pilots/${pilotId}/start`,
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      startingPilot.value = false
    }
  }

  async function cancelPilot(pilotId: number) {
    cancellingPilot.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        `/settings/audio-intelligence/pilots/${pilotId}/cancel`,
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      cancellingPilot.value = false
    }
  }

  async function resumePilot(pilotId: number) {
    resumingPilot.value = true
    error.value = null
    try {
      settings.value = await apiRequest<AudioIntelligenceSettings>(
        `/settings/audio-intelligence/pilots/${pilotId}/resume`,
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      resumingPilot.value = false
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
  ) {
    ratingTrackId.value = candidateTrackId
    evaluationError.value = null
    try {
      const response = await apiRequest<{
        feedback: 'relevant' | 'irrelevant' | null
        feedbackSummary: AudioSimilarityFeedbackSummary
      }>(
        `/settings/audio-intelligence/evaluation/${sourceTrackId}`
          + `/matches/${candidateTrackId}/feedback`,
        feedback === null
          ? { method: 'DELETE' }
          : {
              method: 'PUT',
              body: JSON.stringify({ verdict: feedback }),
            },
      )
      evaluation.value.feedbackSummary = response.feedbackSummary
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
    preparingPilot,
    testingAnalyzer,
    startingPilot,
    cancellingPilot,
    resumingPilot,
    evaluation,
    evaluationResult,
    loadingEvaluation,
    evaluatingTrack,
    ratingTrackId,
    evaluationError,
    error,
    load,
    save,
    preparePilot,
    testAnalyzer,
    startPilot,
    cancelPilot,
    resumePilot,
    loadEvaluation,
    evaluateTrack,
    setSimilarityFeedback,
  }
})

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Audio intelligence settings could not be saved.'
}
