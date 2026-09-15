import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import AudioSimilarityResults from '@/components/AudioSimilarityResults.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import type { AudioSimilarityEvaluation } from '@/stores/audioIntelligenceSettings'
import { usePlayerStore } from '@/stores/player'

const result: AudioSimilarityEvaluation = {
  profile: {
    analyzerName: 'Essentia',
    analyzerVersion: '1',
    analyzerLicense: 'AGPL',
    modelName: 'Test model',
    modelVersion: '1',
    modelChecksum: 'checksum',
    modelLicense: 'CC BY',
    embeddingDimensions: 8,
  },
  source: {
    id: 1,
    title: 'Source track',
    streamUrl: '/api/tracks/1/stream',
    durationMs: 180_000,
    label: 'Artist - Source track',
    artistName: 'Artist',
    artists: [{ id: 10, name: 'Artist' }],
    albumId: 20,
    albumTitle: 'Source album',
    albumOriginalReleaseYear: 2000,
    albumArtworkThumbnailUrl: null,
    year: 2000,
    discNumber: 1,
    trackNumber: 1,
    libraryRootId: 30,
    libraryRootName: 'Music',
    genreIds: [],
    features: { bpm: 120 },
  },
  candidateCount: 1,
  calculationMs: 5,
  filters: { excludeSameAlbum: true, excludeSameArtist: true },
  ranking: {
    method: 'embedding',
    candidatePoolSize: 1,
    preferences: { enabled: false, tempoInfluence: 0, keyInfluence: 0, intensityInfluence: 0 },
    personalization: {
      enabled: false,
      applied: false,
      adjustments: { tempo: 0, key: 0, intensity: 0 },
      trainedAt: null,
    },
  },
  matches: [{
    id: 2,
    title: 'Matching track',
    streamUrl: '/api/tracks/2/stream',
    durationMs: 190_000,
    label: 'Other artist - Matching track',
    artistName: 'Other artist',
    artists: [{ id: 11, name: 'Other artist' }],
    albumId: 21,
    albumTitle: 'Matching album',
    albumOriginalReleaseYear: 2001,
    albumArtworkThumbnailUrl: null,
    year: 2001,
    discNumber: 1,
    trackNumber: 1,
    libraryRootId: 30,
    libraryRootName: 'Music',
    genreIds: [],
    features: { bpm: 121 },
    similarity: 0.9,
    rankingScore: 0.9,
    featureCompatibility: { tempo: null, key: null, intensity: null },
    feedback: null,
  }],
}

describe('AudioSimilarityResults', () => {
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

  it('plays the source and matches as one queue', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const player = usePlayerStore()
    const playTrack = vi.spyOn(player, 'playTrack').mockImplementation(() => {})
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'home', component: { template: '<div />' } },
        { path: '/tracks/:id', name: 'track-detail', component: { template: '<div />' } },
        { path: '/artists/:id', name: 'artist-detail', component: { template: '<div />' } },
        { path: '/albums/:id', name: 'album-detail', component: { template: '<div />' } },
      ],
    })
    await router.push('/')
    await router.isReady()
    const wrapper = mount(AudioSimilarityResults, {
      props: {
        currentReviewProgress: null,
        excludeSameAlbum: true,
        excludeSameArtist: true,
        result,
      },
      global: { plugins: [pinia, router, i18n, vuetify] },
    })

    await [...wrapper.element.querySelectorAll<HTMLButtonElement>('button')]
      .find(button => button.textContent?.trim() === 'Play all')
      ?.click()

    expect(playTrack).toHaveBeenCalledOnce()
    expect(playTrack.mock.calls[0]?.[0].id).toBe(1)
    expect(playTrack.mock.calls[0]?.[1].map(track => track.id)).toEqual([1, 2])
  })
})
