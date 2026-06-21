import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'

import type { Track } from '@/stores/catalog'
import { usePlayerStore } from '@/stores/player'

const tracks: Track[] = [
  {
    id: 1,
    title: 'First',
    streamUrl: '/api/tracks/1/stream',
    album: { id: 10, title: 'Album' },
    artists: [{ id: 100, name: 'Artist' }],
  },
  {
    id: 2,
    title: 'Second',
    streamUrl: '/api/tracks/2/stream',
    album: { id: 10, title: 'Album' },
    artists: [{ id: 100, name: 'Artist' }],
  },
]

describe('player store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('plays a track with its page queue and moves through the queue', () => {
    const player = usePlayerStore()

    player.playTrack(tracks[0], tracks)

    expect(player.currentTrack?.title).toBe('First')
    expect(player.isPlaying).toBe(true)
    expect(player.hasNext).toBe(true)

    player.next()
    expect(player.currentTrack?.title).toBe('Second')
    expect(player.hasPrevious).toBe(true)

    player.previous()
    expect(player.currentTrack?.title).toBe('First')
  })

  it('stops playback and records playback errors', () => {
    const player = usePlayerStore()

    player.playTrack(tracks[0], tracks)
    player.setError('Nope')

    expect(player.error).toBe('Nope')
    expect(player.isPlaying).toBe(false)

    player.stop()
    expect(player.currentTrack).toBeNull()
    expect(player.queue).toEqual([])
  })

  it('keeps volume between silent and full volume', () => {
    const player = usePlayerStore()

    player.setVolume(0.42)
    expect(player.volume).toBe(0.42)

    player.setVolume(2)
    expect(player.volume).toBe(1)

    player.setVolume(-1)
    expect(player.volume).toBe(0)
  })
})
