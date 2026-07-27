import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface LibraryRoot {
  id: number
  name: string
  path: string
  coverImagePaths: string[]
  excludedDirectories: string[]
  enabled: boolean
  lastScannedAt: string | null
  watchEnabled: boolean
  watchPollIntervalMinutes: number
  watchReconcileIntervalMinutes: number
  watchStatus: 'disabled' | 'pending' | 'watching' | 'scanning' | 'unavailable' | 'error'
  watchCheckedAt: string | null
  watchLastEventAt: string | null
  watchLastScanAt: string | null
  watchLastPath: string | null
  watchError: string | null
}

interface HydraCollection<T> { member: T[] }

export interface CreateLibraryRootInput {
  name: string
  path: string
  coverImagePaths: string[]
  excludedDirectories: string[]
  watchEnabled?: boolean
  watchPollIntervalMinutes?: number
  watchReconcileIntervalMinutes?: number
}

export interface UpdateLibraryRootInput {
  name: string
  coverImagePaths: string[]
  excludedDirectories: string[]
  watchEnabled?: boolean
  watchPollIntervalMinutes?: number
  watchReconcileIntervalMinutes?: number
}

export const useLibraryRootsStore = defineStore('libraryRoots', () => {
  const roots = ref<LibraryRoot[]>([])
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const hasRoots = computed(() => roots.value.length > 0)

  async function load({ silent = false } = {}) {
    if (!silent) loading.value = true
    error.value = null
    try {
      roots.value = (await apiRequest<HydraCollection<LibraryRoot>>('/library_roots')).member
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Unable to load library roots.'
    } finally {
      if (!silent) loading.value = false
    }
  }

  async function create(input: CreateLibraryRootInput) {
    saving.value = true
    error.value = null
    try {
      const root = await apiRequest<LibraryRoot>('/library_roots', {
        method: 'POST',
        body: JSON.stringify(input),
      })
      roots.value = [...roots.value, root].sort((left, right) => left.name.localeCompare(right.name))
      return root
    } finally {
      saving.value = false
    }
  }

  async function update(id: number, input: UpdateLibraryRootInput) {
    saving.value = true
    error.value = null
    try {
      const root = await apiRequest<LibraryRoot>(`/library_roots/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/merge-patch+json' },
        body: JSON.stringify(input),
      })
      roots.value = roots.value
        .map((existing) => existing.id === id ? root : existing)
        .sort((left, right) => left.name.localeCompare(right.name))
      return root
    } finally {
      saving.value = false
    }
  }

  async function remove(id: number) {
    await apiRequest<void>(`/library_roots/${id}`, { method: 'DELETE' })
    roots.value = roots.value.filter((root) => root.id !== id)
  }

  function clear() {
    roots.value = []
    error.value = null
  }

  return { roots, loading, saving, error, hasRoots, load, create, update, remove, clear }
})
