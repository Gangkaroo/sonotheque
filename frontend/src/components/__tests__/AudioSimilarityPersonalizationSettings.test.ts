import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import AudioSimilarityPersonalizationSettings from '@/components/AudioSimilarityPersonalizationSettings.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import { useAudioIntelligenceSettingsStore } from '@/stores/audioIntelligenceSettings'

describe('AudioSimilarityPersonalizationSettings', () => {
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

  it('saves the current similarity refinement settings', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const store = useAudioIntelligenceSettingsStore()
    store.settings = {
      ...store.settings,
      enabled: true,
      reranking: {
        enabled: true,
        tempoInfluence: 6,
        keyInfluence: 4,
        intensityInfluence: 7,
      },
    }
    const saveReranking = vi.spyOn(store, 'saveReranking').mockResolvedValue()
    const wrapper = mount(AudioSimilarityPersonalizationSettings, {
      global: { plugins: [pinia, i18n, vuetify] },
    })

    await findButton(wrapper.element, 'Save refinement')?.click()

    expect(saveReranking).toHaveBeenCalledWith({
      enabled: true,
      tempoInfluence: 6,
      keyInfluence: 4,
      intensityInfluence: 7,
    })
  })
})

function findButton(element: Element, label: string) {
  return [...element.querySelectorAll<HTMLButtonElement>('button')]
    .find(button => button.textContent?.trim() === label)
}
