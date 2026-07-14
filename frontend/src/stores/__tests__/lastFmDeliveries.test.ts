import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useLastFmDeliveriesStore } from '@/stores/lastFmDeliveries'

describe('Last.fm deliveries store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('loads all deliveries and applies an optional status filter', async () => {
    const response = {
      items: [{
        id: 4,
        status: 'failed',
        attempts: 5,
        playedAt: '2026-07-14T10:00:00Z',
        scrobbledAt: null,
        error: 'Last.fm rejected the request.',
        ignoredCode: null,
        track: null,
      }],
      total: 1,
      page: 1,
      perPage: 15,
      lastPage: 1,
      summary: { pending: 0, sent: 3, ignored: 1, failed: 1 },
    }
    const fetchMock = vi.fn()
      .mockImplementation(() => Promise.resolve(new Response(JSON.stringify(response), { status: 200 })))
    vi.stubGlobal('fetch', fetchMock)
    const store = useLastFmDeliveriesStore()

    await store.load()
    await store.load(2, 'failed')

    expect(store.deliveries.summary.failed).toBe(1)
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/settings/lastfm/deliveries?page=1',
      expect.any(Object),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/settings/lastfm/deliveries?page=2&status=failed',
      expect.any(Object),
    )
  })
})
