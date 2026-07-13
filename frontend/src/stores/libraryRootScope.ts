import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import type { LibraryRoot } from '@/stores/libraryRoots'

const STORAGE_KEY = 'sonotheque.active-library-root'

function readStoredRootId(): number | null {
  if (typeof window === 'undefined') return null

  const value = window.sessionStorage.getItem(STORAGE_KEY)
  if (value === null) return null

  const id = Number(value)
  return Number.isInteger(id) && id > 0 ? id : null
}

const activeLibraryRootId = ref<number | null>(readStoredRootId())

export function withLibraryRootScope(path: string): string {
  if (activeLibraryRootId.value === null) return path

  const url = new URL(path, 'http://sonotheque.local')
  url.searchParams.set('libraryRoot', String(activeLibraryRootId.value))

  return `${url.pathname}${url.search}${url.hash}`
}

export const useLibraryRootScopeStore = defineStore('libraryRootScope', () => {
  const scopeKey = computed(() => activeLibraryRootId.value === null ? 'all' : `root-${activeLibraryRootId.value}`)

  function select(id: number | null) {
    activeLibraryRootId.value = id
    if (typeof window === 'undefined') return

    if (id === null) {
      window.sessionStorage.removeItem(STORAGE_KEY)
    } else {
      window.sessionStorage.setItem(STORAGE_KEY, String(id))
    }
  }

  function ensureValid(roots: LibraryRoot[]) {
    if (activeLibraryRootId.value === null) return

    const exists = roots.some((root) => root.enabled && root.id === activeLibraryRootId.value)
    if (!exists) select(null)
  }

  return {
    selectedRootId: activeLibraryRootId,
    scopeKey,
    select,
    ensureValid,
  }
})
