import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useLibraryRootsStore } from '@/stores/libraryRoots'

describe('library roots store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads and creates library roots', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ member: [] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        id: 1, name: 'Main', path: 'D:/Music', coverImagePath: 'cover.jpg', enabled: true, lastScannedAt: null,
      }), { status: 201 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useLibraryRootsStore()
    await store.load()
    await store.create({ name: 'Main', path: 'D:/Music', coverImagePath: 'cover.jpg' })

    expect(store.roots).toHaveLength(1)
    expect(store.roots[0]?.name).toBe('Main')
    expect(fetchMock).toHaveBeenLastCalledWith('/api/library_roots', expect.objectContaining({ method: 'POST' }))
  })
})
