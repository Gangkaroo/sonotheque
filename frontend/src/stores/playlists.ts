import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'
import type { Track } from '@/stores/catalog'

export interface PlaylistFolder {
  id: number
  name: string
  playlistCount: number
  createdAt?: string
  updatedAt?: string
}

export interface PlaylistSummary {
  id: number
  name: string
  description?: string | null
  folder?: Pick<PlaylistFolder, 'id' | 'name'> | null
  trackCount: number
  createdAt?: string
  updatedAt?: string
}

export interface PlaylistItem {
  id: number
  position: number
  track: Track
  createdAt?: string
  updatedAt?: string
}

export interface PlaylistDetail extends PlaylistSummary {
  items: PlaylistItem[]
}

interface FolderResponse {
  items: PlaylistFolder[]
}

interface PlaylistResponse {
  items: PlaylistSummary[]
}

export const usePlaylistsStore = defineStore('playlists', () => {
  const folders = ref<PlaylistFolder[]>([])
  const playlists = ref<PlaylistSummary[]>([])
  const current = ref<PlaylistDetail | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  async function loadAll() {
    loading.value = true
    error.value = null
    try {
      const [folderResult, playlistResult] = await Promise.all([
        apiRequest<FolderResponse>('/playlist-folders'),
        apiRequest<PlaylistResponse>('/playlists'),
      ])
      folders.value = folderResult.items
      playlists.value = playlistResult.items
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      loading.value = false
    }
  }

  async function loadPlaylist(id: number) {
    loading.value = true
    error.value = null
    try {
      current.value = await apiRequest<PlaylistDetail>(`/playlists/${id}`)
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      loading.value = false
    }
  }

  async function createFolder(name: string) {
    saving.value = true
    error.value = null
    try {
      const folder = await apiRequest<PlaylistFolder>('/playlist-folders', {
        method: 'POST',
        body: JSON.stringify({ name }),
      })
      folders.value = [...folders.value, folder].sort((left, right) => left.name.localeCompare(right.name))
      return folder
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  async function createPlaylist(payload: { name: string, description?: string | null, folderId?: number | null }) {
    saving.value = true
    error.value = null
    try {
      const playlist = await apiRequest<PlaylistSummary>('/playlists', {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      playlists.value = [...playlists.value, playlist].sort((left, right) => left.name.localeCompare(right.name))
      if (playlist.folder) {
        folders.value = folders.value.map((folder) => folder.id === playlist.folder?.id
          ? { ...folder, playlistCount: folder.playlistCount + 1 }
          : folder)
      }
      return playlist
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  async function deleteFolder(id: number) {
    saving.value = true
    error.value = null
    try {
      await apiRequest<void>(`/playlist-folders/${id}`, { method: 'DELETE' })
      folders.value = folders.value.filter((folder) => folder.id !== id)
      playlists.value = playlists.value.map((playlist) => playlist.folder?.id === id ? { ...playlist, folder: null } : playlist)
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  async function deletePlaylist(id: number) {
    saving.value = true
    error.value = null
    try {
      const playlist = playlists.value.find((item) => item.id === id)
      await apiRequest<void>(`/playlists/${id}`, { method: 'DELETE' })
      playlists.value = playlists.value.filter((item) => item.id !== id)
      if (playlist?.folder) {
        folders.value = folders.value.map((folder) => folder.id === playlist.folder?.id
          ? { ...folder, playlistCount: Math.max(0, folder.playlistCount - 1) }
          : folder)
      }
      if (current.value?.id === id) current.value = null
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  async function addTrack(playlistId: number, trackId: number) {
    const item = await apiRequest<PlaylistItem>(`/playlists/${playlistId}/tracks/${trackId}`, { method: 'POST' })
    incrementPlaylistCount(playlistId, 1)

    if (current.value?.id === playlistId) {
      current.value = { ...current.value, items: [...current.value.items, item], trackCount: current.value.trackCount + 1 }
    }

    return item
  }

  async function addTracks(playlistId: number, trackIds: number[]) {
    const items: PlaylistItem[] = []
    for (const trackId of trackIds) {
      items.push(await apiRequest<PlaylistItem>(`/playlists/${playlistId}/tracks/${trackId}`, { method: 'POST' }))
    }

    incrementPlaylistCount(playlistId, items.length)
    if (current.value?.id === playlistId) {
      current.value = {
        ...current.value,
        items: [...current.value.items, ...items],
        trackCount: current.value.trackCount + items.length,
      }
    }

    return items
  }

  function incrementPlaylistCount(playlistId: number, amount: number) {
    playlists.value = playlists.value.map((playlist) => playlist.id === playlistId
      ? { ...playlist, trackCount: playlist.trackCount + amount }
      : playlist)
  }

  return {
    folders,
    playlists,
    current,
    loading,
    saving,
    error,
    loadAll,
    loadPlaylist,
    createFolder,
    createPlaylist,
    deleteFolder,
    deletePlaylist,
    addTrack,
    addTracks,
  }
})

function errorMessage(cause: unknown): string {
  return cause instanceof Error ? cause.message : 'Unable to update playlists.'
}
