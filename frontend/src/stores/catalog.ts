import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { apiRequest } from '@/api/client'
import { withLibraryRootScope } from '@/stores/libraryRootScope'

export interface Artist {
  id: number
  name: string
  browseInitial: string
  createdAt?: string | null
  updatedAt?: string | null
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
  ownedCopies?: OwnedAlbumCopy[]
}

export interface OwnedAlbumCopy {
  id: number
  isPhysical: boolean
  physicalFormat?: string | null
  purchaseSource?: string | null
  purchaseDate?: string | null
  purchasePriceAmount?: string | null
  purchasePriceCurrency?: string | null
  mediaCondition?: string | null
  sleeveCondition?: string | null
  notes?: string | null
  provider?: string | null
  externalReleaseId?: number | null
  externalMasterId?: number | null
  externalCollectionInstanceId?: number | null
  externalFolderId?: number | null
  providerSyncedAt?: string | null
}

export interface OwnedAlbumCopyValues {
  isPhysical: boolean
  physicalFormat?: string | null
  purchaseSource?: string | null
  purchaseDate?: string | null
  purchasePriceAmount?: string | number | null
  purchasePriceCurrency?: string | null
  mediaCondition?: string | null
  sleeveCondition?: string | null
  notes?: string | null
}

export interface DiscogsReleaseCandidate {
  releaseId: number
  masterId?: number | null
  title: string
  year?: number | null
  country?: string | null
  formats: string[]
  labels: string[]
  catalogNumber?: string | null
  thumbnailUrl?: string | null
  webUrl: string
  inCollection: boolean
}

export interface DiscogsReleaseSearch {
  artist: string
  title: string
  year?: number | null
  format?: string | null
  country?: string | null
  barcode?: string | null
  catalogNumber?: string | null
}

export interface DiscogsCollectionInstance {
  instanceId: number
  folderId: number
  folderName?: string | null
  dateAdded?: string | null
  rating?: number | null
}

export interface DiscogsLinkedReleaseDetails {
  release: {
    id: number
    masterId?: number | null
    title: string
    artist: string
    year?: number | null
    country?: string | null
    formats: string[]
    labels: string[]
    catalogNumber?: string | null
    thumbnailUrl?: string | null
    webUrl: string
  }
  collectionInstance?: DiscogsCollectionInstance | null
  syncedAt?: string | null
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
  musicianCredits?: MusicianAlbumCredits | null
}

export interface AlbumDetail extends Album {
  createdAt?: string | null
  updatedAt?: string | null
  libraryRoot: NamedCatalogItem | null
  genres: NamedCatalogItem[]
  technical: {
    fileTypes: string[]
    bitrateMinimum?: number | null
    bitrateMaximum?: number | null
    bitrateModes: string[]
    encoderSettings: string[]
  }
  additionalTags: AlbumAdditionalMetadataTag[]
  tracks: Track[]
}

export interface Track {
  id: number
  title: string
  available?: boolean
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
    artworkUrl?: string | null
    personalMetadata?: AlbumPersonalMetadata
  } | null
  artists: NamedCatalogItem[]
  playStatistics: TrackPlayStatistics
}

export interface ArtistPlaybackTracks {
  total: number
  requiresConfirmation: boolean
  tracks: Track[]
}

export interface AdditionalMetadataTag {
  key: string
  frameId: string
  name: string
  values: string[]
  sizeBytes?: number | null
  playbackStatistic: boolean
  protectedFromRemoval: boolean
}

export interface AlbumAdditionalMetadataTag extends AdditionalMetadataTag {
  trackCount: number
}

export interface TrackDetail extends Track {
  createdAt?: string | null
  updatedAt?: string | null
  composers: string[]
  performers: string[]
  genres: NamedCatalogItem[]
  mediaFile: {
    id: number
    libraryRoot: NamedCatalogItem | null
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
    additionalTags: AdditionalMetadataTag[]
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

export interface Musician {
  id: number
  name: string
  sortName?: string | null
  disambiguation?: string | null
  albumCount: number
  trackCount: number
}

export interface MusicianRoleSummary {
  name: string
  albumCount: number
  trackCount: number
}

export interface MusicianAlbumCredits {
  roles: string[]
  creditedAs: string[]
  sources: string[]
  albumWide: boolean
  trackCreditCount: number
  guest: boolean
  additional: boolean
}

export interface MusicianDetail extends Musician {
  roles: MusicianRoleSummary[]
  creditedAs: string[]
  sources: string[]
  firstReleaseYear?: number | null
  lastReleaseYear?: number | null
  identity?: {
    provider: string
    reference: string
    sourceUrl?: string | null
  } | null
}

export interface MusicianCoverage {
  checkedAlbums: number
  creditedAlbums: number
  totalAlbums: number
  percentage: number
}

export interface CatalogMetrics {
  artists: number
  musicians: number
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

interface CatalogCacheEntry<T> {
  value: T
  expiresAt: number
}

export interface MusicianCatalogPage extends CatalogPage<Musician> {
  coverage: MusicianCoverage
}

interface CatalogQuery {
  page?: number
  search?: string
  initial?: string | null
  year?: number | string | null
  genre?: number | string | null
  artist?: number | string | null
  musician?: number | string | null
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
  if (query.musician !== undefined && query.musician !== null && String(query.musician).trim() !== '') {
    parameters.set('musician', String(query.musician).trim())
  }
  if (query.playStatus?.trim()) parameters.set('playStatus', query.playStatus.trim())
  if (query.physicalCopy?.trim()) parameters.set('physicalCopy', query.physicalCopy.trim())
  if (query.sort?.trim()) parameters.set('sort', query.sort.trim())
  return withLibraryRootScope(`${path}?${parameters}`)
}

const CATALOG_CACHE_TTL_MS = 10 * 60 * 1000
const MAX_CACHED_PAGES = 40

function readCachedPage<T>(cache: Map<string, CatalogCacheEntry<T>>, key: string): T | null {
  const entry = cache.get(key)
  if (!entry) return null
  if (entry.expiresAt <= Date.now()) {
    cache.delete(key)
    return null
  }

  cache.delete(key)
  cache.set(key, entry)

  return entry.value
}

function rememberPage<T>(cache: Map<string, CatalogCacheEntry<T>>, key: string, value: T) {
  cache.delete(key)
  cache.set(key, { value, expiresAt: Date.now() + CATALOG_CACHE_TTL_MS })
  if (cache.size <= MAX_CACHED_PAGES) return

  const oldestKey = cache.keys().next().value
  if (oldestKey) cache.delete(oldestKey)
}

export const useCatalogStore = defineStore('catalog', () => {
  const artists = ref<CatalogPage<Artist>>(emptyPage())
  const musicians = ref<MusicianCatalogPage>({
    ...emptyPage<Musician>(),
    coverage: { checkedAlbums: 0, creditedAlbums: 0, totalAlbums: 0, percentage: 0 },
  })
  const musicianDetail = ref<MusicianDetail | null>(null)
  const artistDetail = ref<ArtistDetail | null>(null)
  const albums = ref<CatalogPage<Album>>(emptyPage())
  const albumDetail = ref<AlbumDetail | null>(null)
  const tracks = ref<CatalogPage<Track>>(emptyPage())
  const trackDetail = ref<TrackDetail | null>(null)
  const genres = ref<CatalogPage<Genre>>(emptyPage())
  const artistsLoading = ref(false)
  const musiciansLoading = ref(false)
  const musicianDetailLoading = ref(false)
  const artistDetailLoading = ref(false)
  const albumsLoading = ref(false)
  const albumDetailLoading = ref(false)
  const tracksLoading = ref(false)
  const trackDetailLoading = ref(false)
  const genresLoading = ref(false)
  const artistsError = ref<string | null>(null)
  const musiciansError = ref<string | null>(null)
  const musicianDetailError = ref<string | null>(null)
  const artistDetailError = ref<string | null>(null)
  const albumsError = ref<string | null>(null)
  const albumDetailError = ref<string | null>(null)
  const tracksError = ref<string | null>(null)
  const trackDetailError = ref<string | null>(null)
  const genresError = ref<string | null>(null)
  const metrics = ref<CatalogMetrics>({ artists: 0, musicians: 0, albums: 0, tracks: 0, genres: 0, playedAlbums: 0, playedTracks: 0 })
  const metricsLoading = ref(false)
  const metricsLoaded = ref(false)
  const metricsError = ref<string | null>(null)
  let metricsRequest = 0
  let artistsRequest = 0
  let musiciansRequest = 0
  let musicianDetailRequest = 0
  let artistDetailRequest = 0
  let albumsRequest = 0
  let albumDetailRequest = 0
  let tracksRequest = 0
  let trackDetailRequest = 0
  let genresRequest = 0
  let albumsAbortController: AbortController | null = null
  let tracksAbortController: AbortController | null = null
  const artistPages = new Map<string, CatalogCacheEntry<CatalogPage<Artist>>>()
  const musicianPages = new Map<string, CatalogCacheEntry<MusicianCatalogPage>>()
  const albumPages = new Map<string, CatalogCacheEntry<CatalogPage<Album>>>()
  const trackPages = new Map<string, CatalogCacheEntry<CatalogPage<Track>>>()
  const genrePages = new Map<string, CatalogCacheEntry<CatalogPage<Genre>>>()

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

  function invalidateBrowseCache() {
    artistPages.clear()
    musicianPages.clear()
    albumPages.clear()
    trackPages.clear()
    genrePages.clear()
  }

  function invalidateCatalog() {
    invalidateMetrics()
    invalidateBrowseCache()
  }

  async function loadArtists(query: CatalogQuery = {}) {
    const request = ++artistsRequest
    const path = queryPath('/catalog/artists', query)
    const cached = readCachedPage(artistPages, path)
    if (cached) {
      artists.value = cached
      artistsLoading.value = false
      artistsError.value = null
      return
    }

    artistsLoading.value = true
    artistsError.value = null
    try {
      const result = await apiRequest<CatalogPage<Artist>>(path)
      if (request === artistsRequest) {
        rememberPage(artistPages, path, result)
        artists.value = result
      }
    } catch (cause) {
      if (request === artistsRequest) artistsError.value = errorMessage(cause)
    } finally {
      if (request === artistsRequest) artistsLoading.value = false
    }
  }

  async function loadMusicians(query: CatalogQuery = {}) {
    const request = ++musiciansRequest
    const path = queryPath('/catalog/musicians', query)
    const cached = readCachedPage(musicianPages, path)
    if (cached) {
      musicians.value = cached
      musiciansLoading.value = false
      musiciansError.value = null
      return
    }

    musiciansLoading.value = true
    musiciansError.value = null
    try {
      const result = await apiRequest<MusicianCatalogPage>(path)
      if (request === musiciansRequest) {
        rememberPage(musicianPages, path, result)
        musicians.value = result
      }
    } catch (cause) {
      if (request === musiciansRequest) musiciansError.value = errorMessage(cause)
    } finally {
      if (request === musiciansRequest) musiciansLoading.value = false
    }
  }

  async function loadMusician(id: number) {
    const request = ++musicianDetailRequest
    musicianDetail.value = null
    musicianDetailLoading.value = true
    musicianDetailError.value = null
    try {
      const result = await apiRequest<MusicianDetail>(withLibraryRootScope(`/catalog/musicians/${id}`))
      if (request === musicianDetailRequest) musicianDetail.value = result
    } catch (cause) {
      if (request === musicianDetailRequest) musicianDetailError.value = errorMessage(cause)
    } finally {
      if (request === musicianDetailRequest) musicianDetailLoading.value = false
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

  async function loadArtistPlaybackTracks(id: number, confirmationThreshold?: number) {
    const parameters = new URLSearchParams()
    if (confirmationThreshold !== undefined) {
      parameters.set('confirmationThreshold', String(confirmationThreshold))
    }
    const query = parameters.size ? `?${parameters}` : ''

    return apiRequest<ArtistPlaybackTracks>(
      withLibraryRootScope(`/catalog/artists/${id}/tracks${query}`),
    )
  }

  async function loadAlbums(query: CatalogQuery = {}) {
    const request = ++albumsRequest
    const path = queryPath('/catalog/albums', query)
    albumsAbortController?.abort()
    const cached = readCachedPage(albumPages, path)
    if (cached) {
      albums.value = cached
      albumsLoading.value = false
      albumsError.value = null
      albumsAbortController = null
      return
    }

    albumsAbortController = new AbortController()
    albumsLoading.value = true
    albumsError.value = null
    try {
      const result = await apiRequest<CatalogPage<Album>>(path, {
        signal: albumsAbortController.signal,
      })
      if (request === albumsRequest) {
        rememberPage(albumPages, path, result)
        albums.value = result
      }
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
    const path = queryPath('/catalog/tracks', query)
    tracksAbortController?.abort()
    const cached = readCachedPage(trackPages, path)
    if (cached) {
      tracks.value = cached
      tracksLoading.value = false
      tracksError.value = null
      tracksAbortController = null
      return
    }

    tracksAbortController = new AbortController()
    tracksLoading.value = true
    tracksError.value = null
    try {
      const result = await apiRequest<CatalogPage<Track>>(path, {
        signal: tracksAbortController.signal,
      })
      if (request === tracksRequest) {
        rememberPage(trackPages, path, result)
        tracks.value = result
      }
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

    applyAlbumPersonalMetadata(albumId, personalMetadata)

    return personalMetadata
  }

  async function updateAlbumPersonalNotes(albumId: number, notes: string | null) {
    const personalMetadata = await apiRequest<AlbumPersonalMetadata>(`/albums/${albumId}/personal-notes`, {
      method: 'PATCH',
      body: JSON.stringify({ notes }),
    })
    applyAlbumPersonalMetadata(albumId, personalMetadata)

    return personalMetadata
  }

  async function createOwnedAlbumCopy(albumId: number, values: OwnedAlbumCopyValues) {
    const personalMetadata = await apiRequest<AlbumPersonalMetadata>(`/albums/${albumId}/owned-copies`, {
      method: 'POST',
      body: JSON.stringify(values),
    })
    applyAlbumPersonalMetadata(albumId, personalMetadata)

    return personalMetadata
  }

  async function updateOwnedAlbumCopy(albumId: number, ownedCopyId: number, values: OwnedAlbumCopyValues) {
    const personalMetadata = await apiRequest<AlbumPersonalMetadata>(
      `/albums/${albumId}/owned-copies/${ownedCopyId}`,
      {
        method: 'PATCH',
        body: JSON.stringify(values),
      },
    )
    applyAlbumPersonalMetadata(albumId, personalMetadata)

    return personalMetadata
  }

  async function deleteOwnedAlbumCopy(albumId: number, ownedCopyId: number) {
    const personalMetadata = await apiRequest<AlbumPersonalMetadata>(
      `/albums/${albumId}/owned-copies/${ownedCopyId}`,
      { method: 'DELETE' },
    )
    applyAlbumPersonalMetadata(albumId, personalMetadata)

    return personalMetadata
  }

  async function searchDiscogsReleases(albumId: number, search: DiscogsReleaseSearch) {
    const parameters = new URLSearchParams()
    Object.entries(search).forEach(([key, value]) => {
      if (value !== undefined && value !== null && String(value).trim() !== '') {
        parameters.set(key, String(value).trim())
      }
    })
    const result = await apiRequest<{ items: DiscogsReleaseCandidate[] }>(
      `/albums/${albumId}/discogs/candidates?${parameters}`,
    )

    return result.items
  }

  async function loadDiscogsCollectionInstances(albumId: number, releaseId: number, refresh = false) {
    const suffix = refresh ? '?refresh=1' : ''
    const result = await apiRequest<{ items: DiscogsCollectionInstance[] }>(
      `/albums/${albumId}/discogs/releases/${releaseId}/collection-instances${suffix}`,
    )

    return result.items
  }

  async function loadOwnedCopyDiscogsDetails(albumId: number, ownedCopyId: number) {
    return apiRequest<DiscogsLinkedReleaseDetails>(
      `/albums/${albumId}/owned-copies/${ownedCopyId}/discogs`,
    )
  }

  async function linkOwnedCopyToDiscogs(
    albumId: number,
    ownedCopyId: number,
    releaseId: number,
    collectionInstanceId?: number | null,
  ) {
    const personalMetadata = await apiRequest<AlbumPersonalMetadata>(
      `/albums/${albumId}/owned-copies/${ownedCopyId}/discogs`,
      {
        method: 'PUT',
        body: JSON.stringify({ releaseId, collectionInstanceId: collectionInstanceId ?? null }),
      },
    )
    applyAlbumPersonalMetadata(albumId, personalMetadata)

    return personalMetadata
  }

  async function refreshOwnedCopyDiscogsLink(
    albumId: number,
    ownedCopyId: number,
    collectionInstanceId?: number | null,
  ) {
    const personalMetadata = await apiRequest<AlbumPersonalMetadata>(
      `/albums/${albumId}/owned-copies/${ownedCopyId}/discogs/refresh`,
      {
        method: 'POST',
        body: JSON.stringify({ collectionInstanceId: collectionInstanceId ?? null }),
      },
    )
    applyAlbumPersonalMetadata(albumId, personalMetadata)

    return personalMetadata
  }

  async function unlinkOwnedCopyFromDiscogs(albumId: number, ownedCopyId: number) {
    const personalMetadata = await apiRequest<AlbumPersonalMetadata>(
      `/albums/${albumId}/owned-copies/${ownedCopyId}/discogs`,
      { method: 'DELETE' },
    )
    applyAlbumPersonalMetadata(albumId, personalMetadata)

    return personalMetadata
  }

  function applyAlbumPersonalMetadata(albumId: number, personalMetadata: AlbumPersonalMetadata) {
    invalidateBrowseCache()
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

  }

  async function loadGenres(query: CatalogQuery = {}) {
    const request = ++genresRequest
    const path = queryPath('/catalog/genres', query)
    const cached = readCachedPage(genrePages, path)
    if (cached) {
      genres.value = cached
      genresLoading.value = false
      genresError.value = null
      return
    }

    genresLoading.value = true
    genresError.value = null
    try {
      const result = await apiRequest<CatalogPage<Genre>>(path)
      if (request === genresRequest) {
        rememberPage(genrePages, path, result)
        genres.value = result
      }
    } catch (cause) {
      if (request === genresRequest) genresError.value = errorMessage(cause)
    } finally {
      if (request === genresRequest) genresLoading.value = false
    }
  }

  function updateTrackPlayStatistics(trackId: number, statistics: TrackPlayStatistics) {
    invalidateBrowseCache()
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
    musicians,
    musicianDetail,
    artistDetail,
    albums,
    albumDetail,
    tracks,
    trackDetail,
    genres,
    artistsLoading,
    musiciansLoading,
    musicianDetailLoading,
    artistDetailLoading,
    albumsLoading,
    albumDetailLoading,
    tracksLoading,
    trackDetailLoading,
    genresLoading,
    artistsError,
    musiciansError,
    musicianDetailError,
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
    invalidateCatalog,
    loadArtists,
    loadMusicians,
    loadMusician,
    loadArtist,
    loadArtistPlaybackTracks,
    loadAlbums,
    loadAlbum,
    loadTracks,
    loadTrack,
    updateAlbumPersonalMetadata,
    updateAlbumPersonalNotes,
    createOwnedAlbumCopy,
    updateOwnedAlbumCopy,
    deleteOwnedAlbumCopy,
    searchDiscogsReleases,
    loadDiscogsCollectionInstances,
    loadOwnedCopyDiscogsDetails,
    linkOwnedCopyToDiscogs,
    refreshOwnedCopyDiscogsLink,
    unlinkOwnedCopyFromDiscogs,
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
