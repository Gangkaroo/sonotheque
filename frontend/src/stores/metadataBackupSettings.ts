import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface MetadataBackupSettings {
  enabled: boolean
  path: string
  retentionDays: number
}

export const useMetadataBackupSettingsStore = defineStore('metadataBackupSettings', () => {
  const settings = ref<MetadataBackupSettings>({ enabled: false, path: '', retentionDays: 30 })
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  async function load() {
    loading.value = true
    error.value = null
    try {
      settings.value = await apiRequest<MetadataBackupSettings>('/settings/metadata-backups')
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      loading.value = false
    }
  }

  async function save(values: MetadataBackupSettings) {
    saving.value = true
    error.value = null
    try {
      settings.value = await apiRequest<MetadataBackupSettings>('/settings/metadata-backups', {
        method: 'PATCH',
        body: JSON.stringify(values),
      })
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  return { settings, loading, saving, error, load, save }
})

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Metadata backup settings could not be saved.'
}
