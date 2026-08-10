export interface MediaSessionTrackMetadata {
  album: string
  artist: string
  artworkUrl?: string | null
  title: string
}

export interface MediaSessionHandlers {
  nextTrack: () => void
  pause: () => void
  play: () => void
  previousTrack: () => void
}

const mediaSessionActions: Array<[MediaSessionAction, keyof MediaSessionHandlers]> = [
  ['play', 'play'],
  ['pause', 'pause'],
  ['previoustrack', 'previousTrack'],
  ['nexttrack', 'nextTrack'],
]

export function installMediaSessionHandlers(handlers: MediaSessionHandlers) {
  const mediaSession = currentMediaSession()
  if (!mediaSession) return () => undefined

  const installedActions: MediaSessionAction[] = []
  for (const [action, handler] of mediaSessionActions) {
    try {
      mediaSession.setActionHandler(action, handlers[handler])
      installedActions.push(action)
    } catch {
      // Browsers may expose Media Session while omitting individual actions.
    }
  }

  return () => {
    for (const action of installedActions) {
      try {
        mediaSession.setActionHandler(action, null)
      } catch {
        // The session may have been released by the browser already.
      }
    }
  }
}

export function updateMediaSessionMetadata(metadata: MediaSessionTrackMetadata | null) {
  const mediaSession = currentMediaSession()
  if (!mediaSession || typeof MediaMetadata === 'undefined') return

  mediaSession.metadata = metadata
    ? new MediaMetadata({
        album: metadata.album,
        artist: metadata.artist,
        artwork: metadata.artworkUrl
          ? [{ src: absoluteUrl(metadata.artworkUrl) }]
          : [],
        title: metadata.title,
      })
    : null
}

export function updateMediaSessionPlaybackState(hasTrack: boolean, isPlaying: boolean) {
  const mediaSession = currentMediaSession()
  if (!mediaSession) return

  mediaSession.playbackState = hasTrack
    ? isPlaying ? 'playing' : 'paused'
    : 'none'
}

export function updateMediaSessionPosition(position: number, duration: number) {
  const mediaSession = currentMediaSession()
  if (!mediaSession || typeof mediaSession.setPositionState !== 'function') return

  if (!Number.isFinite(duration) || duration <= 0) {
    mediaSession.setPositionState()
    return
  }

  mediaSession.setPositionState({
    duration,
    playbackRate: 1,
    position: Math.min(duration, Math.max(0, position)),
  })
}

export function clearMediaSession() {
  updateMediaSessionMetadata(null)
  updateMediaSessionPlaybackState(false, false)
  updateMediaSessionPosition(0, 0)
}

function currentMediaSession() {
  return typeof navigator !== 'undefined' && 'mediaSession' in navigator
    ? navigator.mediaSession
    : null
}

function absoluteUrl(url: string) {
  if (typeof window === 'undefined') return url

  return new URL(url, window.location.href).href
}
