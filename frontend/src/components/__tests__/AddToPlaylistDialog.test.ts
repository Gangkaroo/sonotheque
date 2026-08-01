import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import type { Track } from '@/stores/catalog'

describe('AddToPlaylistDialog', () => {
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
    vi.restoreAllMocks()
    document.body.innerHTML = ''
  })

  it('adds tracks and opens the selected playlist at the first new item', async () => {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/playlist-folders') return jsonResponse({ items: [] })
      if (url === '/api/playlists') {
        return jsonResponse({
          items: [{ id: 3, name: 'Road trip', folder: null, trackCount: 4 }],
        })
      }
      if (url === '/api/playlists/3/tracks' && init?.method === 'POST') {
        return jsonResponse({
          items: [{ id: 42, position: 4, track: { id: 7 } }],
        }, 201)
      }

      throw new Error(`Unexpected request: ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'home', component: { template: '<div />' } },
        { path: '/playlists/:id', name: 'playlist-detail', component: { template: '<div />' } },
      ],
    })
    await router.push('/')
    await router.isReady()
    i18n.global.locale.value = 'en'
    const wrapper = mount(AddToPlaylistDialog, {
      attachTo: document.body,
      props: {
        modelValue: false,
        tracks: [{ id: 7, title: 'Example track' } as Track],
      },
      global: {
        plugins: [createPinia(), router, i18n, vuetify],
      },
    })
    await wrapper.setProps({ modelValue: true })
    await flushPromises()

    const playlistSelect = wrapper.findComponent({ name: 'VAutocomplete' })
    expect(playlistSelect.exists()).toBe(true)
    await playlistSelect.setValue(3)
    await flushPromises()
    const addAndOpenButton = [...document.body.querySelectorAll<HTMLButtonElement>('button')]
      .find((button) => button.textContent?.trim() === 'Add and open')
    expect(addAndOpenButton).toBeDefined()
    addAndOpenButton?.click()
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledWith('/api/playlists/3/tracks', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ trackIds: [7] }),
    }))
    await vi.waitFor(() => {
      expect(router.currentRoute.value).toMatchObject({
        name: 'playlist-detail',
        params: { id: '3' },
        query: { playlistItem: '42' },
      })
    })
  })
})

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}
