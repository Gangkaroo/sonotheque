import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { usePlaylistsStore } from '@/stores/playlists'

describe('playlists store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads folders and playlists', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      if (url === '/api/playlist-folders') {
        return jsonResponse({ items: [{ id: 1, name: 'Folders', playlistCount: 2 }] })
      }

      if (url === '/api/playlists') {
        return jsonResponse({ items: [{ id: 10, name: 'Mix', folder: { id: 1, name: 'Folders' }, trackCount: 3 }] })
      }

      throw new Error(`Unexpected request: ${url}`)
    }))

    const store = usePlaylistsStore()
    await store.loadAll()

    expect(store.folders[0]?.name).toBe('Folders')
    expect(store.playlists[0]?.name).toBe('Mix')
    expect(store.loading).toBe(false)
  })

  it('orders playlists by folder and then name with unfiled playlists last', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      if (url === '/api/playlist-folders') return jsonResponse({ items: [] })
      if (url === '/api/playlists') {
        return jsonResponse({
          items: [
            { id: 1, name: 'Loose', folder: null, trackCount: 0 },
            { id: 2, name: 'Only', folder: { id: 2, name: 'Zebra' }, trackCount: 0 },
            { id: 3, name: 'Second', folder: { id: 1, name: 'Archive' }, trackCount: 0 },
            { id: 4, name: 'First', folder: { id: 1, name: 'Archive' }, trackCount: 0 },
          ],
        })
      }

      throw new Error(`Unexpected request: ${url}`)
    }))

    const store = usePlaylistsStore()
    await store.loadAll()

    expect(store.playlists.map((playlist) => playlist.name)).toEqual(['First', 'Second', 'Only', 'Loose'])
  })

  it('creates and deletes folders and playlists', async () => {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/playlist-folders' && init?.method === 'POST') {
        return jsonResponse({ id: 1, name: 'Trips', playlistCount: 0 }, 201)
      }

      if (url === '/api/playlists' && init?.method === 'POST') {
        return jsonResponse({ id: 10, name: 'Drive', folder: { id: 1, name: 'Trips' }, trackCount: 0 }, 201)
      }

      if (url === '/api/playlists/10' && init?.method === 'DELETE') {
        return emptyResponse()
      }

      if (url === '/api/playlist-folders/1' && init?.method === 'DELETE') {
        return emptyResponse()
      }

      throw new Error(`Unexpected request: ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)

    const store = usePlaylistsStore()
    await store.createFolder('Trips')
    await store.createPlaylist({ name: 'Drive', folderId: 1 })

    expect(store.folders[0]).toMatchObject({ name: 'Trips', playlistCount: 1 })
    expect(store.playlists[0]).toMatchObject({ name: 'Drive' })

    await store.deletePlaylist(10)
    await store.deleteFolder(1)

    expect(store.playlists).toEqual([])
    expect(store.folders).toEqual([])
  })

  it('creates a playlist with its initial tracks in one request', async () => {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/playlists' && init?.method === 'POST') {
        expect(JSON.parse(String(init.body))).toEqual({
          name: 'New mix',
          description: null,
          folderId: null,
          trackIds: [1, 2],
        })

        return jsonResponse({ id: 11, name: 'New mix', folder: null, trackCount: 2 }, 201)
      }

      throw new Error(`Unexpected request: ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)

    const store = usePlaylistsStore()
    const playlist = await store.createPlaylist({
      name: 'New mix',
      description: null,
      folderId: null,
      trackIds: [1, 2],
    })

    expect(playlist.trackCount).toBe(2)
    expect(store.playlists).toEqual([playlist])
  })

  it('updates folders and refreshes playlist folder names', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/playlist-folders/1' && init?.method === 'PATCH') {
        expect(JSON.parse(String(init.body))).toEqual({ name: 'Road trips' })

        return jsonResponse({ id: 1, name: 'Road trips', playlistCount: 1 })
      }

      throw new Error(`Unexpected request: ${url}`)
    }))

    const store = usePlaylistsStore()
    store.folders = [{ id: 1, name: 'Trips', playlistCount: 1 }]
    store.playlists = [{ id: 10, name: 'Drive', folder: { id: 1, name: 'Trips' }, trackCount: 0 }]

    await store.updateFolder(1, { name: 'Road trips' })

    expect(store.folders[0]?.name).toBe('Road trips')
    expect(store.playlists[0]?.folder?.name).toBe('Road trips')
  })

  it('updates playlists and adjusts folder counts when moved', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/playlists/10' && init?.method === 'PATCH') {
        expect(JSON.parse(String(init.body))).toEqual({ name: 'Drive', description: 'Updated', folderId: 2 })

        return jsonResponse({
          id: 10,
          name: 'Drive',
          description: 'Updated',
          folder: { id: 2, name: 'Favorites' },
          trackCount: 3,
        })
      }

      throw new Error(`Unexpected request: ${url}`)
    }))

    const store = usePlaylistsStore()
    store.folders = [
      { id: 1, name: 'Trips', playlistCount: 1 },
      { id: 2, name: 'Favorites', playlistCount: 0 },
    ]
    store.playlists = [{ id: 10, name: 'Drive', description: null, folder: { id: 1, name: 'Trips' }, trackCount: 3 }]

    await store.updatePlaylist(10, { name: 'Drive', description: 'Updated', folderId: 2 })

    expect(store.playlists[0]).toMatchObject({ description: 'Updated', folder: { id: 2, name: 'Favorites' } })
    expect(store.folders.map((folder) => [folder.id, folder.playlistCount])).toEqual([[1, 0], [2, 1]])
  })

  it('adds tracks to a playlist and updates counts', async () => {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/playlists/10/tracks' && init?.method === 'POST') {
        expect(JSON.parse(String(init.body))).toEqual({ trackIds: [1, 2] })

        return jsonResponse({
          items: [
            { id: 100, position: 1, track: trackResponse(1) },
            { id: 101, position: 2, track: trackResponse(2) },
          ],
        }, 201)
      }

      throw new Error(`Unexpected request: ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)

    const store = usePlaylistsStore()
    store.playlists = [{ id: 10, name: 'Mix', trackCount: 0 }]

    await store.addTracks(10, [1, 2])

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(store.playlists[0]?.trackCount).toBe(2)
  })

  it('removes a playlist item and updates current playlist state', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/playlists/10/items/100' && init?.method === 'DELETE') {
        return emptyResponse()
      }

      throw new Error(`Unexpected request: ${url}`)
    }))

    const store = usePlaylistsStore()
    store.playlists = [{ id: 10, name: 'Mix', trackCount: 1 }]
    store.current = {
      id: 10,
      name: 'Mix',
      trackCount: 1,
      items: [{ id: 100, position: 0, track: trackResponse(1) }],
    }

    await store.removeItem(10, 100)

    expect(store.playlists[0]?.trackCount).toBe(0)
    expect(store.current?.items).toEqual([])
    expect(store.current?.trackCount).toBe(0)
  })

  it('removes multiple playlist items and keeps returned order', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/playlists/10/items' && init?.method === 'DELETE') {
        expect(JSON.parse(String(init.body))).toEqual({ items: [100, 101] })

        return jsonResponse({
          id: 10,
          name: 'Mix',
          trackCount: 1,
          items: [{ id: 102, position: 0, track: trackResponse(3) }],
        })
      }

      throw new Error(`Unexpected request: ${url}`)
    }))

    const store = usePlaylistsStore()
    store.playlists = [{ id: 10, name: 'Mix', trackCount: 3 }]
    store.current = {
      id: 10,
      name: 'Mix',
      trackCount: 3,
      items: [
        { id: 100, position: 0, track: trackResponse(1) },
        { id: 101, position: 1, track: trackResponse(2) },
        { id: 102, position: 2, track: trackResponse(3) },
      ],
    }

    await store.removeItems(10, [100, 101])

    expect(store.playlists[0]?.trackCount).toBe(1)
    expect(store.current?.items.map((item) => item.id)).toEqual([102])
    expect(store.current?.trackCount).toBe(1)
  })

  it('reorders playlist items', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/playlists/10/items/reorder' && init?.method === 'PATCH') {
        expect(JSON.parse(String(init.body))).toEqual({ items: [101, 100] })

        return jsonResponse({
          id: 10,
          name: 'Mix',
          trackCount: 2,
          items: [
            { id: 101, position: 0, track: trackResponse(2) },
            { id: 100, position: 1, track: trackResponse(1) },
          ],
        })
      }

      throw new Error(`Unexpected request: ${url}`)
    }))

    const store = usePlaylistsStore()
    store.current = {
      id: 10,
      name: 'Mix',
      trackCount: 2,
      items: [
        { id: 100, position: 0, track: trackResponse(1) },
        { id: 101, position: 1, track: trackResponse(2) },
      ],
    }

    await store.reorderItems(10, [101, 100])

    expect(store.current?.items.map((item) => item.id)).toEqual([101, 100])
  })
})

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function emptyResponse() {
  return new Response(null, { status: 204 })
}

function trackResponse(id: number) {
  return {
    id,
    title: `Track ${id}`,
    streamUrl: `/api/tracks/${id}/stream`,
    album: null,
    artists: [],
  }
}
