import { describe, expect, it } from 'vitest'

import { createRouteQuerySyncGuard } from '@/utils/routeQuerySyncGuard'

describe('route query sync guard', () => {
  it('recognizes internal query updates even when they arrive out of order', () => {
    const guard = createRouteQuerySyncGuard()

    guard.mark({ search: 'deep' })
    guard.mark({ search: 'deep purple' })

    expect(guard.consume({ search: 'deep' })).toBe(true)
    expect(guard.consume({ search: 'deep purple' })).toBe(true)
    expect(guard.consume({ search: 'external search' })).toBe(false)
  })

  it('keeps repeated pending values independent and releases cancelled updates', () => {
    const guard = createRouteQuerySyncGuard()
    const first = guard.mark({ search: 'amen' })
    const second = guard.mark({ search: 'amen' })

    guard.release(first)

    expect(guard.consume({ search: 'amen' })).toBe(true)
    expect(guard.consume({ search: 'amen' })).toBe(false)

    guard.release(second)
  })
})
