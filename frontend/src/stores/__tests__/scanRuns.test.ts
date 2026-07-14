import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useScanRunsStore } from '@/stores/scanRuns'

const scan = {
  id: 4,
  libraryRootId: 2,
  status: 'pending',
  trigger: 'manual',
  subtreePath: null,
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

  it('finds the active scan for a library root', async () => {
    const completed = { ...scan, id: 5, status: 'completed' }
    const running = { ...scan, id: 6, status: 'running', subtreePath: 'Artist/Album' }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ member: [completed, running] }), { status: 200 }),
    ))

    const store = useScanRunsStore()
    await store.load()

    expect(store.activeForRoot(2)?.id).toBe(6)
    expect(store.activeForRoot(3)).toBeNull()
  })

  it('loads the complete issue list for one scan on demand', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      items: [{ id: 1, code: 'file_warning', severity: 'warning', message: 'Warning' }],
      total: 1,
      totalOccurrences: 1,
    }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const result = await useScanRunsStore().loadIssues(4)

    expect(result.totalOccurrences).toBe(1)
    expect(fetchMock).toHaveBeenCalledWith('/api/scan_runs/4/issues', expect.any(Object))
  })

  it('starts a scan for a root-relative subtree', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ ...scan, subtreePath: 'Artist/Album' }), { status: 201 }),
    )
    vi.stubGlobal('fetch', fetchMock)

    await useScanRunsStore().start(2, 'Artist/Album')

    const request = fetchMock.mock.calls[0]?.[1] as RequestInit
    expect(JSON.parse(String(request.body))).toEqual({
      libraryRootId: '2',
      subtreePath: 'Artist/Album',
    })
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
