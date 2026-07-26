import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import PlaylistFileExportDialog from '@/components/PlaylistFileExportDialog.vue'
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

describe('PlaylistFileExportDialog', () => {
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

  it('uses the configured defaults and saves the visible playlist', async () => {
    apiRequestMock
      .mockResolvedValueOnce({
        defaultFormat: 'm3u8',
        defaultFilename: 'Road Trip.m3u8',
        formats: ['m3u8', 'm3u'],
        defaultLocationId: 7,
        locations: [{
          id: 7,
          name: 'Main playlists',
          path: 'P:/Playlists',
          isDefault: true,
        }],
        trackCount: 12,
      })
      .mockResolvedValueOnce({
        format: 'm3u8',
        filename: 'Road Trip.m3u8',
        trackCount: 12,
        sizeBytes: 400,
        destinationPath: 'P:/Playlists/Road Trip.m3u8',
        location: { id: 7, name: 'Main playlists', path: 'P:/Playlists' },
      })
    i18n.global.locale.value = 'en'
    const wrapper = mount(PlaylistFileExportDialog, {
      attachTo: document.body,
      props: {
        playlistId: 42,
        modelValue: true,
      },
      global: {
        plugins: [createPinia(), i18n, vuetify],
      },
    })

    await flushPromises()

    expect(document.body.textContent).toContain('P:/Playlists')
    expect(document.body.textContent).toContain('12 tracks will be saved')
    const saveButton = [...document.body.querySelectorAll<HTMLButtonElement>('button')]
      .find((button) => button.textContent?.trim() === 'Save')
    expect(saveButton).toBeDefined()
    saveButton?.click()
    await flushPromises()

    expect(apiRequestMock).toHaveBeenLastCalledWith('/playlists/42/file-export', {
      method: 'POST',
      body: JSON.stringify({
        locationId: 7,
        format: 'm3u8',
        filename: 'Road Trip.m3u8',
        overwrite: false,
      }),
    })
    expect(wrapper.emitted('saved')?.[0]?.[0]).toMatchObject({
      filename: 'Road Trip.m3u8',
      trackCount: 12,
    })
  })
})
