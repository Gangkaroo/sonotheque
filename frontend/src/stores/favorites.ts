import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { apiRequest } from '@/api/client'
import type { Album, CatalogPage, Track } from '@/stores/catalog'

interface FavoriteIds {
  tracks: number[]
  albums: number[]
}

function emptyPage<T>(): CatalogPage<T> {
  return { items: [], total: 0, page: 1, perPage: 0, lastPage: 1 }
}

export const useFavoritesStore = defineStore('favorites', () => {
  const trackIds = ref<number[]>([])
  const albumIds = ref<number[]>([])
  const tracks = ref<CatalogPage<Track>>(emptyPage())
  const albums = ref<CatalogPage<Album>>(emptyPage())
  const idsLoading = ref(false)
  const tracksLoading = ref(false)
  const albumsLoading = ref(false)
  const idsLoaded = ref(false)
  const error = ref<string | null>(null)
  const favoriteTrackSet = computed(() => new Set(trackIds.value))
  const favoriteAlbumSet = computed(() => new Set(albumIds.value))

  function isTrackFavorite(id: number) {
    return favoriteTrackSet.value.has(id)
  }

  function isAlbumFavorite(id: number) {
    return favoriteAlbumSet.value.has(id)
  }

  async function loadIds(force = false) {
    if (idsLoaded.value && !force) return

    idsLoading.value = true
    error.value = null
    try {
      const result = await apiRequest<FavoriteIds>('/favorites')
      trackIds.value = result.tracks
      albumIds.value = result.albums
      idsLoaded.value = true
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      idsLoading.value = false
    }
  }

  async function loadTracks(page = 1) {
    tracksLoading.value = true
    error.value = null
    try {
      tracks.value = await apiRequest<CatalogPage<Track>>(`/favorites/tracks?page=${page}`)
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      tracksLoading.value = false
    }
  }

  async function loadAlbums(page = 1) {
    albumsLoading.value = true
    error.value = null
    try {
      albums.value = await apiRequest<CatalogPage<Album>>(`/favorites/albums?page=${page}`)
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      albumsLoading.value = false
    }
  }

  async function toggleTrack(id: number) {
    if (isTrackFavorite(id)) {
      await apiRequest<void>(`/favorites/tracks/${id}`, { method: 'DELETE' })
      trackIds.value = trackIds.value.filter((trackId) => trackId !== id)
      tracks.value.items = tracks.value.items.filter((track) => track.id !== id)
      tracks.value.total = Math.max(0, tracks.value.total - 1)
      return
    }

    await apiRequest<{ trackId: number }>(`/favorites/tracks/${id}`, { method: 'POST' })
    trackIds.value = [...trackIds.value, id]
  }

  async function setTracksFavorite(ids: number[], favorite = true) {
    const uniqueIds = [...new Set(ids)]
    const changedIds = uniqueIds.filter((id) => isTrackFavorite(id) !== favorite)
    await Promise.all(changedIds.map((id) => apiRequest<void>(`/favorites/tracks/${id}`, {
      method: favorite ? 'POST' : 'DELETE',
    })))

    if (favorite) {
      trackIds.value = [...new Set([...trackIds.value, ...changedIds])]
      return
    }

    const removed = new Set(changedIds)
    trackIds.value = trackIds.value.filter((id) => !removed.has(id))
    tracks.value.items = tracks.value.items.filter((track) => !removed.has(track.id))
    tracks.value.total = Math.max(0, tracks.value.total - changedIds.length)
  }

  async function toggleAlbum(id: number) {
    if (isAlbumFavorite(id)) {
      await apiRequest<void>(`/favorites/albums/${id}`, { method: 'DELETE' })
      albumIds.value = albumIds.value.filter((albumId) => albumId !== id)
      albums.value.items = albums.value.items.filter((album) => album.id !== id)
      albums.value.total = Math.max(0, albums.value.total - 1)
      return
    }

    await apiRequest<{ albumId: number }>(`/favorites/albums/${id}`, { method: 'POST' })
    albumIds.value = [...albumIds.value, id]
  }

  return {
    trackIds,
    albumIds,
    tracks,
    albums,
    idsLoading,
    tracksLoading,
    albumsLoading,
    idsLoaded,
    error,
    isTrackFavorite,
    isAlbumFavorite,
    loadIds,
    loadTracks,
    loadAlbums,
    toggleTrack,
    setTracksFavorite,
    toggleAlbum,
  }
})

function errorMessage(cause: unknown): string {
  return cause instanceof Error ? cause.message : 'Unable to update favorites.'
}
