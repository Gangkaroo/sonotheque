import { describe, expect, it, vi } from 'vitest'

import { playbackHandoffAction, releaseMediaSource } from '@/utils/mediaPlayback'

describe('releaseMediaSource', () => {
  it('stops playback and aborts the current media request', () => {
    const element = {
      load: vi.fn(),
      pause: vi.fn(),
      removeAttribute: vi.fn(),
    }

    releaseMediaSource(element)

    expect(element.pause).toHaveBeenCalledOnce()
    expect(element.removeAttribute).toHaveBeenCalledWith('src')
    expect(element.load).toHaveBeenCalledOnce()
  })
})

describe('playbackHandoffAction', () => {
  it('recognizes playback that has already started', () => {
    expect(playbackHandoffAction({
      networkState: 2,
      paused: false,
      readyState: 4,
    })).toBe('playing')
  })

  it('waits when active playback is buffering an ongoing request', () => {
    expect(playbackHandoffAction({
      networkState: 2,
      paused: false,
      readyState: 2,
    })).toBe('wait')
  })

  it('reloads active playback when buffering has stopped making progress', () => {
    expect(playbackHandoffAction({
      networkState: 1,
      paused: false,
      readyState: 2,
    })).toBe('reload')
  })

  it('plays media that has metadata but remains paused', () => {
    expect(playbackHandoffAction({
      networkState: 1,
      paused: true,
      readyState: 1,
    })).toBe('play')
  })

  it('does not interrupt an active media request', () => {
    expect(playbackHandoffAction({
      networkState: 2,
      paused: true,
      readyState: 0,
    })).toBe('wait')
  })

  it('reloads media only when no request is progressing', () => {
    expect(playbackHandoffAction({
      networkState: 3,
      paused: true,
      readyState: 0,
    })).toBe('reload')
  })
})
