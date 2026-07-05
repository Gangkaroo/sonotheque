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
  const error = ref<string | null>(null)

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

  return {
    settings,
    loading,
    saving,
    clearingCache,
    testingProvider,
    providerTests,
    error,
    load,
    save,
    clearCache,
    testProvider,
  }
})

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Online content settings could not be saved.'
}
