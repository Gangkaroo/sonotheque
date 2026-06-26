import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'

import { apiRequest } from '@/api/client'
import type { AlbumDetail, Track } from '@/stores/catalog'

export type PlayableTrack = Track
export type PlaybackContext = 'album' | 'track-list' | null
export type PlaybackState = 'idle' | 'loading' | 'playing' | 'paused' | 'ended' | 'error'

const STORAGE_KEY = 'music-library.player'

interface PersistedPlayerState {
  queue?: PlayableTrack[]
  currentIndex?: number
  isPlaying?: boolean
  volume?: number
  continuousPlay?: boolean
  randomPlay?: boolean
  playbackContext?: PlaybackContext
  playbackPosition?: number
  playbackSessionKey?: string | null
  countedPlaySessionKey?: string | null
}

export const usePlayerStore = defineStore('player', () => {
  const persistedState = readPersistedState()
  const queue = ref<PlayableTrack[]>(persistedState.queue ?? [])
  const currentIndex = ref(normalizeCurrentIndex(persistedState.currentIndex, queue.value))
  const shouldRestorePlayback = currentIndex.value >= 0 && persistedState.isPlaying === true
  const isPlaying = ref(shouldRestorePlayback)
  const playbackState = ref<PlaybackState>(currentIndex.value >= 0 ? shouldRestorePlayback ? 'loading' : 'paused' : 'idle')
  const error = ref<string | null>(null)
  const volume = ref(clampVolume(persistedState.volume ?? 1))
  const continuousPlay = ref(persistedState.continuousPlay ?? false)
  const randomPlay = ref(persistedState.randomPlay ?? false)
  const playbackContext = ref<PlaybackContext>(persistedState.playbackContext ?? null)
  const playbackPosition = ref(normalizePlaybackPosition(persistedState.playbackPosition))
  const playbackSessionKey = ref(persistedState.playbackSessionKey ?? createPlaybackSessionKey())
  const countedPlaySessionKey = ref(persistedState.countedPlaySessionKey ?? null)
  const loadingNext = ref(false)

  const currentTrack = computed(() => {
    return currentIndex.value >= 0 ? queue.value[currentIndex.value] ?? null : null
  })
  const hasPrevious = computed(() => currentIndex.value > 0)
  const hasNext = computed(() => currentIndex.value >= 0 && (currentIndex.value < queue.value.length - 1 || continuousPlay.value))

  function playTrack(track: PlayableTrack, tracks: PlayableTrack[] = [track], context: PlaybackContext = null) {
    const nextQueue = tracks.length ? [...tracks] : [track]
    const index = nextQueue.findIndex((item) => item.id === track.id)

    queue.value = nextQueue
    currentIndex.value = index >= 0 ? index : 0
    isPlaying.value = true
    playbackState.value = 'loading'
    error.value = null
    playbackContext.value = context
    playbackPosition.value = 0
    startNewPlaybackSession()
  }

  function playAlbum(album: AlbumDetail) {
    const [firstTrack] = album.tracks
    if (!firstTrack) return

    playTrack(firstTrack, album.tracks, 'album')
  }

  function queueTracks(tracks: PlayableTrack[], context: PlaybackContext = null) {
    if (!tracks.length) return

    const wasEmpty = queue.value.length === 0 || currentIndex.value < 0
    queue.value = [...queue.value, ...tracks]

    if (wasEmpty) {
      currentIndex.value = 0
      isPlaying.value = false
      playbackState.value = 'paused'
      playbackContext.value = context
      playbackPosition.value = 0
    }
  }

  function queueTrack(track: PlayableTrack, context: PlaybackContext = null) {
    queueTracks([track], context)
  }

  function queueAlbum(album: AlbumDetail) {
    queueTracks(album.tracks, 'album')
  }

  function playQueueIndex(index: number) {
    if (!Number.isInteger(index) || index < 0 || index >= queue.value.length) return

    currentIndex.value = index
    isPlaying.value = true
    playbackState.value = 'loading'
    error.value = null
    playbackPosition.value = 0
    startNewPlaybackSession()
  }

  function removeQueuedTrack(index: number) {
    if (!Number.isInteger(index) || index < 0 || index >= queue.value.length) return

    const removedCurrentTrack = index === currentIndex.value
    const wasPlaying = isPlaying.value
    const nextQueue = queue.value.filter((_, itemIndex) => itemIndex !== index)

    if (!nextQueue.length) {
      stop()
      return
    }

    queue.value = nextQueue

    if (!removedCurrentTrack) {
      if (index < currentIndex.value) currentIndex.value -= 1
      return
    }

    currentIndex.value = Math.min(index, queue.value.length - 1)
    playbackPosition.value = 0
    error.value = null
    isPlaying.value = wasPlaying
    playbackState.value = wasPlaying ? 'loading' : 'paused'
    startNewPlaybackSession()
  }

  function moveQueuedTrack(index: number, targetIndex: number) {
    if (
      !Number.isInteger(index)
      || !Number.isInteger(targetIndex)
      || index < 0
      || targetIndex < 0
      || index >= queue.value.length
      || targetIndex >= queue.value.length
      || index === targetIndex
    ) return

    const current = currentTrack.value
    const nextQueue = [...queue.value]
    const item = nextQueue[index]
    if (!item) return

    nextQueue.splice(index, 1)
    nextQueue.splice(targetIndex, 0, item)
    queue.value = nextQueue
    currentIndex.value = current ? queue.value.indexOf(current) : -1
  }

  function clearQueue() {
    stop()
  }

  async function playRandomAlbum() {
    try {
      const album = await apiRequest<AlbumDetail>('/catalog/playback/albums/random')
      playAlbum(album)
    } catch (cause) {
      setError(errorMessage(cause))
    }
  }

  async function playRandomTrack() {
    try {
      const track = await apiRequest<PlayableTrack>('/catalog/playback/tracks/random')
      playTrack(track, [track], 'track-list')
    } catch (cause) {
      setError(errorMessage(cause))
    }
  }

  function previous() {
    if (!hasPrevious.value) return
    currentIndex.value -= 1
    isPlaying.value = true
    playbackState.value = 'loading'
    error.value = null
    playbackPosition.value = 0
    startNewPlaybackSession()
  }

  async function next() {
    if (!currentTrack.value) {
      isPlaying.value = false
      return
    }

    if (playbackContext.value === 'track-list' && continuousPlay.value && randomPlay.value) {
      await loadNextTrack(true)
      return
    }

    if (currentIndex.value < queue.value.length - 1) {
      currentIndex.value += 1
      isPlaying.value = true
      playbackState.value = 'loading'
      error.value = null
      playbackPosition.value = 0
      startNewPlaybackSession()
      return
    }

    if (!continuousPlay.value) {
      isPlaying.value = false
      playbackState.value = 'ended'
      return
    }

    if (playbackContext.value === 'album') {
      await loadNextAlbum(randomPlay.value)
      return
    }

    await loadNextTrack(randomPlay.value)
  }

  function pause() {
    isPlaying.value = false
    playbackState.value = currentTrack.value ? 'paused' : 'idle'
  }

  function resume() {
    if (!currentTrack.value) return
    isPlaying.value = true
    playbackState.value = 'loading'
    error.value = null
  }

  function stop() {
    currentIndex.value = -1
    queue.value = []
    isPlaying.value = false
    playbackState.value = 'idle'
    error.value = null
    playbackContext.value = null
    playbackPosition.value = 0
    playbackSessionKey.value = createPlaybackSessionKey()
    countedPlaySessionKey.value = null
  }

  function setError(message: string) {
    error.value = message
    isPlaying.value = false
    playbackState.value = 'error'
  }

  function setVolume(value: number) {
    volume.value = clampVolume(value)
  }

  function setContinuousPlay(value: boolean) {
    continuousPlay.value = value
  }

  function setRandomPlay(value: boolean) {
    randomPlay.value = value
  }

  function setPlaybackPosition(value: number) {
    const nextPosition = normalizePlaybackPosition(value)
    if (Math.abs(nextPosition - playbackPosition.value) < 0.5) return

    playbackPosition.value = nextPosition
  }

  function setPlaybackState(state: PlaybackState) {
    if (state === 'playing') {
      isPlaying.value = true
      error.value = null
    }

    if (state === 'paused' || state === 'ended' || state === 'idle') {
      isPlaying.value = false
    }

    playbackState.value = state
  }

  function markCurrentPlayCounted(sessionKey: string) {
    if (sessionKey === playbackSessionKey.value) countedPlaySessionKey.value = sessionKey
  }

  function startNewPlaybackSession() {
    playbackSessionKey.value = createPlaybackSessionKey()
    countedPlaySessionKey.value = null
  }

  async function loadNextAlbum(random: boolean) {
    if (!currentTrack.value?.album?.id || loadingNext.value) return

    loadingNext.value = true
    try {
      const path = random
        ? `/catalog/playback/albums/random?exclude=${currentTrack.value.album.id}`
        : `/catalog/playback/albums/${currentTrack.value.album.id}/next`
      const album = await apiRequest<AlbumDetail>(path)
      playAlbum(album)
    } catch (cause) {
      setError(errorMessage(cause))
    } finally {
      loadingNext.value = false
    }
  }

  async function loadNextTrack(random: boolean) {
    if (!currentTrack.value || loadingNext.value) return

    loadingNext.value = true
    try {
      const path = random
        ? `/catalog/playback/tracks/random?exclude=${currentTrack.value.id}`
        : `/catalog/playback/tracks/${currentTrack.value.id}/next`
      const track = await apiRequest<PlayableTrack>(path)
      playTrack(track, [track], 'track-list')
    } catch (cause) {
      setError(errorMessage(cause))
    } finally {
      loadingNext.value = false
    }
  }

  watch(
    () => ({
      queue: queue.value,
      currentIndex: currentIndex.value,
      isPlaying: isPlaying.value,
      volume: volume.value,
      continuousPlay: continuousPlay.value,
      randomPlay: randomPlay.value,
      playbackContext: playbackContext.value,
      playbackPosition: playbackPosition.value,
      playbackSessionKey: playbackSessionKey.value,
      countedPlaySessionKey: countedPlaySessionKey.value,
    }),
    persistState,
    { deep: true, flush: 'sync' },
  )

  return {
    queue,
    currentIndex,
    currentTrack,
    isPlaying,
    playbackState,
    error,
    volume,
    continuousPlay,
    randomPlay,
    playbackContext,
    playbackPosition,
    playbackSessionKey,
    countedPlaySessionKey,
    loadingNext,
    hasPrevious,
    hasNext,
    playTrack,
    playAlbum,
    queueTracks,
    queueTrack,
    queueAlbum,
    playQueueIndex,
    removeQueuedTrack,
    moveQueuedTrack,
    clearQueue,
    playRandomAlbum,
    playRandomTrack,
    previous,
    next,
    pause,
    resume,
    stop,
    setError,
    setVolume,
    setContinuousPlay,
    setRandomPlay,
    setPlaybackPosition,
    setPlaybackState,
    markCurrentPlayCounted,
  }
})

function errorMessage(cause: unknown): string {
  return cause instanceof Error ? cause.message : 'Playback could not continue.'
}

function readPersistedState(): PersistedPlayerState {
  try {
    const rawState = localStorage.getItem(STORAGE_KEY)
    if (!rawState) return {}

    const parsedState = JSON.parse(rawState) as PersistedPlayerState

    return {
      queue: Array.isArray(parsedState.queue) ? parsedState.queue : [],
      currentIndex: parsedState.currentIndex,
      isPlaying: parsedState.isPlaying === true,
      volume: parsedState.volume,
      continuousPlay: parsedState.continuousPlay,
      randomPlay: parsedState.randomPlay,
      playbackContext: parsedState.playbackContext === 'album' || parsedState.playbackContext === 'track-list'
        ? parsedState.playbackContext
        : null,
      playbackPosition: parsedState.playbackPosition,
      playbackSessionKey: typeof parsedState.playbackSessionKey === 'string' ? parsedState.playbackSessionKey : null,
      countedPlaySessionKey: typeof parsedState.countedPlaySessionKey === 'string' ? parsedState.countedPlaySessionKey : null,
    }
  } catch {
    return {}
  }
}

function persistState(state: PersistedPlayerState) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
      queue: state.queue ?? [],
      currentIndex: normalizeCurrentIndex(state.currentIndex, state.queue ?? []),
      isPlaying: state.isPlaying === true && normalizeCurrentIndex(state.currentIndex, state.queue ?? []) >= 0,
      volume: clampVolume(state.volume ?? 1),
      continuousPlay: state.continuousPlay ?? false,
      randomPlay: state.randomPlay ?? false,
      playbackContext: state.playbackContext ?? null,
      playbackPosition: normalizePlaybackPosition(state.playbackPosition),
      playbackSessionKey: state.playbackSessionKey ?? null,
      countedPlaySessionKey: state.countedPlaySessionKey ?? null,
    }))
  } catch {
    // Persistence is a convenience. Playback should keep working when storage is unavailable.
  }
}

function normalizeCurrentIndex(index: number | undefined, queue: PlayableTrack[]) {
  return Number.isInteger(index) && index !== undefined && index >= 0 && index < queue.length ? index : -1
}

function clampVolume(value: number) {
  return Math.min(1, Math.max(0, Number.isFinite(value) ? value : 1))
}

function normalizePlaybackPosition(value: number | undefined) {
  return Number.isFinite(value) && value !== undefined && value > 0 ? value : 0
}

function createPlaybackSessionKey() {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()

  return `${Date.now()}-${Math.random().toString(36).slice(2)}`
}
