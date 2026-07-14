import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'
import type { Track } from '@/stores/catalog'

export interface FolderBreadcrumb {
  name: string
  path: string | null
}

export interface LibraryFolderEntry {
  name: string
  path: string
}

export interface LibraryFileEntry {
  name: string
  path: string
  indexed: boolean
  available: boolean
  track: Track | null
}

export interface LibraryFolderListing {
  libraryRoot: { id: number; name: string }
  path: string | null
  parentPath: string | null
  breadcrumbs: FolderBreadcrumb[]
  directories: LibraryFolderEntry[]
  files: LibraryFileEntry[]
}

interface FolderTracks {
  path: string | null
  total: number
  tracks: Track[]
}

function pathQuery(path: string | null) {
  if (!path) return ''

  const query = new URLSearchParams({ path })
  return `?${query.toString()}`
}

export const useLibraryFoldersStore = defineStore('libraryFolders', () => {
  const listing = ref<LibraryFolderListing | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  let request = 0

  async function load(libraryRootId: number, path: string | null = null) {
    const currentRequest = ++request
    loading.value = true
    error.value = null

    try {
      const result = await apiRequest<LibraryFolderListing>(
        `/catalog/library-roots/${libraryRootId}/folders${pathQuery(path)}`,
      )
      if (currentRequest === request) listing.value = result
    } catch (cause) {
      if (currentRequest === request) {
        listing.value = null
        error.value = cause instanceof Error ? cause.message : 'Unable to browse this folder.'
      }
    } finally {
      if (currentRequest === request) loading.value = false
    }
  }

  async function loadTracks(libraryRootId: number, path: string | null = null) {
    return apiRequest<FolderTracks>(
      `/catalog/library-roots/${libraryRootId}/folder-tracks${pathQuery(path)}`,
    )
  }

  function clear() {
    request++
    listing.value = null
    loading.value = false
    error.value = null
  }

  return { listing, loading, error, load, loadTracks, clear }
})
