import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import FolderBrowserDialog from '@/components/FolderBrowserDialog.vue'
import PlaylistImportDialog from '@/components/PlaylistImportDialog.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'

const apiRequestMock = vi.hoisted(() => vi.fn())

vi.mock('@/api/client', () => ({
  apiRequest: apiRequestMock,
}))

describe('PlaylistImportDialog', () => {
  beforeAll(() => {
    vi.stubGlobal('ResizeObserver', class {
      observe() {}
      unobserve() {}
      disconnect() {}
    })
    Object.defineProperty(window, 'visualViewport', {
      configurable: true,
      value: {
        width: 1024,
        height: 768,
        offsetLeft: 0,
        offsetTop: 0,
        pageLeft: 0,
        pageTop: 0,
        scale: 1,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      },
    })
  })

  afterEach(() => {
    apiRequestMock.mockReset()
    window.sessionStorage.clear()
    document.body.innerHTML = ''
  })

  it('prefills the playlist name and emits the import result', async () => {
    const result = {
      playlist: {
        id: 42,
        name: 'Road Trip',
        folder: null,
        trackCount: 2,
      },
      totalEntries: 3,
      importedCount: 2,
      unresolvedCount: 1,
      warnings: [{
        line: 4,
        path: '../Missing.mp3',
        code: 'outside_or_missing',
        message: 'The file was not found.',
      }],
    }
    apiRequestMock.mockResolvedValueOnce(result)
    i18n.global.locale.value = 'en'
    const wrapper = mount(PlaylistImportDialog, {
      attachTo: document.body,
      props: { modelValue: true },
      global: {
        plugins: [createPinia(), i18n, vuetify],
      },
    })

    wrapper.findComponent(FolderBrowserDialog).vm.$emit(
      'select',
      'P:/Playlists/Road Trip.m3u8',
    )
    await flushPromises()

    const nameInput = [...document.body.querySelectorAll<HTMLInputElement>('input')]
      .find((input) => input.value === 'Road Trip')
    expect(nameInput).toBeDefined()

    const importButton = [...document.body.querySelectorAll<HTMLButtonElement>('button')]
      .find((button) => button.textContent?.trim() === 'Import playlist')
    expect(importButton).toBeDefined()
    importButton?.click()
    await flushPromises()

    expect(apiRequestMock).toHaveBeenCalledWith('/playlists/import', {
      method: 'POST',
      body: JSON.stringify({
        path: 'P:/Playlists/Road Trip.m3u8',
        name: 'Road Trip',
        folderId: null,
      }),
    })
    expect(wrapper.emitted('imported')?.[0]?.[0]).toEqual(result)
  })
})
