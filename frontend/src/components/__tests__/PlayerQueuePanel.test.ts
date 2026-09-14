import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import PlayerQueuePanel from '@/components/PlayerQueuePanel.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import type { Track } from '@/stores/catalog'

const tracks: Track[] = [
  {
    id: 1,
    title: 'First track',
    streamUrl: '/api/tracks/1/stream',
    durationMs: 60_000,
    album: { id: 10, title: 'Album' },
    artists: [{ id: 100, name: 'Artist' }],
    playStatistics: { playCount: 0 },
  },
  {
    id: 2,
    title: 'Second track',
    streamUrl: '/api/tracks/2/stream',
    durationMs: 90_000,
    album: { id: 10, title: 'Album' },
    artists: [{ id: 100, name: 'Artist' }],
    playStatistics: { playCount: 0 },
  },
  {
    id: 3,
    title: 'Third track',
    streamUrl: '/api/tracks/3/stream',
    durationMs: 120_000,
    album: { id: 11, title: 'Other album' },
    artists: [{ id: 101, name: 'Other artist' }],
    playStatistics: { playCount: 0 },
  },
]

describe('PlayerQueuePanel', () => {
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

  it('groups queue entries around the current track and forwards actions', async () => {
    const wrapper = await mountPanel()
    const rows = wrapper.findAll('.queue-item')

    expect(rows.map((row) => row.text())).toEqual([
      expect.stringContaining('Second track'),
      expect.stringContaining('Third track'),
      expect.stringContaining('First track'),
    ])
    expect(wrapper.text()).toContain('4:30')

    await rows[0]!.trigger('click')
    expect(wrapper.emitted('toggle')).toEqual([[1]])

    await wrapper.get('[aria-label="Create playlist from queue"]').trigger('click')
    expect(wrapper.emitted('createPlaylist')).toHaveLength(1)

    await wrapper.get('[aria-label="Clear queue"]').trigger('click')
    expect(wrapper.emitted('clear')).toHaveLength(1)
  })

  it('emits the source and target indices after a drag', async () => {
    const wrapper = await mountPanel()
    const rows = wrapper.findAll('.queue-item')
    const dataTransfer = {
      effectAllowed: '',
      setData: vi.fn(),
    }

    await rows[2]!.trigger('dragstart', { dataTransfer })
    await rows[1]!.trigger('drop')

    expect(dataTransfer.setData).toHaveBeenCalledWith('text/plain', '0')
    expect(wrapper.emitted('move')).toEqual([[0, 2]])
  })
})

async function mountPanel() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/albums/:id', name: 'album-detail', component: { template: '<div />' } },
      { path: '/artists/:id', name: 'artist-detail', component: { template: '<div />' } },
      { path: '/tracks/:id', name: 'track-detail', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()

  return mount(PlayerQueuePanel, {
    props: {
      currentIndex: 1,
      isPlaying: true,
      playbackScopeIcon: 'mdi-album-multiple',
      playbackScopeLabels: ['All library roots'],
      playbackScopeMode: 'Continuous · Random albums',
      queue: tracks,
    },
    global: {
      plugins: [router, i18n, vuetify],
    },
  })
}
