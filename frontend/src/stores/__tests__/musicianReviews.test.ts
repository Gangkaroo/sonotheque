import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { useMusicianReviewsStore } from '@/stores/musicianReviews'

describe('musician reviews store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads the selected review tab within the active library root', async () => {
    const response = {
      items: [],
      total: 0,
      page: 2,
      perPage: 20,
      lastPage: 1,
      counts: { ambiguous: 0, failed: 0, reviewed: 0 },
    }
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(response), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    useLibraryRootScopeStore().selectedRootId = 12

    await useMusicianReviewsStore().load('failed', 2)

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/musician-reviews?status=failed&page=2&libraryRoot=12',
      expect.any(Object),
    )
  })

  it('uses the review and exact-release mutation contracts', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    useLibraryRootScopeStore().selectedRootId = 12
    const store = useMusicianReviewsStore()

    await store.selectRelease(7, 'release-id')
    await store.retry(7)
    await store.decide(7, 'no_suitable_match')
    await store.reopen(7)

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/enrichment/albums/7/musicians/release',
      expect.objectContaining({ method: 'PUT', body: JSON.stringify({ releaseId: 'release-id' }) }),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/musician-reviews/7/retry?libraryRoot=12',
      expect.objectContaining({ method: 'POST' }),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      3,
      '/api/musician-reviews/7/decision?libraryRoot=12',
      expect.objectContaining({ method: 'PUT', body: JSON.stringify({ decision: 'no_suitable_match' }) }),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      4,
      '/api/musician-reviews/7/decision?libraryRoot=12',
      expect.objectContaining({ method: 'DELETE' }),
    )
  })

  it('does not let a late response replace the active review context', async () => {
    const ambiguousResponse = deferredResponse()
    const failedResponse = deferredResponse()
    const fetchMock = vi.fn()
      .mockReturnValueOnce(ambiguousResponse.promise)
      .mockReturnValueOnce(failedResponse.promise)
    vi.stubGlobal('fetch', fetchMock)
    const store = useMusicianReviewsStore()

    const loadingAmbiguous = store.load('ambiguous')
    const loadingFailed = store.load('failed')
    failedResponse.resolve(new Response(JSON.stringify(pageResponse(2)), { status: 200 }))
    await loadingFailed
    ambiguousResponse.resolve(new Response(JSON.stringify(pageResponse(1)), { status: 200 }))
    await loadingAmbiguous

    expect(store.results.total).toBe(2)
    expect(store.loading).toBe(false)
  })
})

function pageResponse(total: number) {
  return {
    items: [],
    total,
    page: 1,
    perPage: 20,
    lastPage: 1,
    counts: { ambiguous: 0, failed: 0, reviewed: 0 },
  }
}

function deferredResponse() {
  let resolve!: (response: Response) => void
  const promise = new Promise<Response>((resolvePromise) => {
    resolve = resolvePromise
  })

  return { promise, resolve }
}
