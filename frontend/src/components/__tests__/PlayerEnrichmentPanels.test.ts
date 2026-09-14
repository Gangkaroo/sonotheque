import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import PlayerInformationPanel from '@/components/PlayerInformationPanel.vue'
import PlayerLyricsPanel from '@/components/PlayerLyricsPanel.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'

describe('player enrichment panels', () => {
  beforeAll(() => {
    vi.stubGlobal('ResizeObserver', class {
      observe() {}
      unobserve() {}
      disconnect() {}
    })
  })

  beforeEach(() => {
    i18n.global.locale.value = 'en'
    vi.restoreAllMocks()
  })

  it('loads information only when the information pane becomes active', async () => {
    const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(
      jsonResponse(emptyTrackInformation()),
    ))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mount(PlayerInformationPanel, {
      props: {
        active: false,
        albumTitle: 'Album',
        artistName: 'Artist',
        trackId: 7,
      },
      global: { plugins: [createPinia(), i18n, vuetify] },
    })

    expect(fetchMock).not.toHaveBeenCalled()

    await wrapper.setProps({ active: true })
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock.mock.calls.map(([url]) => url)).toEqual(expect.arrayContaining([
      '/api/enrichment/tracks/7/information?language=en',
      '/api/enrichment/tracks/7/identity',
    ]))
  })

  it('renders synchronized lyrics and emits a seek request', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({
      status: 'ready',
      provider: 'lrclib',
      cached: false,
      stale: false,
      data: {
        synchronizedLyrics: '[00:00.00]First line\n[00:12.50]Second line',
        instrumental: false,
        attribution: { provider: 'lrclib', label: 'LRCLIB' },
      },
    })))
    const wrapper = mount(PlayerLyricsPanel, {
      props: {
        active: true,
        currentTime: 0,
        isPlaying: false,
        trackId: 7,
      },
      global: { plugins: [createPinia(), i18n, vuetify] },
    })
    await flushPromises()

    const lines = wrapper.findAll('[data-lyric-index]')
    expect(lines).toHaveLength(2)
    expect(lines[0]!.attributes('aria-current')).toBe('true')

    await lines[1]!.trigger('click')
    expect(wrapper.emitted('seek')).toEqual([[12.5]])
  })
})

function emptyTrackInformation() {
  return {
    artist: { status: 'not_found', cached: false, stale: false },
    album: { status: 'not_found', cached: false, stale: false },
  }
}

function jsonResponse(payload: unknown) {
  return new Response(JSON.stringify(payload), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}
