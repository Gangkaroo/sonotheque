import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { apiRequest } from '@/api/client'
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
    expect(window.sessionStorage.getItem('music-library:admin-token')).toBe('session-token')
    expect(window.localStorage.getItem('music-library:admin-token:remembered')).toBeNull()
  })

  it('persists and clears a remembered token', () => {
    const access = useAdminAccessStore()

    access.save('remembered-token', true)
    setActivePinia(createPinia())
    const restored = useAdminAccessStore()

    expect(restored.token).toBe('remembered-token')
    expect(restored.remember).toBe(true)
    restored.clear()
    expect(window.localStorage.getItem('music-library:admin-token:remembered')).toBeNull()
  })

  it('adds the token to API requests without replacing an explicit candidate', async () => {
    const access = useAdminAccessStore()
    access.save('stored-token', false)
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ authorized: true }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    await apiRequest('/settings/access')
    await apiRequest('/settings/access', { headers: { 'X-Music-Library-Admin-Token': 'candidate-token' } })

    const firstHeaders = new Headers(fetchMock.mock.calls[0]?.[1]?.headers)
    const secondHeaders = new Headers(fetchMock.mock.calls[1]?.[1]?.headers)
    expect(firstHeaders.get('X-Music-Library-Admin-Token')).toBe('stored-token')
    expect(secondHeaders.get('X-Music-Library-Admin-Token')).toBe('candidate-token')
  })
})
