import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import AlbumMetadataDialog from '@/components/AlbumMetadataDialog.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import type { AlbumDetail } from '@/stores/catalog'

const apiRequestMock = vi.hoisted(() => vi.fn())

vi.mock('@/api/client', () => ({
  apiRequest: apiRequestMock,
}))

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
  recordLabels: [{
    id: 9,
    name: 'Current label',
    catalogNumber: 'CAT-1',
    sources: [],
  }],
  technical: {
    fileTypes: ['MP3'],
    bitrateModes: ['CBR'],
    encoderSettings: [],
  },
  additionalTags: [],
  tracks: [{
    id: 11,
    title: 'Track title',
    streamUrl: '/api/tracks/11/stream',
    comment: 'Existing comment',
    album: { id: 42, title: 'Album title' },
    artists: [{ id: 7, name: 'Album artist' }],
    playStatistics: { playCount: 0 },
  }],
}

describe('AlbumMetadataDialog', () => {
  beforeAll(() => {
    vi.stubGlobal('ResizeObserver', class {
      observe() {}
      unobserve() {}
      disconnect() {}
    })
    vi.stubGlobal('visualViewport', {
      width: 1024,
      height: 768,
      offsetLeft: 0,
      offsetTop: 0,
      pageLeft: 0,
      pageTop: 0,
      scale: 1,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    })
  })

  beforeEach(() => {
    i18n.global.locale.value = 'en'
    apiRequestMock.mockReset()
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('initializes from the album and submits the preview payload', async () => {
    apiRequestMock.mockImplementation(async (path: string) => {
      if (path.endsWith('/record-label-suggestions')) return { suggestions: [], errors: [] }
      if (path.endsWith('/metadata/preview')) {
        return {
          albumId: 42,
          fingerprint: 'fingerprint',
          values: {},
          changes: [],
          trackArtistsWillChange: false,
          files: [],
          supportedFiles: 0,
          unsupportedFiles: 0,
        }
      }
      throw new Error(`Unexpected request: ${path}`)
    })
    mountDialog()
    await flushPromises()

    const reviewButton = findButton('Review changes')
    expect(reviewButton).toBeDefined()
    reviewButton?.click()
    await flushPromises()

    expect(apiRequestMock).toHaveBeenCalledWith('/albums/42/metadata/preview', {
      method: 'POST',
      body: JSON.stringify({
        albumTitle: 'Album title',
        albumArtist: 'Album artist',
        updateTrackArtists: true,
        releaseYear: 1999,
        totalDiscs: 1,
        genres: ['Rock'],
        recordLabels: [{ name: 'Current label', catalogNumber: 'CAT-1' }],
        removedTagKeys: [],
      }),
    })
  })

  it('reports when choosing a label suggestion resolves the provider release', async () => {
    const suggestion = {
      provider: 'musicbrainz',
      providerLabel: 'MusicBrainz',
      sourceReference: 'release-id',
      recordLabels: [{ name: 'Suggested label', catalogNumber: 'CAT-2' }],
      matchesCurrent: false,
      sourceConfirmed: false,
    }
    apiRequestMock
      .mockResolvedValueOnce({ suggestions: [suggestion], errors: [] })
      .mockResolvedValueOnce({
        suggestions: [{ ...suggestion, matchesCurrent: true, sourceConfirmed: true }],
        errors: [],
        musicianReleaseResolved: true,
      })
    const wrapper = mountDialog()
    await flushPromises()

    findButton('Use suggestion')?.click()
    await flushPromises()

    expect(apiRequestMock).toHaveBeenLastCalledWith('/albums/42/record-label-suggestions/select', {
      method: 'POST',
      body: JSON.stringify({ provider: 'musicbrainz', sourceReference: 'release-id' }),
    })
    expect(wrapper.emitted('releaseResolved')).toHaveLength(1)
  })
})

function mountDialog() {
  return mount(AlbumMetadataDialog, {
    attachTo: document.body,
    props: {
      album,
      modelValue: true,
    },
    global: {
      plugins: [createPinia(), i18n, vuetify],
    },
  })
}

function findButton(label: string) {
  return [...document.body.querySelectorAll<HTMLButtonElement>('button')]
    .find(button => button.textContent?.trim() === label)
}
