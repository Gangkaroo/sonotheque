import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface OnlineEnrichmentSettings {
  informationEnabled: boolean
  lyricsEnabled: boolean
  cache: OnlineEnrichmentCacheSummary
}

export interface OnlineEnrichmentCacheSummary {
  total: number
  ready: number
  notFound: number
  errors: number
  stale: number
}

export interface ProviderTestResult {
  provider: OnlineEnrichmentProvider
  status: 'available' | 'error' | 'not_configured'
  errorCode?: string | null
}

export type OnlineEnrichmentProvider = 'lastfm' | 'lrclib' | 'musicbrainz'

export interface MusicianBackfillCoverage {
  checkedAlbums: number
  creditedAlbums: number
  totalAlbums: number
  percentage: number
}

export interface MusicianBackfillRun {
  id: number
  status: 'queued' | 'running' | 'paused' | 'completed' | 'partial' | 'failed' | 'cancelled'
  lookupVersion: number
  libraryRoot: { id: number, name: string } | null
  totalAlbumCount: number
  processedAlbumCount: number
  readyAlbumCount: number
  notFoundAlbumCount: number
  ambiguousAlbumCount: number
  failedAlbumCount: number
  estimatedRemainingMilliseconds: number | null
  lastError: string | null
  retryAfter: string | null
  resumable: boolean
  pauseRequested: boolean
  startedAt: string | null
  finishedAt: string | null
  createdAt: string | null
}

export interface MusicianBackfillState {
  coverage: MusicianBackfillCoverage
  run: MusicianBackfillRun | null
  activeRun: MusicianBackfillRun | null
}

const defaults: OnlineEnrichmentSettings = {
  informationEnabled: false,
  lyricsEnabled: false,
  cache: { total: 0, ready: 0, notFound: 0, errors: 0, stale: 0 },
}

export const useOnlineEnrichmentSettingsStore = defineStore('onlineEnrichmentSettings', () => {
  const settings = ref<OnlineEnrichmentSettings>({ ...defaults })
  const loading = ref(false)
  const saving = ref(false)
  const clearingCache = ref(false)
  const testingProvider = ref<OnlineEnrichmentProvider | null>(null)
  const providerTests = ref<Partial<Record<OnlineEnrichmentProvider, ProviderTestResult>>>({})
  const musicianBackfill = ref<MusicianBackfillState | null>(null)
  const loadingMusicianBackfill = ref(false)
  const musicianBackfillOperation = ref<'start' | 'pause' | 'resume' | 'cancel' | null>(null)
  const error = ref<string | null>(null)
  let musicianBackfillRequestId = 0

  async function load() {
    loading.value = true
    error.value = null
    try {
      settings.value = await apiRequest<OnlineEnrichmentSettings>('/settings/online-enrichment')
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      loading.value = false
    }
  }

  async function save(next: OnlineEnrichmentSettings) {
    saving.value = true
    error.value = null
    try {
      settings.value = await apiRequest<OnlineEnrichmentSettings>('/settings/online-enrichment', {
        method: 'PATCH',
        body: JSON.stringify(next),
      })
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  async function clearCache() {
    clearingCache.value = true
    error.value = null
    try {
      const result = await apiRequest<{ deleted: number, cache: OnlineEnrichmentCacheSummary }>(
        '/settings/online-enrichment/cache',
        { method: 'DELETE' },
      )
      settings.value.cache = result.cache
      return result.deleted
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      clearingCache.value = false
    }
  }

  async function testProvider(provider: OnlineEnrichmentProvider) {
    testingProvider.value = provider
    error.value = null
    try {
      const result = await apiRequest<ProviderTestResult>(
        `/settings/online-enrichment/providers/${provider}/test`,
        { method: 'POST' },
      )
      providerTests.value[provider] = result
      return result
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      testingProvider.value = null
    }
  }

  async function loadMusicianBackfill(libraryRootId: number | null, { silent = false } = {}) {
    const requestId = ++musicianBackfillRequestId
    if (!silent) loadingMusicianBackfill.value = true
    try {
      const result = await apiRequest<MusicianBackfillState>(backfillPath(libraryRootId))
      if (requestId === musicianBackfillRequestId) musicianBackfill.value = result
      return result
    } catch (cause) {
      if (requestId === musicianBackfillRequestId) error.value = errorMessage(cause)
      throw cause
    } finally {
      if (requestId === musicianBackfillRequestId) loadingMusicianBackfill.value = false
    }
  }

  async function startMusicianBackfill(libraryRootId: number | null) {
    return runBackfillOperation('start', backfillPath(libraryRootId), 'POST')
  }

  async function pauseMusicianBackfill(runId: number) {
    return runBackfillOperation(
      'pause',
      `/settings/online-enrichment/musician-backfill/${runId}/pause`,
      'POST',
    )
  }

  async function resumeMusicianBackfill(runId: number) {
    return runBackfillOperation(
      'resume',
      `/settings/online-enrichment/musician-backfill/${runId}/resume`,
      'POST',
    )
  }

  async function cancelMusicianBackfill(runId: number) {
    return runBackfillOperation(
      'cancel',
      `/settings/online-enrichment/musician-backfill/${runId}`,
      'DELETE',
    )
  }

  async function runBackfillOperation(
    operation: 'start' | 'pause' | 'resume' | 'cancel',
    path: string,
    method: 'POST' | 'DELETE',
  ) {
    musicianBackfillOperation.value = operation
    error.value = null
    try {
      return await apiRequest<MusicianBackfillState>(path, { method })
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      musicianBackfillOperation.value = null
    }
  }

  return {
    settings,
    loading,
    saving,
    clearingCache,
    testingProvider,
    providerTests,
    musicianBackfill,
    loadingMusicianBackfill,
    musicianBackfillOperation,
    error,
    load,
    save,
    clearCache,
    testProvider,
    loadMusicianBackfill,
    startMusicianBackfill,
    pauseMusicianBackfill,
    resumeMusicianBackfill,
    cancelMusicianBackfill,
  }
})

function backfillPath(libraryRootId: number | null) {
  const path = '/settings/online-enrichment/musician-backfill'
  return libraryRootId === null ? path : `${path}?libraryRoot=${libraryRootId}`
}

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Online content settings could not be saved.'
}
