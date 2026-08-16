import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'
import { withLibraryRootScope } from '@/stores/libraryRootScope'
import type { MusicBrainzReleaseCandidate } from '@/types/musicianCredits'

export type MusicianReviewStatus = 'ambiguous' | 'failed' | 'reviewed'
export type MusicianReviewDecision = 'dismissed' | 'no_suitable_match'

interface MusicianReviewAlbum {
  id: number
  title: string
  originalReleaseYear?: number | null
  trackCount: number
  artworkThumbnailUrl?: string | null
  primaryArtist: { id: number, name: string } | null
  libraryRoot: { id: number, name: string }
}

export interface MusicianReviewItem {
  album: MusicianReviewAlbum
  status: 'ambiguous' | 'error'
  lookupVersion: number
  candidateReleases: MusicBrainzReleaseCandidate[]
  errorCode?: string | null
  failureCount: number
  retryAfter?: string | null
  fetchedAt?: string | null
  review?: {
    decision: MusicianReviewDecision
    reviewedAt?: string | null
  } | null
}

export interface MusicianReviewPage {
  items: MusicianReviewItem[]
  total: number
  page: number
  perPage: number
  lastPage: number
  counts: {
    ambiguous: number
    failed: number
    reviewed: number
  }
}

const emptyPage = (): MusicianReviewPage => ({
  items: [],
  total: 0,
  page: 1,
  perPage: 20,
  lastPage: 1,
  counts: { ambiguous: 0, failed: 0, reviewed: 0 },
})

export const useMusicianReviewsStore = defineStore('musicianReviews', () => {
  const results = ref<MusicianReviewPage>(emptyPage())
  const loading = ref(false)
  const error = ref<string | null>(null)
  let loadRequestId = 0

  async function load(status: MusicianReviewStatus, page = 1) {
    const requestId = ++loadRequestId
    loading.value = true
    error.value = null
    try {
      const response = await apiRequest<MusicianReviewPage>(withLibraryRootScope(
        `/musician-reviews?status=${status}&page=${page}`,
      ))
      if (requestId === loadRequestId) results.value = response
    } catch (cause) {
      if (requestId === loadRequestId) error.value = message(cause)
    } finally {
      if (requestId === loadRequestId) loading.value = false
    }
  }

  function selectRelease(albumId: number, releaseId: string) {
    return apiRequest(`/enrichment/albums/${albumId}/musicians/release`, {
      method: 'PUT',
      body: JSON.stringify({ releaseId }),
    })
  }

  function retry(albumId: number) {
    return apiRequest(withLibraryRootScope(`/musician-reviews/${albumId}/retry`), {
      method: 'POST',
    })
  }

  function decide(albumId: number, decision: MusicianReviewDecision) {
    return apiRequest(withLibraryRootScope(`/musician-reviews/${albumId}/decision`), {
      method: 'PUT',
      body: JSON.stringify({ decision }),
    })
  }

  function reopen(albumId: number) {
    return apiRequest(withLibraryRootScope(`/musician-reviews/${albumId}/decision`), {
      method: 'DELETE',
    })
  }

  return { results, loading, error, load, selectRelease, retry, decide, reopen }
})

function message(cause: unknown) {
  return cause instanceof Error ? cause.message : 'Musician review items could not be loaded.'
}
