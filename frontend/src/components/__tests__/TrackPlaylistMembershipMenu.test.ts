import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { nextTick } from 'vue'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import TrackPlaylistMembershipMenu from '@/components/TrackPlaylistMembershipMenu.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import { usePlaylistsStore } from '@/stores/playlists'

describe('TrackPlaylistMembershipMenu', () => {
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

  async function mountMenu(memberships: ReturnType<typeof usePlaylistsStore>['trackMemberships'][number] = []) {
    const pinia = createPinia()
    setActivePinia(pinia)
    usePlaylistsStore().trackMemberships = { 7: memberships }

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

    return mount(TrackPlaylistMembershipMenu, {
      attachTo: document.body,
      props: {
        iconOnly: true,
        trackId: 7,
      },
      global: {
        plugins: [pinia, router, i18n, vuetify],
      },
    })
  }

  it('shows the direct add action when the track has no playlist memberships', async () => {
    const wrapper = await mountMenu()

    expect(wrapper.get('button').attributes('aria-label')).toBe('Add track to playlist')
    await wrapper.get('button').trigger('click')

    expect(wrapper.emitted('addToPlaylist')).toHaveLength(1)
  })

  it('moves the add action into the membership menu', async () => {
    const wrapper = await mountMenu([{
      id: 3,
      name: 'Road trip',
      folder: null,
      firstItemId: 12,
      occurrenceCount: 1,
    }])

    expect(wrapper.get('button').attributes('aria-label')).toBe('In playlists (1)')
    await wrapper.get('button').trigger('click')
    await nextTick()

    const addItem = [...document.body.querySelectorAll<HTMLElement>('.v-list-item')]
      .find((item) => item.textContent?.includes('Add track to playlist'))

    expect(document.body.textContent).toContain('Road trip')
    expect(addItem).toBeDefined()
    addItem?.click()
    await nextTick()

    expect(wrapper.emitted('addToPlaylist')).toHaveLength(1)
  })

  it('removes the track from a selected playlist through the membership action', async () => {
    const wrapper = await mountMenu([{
      id: 3,
      name: 'Road trip',
      folder: null,
      firstItemId: 12,
      occurrenceCount: 2,
    }])
    const removeTrack = vi.spyOn(usePlaylistsStore(), 'removeTrackFromPlaylist')
      .mockResolvedValue(2)

    await wrapper.get('button').trigger('click')
    await nextTick()
    const removeButton = document.body.querySelector<HTMLButtonElement>(
      'button[aria-label="Remove from Road trip"]',
    )

    expect(removeButton).not.toBeNull()
    removeButton?.click()
    await flushPromises()

    expect(removeTrack).toHaveBeenCalledWith(3, 7)
  })
})
