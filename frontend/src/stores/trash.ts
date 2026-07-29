import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'
import type { CatalogPage } from '@/stores/catalog'
import { withLibraryRootScope } from '@/stores/libraryRootScope'

interface NamedItem {
  id: number
  name: string
}

export interface TrashTrack {
  id: number
  title: string
  album: {
    id: number
    title: string
  } | null
  artists: NamedItem[]
  libraryRoot: NamedItem | null
  relativePath: string | null
  markedMissingAt: string | null
  playlistCount: number
  playCount: number
}

function emptyPage(): CatalogPage<TrashTrack> {
  return { items: [], total: 0, page: 1, perPage: 0, lastPage: 1 }
}

export const useTrashStore = defineStore('trash', () => {
  const tracks = ref<CatalogPage<TrashTrack>>(emptyPage())
  const loading = ref(false)
  const deleting = ref(false)
  const error = ref<string | null>(null)

  async function load(page = 1, search = '') {
    loading.value = true
    error.value = null
    const parameters = new URLSearchParams({ page: String(page) })
    if (search.trim()) parameters.set('search', search.trim())

    try {
      tracks.value = await apiRequest<CatalogPage<TrashTrack>>(
        withLibraryRootScope(`/trash/tracks?${parameters}`),
      )
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      loading.value = false
    }
  }

  async function deleteTracks(trackIds: number[]) {
    const ids = [...new Set(trackIds)]
    if (!ids.length) return

    deleting.value = true
    error.value = null
    try {
      if (ids.length === 1) {
        await apiRequest<void>(`/trash/tracks/${ids[0]}`, { method: 'DELETE' })
      } else {
        await apiRequest<{ deleted: number }>('/trash/tracks', {
          method: 'DELETE',
          body: JSON.stringify({ trackIds: ids }),
        })
      }
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      deleting.value = false
    }
  }

  return {
    tracks,
    loading,
    deleting,
    error,
    load,
    deleteTracks,
  }
})

function errorMessage(cause: unknown): string {
  return cause instanceof Error ? cause.message : 'Unable to update the trash.'
}
