import { adminToken } from '@/stores/adminAccess'

export class ApiError extends Error {
  constructor(message: string, public readonly violations: Record<string, string[]> = {}) {
    super(message)
  }
}

export async function apiRequest<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/ld+json')
  if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/ld+json')
  const token = adminToken()
  if (token && !headers.has('X-Music-Library-Admin-Token')) {
    headers.set('X-Music-Library-Admin-Token', token)
  }

  const response = await fetch(`/api${path}`, { ...init, headers })
  if (response.status === 204) return undefined as T

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    const violations = Object.fromEntries(
      (payload.violations ?? []).map((violation: { propertyPath: string; message: string }) => [
        violation.propertyPath,
        [violation.message],
      ]),
    )
    throw new ApiError(payload.detail ?? payload.message ?? 'The request could not be completed.', violations)
  }

  return payload as T
}
