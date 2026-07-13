import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { apiRequest } from '@/api/client'
import { withLibraryRootScope } from '@/stores/libraryRootScope'

export interface Artist {
  id: number
  name: string
  browseInitial: string
  albumCount: number
  trackCount: number
  playStatistics: {
    playCount: number
    playedTrackCount: number
    lastPlayedAt?: string | null
  }
}

export interface ArtistDetail extends Artist {
  representativeTrackId?: number | null
}

interface NamedCatalogItem {
  id: number
  name: string
}

export interface AlbumPersonalMetadata {
  purchaseSource?: string | null
  purchaseDate?: string | null
  hasPhysicalCopy: boolean
  physicalFormat?: string | null
  notes?: string | null
}

export interface Album {
  id: number
  title: string
  originalReleaseYear?: number
  primaryArtist: NamedCatalogItem | null
  trackCount: number
  discTotal?: number | null
  artworkThumbnailUrl?: string | null
  artworkUrl?: string | null
  artworkWidth?: number | null
  artworkHeight?: number | null
  personalMetadata: AlbumPersonalMetadata
}

export interface AlbumDetail extends Album {
  genres: NamedCatalogItem[]
  tracks: Track[]
}

export interface Track {
  id: number
  title: string
  streamUrl: string
  durationMs?: number
  trackNumber?: number
  discNumber?: number
  year?: number | null
  comment?: string | null
  album: {
    id: number
    title: string
    originalReleaseYear?: number | null
    artworkThumbnailUrl?: string | null
    personalMetadata?: AlbumPersonalMetadata
  } | null
  artists: NamedCatalogItem[]
  playStatistics: TrackPlayStatistics
}

export interface TrackDetail extends Track {
  composers: string[]
  performers: string[]
  genres: NamedCatalogItem[]
  mediaFile: {
    id: number
    relativePath: string
    fileSize: number
    modifiedAt?: string | null
    mimeType?: string | null
    container?: string | null
    codec?: string | null
    encoder?: string | null
    encoderSettings?: string | null
    bitrate?: number | null
    sampleRate?: number | null
    channels?: number | null
    status?: string | null
    scanError?: string | null
  } | null
}

export interface TrackPlayStatistics {
  playCount: number
  firstPlayedAt?: string | null
  lastPlayedAt?: string | null
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
  playedAlbums: number
  playedTracks: number
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
  year?: number | string | null
  genre?: number | string | null
  artist?: number | string | null
  playStatus?: string | null
  physicalCopy?: string | null
  sort?: string | null
}

function emptyPage<T>(): CatalogPage<T> {
  return { items: [], total: 0, page: 1, perPage: 0, lastPage: 1 }
}

function queryPath(path: string, query: CatalogQuery): string {
  const parameters = new URLSearchParams({ page: String(query.page ?? 1) })
  if (query.search?.trim()) parameters.set('search', query.search.trim())
  if (query.initial) parameters.set('initial', query.initial)
  if (query.year !== undefined && query.year !== null && String(query.year).trim() !== '') {
    parameters.set('year', String(query.year).trim())
  }
  if (query.genre !== undefined && query.genre !== null && String(query.genre).trim() !== '') {
    parameters.set('genre', String(query.genre).trim())
  }
  if (query.artist !== undefined && query.artist !== null && String(query.artist).trim() !== '') {
    parameters.set('artist', String(query.artist).trim())
  }
  if (query.playStatus?.trim()) parameters.set('playStatus', query.playStatus.trim())
  if (query.physicalCopy?.trim()) parameters.set('physicalCopy', query.physicalCopy.trim())
  if (query.sort?.trim()) parameters.set('sort', query.sort.trim())
  return withLibraryRootScope(`${path}?${parameters}`)
}

export const useCatalogStore = defineStore('catalog', () => {
  const artists = ref<CatalogPage<Artist>>(emptyPage())
  const artistDetail = ref<ArtistDetail | null>(null)
  const albums = ref<CatalogPage<Album>>(emptyPage())
  const albumDetail = ref<AlbumDetail | null>(null)
  const tracks = ref<CatalogPage<Track>>(emptyPage())
  const trackDetail = ref<TrackDetail | null>(null)
  const genres = ref<CatalogPage<Genre>>(emptyPage())
  const artistsLoading = ref(false)
  const artistDetailLoading = ref(false)
  const albumsLoading = ref(false)
  const albumDetailLoading = ref(false)
  const tracksLoading = ref(false)
  const trackDetailLoading = ref(false)
  const genresLoading = ref(false)
  const artistsError = ref<string | null>(null)
  const artistDetailError = ref<string | null>(null)
  const albumsError = ref<string | null>(null)
  const albumDetailError = ref<string | null>(null)
  const tracksError = ref<string | null>(null)
  const trackDetailError = ref<string | null>(null)
  const genresError = ref<string | null>(null)
  const metrics = ref<CatalogMetrics>({ artists: 0, albums: 0, tracks: 0, genres: 0, playedAlbums: 0, playedTracks: 0 })
  const metricsLoading = ref(false)
  const metricsLoaded = ref(false)
  const metricsError = ref<string | null>(null)
  let metricsRequest = 0
  let artistsRequest = 0
  let artistDetailRequest = 0
  let albumsRequest = 0
  let albumDetailRequest = 0
  let tracksRequest = 0
  let trackDetailRequest = 0
  let genresRequest = 0
  let albumsAbortController: AbortController | null = null
  let tracksAbortController: AbortController | null = null

  const metricsHaveCatalog = computed(() => metrics.value.albums > 0 || metrics.value.tracks > 0)

  async function loadMetrics(force = false) {
    if (metricsLoaded.value && !force) return

    const request = ++metricsRequest
    metricsLoading.value = true
    metricsError.value = null
    try {
      const result = await apiRequest<CatalogMetrics>(withLibraryRootScope('/dashboard-metrics'), { cache: 'no-store' })
      if (request === metricsRequest) {
        metrics.value = result
        metricsLoaded.value = true
      }
    } catch (cause) {
      if (request === metricsRequest) {
        metricsError.value = cause instanceof Error ? cause.message : 'Unable to load dashboard metrics.'
      }
    } finally {
      if (request === metricsRequest) metricsLoading.value = false
    }
  }

  function invalidateMetrics() {
    metricsLoaded.value = false
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

  async function loadArtist(id: number) {
    const request = ++artistDetailRequest
    artistDetail.value = null
    artistDetailLoading.value = true
    artistDetailError.value = null
    try {
      const result = await apiRequest<ArtistDetail>(withLibraryRootScope(`/catalog/artists/${id}`))
      if (request === artistDetailRequest) artistDetail.value = result
    } catch (cause) {
      if (request === artistDetailRequest) artistDetailError.value = errorMessage(cause)
    } finally {
      if (request === artistDetailRequest) artistDetailLoading.value = false
    }
  }

  async function loadAlbums(query: CatalogQuery = {}) {
    const request = ++albumsRequest
    albumsAbortController?.abort()
    albumsAbortController = new AbortController()
    albumsLoading.value = true
    albumsError.value = null
    try {
      const result = await apiRequest<CatalogPage<Album>>(queryPath('/catalog/albums', query), {
        signal: albumsAbortController.signal,
      })
      if (request === albumsRequest) albums.value = result
    } catch (cause) {
      if (isAbortError(cause)) return
      if (request === albumsRequest) albumsError.value = errorMessage(cause)
    } finally {
      if (request === albumsRequest) {
        albumsLoading.value = false
        albumsAbortController = null
      }
    }
  }

  async function loadAlbum(id: number) {
    const request = ++albumDetailRequest
    albumDetail.value = null
    albumDetailLoading.value = true
    albumDetailError.value = null
    try {
      const result = await apiRequest<AlbumDetail>(withLibraryRootScope(`/catalog/albums/${id}`))
      if (request === albumDetailRequest) albumDetail.value = result
    } catch (cause) {
      if (request === albumDetailRequest) albumDetailError.value = errorMessage(cause)
    } finally {
      if (request === albumDetailRequest) albumDetailLoading.value = false
    }
  }

  async function loadTracks(query: CatalogQuery = {}) {
    const request = ++tracksRequest
    tracksAbortController?.abort()
    tracksAbortController = new AbortController()
    tracksLoading.value = true
    tracksError.value = null
    try {
      const result = await apiRequest<CatalogPage<Track>>(queryPath('/catalog/tracks', query), {
        signal: tracksAbortController.signal,
      })
      if (request === tracksRequest) tracks.value = result
    } catch (cause) {
      if (isAbortError(cause)) return
      if (request === tracksRequest) tracksError.value = errorMessage(cause)
    } finally {
      if (request === tracksRequest) {
        tracksLoading.value = false
        tracksAbortController = null
      }
    }
  }

  async function loadTrack(id: number) {
    const request = ++trackDetailRequest
    trackDetail.value = null
    trackDetailLoading.value = true
    trackDetailError.value = null
    try {
      const result = await apiRequest<TrackDetail>(withLibraryRootScope(`/catalog/tracks/${id}`))
      if (request === trackDetailRequest) trackDetail.value = result
    } catch (cause) {
      if (request === trackDetailRequest) trackDetailError.value = errorMessage(cause)
    } finally {
      if (request === trackDetailRequest) trackDetailLoading.value = false
    }
  }

  async function updateAlbumPersonalMetadata(albumId: number, values: AlbumPersonalMetadata) {
    const personalMetadata = await apiRequest<AlbumPersonalMetadata>(`/albums/${albumId}/personal-metadata`, {
      method: 'PATCH',
      body: JSON.stringify({
        purchaseSource: values.purchaseSource ?? null,
        purchaseDate: values.purchaseDate ?? null,
        hasPhysicalCopy: values.hasPhysicalCopy,
        physicalFormat: values.physicalFormat ?? null,
        notes: values.notes ?? null,
      }),
    })

    albums.value = {
      ...albums.value,
      items: albums.value.items.map((album) => album.id === albumId ? { ...album, personalMetadata } : album),
    }
    tracks.value = {
      ...tracks.value,
      items: tracks.value.items.map((track) => track.album?.id === albumId
        ? { ...track, album: { ...track.album, personalMetadata } }
        : track),
    }
    if (albumDetail.value?.id === albumId) {
      albumDetail.value = { ...albumDetail.value, personalMetadata }
    }
    if (trackDetail.value?.album?.id === albumId) {
      trackDetail.value = {
        ...trackDetail.value,
        album: { ...trackDetail.value.album, personalMetadata },
      }
    }

    return personalMetadata
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

  function updateTrackPlayStatistics(trackId: number, statistics: TrackPlayStatistics) {
    const updateTrack = <T extends Track>(track: T): T => track.id === trackId
      ? { ...track, playStatistics: statistics }
      : track

    tracks.value = {
      ...tracks.value,
      items: tracks.value.items.map(updateTrack),
    }

    if (albumDetail.value) {
      albumDetail.value = {
        ...albumDetail.value,
        tracks: albumDetail.value.tracks.map(updateTrack),
      }
    }

    if (trackDetail.value?.id === trackId) {
      trackDetail.value = updateTrack(trackDetail.value)
    }
  }

  return {
    artists,
    artistDetail,
    albums,
    albumDetail,
    tracks,
    trackDetail,
    genres,
    artistsLoading,
    artistDetailLoading,
    albumsLoading,
    albumDetailLoading,
    tracksLoading,
    trackDetailLoading,
    genresLoading,
    artistsError,
    artistDetailError,
    albumsError,
    albumDetailError,
    tracksError,
    trackDetailError,
    genresError,
    metrics,
    metricsLoading,
    metricsLoaded,
    metricsError,
    metricsHaveCatalog,
    loadMetrics,
    invalidateMetrics,
    loadArtists,
    loadArtist,
    loadAlbums,
    loadAlbum,
    loadTracks,
    loadTrack,
    updateAlbumPersonalMetadata,
    loadGenres,
    updateTrackPlayStatistics,
  }
})

function errorMessage(cause: unknown): string {
  return cause instanceof Error ? cause.message : 'Unable to load the music catalog.'
}

function isAbortError(cause: unknown): boolean {
  return cause instanceof DOMException && cause.name === 'AbortError'
}
