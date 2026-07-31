export interface ReleasableMediaElement {
  load: () => void
  pause: () => void
  removeAttribute: (name: string) => void
}

export interface PlaybackHandoffMediaElement {
  networkState: number
  paused: boolean
  readyState: number
}

export type PlaybackHandoffAction = 'playing' | 'play' | 'wait' | 'reload'

const HAVE_METADATA = 1
const NETWORK_LOADING = 2

export function releaseMediaSource(element: ReleasableMediaElement) {
  element.pause()
  element.removeAttribute('src')
  element.load()
}

export function playbackHandoffAction(
  element: PlaybackHandoffMediaElement,
): PlaybackHandoffAction {
  if (!element.paused) return 'playing'
  if (element.readyState >= HAVE_METADATA) return 'play'
  if (element.networkState === NETWORK_LOADING) return 'wait'

  return 'reload'
}
