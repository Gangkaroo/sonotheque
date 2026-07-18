import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface AudioIntelligencePilotSummary {
  eligibleTrackCount: number
  eligibleRootCount: number
  selectedRootCount: number
  selectedGenreCount: number
  unclassifiedTrackCount: number
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
  status: 'prepared' | 'queued' | 'running' | 'completed' | 'partial' | 'failed' | 'cancelled'
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
  analyzerStatus: AudioAnalyzerStatus
  analyzer: {
    status: AudioAnalyzerStatus
    message: string | null
    profile: AudioAnalyzerProfile | null
  }
  latestPilot: AudioIntelligencePilot | null
}

const defaults: AudioIntelligenceSettings = {
  enabled: false,
  sampleSize: 200,
  eligibleTrackCount: 0,
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

  return {
    settings,
    loading,
    saving,
    preparingPilot,
    testingAnalyzer,
    startingPilot,
    cancellingPilot,
    resumingPilot,
    error,
    load,
    save,
    preparePilot,
    testAnalyzer,
    startPilot,
    cancelPilot,
    resumePilot,
  }
})

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Audio intelligence settings could not be saved.'
}
