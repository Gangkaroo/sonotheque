import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useFavoritesStore } from '@/stores/favorites'

describe('favorites store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads favorite ids and toggles track and album favorites', async () => {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/favorites') {
        return new Response(JSON.stringify({ tracks: [1], albums: [5] }), { status: 200 })
      }
      if (url === '/api/favorites/tracks/1' && init?.method === 'DELETE') {
        return new Response(null, { status: 204 })
      }
      if (url === '/api/favorites/tracks/2' && init?.method === 'POST') {
        return new Response(JSON.stringify({ trackId: 2 }), { status: 201 })
      }
      if (url === '/api/favorites/albums/5' && init?.method === 'DELETE') {
        return new Response(null, { status: 204 })
      }
      throw new Error(`Unexpected request ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)

    const favorites = useFavoritesStore()
    await favorites.loadIds()

    expect(favorites.isTrackFavorite(1)).toBe(true)
    expect(favorites.isAlbumFavorite(5)).toBe(true)

    await favorites.toggleTrack(1)
    await favorites.toggleTrack(2)
    await favorites.toggleAlbum(5)

    expect(favorites.isTrackFavorite(1)).toBe(false)
    expect(favorites.isTrackFavorite(2)).toBe(true)
    expect(favorites.isAlbumFavorite(5)).toBe(false)
  })

  it('loads favorite album and track pages', async () => {
    const fetchMock = vi.fn(async (url: string) => {
      if (url === '/api/favorites/albums?page=2') {
        return new Response(JSON.stringify({
          items: [{ id: 5, title: 'Album', primaryArtist: null, trackCount: 1 }],
          total: 1,
          page: 2,
          perPage: 24,
          lastPage: 2,
        }), { status: 200 })
      }
      if (url === '/api/favorites/tracks?page=3') {
        return new Response(JSON.stringify({
          items: [{ id: 9, title: 'Track', streamUrl: '/api/tracks/9/stream', album: null, artists: [] }],
          total: 1,
          page: 3,
          perPage: 50,
          lastPage: 3,
        }), { status: 200 })
      }
      throw new Error(`Unexpected request ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)

    const favorites = useFavoritesStore()
    await favorites.loadAlbums(2)
    await favorites.loadTracks(3)

    expect(favorites.albums.items[0]?.title).toBe('Album')
    expect(favorites.tracks.items[0]?.title).toBe('Track')
  })
})
