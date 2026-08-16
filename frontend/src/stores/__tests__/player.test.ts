import { createPinia, setActivePinia } from 'pinia'
import { nextTick, watch } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { AlbumDetail, Track } from '@/stores/catalog'
import {
  usePlayerStore,
  type AlbumPlaybackScope,
  type TrackPlaybackScope,
} from '@/stores/player'

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
const emptyAlbumTechnical = {
  fileTypes: [],
  bitrateMinimum: null,
  bitrateMaximum: null,
  bitrateModes: [],
  encoderSettings: [],
}

describe('player store', () => {
  beforeEach(() => {
    vi.useRealTimers()
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

  it('refreshes queued metadata without restarting playback', () => {
    const player = usePlayerStore()
    player.playTrack(tracks[0], tracks)
    const sessionKey = player.playbackSessionKey

    player.refreshQueuedTracks([{
      ...tracks[0],
      title: 'Updated title',
      album: { id: 10, title: 'Updated album' },
    }])

    expect(player.currentTrack?.title).toBe('Updated title')
    expect(player.currentTrack?.album?.title).toBe('Updated album')
    expect(player.queue[1].title).toBe('Second')
    expect(player.playbackSessionKey).toBe(sessionKey)
  })

  it('publishes one settled playback identity when replacing an album queue', async () => {
    const player = usePlayerStore()
    const identities: string[] = []
    watch(
      () => `${player.currentTrack?.id ?? 'none'}:${player.playbackSessionKey}`,
      identity => identities.push(identity),
    )
    player.playAlbum({
      id: 10,
      title: 'Album',
      primaryArtist: { id: 100, name: 'Artist' },
      libraryRoot: null,
      trackCount: tracks.length,
      personalMetadata: { hasPhysicalCopy: false },
      genres: [],
      technical: emptyAlbumTechnical,
      tracks,
    })
    await nextTick()
    identities.length = 0

    const nextTrack: Track = {
      id: 3,
      title: 'Next album',
      streamUrl: '/api/tracks/3/stream',
      album: { id: 11, title: 'Next album' },
      artists: [{ id: 100, name: 'Artist' }],
    }
    player.playAlbum({
      id: 11,
      title: 'Next album',
      primaryArtist: { id: 100, name: 'Artist' },
      libraryRoot: null,
      trackCount: 1,
      personalMetadata: { hasPhysicalCopy: false },
      genres: [],
      technical: emptyAlbumTechnical,
      tracks: [nextTrack],
    })
    await nextTick()

    expect(identities).toEqual([`${nextTrack.id}:${player.playbackSessionKey}`])
  })

  it('loads and plays an album by id', async () => {
    const album: AlbumDetail = {
      id: 10,
      title: 'Album',
      primaryArtist: { id: 100, name: 'Artist' },
      libraryRoot: null,
      trackCount: tracks.length,
      personalMetadata: { hasPhysicalCopy: false },
      genres: [],
      technical: emptyAlbumTechnical,
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

  it('keeps a frozen album filter scope for continuous random playback', async () => {
    const nextTrack: Track = {
      id: 3,
      title: 'Scoped next',
      streamUrl: '/api/tracks/3/stream',
      album: { id: 11, title: 'Scoped next album' },
      artists: [{ id: 100, name: 'Artist' }],
    }
    const firstAlbum: AlbumDetail = {
      id: 10,
      title: 'Album',
      primaryArtist: { id: 100, name: 'Artist' },
      libraryRoot: null,
      trackCount: 1,
      personalMetadata: { hasPhysicalCopy: true },
      genres: [],
      technical: emptyAlbumTechnical,
      tracks: [tracks[0]],
    }
    const nextAlbum: AlbumDetail = {
      id: 11,
      title: 'Scoped next album',
      primaryArtist: { id: 100, name: 'Artist' },
      libraryRoot: null,
      trackCount: 1,
      personalMetadata: { hasPhysicalCopy: true },
      genres: [],
      technical: emptyAlbumTechnical,
      tracks: [nextTrack],
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(firstAlbum), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(nextAlbum), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const player = usePlayerStore()
    const scope: AlbumPlaybackScope = {
      type: 'albums',
      libraryRootId: 7,
      libraryRootName: 'Archive',
      search: 'Alpha Artist',
      initial: 'A',
      year: 2001,
      genreId: 12,
      genreName: 'Rock',
      musicianId: null,
      musicianName: '',
      physicalCopy: 'owned',
      sort: 'year_desc',
    }

    await player.playRandomAlbum(scope)
    player.setContinuousPlay(true)
    player.setRandomPlay(true)
    await player.next()

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/catalog/playback/albums/random?libraryRoot=7&search=Alpha+Artist&genre=12&physicalCopy=owned&initial=A&year=2001&sort=year_desc',
      expect.any(Object),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/catalog/playback/albums/random?exclude=10&libraryRoot=7&search=Alpha+Artist&genre=12&physicalCopy=owned&initial=A&year=2001&sort=year_desc',
      expect.any(Object),
    )
    expect(player.currentTrack?.title).toBe('Scoped next')
    expect(player.playbackScope).toEqual(scope)

    setActivePinia(createPinia())
    expect(usePlayerStore().playbackScope).toEqual(scope)
  })

  it('keeps a frozen track filter scope for continuous random playback', async () => {
    const nextTrack: Track = {
      id: 3,
      title: 'Scoped random track',
      streamUrl: '/api/tracks/3/stream',
      album: { id: 11, title: 'Other Album' },
      artists: [{ id: 101, name: 'Other Artist' }],
    }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(tracks[0]), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(nextTrack), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const player = usePlayerStore()
    const scope: TrackPlaybackScope = {
      type: 'tracks',
      libraryRootId: 7,
      libraryRootName: 'Archive',
      search: 'Alpha Track',
      artistId: 101,
      artistName: 'Alpha Artist',
      genreId: 12,
      genreName: 'Rock',
      musicianId: null,
      musicianName: '',
      playStatus: 'never',
      physicalCopy: 'owned',
      sort: 'year_desc',
    }

    await player.playRandomTrack(scope)
    player.setContinuousPlay(true)
    player.setRandomPlay(true)
    await player.next()

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/catalog/playback/tracks/random?libraryRoot=7&search=Alpha+Track&artist=101&genre=12&physicalCopy=owned&playStatus=never&sort=year_desc',
      expect.any(Object),
    )
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/catalog/playback/tracks/random?exclude=1&libraryRoot=7&search=Alpha+Track&artist=101&genre=12&physicalCopy=owned&playStatus=never&sort=year_desc',
      expect.any(Object),
    )
    expect(player.currentTrack?.title).toBe('Scoped random track')
    expect(player.playbackScope).toEqual(scope)
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

  it('continues with reviewed tracks without interrupting the current track', () => {
    const player = usePlayerStore()
    const matches: Track[] = [
      {
        id: 3,
        title: 'First match',
        streamUrl: '/api/tracks/3/stream',
        album: { id: 11, title: 'Other Album' },
        artists: [{ id: 101, name: 'Other Artist' }],
      },
      {
        id: 4,
        title: 'Second match',
        streamUrl: '/api/tracks/4/stream',
        album: { id: 12, title: 'Another Album' },
        artists: [{ id: 102, name: 'Another Artist' }],
      },
    ]

    player.playTrack(tracks[1], tracks, 'album')
    player.setPlaybackState('playing')
    const playbackSessionKey = player.playbackSessionKey

    player.continueWithTracks(matches)

    expect(player.queue.map((track) => track.title)).toEqual([
      'First',
      'Second',
      'First match',
      'Second match',
    ])
    expect(player.currentTrack?.title).toBe('Second')
    expect(player.currentIndex).toBe(1)
    expect(player.playbackState).toBe('playing')
    expect(player.playbackSessionKey).toBe(playbackSessionKey)
    expect(player.playbackContext).toBe('track-list')
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
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-23T10:00:00Z'))
    const player = usePlayerStore()

    player.setVolume(0.42)
    player.setContinuousPlay(true)
    player.setRandomPlay(true)
    player.setVisualizerEnabled(false)
    player.playTrack(tracks[1], tracks, 'album')
    vi.advanceTimersByTime(45_000)
    player.setPlaybackPosition(73)
    player.setListenedPlaybackMs(42_500)
    const playbackStartedAt = player.playbackStartedAt

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
    expect(restoredPlayer.listenedPlaybackMs).toBe(42_500)
    expect(restoredPlayer.playbackStartedAt).toBe(playbackStartedAt)
    expect(restoredPlayer.isPlaying).toBe(true)
    expect(restoredPlayer.playbackState).toBe('loading')
  })

  it('preserves accumulated listening across multiple page refreshes', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-28T12:00:00Z'))
    const player = usePlayerStore()

    player.playTrack(tracks[0], tracks, 'album')
    const sessionKey = player.playbackSessionKey
    vi.advanceTimersByTime(60_000)
    player.setListenedPlaybackMs(30_000)

    setActivePinia(createPinia())
    const firstRefresh = usePlayerStore()
    expect(firstRefresh.playbackSessionKey).toBe(sessionKey)
    expect(firstRefresh.listenedPlaybackMs).toBe(30_000)

    vi.advanceTimersByTime(60_000)
    firstRefresh.setListenedPlaybackMs(70_000)

    setActivePinia(createPinia())
    const secondRefresh = usePlayerStore()
    expect(secondRefresh.playbackSessionKey).toBe(sessionKey)
    expect(secondRefresh.listenedPlaybackMs).toBe(70_000)
  })

  it('resets accumulated listening time for a new playback session', () => {
    const player = usePlayerStore()
    const listenedTimeAtPlaybackChange: number[] = []
    watch(
      () => `${player.currentTrack?.id ?? 'none'}:${player.playbackSessionKey}`,
      () => listenedTimeAtPlaybackChange.push(player.listenedPlaybackMs),
      { flush: 'sync' },
    )

    player.playTrack(tracks[0], tracks)
    player.setListenedPlaybackMs(42_500)
    const firstSessionKey = player.playbackSessionKey
    listenedTimeAtPlaybackChange.length = 0

    player.playQueueIndex(1)

    expect(player.playbackSessionKey).not.toBe(firstSessionKey)
    expect(player.listenedPlaybackMs).toBe(0)
    expect(listenedTimeAtPlaybackChange.length).toBeGreaterThan(0)
    expect(listenedTimeAtPlaybackChange.every(value => value === 0)).toBe(true)
    expect(player.playbackStartedAt).not.toBeNull()
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

  it('discards an impossible persisted listening duration', () => {
    localStorage.setItem('sonotheque.player', JSON.stringify({
      queue: tracks,
      currentIndex: 0,
      isPlaying: true,
      playbackSessionKey: 'corrupted-session',
      countedPlaySessionKey: 'corrupted-session',
      listenedPlaybackMs: 9_000_000,
      playbackStartedAt: new Date(Date.now() - 2000).toISOString(),
    }))

    const player = usePlayerStore()

    expect(player.currentTrack?.title).toBe('First')
    expect(player.listenedPlaybackMs).toBe(0)
    expect(player.playbackSessionKey).not.toBe('corrupted-session')
    expect(player.countedPlaySessionKey).toBeNull()
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
