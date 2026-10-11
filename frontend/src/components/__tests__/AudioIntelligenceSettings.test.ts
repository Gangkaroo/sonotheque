import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { beforeAll, describe, expect, it, vi } from 'vitest'

import AudioIntelligenceSettings from '@/components/AudioIntelligenceSettings.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import { useAudioIntelligenceSettingsStore } from '@/stores/audioIntelligenceSettings'

describe('AudioIntelligenceSettings', () => {
  beforeAll(() => {
    vi.stubGlobal('ResizeObserver', class {
      observe() {}
      unobserve() {}
      disconnect() {}
    })
  })

  it('shows the current configuration with diagnostics collapsed and updates it reactively', async () => {
    i18n.global.locale.value = 'en'
    const pinia = createPinia()
    setActivePinia(pinia)
    const store = useAudioIntelligenceSettingsStore()
    store.loading = false
    vi.spyOn(store, 'load').mockResolvedValue()
    vi.spyOn(store, 'loadEvaluation').mockResolvedValue()
    const wrapper = mount(AudioIntelligenceSettings, {
      global: {
        plugins: [pinia, i18n, vuetify],
        stubs: {
          AudioCollectionAnalysisSettings: true,
          AudioSimilarityPersonalizationSettings: true,
          AudioAnalyzerBenchmark: true,
          AudioSimilarityReviewSettings: true,
        },
      },
    })

    expect(wrapper.get('.v-expansion-panel-title').attributes('aria-expanded')).toBe('false')
    expect(wrapper.text()).toContain('Current configuration: CPU, batch size 5, 2 preparation worker(s).')

    store.settings.analyzerSelection = {
      ...store.settings.analyzerSelection,
      selected: 'cuda', preparationWorkers: 3, chunkSize: 15, tuningConfigured: true,
    }
    await nextTick()
    expect(wrapper.text()).toContain('Current configuration: CUDA, batch size 15, 3 preparation worker(s).')
    wrapper.unmount()
  })
})
