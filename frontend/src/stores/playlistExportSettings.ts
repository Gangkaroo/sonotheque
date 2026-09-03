import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'

export type PlaylistExportFormat = 'm3u' | 'm3u8'

export interface PlaylistExportLocation {
  id: number
  name: string
  path: string
  isDefault: boolean
}

export interface PlaylistExportSettings {
  defaultFormat: PlaylistExportFormat
  synchronizePlaylists: boolean
  synchronization: {
    playlistCount: number
    syncedCount: number
    failedCount: number
    pendingCount: number
  }
  locations: PlaylistExportLocation[]
}

export interface PlaylistExportLocationInput {
  name: string
  path: string
  makeDefault: boolean
  createSubfolder?: boolean
}

const defaults: PlaylistExportSettings = {
  defaultFormat: 'm3u8',
  synchronizePlaylists: false,
  synchronization: {
    playlistCount: 0,
    syncedCount: 0,
    failedCount: 0,
    pendingCount: 0,
  },
  locations: [],
}

export const usePlaylistExportSettingsStore = defineStore('playlistExportSettings', () => {
  const settings = ref<PlaylistExportSettings>({ ...defaults, locations: [] })
  const loading = ref(false)
  const saving = ref(false)
  const retrying = ref(false)
  const error = ref<string | null>(null)

  async function load() {
    loading.value = true
    error.value = null
    try {
      settings.value = await apiRequest<PlaylistExportSettings>('/settings/playlist-exports')
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      loading.value = false
    }
  }

  async function refreshSynchronization() {
    try {
      const refreshed = await apiRequest<PlaylistExportSettings>('/settings/playlist-exports')
      settings.value = refreshed
    } catch {
      // Keep the last visible status and retry on the next polling interval.
    }
  }

  async function saveDefaults(
    defaultFormat: PlaylistExportFormat,
    synchronizePlaylists: boolean,
  ) {
    await save(async () => {
      settings.value = await apiRequest<PlaylistExportSettings>('/settings/playlist-exports', {
        method: 'PATCH',
        body: JSON.stringify({ defaultFormat, synchronizePlaylists }),
      })
    })
  }

  async function createLocation(input: PlaylistExportLocationInput) {
    await save(async () => {
      settings.value = await apiRequest<PlaylistExportSettings>('/settings/playlist-exports/locations', {
        method: 'POST',
        body: JSON.stringify(input),
      })
    })
  }

  async function updateLocation(id: number, input: PlaylistExportLocationInput) {
    await save(async () => {
      settings.value = await apiRequest<PlaylistExportSettings>(
        `/settings/playlist-exports/locations/${id}`,
        {
        method: 'PATCH',
        body: JSON.stringify(input),
        },
      )
    })
  }

  async function setDefaultLocation(id: number) {
    await save(async () => {
      settings.value = await apiRequest<PlaylistExportSettings>(
        `/settings/playlist-exports/locations/${id}/default`,
        { method: 'POST' },
      )
    })
  }

  async function removeLocation(id: number) {
    await save(async () => {
      settings.value = await apiRequest<PlaylistExportSettings>(
        `/settings/playlist-exports/locations/${id}`,
        { method: 'DELETE' },
      )
    })
  }

  async function retryFailedSynchronization() {
    retrying.value = true
    error.value = null
    try {
      settings.value = await apiRequest<PlaylistExportSettings>(
        '/settings/playlist-exports/synchronization/retry-failed',
        { method: 'POST' },
      )
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      retrying.value = false
    }
  }

  async function save(operation: () => Promise<void>) {
    saving.value = true
    error.value = null
    try {
      await operation()
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  return {
    settings,
    loading,
    saving,
    retrying,
    error,
    load,
    refreshSynchronization,
    saveDefaults,
    createLocation,
    updateLocation,
    setDefaultLocation,
    removeLocation,
    retryFailedSynchronization,
  }
})

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Playlist export settings could not be saved.'
}
