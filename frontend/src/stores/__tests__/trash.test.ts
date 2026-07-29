import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useTrashStore } from '@/stores/trash'

describe('trash store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
    window.sessionStorage.clear()
  })

  it('loads and permanently deletes unavailable tracks', async () => {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/trash/tracks?page=2&search=Bjoerk') {
        return new Response(JSON.stringify({
          items: [{
            id: 9,
            title: 'Human Behaviour',
            album: { id: 3, title: 'Debut' },
            artists: [{ id: 2, name: 'Bjoerk' }],
            libraryRoot: { id: 1, name: 'Archive' },
            relativePath: 'Bjoerk/Debut/Human Behaviour.mp3',
            markedMissingAt: '2026-07-28T10:00:00Z',
            playlistCount: 1,
            playCount: 4,
          }],
          total: 51,
          page: 2,
          perPage: 50,
          lastPage: 2,
        }), { status: 200 })
      }
      if (url === '/api/trash/tracks/9' && init?.method === 'DELETE') {
        return new Response(null, { status: 204 })
      }
      if (url === '/api/trash/tracks' && init?.method === 'DELETE') {
        expect(JSON.parse(String(init.body))).toEqual({ trackIds: [9, 10] })
        return new Response(JSON.stringify({ deleted: 2 }), { status: 200 })
      }
      throw new Error(`Unexpected request ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)

    const trash = useTrashStore()
    await trash.load(2, ' Bjoerk ')
    await trash.deleteTracks([9])
    await trash.deleteTracks([9, 10, 10])

    expect(trash.tracks.items[0]?.title).toBe('Human Behaviour')
    expect(trash.tracks.total).toBe(51)
    expect(trash.error).toBeNull()
  })
})
