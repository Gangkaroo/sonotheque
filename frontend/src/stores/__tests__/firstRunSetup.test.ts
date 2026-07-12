import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useFirstRunSetupStore } from '@/stores/firstRunSetup'

describe('first-run setup store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  it('loads and persists setup progress', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response(JSON.stringify({
        completed: false,
        step: 2,
        hasLibraryRoots: true,
      }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        completed: true,
        step: 5,
        hasLibraryRoots: true,
      }), { status: 200 }))
    const store = useFirstRunSetupStore()

    await store.load()
    expect(store.status?.step).toBe(2)

    await store.update({ step: 5, completed: true })
    expect(store.status?.completed).toBe(true)
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/settings/first-run', expect.objectContaining({
      method: 'PATCH',
    }))
  })
})
