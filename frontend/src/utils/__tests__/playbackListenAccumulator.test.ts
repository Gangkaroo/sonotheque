import { describe, expect, it } from 'vitest'

import { PlaybackListenAccumulator } from '@/utils/playbackListenAccumulator'

describe('PlaybackListenAccumulator', () => {
  it('counts only media progress while playback is active', () => {
    const accumulator = new PlaybackListenAccumulator()

    accumulator.resume(10)
    accumulator.observe(14.5)
    accumulator.suspend(16)
    accumulator.observe(40)

    expect(accumulator.elapsedMs()).toBe(6000)
  })

  it('does not count a seek between playback segments', () => {
    const accumulator = new PlaybackListenAccumulator()

    accumulator.resume(10)
    accumulator.observe(15)
    accumulator.suspend()
    accumulator.resume(180)
    accumulator.observe(184)

    expect(accumulator.elapsedMs()).toBe(9000)
  })

  it('retains accumulated listening time across restoration', () => {
    const accumulator = new PlaybackListenAccumulator(45_000)

    accumulator.resume(120)
    accumulator.observe(135)

    expect(accumulator.elapsedMs()).toBe(60_000)
  })

  it('ignores backward media movement without reducing accumulated time', () => {
    const accumulator = new PlaybackListenAccumulator()

    accumulator.resume(20)
    accumulator.observe(25)
    accumulator.observe(5)
    accumulator.observe(8)

    expect(accumulator.elapsedMs()).toBe(8000)
  })
})
