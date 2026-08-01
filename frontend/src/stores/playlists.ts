import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'
import { withLibraryRootScope } from '@/stores/libraryRootScope'
import type { Track, TrackPlayStatistics } from '@/stores/catalog'

export interface PlaylistFolder {
  id: number
  name: string
  parent?: Pick<PlaylistFolder, 'id' | 'name'> | null
  playlistCount: number
  childCount?: number
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

export interface PlaylistImportWarning {
  line: number
  path: string
  code: 'outside_or_missing' | 'not_in_collection'
  message: string
}

export interface PlaylistImportResult {
  playlist: PlaylistSummary
  totalEntries: number
  importedCount: number
  unresolvedCount: number
  warnings: PlaylistImportWarning[]
}

export interface TrackPlaylistMembership {
  id: number
  name: string
  folder?: Pick<PlaylistFolder, 'id' | 'name'> | null
  firstItemId: number
  occurrenceCount: number
}

interface FolderResponse {
  items: PlaylistFolder[]
}

interface PlaylistResponse {
  items: PlaylistSummary[]
}

interface MembershipResponse {
  items: Array<{
    trackId: number
    playlists: TrackPlaylistMembership[]
  }>
}

// Keep GET query strings comfortably below common proxy and web-server limits.
const MEMBERSHIP_BATCH_SIZE = 200

export const usePlaylistsStore = defineStore('playlists', () => {
  const folders = ref<PlaylistFolder[]>([])
  const playlists = ref<PlaylistSummary[]>([])
  const current = ref<PlaylistDetail | null>(null)
  const trackMemberships = ref<Record<number, TrackPlaylistMembership[]>>({})
  const loading = ref(false)
  const membershipsLoading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const membershipsError = ref<string | null>(null)
  let membershipRequestId = 0

  async function loadAll() {
    loading.value = true
    error.value = null
    try {
      const [folderResult, playlistResult] = await Promise.all([
        apiRequest<FolderResponse>('/playlist-folders'),
        apiRequest<PlaylistResponse>(withLibraryRootScope('/playlists')),
      ])
      folders.value = folderResult.items
      playlists.value = sortPlaylists(playlistResult.items)
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
      current.value = await apiRequest<PlaylistDetail>(withLibraryRootScope(`/playlists/${id}`))
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      loading.value = false
    }
  }

  async function loadMemberships(trackIds: number[]) {
    const requestedTrackIds = [...new Set(trackIds)]
      .filter((trackId) => Number.isInteger(trackId) && trackId > 0)
    if (!requestedTrackIds.length) return

    const requestId = ++membershipRequestId
    membershipsLoading.value = true
    membershipsError.value = null
    try {
      const batches: number[][] = []
      for (let index = 0; index < requestedTrackIds.length; index += MEMBERSHIP_BATCH_SIZE) {
        batches.push(requestedTrackIds.slice(index, index + MEMBERSHIP_BATCH_SIZE))
      }
      const results = await Promise.all(batches.map((batch) => {
        const parameters = new URLSearchParams()
        batch.forEach((trackId) => parameters.append('trackIds[]', String(trackId)))

        return apiRequest<MembershipResponse>(
          withLibraryRootScope(`/playlists/memberships?${parameters.toString()}`),
        )
      }))
      if (requestId !== membershipRequestId) return

      const nextMemberships = { ...trackMemberships.value }
      requestedTrackIds.forEach((trackId) => {
        nextMemberships[trackId] = []
      })
      results.forEach((result) => result.items.forEach((item) => {
        nextMemberships[item.trackId] = item.playlists
      }))
      trackMemberships.value = nextMemberships
    } catch (cause) {
      if (requestId === membershipRequestId) membershipsError.value = errorMessage(cause)
    } finally {
      if (requestId === membershipRequestId) membershipsLoading.value = false
    }
  }

  function updateTrackPlayStatistics(trackId: number, statistics: TrackPlayStatistics) {
    if (!current.value) return

    current.value = {
      ...current.value,
      items: current.value.items.map((item) => item.track.id === trackId
        ? { ...item, track: { ...item.track, playStatistics: statistics } }
        : item),
    }
  }

  function membershipsForTrack(trackId: number) {
    return trackMemberships.value[trackId] ?? []
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

  async function updateFolder(id: number, payload: { name: string, parentId?: number | null }) {
    saving.value = true
    error.value = null
    try {
      const folder = await apiRequest<PlaylistFolder>(`/playlist-folders/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      })
      folders.value = folders.value
        .map((item) => item.id === id ? folder : item)
        .sort((left, right) => left.name.localeCompare(right.name))
      playlists.value = sortPlaylists(playlists.value.map((playlist) => playlist.folder?.id === id
        ? { ...playlist, folder: { id: folder.id, name: folder.name } }
        : playlist))
      if (current.value?.folder?.id === id) {
        current.value = { ...current.value, folder: { id: folder.id, name: folder.name } }
      }
      return folder
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  async function createPlaylist(payload: {
    name: string
    description?: string | null
    folderId?: number | null
    trackIds?: number[]
  }) {
    saving.value = true
    error.value = null
    try {
      const playlist = await apiRequest<PlaylistSummary>('/playlists', {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      playlists.value = sortPlaylists([...playlists.value, playlist])
      if (playlist.folder) {
        folders.value = folders.value.map((folder) => folder.id === playlist.folder?.id
          ? { ...folder, playlistCount: folder.playlistCount + 1 }
          : folder)
      }
      const loadedTrackIds = (payload.trackIds ?? []).filter((trackId) => trackId in trackMemberships.value)
      if (loadedTrackIds.length) await loadMemberships(loadedTrackIds)
      return playlist
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  async function updatePlaylist(id: number, payload: { name?: string, description?: string | null, folderId?: number | null }) {
    saving.value = true
    error.value = null
    try {
      const previousPlaylist = playlists.value.find((playlist) => playlist.id === id) ?? current.value
      const playlist = await apiRequest<PlaylistSummary>(`/playlists/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      })
      playlists.value = sortPlaylists(playlists.value
        .map((item) => item.id === id ? playlist : item)
      )
      updateFolderCounts(previousPlaylist?.folder?.id ?? null, playlist.folder?.id ?? null)
      if (current.value?.id === id) {
        current.value = {
          ...current.value,
          name: playlist.name,
          description: playlist.description,
          folder: playlist.folder,
          trackCount: playlist.trackCount,
          updatedAt: playlist.updatedAt,
        }
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
      playlists.value = sortPlaylists(playlists.value.map((playlist) => playlist.folder?.id === id
        ? { ...playlist, folder: null }
        : playlist))
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
      trackMemberships.value = Object.fromEntries(
        Object.entries(trackMemberships.value).map(([trackId, memberships]) => [
          trackId,
          memberships.filter((membership) => membership.id !== id),
        ]),
      )
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
    addMembership(item, playlistId)

    return item
  }

  async function addTracks(playlistId: number, trackIds: number[]) {
    const result = await apiRequest<{ items: PlaylistItem[] }>(`/playlists/${playlistId}/tracks`, {
      method: 'POST',
      body: JSON.stringify({ trackIds }),
    })
    const items = result.items

    incrementPlaylistCount(playlistId, items.length)
    if (current.value?.id === playlistId) {
      current.value = {
        ...current.value,
        items: [...current.value.items, ...items],
        trackCount: current.value.trackCount + items.length,
      }
    }
    items.forEach((item) => addMembership(item, playlistId))

    return items
  }

  async function removeItem(playlistId: number, itemId: number) {
    saving.value = true
    error.value = null
    const removedTrackId = current.value?.id === playlistId
      ? current.value.items.find((item) => item.id === itemId)?.track.id
      : undefined
    try {
      await apiRequest<void>(`/playlists/${playlistId}/items/${itemId}`, { method: 'DELETE' })
      incrementPlaylistCount(playlistId, -1)
      if (current.value?.id === playlistId) {
        current.value = {
          ...current.value,
          items: current.value.items.filter((item) => item.id !== itemId),
          trackCount: Math.max(0, current.value.trackCount - 1),
        }
      }
      if (removedTrackId !== undefined && removedTrackId in trackMemberships.value) {
        await loadMemberships([removedTrackId])
      }
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  async function removeItems(playlistId: number, itemIds: number[]) {
    if (!itemIds.length) return current.value

    saving.value = true
    error.value = null
    const removedTrackIds = current.value?.id === playlistId
      ? [...new Set(current.value.items
          .filter((item) => itemIds.includes(item.id))
          .map((item) => item.track.id))]
      : []
    try {
      const playlist = await apiRequest<PlaylistDetail>(withLibraryRootScope(`/playlists/${playlistId}/items`), {
        method: 'DELETE',
        body: JSON.stringify({ items: itemIds }),
      })
      const removedCount = itemIds.length
      incrementPlaylistCount(playlistId, -removedCount)
      if (current.value?.id === playlistId) current.value = playlist
      const loadedTrackIds = removedTrackIds.filter((trackId) => trackId in trackMemberships.value)
      if (loadedTrackIds.length) await loadMemberships(loadedTrackIds)
      return playlist
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  async function reorderItems(playlistId: number, itemIds: number[]) {
    saving.value = true
    error.value = null
    try {
      const playlist = await apiRequest<PlaylistDetail>(withLibraryRootScope(`/playlists/${playlistId}/items/reorder`), {
        method: 'PATCH',
        body: JSON.stringify({ items: itemIds }),
      })
      if (current.value?.id === playlistId) current.value = playlist
      return playlist
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  function incrementPlaylistCount(playlistId: number, amount: number) {
    playlists.value = playlists.value.map((playlist) => playlist.id === playlistId
      ? { ...playlist, trackCount: Math.max(0, playlist.trackCount + amount) }
      : playlist)
  }

  async function importPlaylist(payload: {
    path: string
    name: string
    folderId?: number | null
  }) {
    saving.value = true
    error.value = null
    try {
      const result = await apiRequest<PlaylistImportResult>('/playlists/import', {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      playlists.value = sortPlaylists([...playlists.value, result.playlist])
      if (result.playlist.folder) {
        folders.value = folders.value.map((folder) => folder.id === result.playlist.folder?.id
          ? { ...folder, playlistCount: folder.playlistCount + 1 }
          : folder)
      }

      return result
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      saving.value = false
    }
  }

  function addMembership(item: PlaylistItem, playlistId: number) {
    const trackId = item.track.id
    if (! (trackId in trackMemberships.value)) return

    const playlist = playlists.value.find((candidate) => candidate.id === playlistId)
    if (!playlist) return

    const memberships = trackMemberships.value[trackId] ?? []
    const existing = memberships.find((membership) => membership.id === playlistId)
    const updatedMemberships = existing
      ? memberships.map((membership) => membership.id === playlistId
          ? {
              ...membership,
              firstItemId: Math.min(membership.firstItemId, item.id),
              occurrenceCount: membership.occurrenceCount + 1,
            }
          : membership)
      : [...memberships, {
          id: playlist.id,
          name: playlist.name,
          folder: playlist.folder,
          firstItemId: item.id,
          occurrenceCount: 1,
        }]

    trackMemberships.value = {
      ...trackMemberships.value,
      [trackId]: sortMemberships(updatedMemberships),
    }
  }

  function updateFolderCounts(previousFolderId: number | null, nextFolderId: number | null) {
    if (previousFolderId === nextFolderId) return

    folders.value = folders.value.map((folder) => {
      if (folder.id === previousFolderId) return { ...folder, playlistCount: Math.max(0, folder.playlistCount - 1) }
      if (folder.id === nextFolderId) return { ...folder, playlistCount: folder.playlistCount + 1 }

      return folder
    })
  }

  return {
    folders,
    playlists,
    current,
    trackMemberships,
    loading,
    membershipsLoading,
    saving,
    error,
    membershipsError,
    loadAll,
    loadPlaylist,
    loadMemberships,
    updateTrackPlayStatistics,
    membershipsForTrack,
    createFolder,
    updateFolder,
    createPlaylist,
    importPlaylist,
    updatePlaylist,
    deleteFolder,
    deletePlaylist,
    addTrack,
    addTracks,
    removeItem,
    removeItems,
    reorderItems,
  }
})

function errorMessage(cause: unknown): string {
  return cause instanceof Error ? cause.message : 'Unable to update playlists.'
}

function sortPlaylists(items: PlaylistSummary[]) {
  return [...items].sort((left, right) => {
    const leftFolder = left.folder?.name ?? null
    const rightFolder = right.folder?.name ?? null
    if (leftFolder === null && rightFolder !== null) return 1
    if (leftFolder !== null && rightFolder === null) return -1

    const folderComparison = (leftFolder ?? '').localeCompare(rightFolder ?? '', undefined, { sensitivity: 'base' })

    return folderComparison || left.name.localeCompare(right.name, undefined, { sensitivity: 'base' })
  })
}

function sortMemberships(items: TrackPlaylistMembership[]) {
  return [...items].sort((left, right) => {
    const leftFolder = left.folder?.name ?? null
    const rightFolder = right.folder?.name ?? null
    if (leftFolder === null && rightFolder !== null) return 1
    if (leftFolder !== null && rightFolder === null) return -1

    const folderComparison = (leftFolder ?? '').localeCompare(rightFolder ?? '', undefined, { sensitivity: 'base' })

    return folderComparison || left.name.localeCompare(right.name, undefined, { sensitivity: 'base' })
  })
}
