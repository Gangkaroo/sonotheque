import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useLibraryRootsStore } from '@/stores/libraryRoots'

describe('library roots store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads, creates, and updates library roots', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ member: [] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        id: 1, name: 'Main', path: 'D:/Music', coverImagePath: 'cover.jpg', enabled: true, lastScannedAt: null,
      }), { status: 201 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        id: 1, name: 'Archive', path: 'D:/Music', coverImagePath: 'artwork/front.jpg', enabled: true, lastScannedAt: null,
      }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useLibraryRootsStore()
    await store.load()
    await store.create({ name: 'Main', path: 'D:/Music', coverImagePath: 'cover.jpg' })
    await store.update(1, { name: 'Archive', coverImagePath: 'artwork/front.jpg' })

    expect(store.roots).toHaveLength(1)
    expect(store.roots[0]?.name).toBe('Archive')
    expect(fetchMock).toHaveBeenLastCalledWith('/api/library_roots/1', expect.objectContaining({ method: 'PATCH' }))
    const request = fetchMock.mock.lastCall?.[1] as RequestInit
    expect(new Headers(request.headers).get('Content-Type')).toBe('application/merge-patch+json')
  })
})
