import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'
import type { Album, CatalogPage, Track } from '@/stores/catalog'
import { withLibraryRootScope } from '@/stores/libraryRootScope'

export interface RecentPlay {
  id: number
  playedAt: string | null
  track: Track
}

export interface MostPlayedAlbum extends Album {
  playCount: number
  lastPlayedAt?: string | null
}

function emptyPage<T>(): CatalogPage<T> {
  return { items: [], total: 0, page: 1, perPage: 0, lastPage: 1 }
}

export const useStatisticsStore = defineStore('statistics', () => {
  const recentPlays = ref<CatalogPage<RecentPlay>>(emptyPage())
  const mostPlayedTracks = ref<CatalogPage<Track>>(emptyPage())
  const mostPlayedAlbums = ref<CatalogPage<MostPlayedAlbum>>(emptyPage())
  const trackRecentPlays = ref<CatalogPage<RecentPlay>>(emptyPage())
  const recentPlaysLoading = ref(false)
  const mostPlayedTracksLoading = ref(false)
  const mostPlayedAlbumsLoading = ref(false)
  const trackRecentPlaysLoading = ref(false)
  const error = ref<string | null>(null)
  const trackRecentPlaysError = ref<string | null>(null)

  async function loadRecentPlays(page = 1) {
    recentPlaysLoading.value = true
    error.value = null
    try {
      recentPlays.value = await apiRequest<CatalogPage<RecentPlay>>(withLibraryRootScope(`/statistics/recent-plays?page=${page}`))
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      recentPlaysLoading.value = false
    }
  }

  async function loadMostPlayedTracks(page = 1) {
    mostPlayedTracksLoading.value = true
    error.value = null
    try {
      mostPlayedTracks.value = await apiRequest<CatalogPage<Track>>(withLibraryRootScope(`/statistics/most-played-tracks?page=${page}`))
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      mostPlayedTracksLoading.value = false
    }
  }

  async function loadMostPlayedAlbums(page = 1) {
    mostPlayedAlbumsLoading.value = true
    error.value = null
    try {
      mostPlayedAlbums.value = await apiRequest<CatalogPage<MostPlayedAlbum>>(withLibraryRootScope(`/statistics/most-played-albums?page=${page}`))
    } catch (cause) {
      error.value = errorMessage(cause)
    } finally {
      mostPlayedAlbumsLoading.value = false
    }
  }

  async function loadTrackRecentPlays(trackId: number, page = 1) {
    trackRecentPlaysLoading.value = true
    trackRecentPlaysError.value = null
    try {
      trackRecentPlays.value = await apiRequest<CatalogPage<RecentPlay>>(withLibraryRootScope(`/statistics/tracks/${trackId}/recent-plays?page=${page}`))
    } catch (cause) {
      trackRecentPlaysError.value = errorMessage(cause)
    } finally {
      trackRecentPlaysLoading.value = false
    }
  }

  return {
    recentPlays,
    mostPlayedTracks,
    mostPlayedAlbums,
    trackRecentPlays,
    recentPlaysLoading,
    mostPlayedTracksLoading,
    mostPlayedAlbumsLoading,
    trackRecentPlaysLoading,
    error,
    trackRecentPlaysError,
    loadRecentPlays,
    loadMostPlayedTracks,
    loadMostPlayedAlbums,
    loadTrackRecentPlays,
  }
})

function errorMessage(cause: unknown): string {
  return cause instanceof Error ? cause.message : 'Unable to load playback history.'
}
