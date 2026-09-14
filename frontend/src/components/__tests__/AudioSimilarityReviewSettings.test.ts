import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import AudioSimilarityReviewSettings from '@/components/AudioSimilarityReviewSettings.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import { useAudioIntelligenceSettingsStore } from '@/stores/audioIntelligenceSettings'

describe('AudioSimilarityReviewSettings', () => {
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

  it('renders validation state and forwards sample preparation', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const store = useAudioIntelligenceSettingsStore()
    store.settings = {
      ...store.settings,
      enabled: true,
      eligibleTrackCount: 120,
      fingerprintedTrackCount: 20,
    }
    const prepareValidationSample = vi
      .spyOn(store, 'prepareValidationSample')
      .mockResolvedValue()
    const wrapper = mount(AudioSimilarityReviewSettings, {
      global: { plugins: [pinia, i18n, vuetify] },
    })

    expect(wrapper.text()).toContain('120 catalog tracks are available')

    await findButton(wrapper.element, 'Prepare sample')?.click()

    expect(prepareValidationSample).toHaveBeenCalledOnce()
  })
})

function findButton(element: Element, label: string) {
  return [...element.querySelectorAll<HTMLButtonElement>('button')]
    .find(button => button.textContent?.trim() === label)
}
