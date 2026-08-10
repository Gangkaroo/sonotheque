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

export interface PlaybackProgressState {
  currentTime: number
  duration: number
  ended: boolean
  paused: boolean
  seeking: boolean
}

export type PlaybackHandoffAction = 'playing' | 'play' | 'wait' | 'reload'

const HAVE_METADATA = 1
const HAVE_FUTURE_DATA = 3
const NETWORK_LOADING = 2

export function releaseMediaSource(element: ReleasableMediaElement) {
  element.pause()
  element.removeAttribute('src')
  element.load()
}

export function playbackHandoffAction(
  element: PlaybackHandoffMediaElement,
): PlaybackHandoffAction {
  if (!element.paused) {
    if (element.readyState >= HAVE_FUTURE_DATA) return 'playing'
    if (element.networkState === NETWORK_LOADING) return 'wait'

    return 'reload'
  }
  if (element.readyState >= HAVE_METADATA) return 'play'
  if (element.networkState === NETWORK_LOADING) return 'wait'

  return 'reload'
}

export function playbackProgressHasStalled(
  element: PlaybackProgressState,
  elapsedWithoutProgressMs: number,
  timeoutMs: number,
) {
  if (
    element.paused
    || element.seeking
    || element.ended
    || elapsedWithoutProgressMs < timeoutMs
  ) return false

  const remaining = element.duration - element.currentTime

  return !Number.isFinite(remaining) || remaining > 0.5
}
