import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import AlbumTrackList from '@/components/AlbumTrackList.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import type { Track } from '@/stores/catalog'
import { usePlayerStore } from '@/stores/player'
import { usePlaylistsStore } from '@/stores/playlists'

const tracks: Track[] = [
  {
    id: 11,
    title: 'Opening track',
    streamUrl: '/api/tracks/11/stream',
    durationMs: 180_000,
    trackNumber: 1,
    album: { id: 5, title: 'Test album' },
    artists: [{ id: 3, name: 'Test artist' }],
    playStatistics: { playCount: 2 },
  },
]

describe('AlbumTrackList', () => {
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

  it('loads playlist memberships and plays a track in album context', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const player = usePlayerStore()
    const playlists = usePlaylistsStore()
    const loadMemberships = vi.spyOn(playlists, 'loadMemberships').mockResolvedValue()
    const playTrack = vi.spyOn(player, 'playTrack').mockImplementation(() => {})
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'home', component: { template: '<div />' } },
        { path: '/tracks/:id', name: 'track-detail', component: { template: '<div />' } },
        { path: '/artists/:id', name: 'artist-detail', component: { template: '<div />' } },
      ],
    })
    await router.push('/')
    await router.isReady()
    const wrapper = mount(AlbumTrackList, {
      props: { albumId: 5, tracks },
      global: { plugins: [pinia, router, i18n, vuetify] },
    })

    await wrapper.get('button[aria-label="Play"]').trigger('click')

    expect(loadMemberships).toHaveBeenCalledWith([11])
    expect(playTrack).toHaveBeenCalledWith(tracks[0], tracks, 'album')
  })
})
