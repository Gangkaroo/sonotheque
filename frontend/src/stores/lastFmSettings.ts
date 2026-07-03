import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface LastFmSettings {
  configured: boolean
  connected: boolean
  enabled: boolean
  username: string | null
  apiKey: string | null
  authorizationPending: boolean
  authorizationExpiresAt: string | null
  authorizationUrl: string | null
}

const emptySettings: LastFmSettings = {
  configured: false,
  connected: false,
  enabled: false,
  username: null,
  apiKey: null,
  authorizationPending: false,
  authorizationExpiresAt: null,
  authorizationUrl: null,
}

export const useLastFmSettingsStore = defineStore('lastFmSettings', () => {
  const settings = ref<LastFmSettings>({ ...emptySettings })
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  async function load() {
    loading.value = true
    error.value = null
    try {
      settings.value = await apiRequest<LastFmSettings>('/settings/lastfm')
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      loading.value = false
    }
  }

  async function connect(apiKey: string, apiSecret: string) {
    await save(() => apiRequest<LastFmSettings>('/settings/lastfm/connect', {
      method: 'POST',
      body: JSON.stringify({ apiKey, apiSecret }),
    }))
  }

  async function complete() {
    await save(() => apiRequest<LastFmSettings>('/settings/lastfm/complete', {
      method: 'POST',
    }))
  }

  async function setEnabled(enabled: boolean) {
    await save(() => apiRequest<LastFmSettings>('/settings/lastfm', {
      method: 'PATCH',
      body: JSON.stringify({ enabled }),
    }))
  }

  async function disconnect() {
    await save(() => apiRequest<LastFmSettings>('/settings/lastfm', {
      method: 'DELETE',
    }))
  }

  async function save(request: () => Promise<LastFmSettings>) {
    saving.value = true
    error.value = null
    try {
      settings.value = await request()
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  return { settings, loading, saving, error, load, connect, complete, setEnabled, disconnect }
})

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Last.fm settings could not be saved.'
}
