import { describe, expect, it, vi } from 'vitest'

import {
  playbackHandoffAction,
  playbackProgressHasStalled,
  releaseMediaSource,
} from '@/utils/mediaPlayback'

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

describe('playbackProgressHasStalled', () => {
  const playing = {
    currentTime: 60,
    duration: 180,
    ended: false,
    paused: false,
    seeking: false,
  }

  it('detects playback whose media clock has stopped for too long', () => {
    expect(playbackProgressHasStalled(playing, 10_000, 10_000)).toBe(true)
  })

  it('does not treat ordinary buffering or a seek as a confirmed stall', () => {
    expect(playbackProgressHasStalled(playing, 9_999, 10_000)).toBe(false)
    expect(playbackProgressHasStalled({ ...playing, seeking: true }, 20_000, 10_000)).toBe(false)
  })

  it('does not recover paused, ended, or nearly completed media', () => {
    expect(playbackProgressHasStalled({ ...playing, paused: true }, 20_000, 10_000)).toBe(false)
    expect(playbackProgressHasStalled({ ...playing, ended: true }, 20_000, 10_000)).toBe(false)
    expect(playbackProgressHasStalled({ ...playing, currentTime: 179.75 }, 20_000, 10_000)).toBe(false)
  })
})
