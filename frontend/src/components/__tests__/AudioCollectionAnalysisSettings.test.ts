import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import AudioCollectionAnalysisSettings from '@/components/AudioCollectionAnalysisSettings.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import { useAudioIntelligenceSettingsStore } from '@/stores/audioIntelligenceSettings'

describe('AudioCollectionAnalysisSettings', () => {
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

  it('prepares the collection for the selected scope', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const store = useAudioIntelligenceSettingsStore()
    store.settings = {
      ...store.settings,
      enabled: true,
      eligibleTrackCount: 120,
      eligibleRoots: [{ id: 7, name: 'Archive', eligibleTrackCount: 120 }],
    }
    const prepareCollection = vi.spyOn(store, 'prepareCollection').mockResolvedValue()
    const wrapper = mount(AudioCollectionAnalysisSettings, {
      global: { plugins: [pinia, i18n, vuetify] },
    })

    await findButton(wrapper.element, 'Prepare collection analysis')?.click()

    expect(prepareCollection).toHaveBeenCalledOnce()
    expect(prepareCollection).toHaveBeenCalledWith(null)
  })
})

function findButton(element: Element, label: string) {
  return [...element.querySelectorAll<HTMLButtonElement>('button')]
    .find(button => button.textContent?.trim() === label)
}
