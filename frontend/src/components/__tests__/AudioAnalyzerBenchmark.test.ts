import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import AudioAnalyzerBenchmark from '@/components/AudioAnalyzerBenchmark.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import { useAudioIntelligenceSettingsStore } from '@/stores/audioIntelligenceSettings'

describe('AudioAnalyzerBenchmark', () => {
  beforeAll(() => {
    vi.stubGlobal('ResizeObserver', class {
      observe() {}
      unobserve() {}
      disconnect() {}
    })
  })

  beforeEach(() => {
    i18n.global.locale.value = 'en'
  })

  it('renders analyzer and benchmark status and forwards their actions', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const store = useAudioIntelligenceSettingsStore()
    store.settings = {
      ...store.settings,
      enabled: true,
      analyzerStatus: 'ready',
      analyzer: {
        status: 'ready',
        message: 'Analyzer is available.',
        profile: null,
      },
      vectorIndex: {
        status: 'ready',
        dimensions: 1280,
        indexedArtifactCount: 120,
        eligibleArtifactCount: 120,
      },
      latestBenchmark: {
        id: 9,
        status: 'completed',
        sampleSize: 20,
        sampleTrackIds: [],
        results: [{
          accelerator: 'cuda',
          preparationWorkers: 2,
          chunkSize: 8,
          status: 'completed',
          wallTimeMs: 60_000,
          tracksPerMinute: 20,
          equivalent: true,
        }],
        recommendation: {
          accelerator: 'cuda',
          preparationWorkers: 2,
          chunkSize: 8,
          tracksPerMinute: 20,
          speedupVsCpu: 4,
        },
        completedConfigurationCount: 3,
        totalConfigurationCount: 3,
        error: null,
        cancelRequestedAt: null,
        startedAt: null,
        finishedAt: null,
        createdAt: '2026-09-14T08:00:00Z',
      },
    }
    const testAnalyzer = vi.spyOn(store, 'testAnalyzer').mockResolvedValue()
    const startBenchmark = vi.spyOn(store, 'startBenchmark').mockResolvedValue()
    const applyConfiguration = vi.spyOn(store, 'applyBenchmarkRecommendation').mockResolvedValue()
    const wrapper = mount(AudioAnalyzerBenchmark, {
      global: { plugins: [pinia, i18n, vuetify] },
    })

    expect(wrapper.text()).toContain('Analyzer is available.')
    expect(wrapper.text()).toContain('3 of 3 configurations')
    expect(wrapper.text()).toContain('CUDA')

    await findButton(wrapper.element, 'Check analyzer')?.click()
    await findButton(wrapper.element, 'Run benchmark')?.click()
    await findButton(wrapper.element, 'Apply recommended configuration')?.click()

    expect(testAnalyzer).toHaveBeenCalledOnce()
    expect(startBenchmark).toHaveBeenCalledOnce()
    expect(applyConfiguration).toHaveBeenCalledExactlyOnceWith(9)

    store.saving = true
    await nextTick()
    expect(findButton(wrapper.element, 'Apply recommended configuration')?.disabled).toBe(true)
    store.saving = false
    store.settings.activeRun = {
      id: 5, kind: 'collection', phase: 'analysis', status: 'running',
      requestedTrackCount: 100, selectedTrackCount: 100,
      summary: { eligibleTrackCount: 100, eligibleRootCount: 1 },
      resumable: false, libraryRoot: null, profile: null,
      startedAt: null, finishedAt: null, cancelRequestedAt: null,
      pauseRequestedAt: null, createdAt: '2026-09-14T08:00:00Z',
    }
    await nextTick()
    expect(findButton(wrapper.element, 'Apply recommended configuration')?.disabled).toBe(true)
    store.settings.activeRun = null
    store.settings.analyzerSelection.selected = 'cuda'
    await nextTick()
    expect(findButton(wrapper.element, 'Apply recommended configuration')?.disabled).toBe(false)
    store.settings.analyzerSelection.chunkSize = 8
    await nextTick()
    expect(findButton(wrapper.element, 'Apply recommended configuration')?.disabled).toBe(false)
    store.settings.analyzerSelection.tuningConfigured = true
    await nextTick()
    expect(findButton(wrapper.element, 'Configuration applied')?.disabled).toBe(true)
  })
})

function findButton(element: Element, label: string) {
  return [...element.querySelectorAll<HTMLButtonElement>('button')]
    .find(button => button.textContent?.trim() === label)
}
