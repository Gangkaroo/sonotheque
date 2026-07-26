export interface PlaylistFileExportResult {
  format: 'm3u' | 'm3u8'
  filename: string
  trackCount: number
  sizeBytes: number
  destinationPath: string
  location: {
    id: number
    name: string
    path: string
  }
}
