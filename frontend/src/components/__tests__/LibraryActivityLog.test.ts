import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import LibraryActivityLog from '@/components/LibraryActivityLog.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'

const apiRequestMock = vi.hoisted(() => vi.fn())

vi.mock('@/api/client', () => ({
  apiRequest: apiRequestMock,
}))

describe('LibraryActivityLog', () => {
  beforeAll(() => {
    vi.stubGlobal('ResizeObserver', class {
      observe() {}
      unobserve() {}
      disconnect() {}
    })
  })

  afterEach(() => {
    apiRequestMock.mockReset()
    document.body.innerHTML = ''
  })

  it('shows cross-root activity and links entries back to scans', async () => {
    apiRequestMock.mockResolvedValue({
      items: [{
        id: 1,
        libraryRootId: 7,
        libraryRootName: 'Archive',
        scanRunId: 42,
        source: 'watcher',
        severity: 'warning',
        code: 'file_warning',
        message: 'A tag could not be read.',
        path: 'Artist/Album/Track.mp3',
        count: 2,
        createdAt: '2026-07-26T10:00:00Z',
      }],
      page: 1,
      lastPage: 1,
      total: 1,
    })
    i18n.global.locale.value = 'en'
    const wrapper = mount(LibraryActivityLog, {
      attachTo: document.body,
      props: {
        roots: [{
          id: 7,
          name: 'Archive',
          path: 'G:/Music',
          coverImagePaths: ['cover.jpg'],
          excludedDirectories: [],
          enabled: true,
          lastScannedAt: null,
          watchEnabled: true,
          watchPollIntervalMinutes: 5,
          watchReconcileIntervalMinutes: 1440,
          watchStatus: 'watching',
          watchCheckedAt: null,
          watchLastEventAt: null,
          watchLastScanAt: null,
          watchLastPath: null,
          watchError: null,
        }],
      },
      global: {
        plugins: [createPinia(), i18n, vuetify],
      },
    })

    await flushPromises()

    expect(wrapper.text()).toContain('A tag could not be read.')
    expect(wrapper.text()).toContain('Artist/Album/Track.mp3')
    const scanButton = wrapper.findAll('button')
      .find((button) => button.text().includes('Scan #42'))
    expect(scanButton).toBeDefined()
    await scanButton?.trigger('click')
    expect(wrapper.emitted('scan')?.[0]).toEqual([42])
  })
})
