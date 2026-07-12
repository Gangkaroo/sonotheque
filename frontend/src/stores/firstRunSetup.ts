import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface FirstRunSetupStatus {
  completed: boolean
  step: number
  hasLibraryRoots: boolean
}

export const useFirstRunSetupStore = defineStore('firstRunSetup', () => {
  const status = ref<FirstRunSetupStatus | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  async function load() {
    loading.value = true
    error.value = null
    try {
      status.value = await apiRequest<FirstRunSetupStatus>('/settings/first-run')
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Setup status could not be loaded.'
    } finally {
      loading.value = false
    }
  }

  async function update(input: { step?: number, completed?: boolean }) {
    saving.value = true
    error.value = null
    try {
      status.value = await apiRequest<FirstRunSetupStatus>('/settings/first-run', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/merge-patch+json' },
        body: JSON.stringify(input),
      })
      return status.value
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Setup progress could not be saved.'
      throw cause
    } finally {
      saving.value = false
    }
  }

  return { status, loading, saving, error, load, update }
})
