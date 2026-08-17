import { createPinia } from 'pinia'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { VApp, VMain } from 'vuetify/components'

import AppPlayer from '@/components/AppPlayer.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import type { Track } from '@/stores/catalog'
import { usePlayerStore } from '@/stores/player'

const tracks: Track[] = [
  {
    id: 1,
    title: 'First track',
    streamUrl: '/api/tracks/1/stream',
    durationMs: 120_000,
    album: { id: 10, title: 'First album' },
    artists: [{ id: 100, name: 'Artist' }],
    playStatistics: { playCount: 0 },
  },
  {
    id: 2,
    title: 'Second track',
    streamUrl: '/api/tracks/2/stream',
    durationMs: 120_000,
    album: { id: 10, title: 'First album' },
    artists: [{ id: 100, name: 'Artist' }],
    playStatistics: { playCount: 0 },
  },
]

describe('AppPlayer playback reporting', () => {
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
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue()
    vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => undefined)
    vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => undefined)
  })

  afterEach(() => {
    vi.restoreAllMocks()
    document.body.innerHTML = ''
  })

  it('reports a play from the final progress observed by the ended event', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
      counted: true,
      statistics: { playCount: 1 },
    }, 201))
    vi.stubGlobal('fetch', fetchMock)
    const { wrapper } = await mountPlayer()
    const media = currentMedia(wrapper)

    setMediaState(media, 0, 120)
    await media.trigger('playing')
    setMediaState(media, 59, 120)
    await media.trigger('timeupdate')
    expect(fetchMock).not.toHaveBeenCalled()

    setMediaState(media, 61, 120)
    await media.trigger('ended')
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock).toHaveBeenCalledWith('/api/tracks/1/plays', expect.objectContaining({
      method: 'POST',
      body: expect.stringContaining('"listenedMs":61000'),
    }))
    wrapper.unmount()
  })

  it('does not apply a late retry threshold to the next track', async () => {
    let resolveFirstRequest!: (response: Response) => void
    const firstRequest = new Promise<Response>((resolve) => {
      resolveFirstRequest = resolve
    })
    const fetchMock = vi.fn()
      .mockReturnValueOnce(firstRequest)
      .mockResolvedValueOnce(jsonResponse({
        counted: true,
        statistics: { playCount: 1 },
      }, 201))
    vi.stubGlobal('fetch', fetchMock)
    const { player, wrapper } = await mountPlayer()
    let media = currentMedia(wrapper)

    setMediaState(media, 0, 120)
    await media.trigger('playing')
    setMediaState(media, 60, 120)
    await media.trigger('timeupdate')
    expect(fetchMock).toHaveBeenCalledTimes(1)

    setMediaState(media, 120, 120)
    await media.trigger('ended')
    expect(player.currentTrack?.id).toBe(2)

    resolveFirstRequest(jsonResponse({
      counted: false,
      statistics: { playCount: 0 },
    }, 202))
    await flushPromises()

    media = currentMedia(wrapper)
    setMediaState(media, 0, 120)
    await media.trigger('playing')
    setMediaState(media, 60, 120)
    await media.trigger('timeupdate')
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/tracks/2/plays')
    wrapper.unmount()
  })

  it('preserves and restores the playback position while media reloads after a refresh', async () => {
    localStorage.setItem('sonotheque.player', JSON.stringify({
      queue: tracks,
      currentIndex: 0,
      isPlaying: true,
      playbackPosition: 73,
      playbackSessionKey: 'restored-session',
      playbackStartedAt: new Date().toISOString(),
      visualizerEnabled: false,
    }))
    const { player, wrapper } = await mountPlayer(false)
    const media = currentMedia(wrapper)

    setMediaState(media, 0, 120)
    await media.trigger('loadstart')
    expect(player.playbackPosition).toBe(73)

    window.dispatchEvent(new Event('beforeunload'))
    expect(player.playbackPosition).toBe(73)

    await media.trigger('loadedmetadata')
    expect(media.element.currentTime).toBe(73)
    expect(player.playbackPosition).toBe(73)
    wrapper.unmount()
  })

  it('keeps one visualizer instance while the player is collapsed and expanded', async () => {
    const { wrapper } = await mountPlayer()
    const visualizer = wrapper.get('music-visualizer-stub').element

    await wrapper.get('[aria-label="Collapse player"]').trigger('click')
    expect(wrapper.get('music-visualizer-stub').element).toBe(visualizer)

    await wrapper.get('[aria-label="Expand player"]').trigger('click')
    expect(wrapper.get('music-visualizer-stub').element).toBe(visualizer)

    wrapper.unmount()
  })
})

async function mountPlayer(startPlayback = true) {
  const pinia = createPinia()
  const player = usePlayerStore(pinia)
  player.setVisualizerEnabled(false)
  if (startPlayback) player.playTrack(tracks[0]!, tracks, 'album')
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
  i18n.global.locale.value = 'en'

  const host = defineComponent({
    components: { AppPlayer, VApp, VMain },
    template: '<VApp><VMain /><AppPlayer /></VApp>',
  })
  const wrapper = mount(host, {
    attachTo: document.body,
    global: {
      plugins: [pinia, router, i18n, vuetify],
      stubs: { MusicVisualizer: true },
    },
  })
  await wrapper.vm.$nextTick()

  return { player, wrapper }
}

function currentMedia(wrapper: VueWrapper) {
  const media = wrapper.find('audio')
  expect(media.exists()).toBe(true)

  return media
}

function setMediaState(media: ReturnType<typeof currentMedia>, currentTime: number, duration: number) {
  Object.defineProperties(media.element, {
    currentTime: { configurable: true, value: currentTime, writable: true },
    duration: { configurable: true, value: duration },
    paused: { configurable: true, value: false },
    seeking: { configurable: true, value: false },
  })
}

function jsonResponse(payload: unknown, status = 200) {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}
