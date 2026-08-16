import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface CollectionAssistantSettings {
  enabled: boolean
  provider: 'ollama'
  model: string | null
  endpoint: string
  recommendedModel: string
}

export interface CollectionAssistantModel {
  name: string
  size: number | null
  parameterSize: string | null
  quantization: string | null
  family: string | null
}

export interface CollectionAssistantTest {
  status: 'available' | 'error' | 'not_configured'
  model: string | null
  toolCalling: boolean
  errorCode: string | null
}

interface ModelDiscovery {
  status: 'available' | 'error'
  models: CollectionAssistantModel[]
  errorCode: string | null
}

const defaults: CollectionAssistantSettings = {
  enabled: false,
  provider: 'ollama',
  model: null,
  endpoint: '',
  recommendedModel: 'qwen3:4b',
}

export const useCollectionAssistantSettingsStore = defineStore('collectionAssistantSettings', () => {
  const settings = ref<CollectionAssistantSettings>({ ...defaults })
  const models = ref<CollectionAssistantModel[]>([])
  const discoveryStatus = ref<ModelDiscovery['status'] | 'unchecked'>('unchecked')
  const discoveryErrorCode = ref<string | null>(null)
  const testResult = ref<CollectionAssistantTest | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const discovering = ref(false)
  const testing = ref(false)
  const error = ref<string | null>(null)

  async function load() {
    loading.value = true
    error.value = null
    try {
      settings.value = await apiRequest<CollectionAssistantSettings>('/settings/collection-assistant')
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      loading.value = false
    }
  }

  async function save(next: Pick<CollectionAssistantSettings, 'enabled' | 'model'>) {
    saving.value = true
    error.value = null
    try {
      settings.value = await apiRequest<CollectionAssistantSettings>('/settings/collection-assistant', {
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

  async function discoverModels() {
    discovering.value = true
    discoveryErrorCode.value = null
    error.value = null
    try {
      const result = await apiRequest<ModelDiscovery>('/settings/collection-assistant/models', {
        method: 'POST',
      })
      models.value = result.models
      discoveryStatus.value = result.status
      discoveryErrorCode.value = result.errorCode
      return result
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      discovering.value = false
    }
  }

  async function test(model: string | null) {
    testing.value = true
    testResult.value = null
    error.value = null
    try {
      testResult.value = await apiRequest<CollectionAssistantTest>(
        '/settings/collection-assistant/test',
        { method: 'POST', body: JSON.stringify({ model }) },
      )
      return testResult.value
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      testing.value = false
    }
  }

  return {
    settings,
    models,
    discoveryStatus,
    discoveryErrorCode,
    testResult,
    loading,
    saving,
    discovering,
    testing,
    error,
    load,
    save,
    discoverModels,
    test,
  }
})

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Collection Assistant settings could not be loaded.'
}
