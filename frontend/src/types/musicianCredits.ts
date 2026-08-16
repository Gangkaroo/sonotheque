export interface MusicBrainzReleaseCandidate {
  id: string
  title?: string | null
  artistName?: string | null
  date?: string | null
  country?: string | null
  status?: string | null
  formats: string[]
  trackCount?: number | null
  barcode?: string | null
  score?: number | null
  sourceUrl?: string | null
}
