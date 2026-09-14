import { computed, nextTick, onScopeDispose, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { createRouteQuerySyncGuard } from '@/utils/routeQuerySyncGuard'

type FilterInput = Record<string, unknown>

export function querySearch(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

export function queryNumber(value: unknown): number | null {
  const number = typeof value === 'string' || typeof value === 'number' ? Number(value) : NaN
  return Number.isInteger(number) && number > 0 ? number : null
}

export function queryPage(value: unknown): number {
  return queryNumber(value) ?? 1
}

export function queryInitial(value: unknown): string | null {
  const initial = querySearch(value).toUpperCase()
  return initial === '#' || /^[A-Z]$/.test(initial) ? initial : null
}

export function useCatalogFilters<T extends { page: number, search: string }>(options: {
  routeName: string
  storageKey: string
  parse: (input: FilterInput) => T
  scope: () => unknown
  load: (filters: T) => void
}) {
  const route = useRoute()
  const router = useRouter()
  const defaults = options.parse({})
  const keys = Object.keys(defaults)
  const isActive = computed(() => route.name === options.routeName)
  const hasQuery = () => keys.some(key => route.query[key] !== undefined)
  const guard = createRouteQuerySyncGuard()
  let applyingRoute = false
  let searchTimer: ReturnType<typeof setTimeout> | undefined

  function storedFilters(): T {
    try {
      const stored: unknown = JSON.parse(sessionStorage.getItem(options.storageKey) ?? 'null')
      return stored && typeof stored === 'object' && !Array.isArray(stored)
        ? options.parse(stored as FilterInput)
        : defaults
    } catch {
      return defaults
    }
  }

  const filters = reactive(hasQuery() ? options.parse(route.query) : storedFilters()) as T
  const searchInput = ref(filters.search)
  const resultsTop = ref<HTMLElement | null>(null)

  function currentFilters(): T {
    return { ...filters }
  }

  function queryFor(value: T): Record<string, string> {
    return Object.fromEntries(keys.flatMap((key) => {
      const item = value[key as keyof T]
      const normalized = typeof item === 'string' ? item.trim() : item
      return normalized !== null && normalized !== '' && normalized !== defaults[key as keyof T]
        ? [[key, String(normalized)]]
        : []
    }))
  }

  function saveFilters() {
    try {
      sessionStorage.setItem(options.storageKey, JSON.stringify(filters))
    } catch {
      // Browsing remains usable when browser storage is unavailable.
    }
  }

  function syncRoute() {
    if (!isActive.value) return
    const query = queryFor(currentFilters())
    if (JSON.stringify(queryFor(options.parse(route.query))) === JSON.stringify(query)) return
    const pending = guard.mark(query)
    void router.replace({ name: options.routeName, query }).finally(() => guard.release(pending))
  }

  function cancelSearch() {
    clearTimeout(searchTimer)
    searchTimer = undefined
  }

  function applySearch() {
    cancelSearch()
    filters.search = querySearch(searchInput.value).trim()
  }

  function clearSearch() {
    searchInput.value = ''
    applySearch()
  }

  function changePage(page: number) {
    if (filters.page === page) return
    filters.page = page
    void nextTick(() => resultsTop.value?.scrollIntoView({ behavior: 'auto', block: 'start' }))
  }

  // Synchronous watchers see the restoration guard before it is released.
  watch(() => keys.filter(key => key !== 'page').map(key => filters[key as keyof T]), () => {
    if (!applyingRoute) filters.page = 1
  }, { flush: 'sync' })

  watch(searchInput, () => {
    if (applyingRoute || !isActive.value) return
    cancelSearch()
    searchTimer = setTimeout(applySearch, 300)
  }, { flush: 'sync' })

  watch(() => route.fullPath, () => {
    if (!isActive.value) {
      cancelSearch()
      return
    }
    const query = queryFor(options.parse(route.query))
    if (guard.consume(query)) return
    if (hasQuery()) {
      cancelSearch()
      applyingRoute = true
      Object.assign(filters, options.parse(route.query))
      searchInput.value = filters.search
      applyingRoute = false
    } else {
      syncRoute()
    }
  }, { flush: 'sync' })

  watch(filters, () => {
    saveFilters()
    syncRoute()
  }, { deep: true })

  watch(() => [isActive.value, options.scope(), JSON.stringify(queryFor(currentFilters()))], () => {
    if (isActive.value) options.load(currentFilters())
  }, { immediate: true })

  saveFilters()
  onScopeDispose(cancelSearch)

  return { filters, searchInput, resultsTop, isActive, currentFilters, changePage, applySearch, clearSearch }
}
