import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError, apiRequest } from '@/api/client'
import { useAdminAccessStore } from '@/stores/adminAccess'

describe('admin access store', () => {
  beforeEach(() => {
    window.localStorage.clear()
    window.sessionStorage.clear()
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('stores tokens for the session by default', () => {
    const access = useAdminAccessStore()

    access.save(' session-token ', false)

    expect(access.token).toBe('session-token')
    expect(window.sessionStorage.getItem('sonotheque:admin-token')).toBe('session-token')
    expect(window.localStorage.getItem('sonotheque:admin-token:remembered')).toBeNull()
  })

  it('persists and clears a remembered token', () => {
    const access = useAdminAccessStore()

    access.save('remembered-token', true)
    setActivePinia(createPinia())
    const restored = useAdminAccessStore()

    expect(restored.token).toBe('remembered-token')
    expect(restored.remember).toBe(true)
    restored.clear()
    expect(window.localStorage.getItem('sonotheque:admin-token:remembered')).toBeNull()
  })

  it('adds the token to API requests without replacing an explicit candidate', async () => {
    const access = useAdminAccessStore()
    access.save('stored-token', false)
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ authorized: true }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    await apiRequest('/settings/access')
    await apiRequest('/settings/access', { headers: { 'X-Sonotheque-Admin-Token': 'candidate-token' } })

    const firstHeaders = new Headers(fetchMock.mock.calls[0]?.[1]?.headers)
    const secondHeaders = new Headers(fetchMock.mock.calls[1]?.[1]?.headers)
    expect(firstHeaders.get('X-Sonotheque-Admin-Token')).toBe('stored-token')
    expect(secondHeaders.get('X-Sonotheque-Admin-Token')).toBe('candidate-token')
  })

  it('keeps the response status on API errors', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      message: 'Protected operation.',
      errorCode: 'admin_required',
    }), { status: 403 })))

    await expect(apiRequest('/settings/access')).rejects.toMatchObject<Partial<ApiError>>({
      message: 'Protected operation.',
      status: 403,
      errorCode: 'admin_required',
    })
  })
})
