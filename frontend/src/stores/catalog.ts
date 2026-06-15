import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { apiRequest } from '@/api/client'

export interface Artist {
  id: number
  name: string
  browseInitial: string
  albumCount: number
}

interface NamedCatalogItem {
  id: number
  name: string
}

export interface Album {
  id: number
  title: string
  originalReleaseYear?: number
  primaryArtist: NamedCatalogItem | null
  trackCount: number
  artworkThumbnailUrl?: string | null
}

export interface Track {
  id: number
  title: string
  durationMs?: number
  trackNumber?: number
  discNumber?: number
  album: { id: number, title: string } | null
  artists: NamedCatalogItem[]
}

export interface Genre {
  id: number
  name: string
  trackCount: number
}

export interface CatalogMetrics {
  artists: number
  albums: number
  tracks: number
  genres: number
}

export interface CatalogPage<T> {
  items: T[]
  total: number
  page: number
  perPage: number
  lastPage: number
}

interface CatalogQuery {
  page?: number
  search?: string
  initial?: string | null
}

function emptyPage<T>(): CatalogPage<T> {
  return { items: [], total: 0, page: 1, perPage: 0, lastPage: 1 }
}

function queryPath(path: string, query: CatalogQuery): string {
  const parameters = new URLSearchParams({ page: String(query.page ?? 1) })
  if (query.search?.trim()) parameters.set('search', query.search.trim())
  if (query.initial) parameters.set('initial', query.initial)
  return `${path}?${parameters}`
}

export const useCatalogStore = defineStore('catalog', () => {
  const artists = ref<CatalogPage<Artist>>(emptyPage())
  const albums = ref<CatalogPage<Album>>(emptyPage())
  const tracks = ref<CatalogPage<Track>>(emptyPage())
  const genres = ref<CatalogPage<Genre>>(emptyPage())
  const artistsLoading = ref(false)
  const albumsLoading = ref(false)
  const tracksLoading = ref(false)
  const genresLoading = ref(false)
  const artistsError = ref<string | null>(null)
  const albumsError = ref<string | null>(null)
  const tracksError = ref<string | null>(null)
  const genresError = ref<string | null>(null)
  const metrics = ref<CatalogMetrics>({ artists: 0, albums: 0, tracks: 0, genres: 0 })
  const metricsLoading = ref(false)
  const metricsLoaded = ref(false)
  const metricsError = ref<string | null>(null)
  let metricsPromise: Promise<void> | null = null
  let artistsRequest = 0
  let albumsRequest = 0
  let tracksRequest = 0
  let genresRequest = 0

  const metricsHaveCatalog = computed(() => metrics.value.albums > 0 || metrics.value.tracks > 0)

  async function loadMetrics(force = false) {
    if (metricsLoaded.value && !force) return
    if (metricsPromise) return metricsPromise

    metricsLoading.value = true
    metricsError.value = null
    metricsPromise = (async () => {
      try {
        metrics.value = await apiRequest<CatalogMetrics>('/dashboard-metrics')
        metricsLoaded.value = true
      } catch (cause) {
        metricsError.value = cause instanceof Error ? cause.message : 'Unable to load dashboard metrics.'
      } finally {
        metricsLoading.value = false
        metricsPromise = null
      }
    })()

    return metricsPromise
  }

  async function loadArtists(query: CatalogQuery = {}) {
    const request = ++artistsRequest
    artistsLoading.value = true
    artistsError.value = null
    try {
      const result = await apiRequest<CatalogPage<Artist>>(queryPath('/catalog/artists', query))
      if (request === artistsRequest) artists.value = result
    } catch (cause) {
      if (request === artistsRequest) artistsError.value = errorMessage(cause)
    } finally {
      if (request === artistsRequest) artistsLoading.value = false
    }
  }

  async function loadAlbums(query: CatalogQuery = {}) {
    const request = ++albumsRequest
    albumsLoading.value = true
    albumsError.value = null
    try {
      const result = await apiRequest<CatalogPage<Album>>(queryPath('/catalog/albums', query))
      if (request === albumsRequest) albums.value = result
    } catch (cause) {
      if (request === albumsRequest) albumsError.value = errorMessage(cause)
    } finally {
      if (request === albumsRequest) albumsLoading.value = false
    }
  }

  async function loadTracks(query: CatalogQuery = {}) {
    const request = ++tracksRequest
    tracksLoading.value = true
    tracksError.value = null
    try {
      const result = await apiRequest<CatalogPage<Track>>(queryPath('/catalog/tracks', query))
      if (request === tracksRequest) tracks.value = result
    } catch (cause) {
      if (request === tracksRequest) tracksError.value = errorMessage(cause)
    } finally {
      if (request === tracksRequest) tracksLoading.value = false
    }
  }

  async function loadGenres(query: CatalogQuery = {}) {
    const request = ++genresRequest
    genresLoading.value = true
    genresError.value = null
    try {
      const result = await apiRequest<CatalogPage<Genre>>(queryPath('/catalog/genres', query))
      if (request === genresRequest) genres.value = result
    } catch (cause) {
      if (request === genresRequest) genresError.value = errorMessage(cause)
    } finally {
      if (request === genresRequest) genresLoading.value = false
    }
  }

  return {
    artists,
    albums,
    tracks,
    genres,
    artistsLoading,
    albumsLoading,
    tracksLoading,
    genresLoading,
    artistsError,
    albumsError,
    tracksError,
    genresError,
    metrics,
    metricsLoading,
    metricsLoaded,
    metricsError,
    metricsHaveCatalog,
    loadMetrics,
    loadArtists,
    loadAlbums,
    loadTracks,
    loadGenres,
  }
})

function errorMessage(cause: unknown): string {
  return cause instanceof Error ? cause.message : 'Unable to load the music catalog.'
}
