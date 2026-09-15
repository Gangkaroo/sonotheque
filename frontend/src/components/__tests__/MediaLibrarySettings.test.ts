import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import MediaLibrarySettings from '@/components/MediaLibrarySettings.vue'
import { i18n } from '@/plugins/i18n'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { useScanRunsStore } from '@/stores/scanRuns'

describe('MediaLibrarySettings', () => {
  beforeEach(() => {
    i18n.global.locale.value = 'en'
  })

  it('loads library roots and scan history when mounted', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const libraryRoots = useLibraryRootsStore()
    const scanRuns = useScanRunsStore()
    const loadRoots = vi.spyOn(libraryRoots, 'load').mockResolvedValue()
    const loadScans = vi.spyOn(scanRuns, 'load').mockResolvedValue()

    const wrapper = shallowMount(MediaLibrarySettings, {
      global: { plugins: [pinia, i18n] },
    })
    await flushPromises()

    expect(loadRoots).toHaveBeenCalledOnce()
    expect(loadScans).toHaveBeenCalledOnce()
    wrapper.unmount()
  })
})
