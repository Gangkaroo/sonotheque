import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useCollectionAssistantSettingsStore } from '@/stores/collectionAssistantSettings'

describe('collection assistant settings store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads settings without discovering models', async () => {
    const settings = {
      enabled: false,
      provider: 'ollama',
      model: null,
      endpoint: 'http://127.0.0.1:11434',
      recommendedModel: 'qwen3:8b',
    }
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify(settings), { status: 200 }),
    )
    vi.stubGlobal('fetch', fetchMock)
    const store = useCollectionAssistantSettingsStore()

    await store.load()

    expect(store.settings).toEqual(settings)
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock).toHaveBeenCalledWith('/api/settings/collection-assistant', expect.any(Object))
  })

  it('discovers installed models and verifies the selected model', async () => {
    const discovery = {
      status: 'available',
      errorCode: null,
      models: [{
        name: 'qwen3:8b',
        size: 5_200_000_000,
        parameterSize: '8.2B',
        quantization: 'Q4_K_M',
        family: 'qwen3',
      }],
    }
    const test = {
      status: 'available',
      model: 'qwen3:8b',
      toolCalling: true,
      errorCode: null,
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(discovery), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(test), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useCollectionAssistantSettingsStore()

    await store.discoverModels()
    await store.test('qwen3:8b')

    expect(store.models).toEqual(discovery.models)
    expect(store.testResult).toEqual(test)
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/settings/collection-assistant/models',
      expect.objectContaining({ method: 'POST' }),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/settings/collection-assistant/test',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ model: 'qwen3:8b' }) }),
    )
  })

  it('saves the opt-in only with an explicitly selected model', async () => {
    const enabled = {
      enabled: true,
      provider: 'ollama',
      model: 'qwen3:8b',
      endpoint: 'http://127.0.0.1:11434',
      recommendedModel: 'qwen3:8b',
    }
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify(enabled), { status: 200 }),
    )
    vi.stubGlobal('fetch', fetchMock)
    const store = useCollectionAssistantSettingsStore()

    await store.save({ enabled: true, model: 'qwen3:8b' })

    expect(store.settings).toEqual(enabled)
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/settings/collection-assistant',
      expect.objectContaining({
        method: 'PATCH',
        body: JSON.stringify({ enabled: true, model: 'qwen3:8b' }),
      }),
    )
  })
})
