import { describe, expect, it, vi } from 'vitest'

import { releaseMediaSource } from '@/utils/mediaPlayback'

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
