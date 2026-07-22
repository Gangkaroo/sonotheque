import type { AudioSimilarityTrack } from '@/stores/audioIntelligenceSettings'
import type { Track } from '@/stores/catalog'

export function similarityTrackToPlayableTrack(track: AudioSimilarityTrack): Track {
  return {
    id: track.id,
    title: track.title,
    streamUrl: track.streamUrl,
    durationMs: track.durationMs ?? undefined,
    trackNumber: track.trackNumber ?? undefined,
    discNumber: track.discNumber ?? undefined,
    year: track.year,
    album: track.albumId === null
      ? null
      : {
          id: track.albumId,
          title: track.albumTitle,
          originalReleaseYear: track.albumOriginalReleaseYear,
          artworkThumbnailUrl: track.albumArtworkThumbnailUrl,
        },
    artists: track.artists,
    playStatistics: { playCount: 0 },
  }
}
