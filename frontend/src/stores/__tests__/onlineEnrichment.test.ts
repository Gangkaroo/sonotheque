import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useOnlineEnrichmentStore } from '@/stores/onlineEnrichment'

describe('online enrichment store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads attributed information, identity, and lyrics independently', async () => {
    const information = {
      artist: {
        status: 'ready',
        cached: false,
        stale: false,
        data: {
          name: 'Artist',
          biography: 'Biography',
          tags: ['Rock'],
          attribution: { provider: 'lastfm', label: 'Last.fm', sourceUrl: 'https://last.fm/artist' },
        },
      },
      album: { status: 'not_found', cached: false, stale: false, data: null },
    }
    const lyrics = {
      status: 'ready',
      cached: false,
      stale: false,
      data: {
        plainLyrics: 'Lyrics',
        synchronizedLyrics: null,
        instrumental: false,
        attribution: { provider: 'lrclib', label: 'LRCLIB', sourceUrl: 'https://lrclib.net/lyrics' },
      },
    }
    const identity = {
      artist: {
        status: 'ready',
        cached: false,
        stale: false,
        data: {
          name: 'Artist',
          country: 'DE',
          tags: [],
          matchMethod: 'tag',
          matchConfidence: 100,
          attribution: { provider: 'musicbrainz', label: 'MusicBrainz' },
        },
      },
      album: { status: 'not_found', cached: false, stale: false, data: null },
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(information), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(identity), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(lyrics), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const store = useOnlineEnrichmentStore()

    await store.loadInformation(12, 'de')
    await store.loadIdentity(12)
    await store.loadLyrics(12)

    expect(store.information?.artist.data?.biography).toBe('Biography')
    expect(store.identity?.artist.data?.country).toBe('DE')
    expect(store.lyrics?.data?.plainLyrics).toBe('Lyrics')
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/enrichment/tracks/12/information?language=de',
      expect.any(Object),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/enrichment/tracks/12/identity',
      expect.any(Object),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      3,
      '/api/enrichment/tracks/12/lyrics',
      expect.any(Object),
    )
  })
})
