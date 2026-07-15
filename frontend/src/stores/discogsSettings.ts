import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface DiscogsSettings {
  connected: boolean
  username: string | null
  userId: number | null
  connectedAt: string | null
}

const emptySettings: DiscogsSettings = {
  connected: false,
  username: null,
  userId: null,
  connectedAt: null,
}

export const useDiscogsSettingsStore = defineStore('discogsSettings', () => {
  const settings = ref<DiscogsSettings>({ ...emptySettings })
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  async function load() {
    loading.value = true
    error.value = null
    try {
      settings.value = await apiRequest<DiscogsSettings>('/settings/discogs')
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      loading.value = false
    }
  }

  async function connect(personalAccessToken: string) {
    await save(() => apiRequest<DiscogsSettings>('/settings/discogs/connect', {
      method: 'POST',
      body: JSON.stringify({ personalAccessToken }),
    }))
  }

  async function disconnect() {
    await save(() => apiRequest<DiscogsSettings>('/settings/discogs', {
      method: 'DELETE',
    }))
  }

  async function save(request: () => Promise<DiscogsSettings>) {
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

  return { settings, loading, saving, error, load, connect, disconnect }
})

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Discogs settings could not be saved.'
}
