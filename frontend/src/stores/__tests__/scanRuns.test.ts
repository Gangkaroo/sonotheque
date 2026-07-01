import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useScanRunsStore } from '@/stores/scanRuns'

const scan = {
  id: 4,
  libraryRootId: 2,
  status: 'pending',
  trigger: 'manual',
  filesDiscovered: 0,
  filesProcessed: 0,
  filesAdded: 0,
  filesUpdated: 0,
  filesRemoved: 0,
  warningCount: 0,
  errorCount: 0,
  startedAt: null,
  finishedAt: null,
  cancelRequestedAt: null,
  summary: { phase: 'queued' },
  createdAt: '2026-06-14T12:00:00Z',
}

describe('scan runs store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('starts and cancels a scan', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(scan), { status: 201 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ ...scan, status: 'cancelled' }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const store = useScanRunsStore()
    await store.start(2)
    await store.cancel(4)

    expect(store.scans[0]?.status).toBe('cancelled')
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/api/scan_runs', expect.objectContaining({ method: 'POST' }))
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/scan_runs/4/cancel', expect.objectContaining({ method: 'PATCH' }))
  })

  it('shows only terminal scans in recent history', async () => {
    const completed = { ...scan, id: 5, status: 'completed' }
    const running = { ...scan, id: 6, status: 'running' }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ member: [running, completed] }), { status: 200 }),
    ))

    const store = useScanRunsStore()
    await store.load()

    expect(store.scans).toHaveLength(2)
    expect(store.recentScans.map((item) => item.id)).toEqual([5])
    expect(store.hasActiveScans).toBe(true)
  })

  it('keeps background polling visually silent', async () => {
    let resolveFetch
    vi.stubGlobal('fetch', vi.fn().mockImplementation(() => new Promise((resolve) => {
      resolveFetch = resolve
    })))

    const store = useScanRunsStore()
    const request = store.load({ silent: true })

    expect(store.loading).toBe(false)
    resolveFetch(new Response(JSON.stringify({ member: [scan] }), { status: 200 }))
    await request
    expect(store.loading).toBe(false)
    expect(store.hasActiveScans).toBe(true)
  })
})
