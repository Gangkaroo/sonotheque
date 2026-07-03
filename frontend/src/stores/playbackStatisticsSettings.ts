import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface PlaybackStatisticsSettings {
  synchronizeWithFileTags: boolean
  supportedExportFormats: string[]
}

export const usePlaybackStatisticsSettingsStore = defineStore('playbackStatisticsSettings', () => {
  const settings = ref<PlaybackStatisticsSettings>({
    synchronizeWithFileTags: false,
    supportedExportFormats: ['mp3'],
  })
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  async function load() {
    loading.value = true
    error.value = null
    try {
      settings.value = await apiRequest<PlaybackStatisticsSettings>('/settings/playback-statistics')
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      loading.value = false
    }
  }

  async function setSynchronizeWithFileTags(enabled: boolean) {
    saving.value = true
    error.value = null
    try {
      settings.value = await apiRequest<PlaybackStatisticsSettings>('/settings/playback-statistics', {
        method: 'PATCH',
        body: JSON.stringify({ synchronizeWithFileTags: enabled }),
      })
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  return { settings, loading, saving, error, load, setSynchronizeWithFileTags }
})

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Playback statistics settings could not be saved.'
}
