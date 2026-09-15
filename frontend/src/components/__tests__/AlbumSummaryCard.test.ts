import { createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import AlbumSummaryCard from '@/components/AlbumSummaryCard.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import type { AlbumDetail } from '@/stores/catalog'

const album: AlbumDetail = {
  id: 42,
  title: 'Album title',
  originalReleaseYear: 1999,
  primaryArtist: { id: 7, name: 'Album artist' },
  trackCount: 1,
  discTotal: 1,
  artworkThumbnailUrl: null,
  artworkUrl: null,
  personalMetadata: { hasPhysicalCopy: false, notes: null, ownedCopies: [] },
  libraryRoot: { id: 3, name: 'Archive' },
  genres: [{ id: 8, name: 'Rock' }],
  recordLabels: [],
  technical: {
    fileTypes: ['MP3'],
    bitrateMinimum: 320_000,
    bitrateMaximum: 320_000,
    bitrateModes: ['CBR'],
    encoderSettings: [],
  },
  additionalTags: [],
  tracks: [{
    id: 11,
    title: 'Track title',
    streamUrl: '/api/tracks/11/stream',
    durationMs: 180_000,
    album: { id: 42, title: 'Album title' },
    artists: [{ id: 7, name: 'Album artist' }],
    playStatistics: { playCount: 0 },
  }],
}

describe('AlbumSummaryCard', () => {
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

  it('renders album summary information and forwards playback', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'home', component: { template: '<div />' } },
        { path: '/albums', name: 'albums', component: { template: '<div />' } },
        { path: '/artists/:id', name: 'artist-detail', component: { template: '<div />' } },
      ],
    })
    await router.push('/')
    await router.isReady()
    const wrapper = mount(AlbumSummaryCard, {
      props: { album, artistCountry: 'Germany' },
      global: { plugins: [createPinia(), router, i18n, vuetify] },
    })

    expect(wrapper.text()).toContain('Album title')
    expect(wrapper.text()).toContain('CBR · 320 kbps')
    expect(wrapper.text()).toContain('Playing time: 3:00')

    await findButton(wrapper.element, 'Play')?.click()

    expect(wrapper.emitted('play')).toHaveLength(1)
  })
})

function findButton(element: Element, label: string) {
  return [...element.querySelectorAll<HTMLButtonElement>('button')]
    .find(button => button.textContent?.trim() === label)
}
