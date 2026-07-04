import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'

export type EnrichmentStatus = 'disabled' | 'error' | 'not_configured' | 'not_found' | 'pending' | 'ready'
export type EnrichmentErrorCode =
  | 'connection'
  | 'invalid_response'
  | 'provider_error'
  | 'provider_unavailable'
  | 'rate_limited'
  | 'timeout'
  | 'tls_certificate'

interface Attribution {
  provider: string
  label: string
  sourceUrl?: string | null
}

export interface ArtistInformation {
  name: string
  biography?: string | null
  country?: string | null
  activeFrom?: string | null
  activeTo?: string | null
  tags: string[]
  attribution: Attribution
}

export interface AlbumInformation {
  title: string
  artistName: string
  summary?: string | null
  releaseDate?: string | null
  label?: string | null
  releaseType?: string | null
  tags: string[]
  attribution: Attribution
}

export interface LyricsInformation {
  plainLyrics?: string | null
  synchronizedLyrics?: string | null
  language?: string | null
  instrumental: boolean
  attribution: Attribution
}

export interface EnrichmentResult<T> {
  status: EnrichmentStatus
  provider?: string | null
  cached: boolean
  stale: boolean
  data?: T | null
  errorCode?: EnrichmentErrorCode | null
}

interface TrackInformation {
  artist: EnrichmentResult<ArtistInformation>
  album: EnrichmentResult<AlbumInformation>
}

export const useOnlineEnrichmentStore = defineStore('onlineEnrichment', () => {
  const information = ref<TrackInformation | null>(null)
  const lyrics = ref<EnrichmentResult<LyricsInformation> | null>(null)
  const informationLoading = ref(false)
  const lyricsLoading = ref(false)
  const informationError = ref<string | null>(null)
  const lyricsError = ref<string | null>(null)
  let informationRequest = 0
  let lyricsRequest = 0

  async function loadInformation(trackId: number, language: string) {
    const request = ++informationRequest
    information.value = null
    informationError.value = null
    informationLoading.value = true

    try {
      const result = await apiRequest<TrackInformation>(
        `/enrichment/tracks/${trackId}/information?language=${encodeURIComponent(language)}`,
      )
      if (request === informationRequest) information.value = result
    } catch (cause) {
      if (request === informationRequest) informationError.value = errorMessage(cause)
    } finally {
      if (request === informationRequest) informationLoading.value = false
    }
  }

  async function loadLyrics(trackId: number) {
    const request = ++lyricsRequest
    lyrics.value = null
    lyricsError.value = null
    lyricsLoading.value = true

    try {
      const result = await apiRequest<EnrichmentResult<LyricsInformation>>(
        `/enrichment/tracks/${trackId}/lyrics`,
      )
      if (request === lyricsRequest) lyrics.value = result
    } catch (cause) {
      if (request === lyricsRequest) lyricsError.value = errorMessage(cause)
    } finally {
      if (request === lyricsRequest) lyricsLoading.value = false
    }
  }

  return {
    information,
    lyrics,
    informationLoading,
    lyricsLoading,
    informationError,
    lyricsError,
    loadInformation,
    loadLyrics,
  }
})

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Online information could not be loaded.'
}
