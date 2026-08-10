import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  clearMediaSession,
  installMediaSessionHandlers,
  updateMediaSessionMetadata,
  updateMediaSessionPlaybackState,
  updateMediaSessionPosition,
} from '@/utils/mediaSession'

describe('media session integration', () => {
  const setActionHandler = vi.fn()
  const setPositionState = vi.fn()
  const mediaSession = {
    metadata: null as MediaMetadata | null,
    playbackState: 'none' as MediaSessionPlaybackState,
    setActionHandler,
    setPositionState,
  }

  beforeEach(() => {
    setActionHandler.mockReset()
    setPositionState.mockReset()
    mediaSession.metadata = null
    mediaSession.playbackState = 'none'
    Object.defineProperty(navigator, 'mediaSession', {
      configurable: true,
      value: mediaSession,
    })
    vi.stubGlobal('MediaMetadata', class {
      album?: string
      artist?: string
      artwork?: MediaImage[]
      title?: string

      constructor(init: MediaMetadataInit) {
        Object.assign(this, init)
      }
    })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    Reflect.deleteProperty(navigator, 'mediaSession')
  })

  it('installs and removes the supported media-key handlers', () => {
    const handlers = {
      nextTrack: vi.fn(),
      pause: vi.fn(),
      play: vi.fn(),
      previousTrack: vi.fn(),
    }

    const removeHandlers = installMediaSessionHandlers(handlers)

    expect(setActionHandler).toHaveBeenCalledWith('play', handlers.play)
    expect(setActionHandler).toHaveBeenCalledWith('pause', handlers.pause)
    expect(setActionHandler).toHaveBeenCalledWith('previoustrack', handlers.previousTrack)
    expect(setActionHandler).toHaveBeenCalledWith('nexttrack', handlers.nextTrack)

    removeHandlers()

    expect(setActionHandler).toHaveBeenCalledWith('play', null)
    expect(setActionHandler).toHaveBeenCalledWith('pause', null)
  })

  it('publishes track metadata and resolves relative artwork URLs', () => {
    updateMediaSessionMetadata({
      album: 'Album',
      artist: 'Artist',
      artworkUrl: '/api/albums/1/artwork/thumbnail',
      title: 'Track',
    })

    expect(mediaSession.metadata).toMatchObject({
      album: 'Album',
      artist: 'Artist',
      artwork: [{ src: 'http://localhost:3000/api/albums/1/artwork/thumbnail' }],
      title: 'Track',
    })
  })

  it('updates playback and position state safely', () => {
    updateMediaSessionPlaybackState(true, true)
    updateMediaSessionPosition(45, 180)

    expect(mediaSession.playbackState).toBe('playing')
    expect(setPositionState).toHaveBeenCalledWith({
      duration: 180,
      playbackRate: 1,
      position: 45,
    })

    clearMediaSession()

    expect(mediaSession.playbackState).toBe('none')
    expect(setPositionState).toHaveBeenLastCalledWith()
  })
})
