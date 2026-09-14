import type { Ref } from 'vue'

import { apiRequest } from '@/api/client'

const CACHE_TTL_MS = 10 * 60 * 1000
const MAX_CACHED_PAGES = 40

export function createCatalogPageLoader<T>(data: Ref<T>, loading: Ref<boolean>, error: Ref<string | null>) {
  const pages = new Map<string, { value: T, expiresAt: number }>()
  let generation = 0
  let controller: AbortController | null = null
  let pendingPath: string | null = null

  async function load(path: string) {
    const request = ++generation
    controller?.abort()
    controller = null
    pendingPath = null
    error.value = null
    const cached = pages.get(path)
    if (cached && cached.expiresAt > Date.now()) {
      pages.delete(path)
      pages.set(path, cached)
      data.value = cached.value
      loading.value = false
      return
    }
    pages.delete(path)
    controller = new AbortController()
    pendingPath = path
    loading.value = true
    try {
      const result = await apiRequest<T>(path, { signal: controller.signal })
      if (request !== generation) return
      pages.set(path, { value: result, expiresAt: Date.now() + CACHE_TTL_MS })
      if (pages.size > MAX_CACHED_PAGES) {
        const oldest = pages.keys().next().value
        if (oldest !== undefined) pages.delete(oldest)
      }
      data.value = result
    } catch (cause) {
      if (request !== generation || (cause instanceof DOMException && cause.name === 'AbortError')) return
      error.value = cause instanceof Error ? cause.message : 'Unable to load the music catalog.'
    } finally {
      if (request === generation) {
        loading.value = false
        controller = null
        pendingPath = null
      }
    }
  }

  function invalidate() {
    pages.clear()
    // A pending response predates the mutation; replace it with a fresh read.
    if (pendingPath !== null) void load(pendingPath)
    else generation++
  }

  return { load, invalidate }
}
