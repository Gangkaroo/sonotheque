import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import TrackMetadataDialog from '@/components/TrackMetadataDialog.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'
import type { TrackDetail } from '@/stores/catalog'

const apiRequestMock = vi.hoisted(() => vi.fn())

vi.mock('@/api/client', () => ({
  apiRequest: apiRequestMock,
}))

const track: TrackDetail = {
  id: 11,
  title: 'Track title',
  streamUrl: '/api/tracks/11/stream',
  durationMs: 180_000,
  trackNumber: 2,
  discNumber: 1,
  year: 1999,
  comment: 'Existing comment',
  album: { id: 42, title: 'Album title' },
  artists: [{ id: 7, name: 'Track artist' }],
  composers: ['Composer'],
  performers: ['Performer'],
  genres: [{ id: 8, name: 'Rock' }],
  playStatistics: { playCount: 0 },
  mediaFile: {
    id: 12,
    libraryRoot: { id: 3, name: 'Archive' },
    relativePath: 'Artist/Album/track.mp3',
    fileSize: 1234,
    additionalTags: [],
  },
}

describe('TrackMetadataDialog', () => {
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
    vi.useRealTimers()
    document.body.innerHTML = ''
  })

  it('initializes from the track and submits the preview payload', async () => {
    apiRequestMock.mockResolvedValue(previewResult())
    mountDialog()
    await flushPromises()

    findButton('Review changes')?.click()
    await flushPromises()

    expect(apiRequestMock).toHaveBeenCalledWith('/tracks/11/metadata/preview', {
      method: 'POST',
      body: JSON.stringify({
        title: 'Track title',
        artistNames: ['Track artist'],
        composers: ['Composer'],
        performers: ['Performer'],
        genres: ['Rock'],
        comment: 'Existing comment',
        trackNumber: 2,
        discNumber: 1,
        year: 1999,
        removedTagKeys: [],
      }),
    })
  })

  it('reports completion after the background metadata job finishes', async () => {
    apiRequestMock
      .mockResolvedValueOnce(previewResult())
      .mockResolvedValueOnce({ id: 70, trackId: 11, status: 'pending' })
      .mockResolvedValueOnce({ id: 70, trackId: 11, status: 'completed' })
    const wrapper = mountDialog()
    await flushPromises()

    findButton('Review changes')?.click()
    await flushPromises()
    vi.useFakeTimers()
    findButton('Apply changes')?.click()
    await flushPromises()

    await vi.advanceTimersByTimeAsync(1000)
    await flushPromises()

    expect(apiRequestMock).toHaveBeenLastCalledWith('/metadata-edits/70', {
      signal: expect.any(AbortSignal),
    })
    expect(wrapper.emitted('completed')).toHaveLength(1)
    expect(wrapper.emitted('update:modelValue')).toContainEqual([false])
  })
})

function previewResult() {
  return {
    trackId: 11,
    file: 'Artist/Album/track.mp3',
    format: 'mp3',
    supported: true,
    fingerprint: 'fingerprint',
    values: {},
    changes: [{ field: 'title', current: 'Old title', proposed: 'Track title' }],
  }
}

function mountDialog() {
  return mount(TrackMetadataDialog, {
    attachTo: document.body,
    props: {
      modelValue: true,
      track,
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
