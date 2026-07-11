import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { AlbumDetail, Track } from '@/stores/catalog'
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
    localStorage.clear()
    vi.unstubAllGlobals()
  })

  it('plays a track with its page queue and moves through the queue', async () => {
    const player = usePlayerStore()

    player.playTrack(tracks[0], tracks)

    expect(player.currentTrack?.title).toBe('First')
    expect(player.isPlaying).toBe(true)
    expect(player.playbackState).toBe('loading')
    expect(player.hasNext).toBe(true)

    await player.next()
    expect(player.currentTrack?.title).toBe('Second')
    expect(player.playbackState).toBe('loading')
    expect(player.hasPrevious).toBe(true)

    player.previous()
    expect(player.currentTrack?.title).toBe('First')
    expect(player.playbackState).toBe('loading')
  })

  it('loads and plays an album by id', async () => {
    const album: AlbumDetail = {
      id: 10,
      title: 'Album',
      primaryArtist: { id: 100, name: 'Artist' },
      trackCount: tracks.length,
      personalMetadata: { hasPhysicalCopy: false },
      genres: [],
      tracks,
    }
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(album), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const player = usePlayerStore()

    await player.playAlbumById(album.id)

    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/albums/10', expect.any(Object))
    expect(player.queue).toHaveLength(2)
    expect(player.currentTrack?.title).toBe('First')
    expect(player.playbackContext).toBe('album')
  })

  it('loads a random next track when continuous random track-list playback is enabled', async () => {
    const nextTrack: Track = {
      id: 3,
      title: 'Random next',
      streamUrl: '/api/tracks/3/stream',
      album: { id: 11, title: 'Other Album' },
      artists: [{ id: 101, name: 'Other Artist' }],
    }
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(nextTrack), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const player = usePlayerStore()

    player.playTrack(tracks[0], tracks, 'track-list')
    player.setContinuousPlay(true)
    player.setRandomPlay(true)
    await player.next()

    expect(player.currentTrack?.title).toBe('Random next')
    expect(player.playbackContext).toBe('track-list')
    expect(fetchMock).toHaveBeenCalledWith('/api/catalog/playback/tracks/random?exclude=1', expect.any(Object))
  })

  it('queues tracks without interrupting the current track', () => {
    const player = usePlayerStore()
    const queuedTrack: Track = {
      id: 3,
      title: 'Queued',
      streamUrl: '/api/tracks/3/stream',
      album: { id: 11, title: 'Other Album' },
      artists: [{ id: 101, name: 'Other Artist' }],
    }

    player.playTrack(tracks[0], tracks, 'track-list')
    player.setPlaybackState('playing')
    player.queueTrack(queuedTrack)

    expect(player.queue.map((track) => track.title)).toEqual(['First', 'Second', 'Queued'])
    expect(player.currentTrack?.title).toBe('First')
    expect(player.playbackState).toBe('playing')
  })

  it('prepares queued tracks paused when the queue is empty', () => {
    const player = usePlayerStore()

    player.queueTracks(tracks, 'album')

    expect(player.queue).toHaveLength(2)
    expect(player.currentTrack?.title).toBe('First')
    expect(player.isPlaying).toBe(false)
    expect(player.playbackState).toBe('paused')
    expect(player.playbackContext).toBe('album')
  })

  it('jumps to queued tracks and removes queue entries', () => {
    const player = usePlayerStore()

    player.playTrack(tracks[0], tracks)
    player.playQueueIndex(1)
    expect(player.currentTrack?.title).toBe('Second')
    expect(player.playbackState).toBe('loading')

    player.removeQueuedTrack(0)
    expect(player.queue.map((track) => track.title)).toEqual(['Second'])
    expect(player.currentTrack?.title).toBe('Second')

    player.removeQueuedTrack(0)
    expect(player.queue).toEqual([])
    expect(player.currentTrack).toBeNull()
    expect(player.playbackState).toBe('idle')
  })

  it('removes the current queue entry and continues with the next track', () => {
    const player = usePlayerStore()

    player.playTrack(tracks[0], tracks)
    player.setPlaybackState('playing')

    player.removeQueuedTrack(0)

    expect(player.queue.map((track) => track.title)).toEqual(['Second'])
    expect(player.currentTrack?.title).toBe('Second')
    expect(player.isPlaying).toBe(true)
    expect(player.playbackState).toBe('loading')
  })

  it('moves queued tracks to a target position without changing the active track', () => {
    const player = usePlayerStore()

    player.playTrack(tracks[0], tracks)
    player.playQueueIndex(1)

    player.moveQueuedTrack(1, 0)

    expect(player.queue.map((track) => track.title)).toEqual(['Second', 'First'])
    expect(player.currentTrack?.title).toBe('Second')
    expect(player.currentIndex).toBe(0)
  })

  it('clears the queue', () => {
    const player = usePlayerStore()

    player.playTrack(tracks[0], tracks)
    player.clearQueue()

    expect(player.queue).toEqual([])
    expect(player.currentTrack).toBeNull()
    expect(player.playbackState).toBe('idle')
  })

  it('stops playback and records playback errors', () => {
    const player = usePlayerStore()

    player.playTrack(tracks[0], tracks)
    player.setError('Nope')

    expect(player.error).toBe('Nope')
    expect(player.isPlaying).toBe(false)
    expect(player.playbackState).toBe('error')

    player.stop()
    expect(player.currentTrack).toBeNull()
    expect(player.queue).toEqual([])
    expect(player.playbackState).toBe('idle')
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

  it('persists player settings, queue, context, position, and active playback between store instances', () => {
    const player = usePlayerStore()

    player.setVolume(0.42)
    player.setContinuousPlay(true)
    player.setRandomPlay(true)
    player.setVisualizerEnabled(false)
    player.playTrack(tracks[1], tracks, 'album')
    player.setPlaybackPosition(73)

    setActivePinia(createPinia())
    const restoredPlayer = usePlayerStore()

    expect(restoredPlayer.volume).toBe(0.42)
    expect(restoredPlayer.continuousPlay).toBe(true)
    expect(restoredPlayer.randomPlay).toBe(true)
    expect(restoredPlayer.visualizerEnabled).toBe(false)
    expect(restoredPlayer.currentTrack?.title).toBe('Second')
    expect(restoredPlayer.queue).toHaveLength(2)
    expect(restoredPlayer.playbackContext).toBe('album')
    expect(restoredPlayer.playbackPosition).toBe(73)
    expect(restoredPlayer.isPlaying).toBe(true)
    expect(restoredPlayer.playbackState).toBe('loading')
  })

  it('restores paused playback as paused between store instances', () => {
    const player = usePlayerStore()

    player.playTrack(tracks[1], tracks, 'album')
    player.setPlaybackPosition(73)
    player.pause()

    setActivePinia(createPinia())
    const restoredPlayer = usePlayerStore()

    expect(restoredPlayer.currentTrack?.title).toBe('Second')
    expect(restoredPlayer.playbackPosition).toBe(73)
    expect(restoredPlayer.isPlaying).toBe(false)
    expect(restoredPlayer.playbackState).toBe('paused')
  })

  it('clears the persisted queue on stop without resetting player settings', () => {
    const player = usePlayerStore()

    player.setVolume(0.3)
    player.setContinuousPlay(true)
    player.playTrack(tracks[0], tracks, 'track-list')
    player.setPlaybackPosition(11)
    player.stop()

    setActivePinia(createPinia())
    const restoredPlayer = usePlayerStore()

    expect(restoredPlayer.currentTrack).toBeNull()
    expect(restoredPlayer.queue).toEqual([])
    expect(restoredPlayer.playbackPosition).toBe(0)
    expect(restoredPlayer.volume).toBe(0.3)
    expect(restoredPlayer.continuousPlay).toBe(true)
  })

  it('moves into ended state when the queue finishes without continuous play', async () => {
    const player = usePlayerStore()

    player.playTrack(tracks[1], tracks, 'track-list')
    await player.next()

    expect(player.isPlaying).toBe(false)
    expect(player.playbackState).toBe('ended')
    expect(player.currentTrack?.title).toBe('Second')
  })

  it('allows media events to update playback state', () => {
    const player = usePlayerStore()

    player.playTrack(tracks[0], tracks)
    player.setPlaybackState('playing')

    expect(player.isPlaying).toBe(true)
    expect(player.playbackState).toBe('playing')

    player.setPlaybackState('paused')

    expect(player.isPlaying).toBe(false)
    expect(player.playbackState).toBe('paused')
  })
})
