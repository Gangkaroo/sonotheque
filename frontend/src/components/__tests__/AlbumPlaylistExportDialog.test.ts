import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import AlbumPlaylistExportDialog from '@/components/AlbumPlaylistExportDialog.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'

const apiRequestMock = vi.hoisted(() => vi.fn())

vi.mock('@/api/client', () => ({
  ApiError: class extends Error {
    constructor(
      message: string,
      public readonly violations = {},
      public readonly status = 0,
    ) {
      super(message)
    }
  },
  apiRequest: apiRequestMock,
}))

describe('AlbumPlaylistExportDialog', () => {
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
    document.body.innerHTML = ''
  })

  it('prefills the album folder and saves the selected playlist format', async () => {
    apiRequestMock
      .mockResolvedValueOnce({
        defaultFormat: 'm3u8',
        defaultFilename: 'Album Artist - Album Title.m3u8',
        formats: ['m3u8', 'm3u'],
        directory: {
          libraryRoot: 'Music Archive',
          relativePath: 'Artist/Album',
        },
      })
      .mockResolvedValueOnce({
        format: 'm3u8',
        filename: 'Album Artist - Album Title.m3u8',
        trackCount: 10,
        sizeBytes: 400,
        relativePath: 'Artist/Album/Album Artist - Album Title.m3u8',
      })
    i18n.global.locale.value = 'en'
    const wrapper = mount(AlbumPlaylistExportDialog, {
      attachTo: document.body,
      props: {
        albumId: 42,
        modelValue: true,
      },
      global: {
        plugins: [createPinia(), i18n, vuetify],
      },
    })

    await flushPromises()

    expect(document.body.textContent).toContain('Music Archive / Artist/Album')
    expect([...document.body.querySelectorAll<HTMLInputElement>('input[type="text"]')]
      .some((input) => input.value === 'Album Artist - Album Title.m3u8'))
      .toBe(true)

    const saveButton = [...document.body.querySelectorAll<HTMLButtonElement>('button')]
      .find((button) => button.textContent?.trim() === 'Save')
    expect(saveButton).toBeDefined()
    saveButton?.click()
    await flushPromises()

    expect(apiRequestMock).toHaveBeenLastCalledWith('/albums/42/playlist-export', {
      method: 'POST',
      body: JSON.stringify({
        format: 'm3u8',
        filename: 'Album Artist - Album Title.m3u8',
        overwrite: false,
      }),
    })
    expect(wrapper.emitted('saved')?.[0]?.[0]).toMatchObject({
      filename: 'Album Artist - Album Title.m3u8',
    })
  })
})
