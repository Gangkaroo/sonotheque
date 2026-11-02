import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PlaylistTrackList from '@/components/PlaylistTrackList.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import type { PlaylistDetail } from '@/stores/playlists'
import { usePlaylistsStore } from '@/stores/playlists'
import PlaylistDetailView from '@/views/PlaylistDetailView.vue'

const playlist: PlaylistDetail = {
  id: 7,
  name: 'Source playlist',
  trackCount: 1,
  items: [{
    id: 70,
    position: 1,
    track: {
      id: 11,
      title: 'Opening track',
      streamUrl: '/api/tracks/11/stream',
      durationMs: 180_000,
      album: { id: 5, title: 'Test album' },
      artists: [{ id: 3, name: 'Test artist' }],
      playStatistics: { playCount: 0 },
    },
  }],
}

describe('PlaylistDetailView', () => {
  let wrapper: ReturnType<typeof mount> | undefined

  beforeAll(() => {
    vi.stubGlobal('ResizeObserver', class {
      observe() {}
      unobserve() {}
      disconnect() {}
    })
    Object.defineProperty(window, 'visualViewport', {
      configurable: true,
      value: {
        width: 1024, height: 768, offsetLeft: 0, offsetTop: 0,
        pageLeft: 0, pageTop: 0, scale: 1,
        addEventListener: vi.fn(), removeEventListener: vi.fn(),
      },
    })
  })

  beforeEach(() => {
    localStorage.clear()
    i18n.global.locale.value = 'en'
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = undefined
    vi.restoreAllMocks()
    document.body.innerHTML = ''
  })

  async function openDialog(choices: Promise<Response>) {
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      if (url === '/api/playlists/7') return jsonResponse(playlist)
      if (url === '/api/playlist-folders') return jsonResponse({ items: [] })
      if (url === '/api/playlists') return choices
      if (url.startsWith('/api/playlists/memberships?')) {
        return jsonResponse({ items: [{
          trackId: 11,
          playlists: [{ id: 7, name: playlist.name, firstItemId: 70, occurrenceCount: 1 }],
        }] })
      }
      throw new Error(`Unexpected request: ${url}`)
    }))
    const { view } = await mountView()
    await flushPromises()
    await view.get('button[aria-label="In playlists (1)"]').trigger('click')
    await flushPromises()
    const addItem = [...document.body.querySelectorAll<HTMLElement>('.v-list-item')]
      .find(item => item.querySelector('.v-list-item-title')?.textContent === 'Add track to playlist')
    expect(addItem).toBeDefined()
    addItem!.click()
    await flushPromises()
    return view
  }

  async function mountView() {
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/playlists', name: 'playlists', component: { template: '<div />' } },
        { path: '/playlists/:id', name: 'playlist-detail', component: PlaylistDetailView },
        { path: '/tracks/:id', name: 'track-detail', component: { template: '<div />' } },
        { path: '/artists/:id', name: 'artist-detail', component: { template: '<div />' } },
        { path: '/albums/:id', name: 'album-detail', component: { template: '<div />' } },
      ],
    })
    await router.push('/playlists/7')
    await router.isReady()
    wrapper = mount(PlaylistDetailView, {
      attachTo: document.body,
      global: {
        plugins: [pinia, router, i18n, vuetify],
        stubs: { PlaylistFileExportDialog: true, PlaylistSimilarityOrderDialog: true },
      },
    })
    return { view: wrapper, router }
  }

  it('shows a loading skeleton until the requested playlist arrives, without a not-found flash', async () => {
    let resolveDetail!: (response: Response) => void
    const response = new Promise<Response>(resolve => { resolveDetail = resolve })
    vi.stubGlobal('fetch', vi.fn(() => response))
    const { view } = await mountView()

    expect(view.find('.v-skeleton-loader').exists()).toBe(true)
    expect(view.findComponent(EmptyCatalogState).exists()).toBe(false)
    resolveDetail(jsonResponse({ ...playlist, items: [] }))
    await flushPromises()

    expect(view.find('.v-skeleton-loader').exists()).toBe(false)
    expect(view.text()).toContain(playlist.name)
    expect(view.findComponent(EmptyCatalogState).props('title')).toBe(i18n.global.t('playlists.emptyPlaylistTitle'))
    expect(view.text()).not.toContain(i18n.global.t('playlists.notFoundTitle'))
  })

  it.each([404, 500])('shows not found only for a confirmed 404, not other request failures (%s)', async status => {
    let resolveDetail!: (response: Response) => void
    const response = new Promise<Response>(resolve => { resolveDetail = resolve })
    vi.stubGlobal('fetch', vi.fn(() => response))
    const { view } = await mountView()

    expect(view.find('.v-skeleton-loader').exists()).toBe(true)
    expect(view.findComponent(EmptyCatalogState).exists()).toBe(false)
    resolveDetail(jsonResponse({ detail: 'Unable to load playlist' }, status))
    await flushPromises()

    expect(view.find('.v-skeleton-loader').exists()).toBe(false)
    if (status === 404) {
      expect(view.getComponent(EmptyCatalogState).props('title')).toBe(i18n.global.t('playlists.notFoundTitle'))
      expect(view.find('.v-alert').exists()).toBe(false)
    } else {
      expect(view.findComponent(EmptyCatalogState).exists()).toBe(false)
      expect(view.get('.v-alert').text()).toContain('Unable to load playlist')
    }
  })

  it('keeps loading the new playlist when an older request finishes as not found', async () => {
    let resolveFirst!: (response: Response) => void
    let resolveSecond!: (response: Response) => void
    const first = new Promise<Response>(resolve => { resolveFirst = resolve })
    const second = new Promise<Response>(resolve => { resolveSecond = resolve })
    vi.stubGlobal('fetch', vi.fn((url: string) => url === '/api/playlists/7' ? first : second))
    const { view, router } = await mountView()
    await router.push('/playlists/8')
    resolveFirst(jsonResponse({ detail: 'Playlist not found' }, 404))
    await flushPromises()

    expect(view.find('.v-skeleton-loader').exists()).toBe(true)
    expect(view.findComponent(EmptyCatalogState).exists()).toBe(false)
    expect(view.find('.v-alert').exists()).toBe(false)
    resolveSecond(jsonResponse({ id: 8, name: 'Next playlist', trackCount: 0, items: [] }))
    await flushPromises()

    expect(view.find('.v-skeleton-loader').exists()).toBe(false)
    expect(view.text()).toContain('Next playlist')
    expect(view.text()).not.toContain(i18n.global.t('playlists.notFoundTitle'))
  })

  it('keeps the dialog and source tracks mounted while playlist choices load and adds to another playlist', async () => {
    let resolveChoices!: (response: Response) => void
    const choices = new Promise<Response>(resolve => { resolveChoices = resolve })
    const view = await openDialog(choices)
    const tracks = view.getComponent(PlaylistTrackList)
    const dialog = view.getComponent(AddToPlaylistDialog)

    expect(usePlaylistsStore().loading).toBe(true)
    expect(dialog.props('modelValue')).toBe(true)
    expect(dialog.props('tracks')).toEqual([playlist.items[0]!.track])

    resolveChoices(jsonResponse({ items: [
      { id: 7, name: playlist.name, trackCount: 1 },
      { id: 8, name: 'Destination playlist', trackCount: 0 },
    ] }))
    await flushPromises()

    expect(view.getComponent(PlaylistTrackList).element).toBe(tracks.element)
    expect(dialog.props('modelValue')).toBe(true)
    await dialog.getComponent({ name: 'VAutocomplete' }).setValue(8)
    const addTracks = vi.spyOn(usePlaylistsStore(), 'addTracks').mockResolvedValue([])
    const addButton = [...document.body.querySelectorAll<HTMLButtonElement>('button')]
      .find(button => button.textContent?.trim() === 'Add to playlist')
    expect(addButton).toBeDefined()
    addButton!.click()
    await flushPromises()

    expect(addTracks).toHaveBeenCalledWith(8, [11])
    expect(dialog.props('modelValue')).toBe(false)
  })

  it('keeps the dialog open with feedback if loading playlist choices fails', async () => {
    let rejectChoices!: (error: Error) => void
    const choices = new Promise<Response>((_resolve, reject) => { rejectChoices = reject })
    const view = await openDialog(choices)
    rejectChoices(new Error('Unable to load playlist choices'))
    await flushPromises()

    expect(view.getComponent(AddToPlaylistDialog).props('modelValue')).toBe(true)
    expect(view.findComponent(PlaylistTrackList).exists()).toBe(true)
    expect(document.body.textContent).toContain('Unable to load playlist choices')
  })

  it('keeps the selected destination and dialog open if adding the track fails', async () => {
    const view = await openDialog(Promise.resolve(jsonResponse({ items: [
      { id: 8, name: 'Destination playlist', trackCount: 0 },
    ] })))
    const dialog = view.getComponent(AddToPlaylistDialog)
    const select = dialog.getComponent({ name: 'VAutocomplete' })
    await select.setValue(8)
    const store = usePlaylistsStore()
    vi.spyOn(store, 'addTracks').mockImplementation(async () => {
      store.error = 'Unable to add track'
      throw new Error(store.error)
    })
    const addButton = [...document.body.querySelectorAll<HTMLButtonElement>('button')]
      .find(button => button.textContent?.trim() === 'Add to playlist')
    expect(addButton).toBeDefined()
    addButton!.click()
    await flushPromises()

    expect(dialog.props('modelValue')).toBe(true)
    expect(select.props('modelValue')).toBe(8)
    expect(view.findComponent(PlaylistTrackList).exists()).toBe(true)
    expect(document.body.textContent).toContain('Unable to add track')
  })
})

function jsonResponse(value: unknown, status = 200) {
  return new Response(JSON.stringify(value), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}
