import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
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

  it('keeps pause and resume in the same action position before cancel', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const store = useAudioIntelligenceSettingsStore()
    const run = {
      id: 5, kind: 'collection' as const, phase: 'analysis' as const,
      status: 'running' as const, requestedTrackCount: 100, selectedTrackCount: 100,
      summary: { eligibleTrackCount: 100, eligibleRootCount: 1 },
      resumable: false, libraryRoot: { id: 7, name: 'Archive' }, profile: null,
      startedAt: null, finishedAt: null, cancelRequestedAt: null,
      pauseRequestedAt: null, createdAt: '2026-10-10T12:00:00Z',
    }
    store.settings.collectionRuns = [run]
    store.settings.activeRun = run
    const pauseRun = vi.spyOn(store, 'pauseRun').mockResolvedValue()
    const resumeRun = vi.spyOn(store, 'resumeRun').mockResolvedValue()
    const wrapper = mount(AudioCollectionAnalysisSettings, {
      global: { plugins: [pinia, i18n, vuetify] },
    })
    const actionLabels = () => [...wrapper.element.querySelectorAll('.audio-collection-actions button')]
      .map(button => button.textContent?.trim())

    expect(actionLabels()).toEqual([
      i18n.global.t('settings.prepareAudioCollection'),
      i18n.global.t('settings.pauseAudioAnalysis'),
      i18n.global.t('settings.cancelAudioAnalysis'),
    ])
    await findButton(wrapper.element, i18n.global.t('settings.pauseAudioAnalysis'))?.click()
    expect(pauseRun).toHaveBeenCalledExactlyOnceWith(5)

    store.settings.collectionRuns = [{ ...run, status: 'paused', resumable: true }]
    store.settings.activeRun = null
    await nextTick()
    const resumeLabel = i18n.global.t('settings.resumeScopedAudioAnalysis', { scope: 'Archive' })
    expect(actionLabels()).toEqual([
      i18n.global.t('settings.prepareAudioCollection'),
      resumeLabel,
      i18n.global.t('settings.cancelAudioAnalysis'),
    ])
    await findButton(wrapper.element, resumeLabel)?.click()
    expect(resumeRun).toHaveBeenCalledExactlyOnceWith(5)
    wrapper.unmount()
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
