import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface LibraryRoot {
  id: number
  name: string
  path: string
  coverImagePath: string
  enabled: boolean
  lastScannedAt: string | null
}

interface HydraCollection<T> { member: T[] }

export interface CreateLibraryRootInput {
  name: string
  path: string
  coverImagePath: string
}

export const useLibraryRootsStore = defineStore('libraryRoots', () => {
  const roots = ref<LibraryRoot[]>([])
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const hasRoots = computed(() => roots.value.length > 0)

  async function load() {
    loading.value = true
    error.value = null
    try {
      roots.value = (await apiRequest<HydraCollection<LibraryRoot>>('/library_roots')).member
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Unable to load library roots.'
    } finally {
      loading.value = false
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

  async function remove(id: number) {
    await apiRequest<void>(`/library_roots/${id}`, { method: 'DELETE' })
    roots.value = roots.value.filter((root) => root.id !== id)
  }

  return { roots, loading, saving, error, hasRoots, load, create, remove }
})
