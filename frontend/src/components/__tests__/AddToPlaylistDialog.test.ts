import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

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

  beforeEach(() => {
    localStorage.clear()
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
    expect(JSON.parse(localStorage.getItem('sonotheque.recent-playlists') ?? '[]')).toEqual([3])
    wrapper.unmount()
  })

  it('offers the five most recently used available playlists as direct choices', async () => {
    localStorage.setItem('sonotheque.recent-playlists', JSON.stringify([6, 3, 5, 2, 4, 1, 999]))
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/playlist-folders') return jsonResponse({ items: [] })
      if (url === '/api/playlists') {
        return jsonResponse({
          items: [1, 2, 3, 4, 5, 6].map((id) => ({
            id,
            name: `Playlist ${id}`,
            folder: null,
            trackCount: 0,
          })),
        })
      }
      if (url === '/api/playlists/3/tracks' && init?.method === 'POST') {
        return jsonResponse({ items: [{ id: 43, position: 1, track: { id: 7 } }] }, 201)
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

    const recentChoices = [...document.body.querySelectorAll<HTMLElement>('[data-testid^="recent-playlist-"]')]
    expect(recentChoices.map((choice) => choice.textContent?.trim())).toEqual([
      'Playlist 6',
      'Playlist 3',
      'Playlist 5',
      'Playlist 2',
      'Playlist 4',
    ])
    document.body.querySelector<HTMLElement>('[data-testid="recent-playlist-3"]')?.click()
    await flushPromises()
    const addButton = [...document.body.querySelectorAll<HTMLButtonElement>('button')]
      .find((button) => button.textContent?.trim() === 'Add to playlist')
    expect(addButton).toBeDefined()
    addButton?.click()
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledWith('/api/playlists/3/tracks', expect.objectContaining({
      method: 'POST',
    }))
    expect(JSON.parse(localStorage.getItem('sonotheque.recent-playlists') ?? '[]')).toEqual([3, 6, 5, 2, 4])
    wrapper.unmount()
  })
})

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}
