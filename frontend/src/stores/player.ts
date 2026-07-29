import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'

import { apiRequest } from '@/api/client'
import { withLibraryRootScope } from '@/stores/libraryRootScope'
import type { AlbumDetail, Track } from '@/stores/catalog'

export type PlayableTrack = Track
export type PlaybackContext = 'album' | 'track-list' | null
export type PlaybackState = 'idle' | 'loading' | 'playing' | 'paused' | 'ended' | 'error'
export type AlbumPlaybackSort = 'artist' | 'title' | 'year_asc' | 'year_desc' | 'plays' | 'last_played' | 'added'
export type TrackPlaybackSort = 'album' | 'title' | 'year_asc' | 'year_desc' | 'plays' | 'last_played' | 'added'
export type PlaybackPhysicalCopyFilter = 'owned' | 'not_owned' | null

export interface AlbumPlaybackScope {
  type: 'albums'
  libraryRootId: number | null
  libraryRootName: string | null
  search: string
  initial: string | null
  year: number | null
  genreId: number | null
  genreName: string
  physicalCopy: PlaybackPhysicalCopyFilter
  sort: AlbumPlaybackSort
}

export interface TrackPlaybackScope {
  type: 'tracks'
  libraryRootId: number | null
  libraryRootName: string | null
  search: string
  genreId: number | null
  genreName: string
  playStatus: 'never' | null
  physicalCopy: PlaybackPhysicalCopyFilter
  sort: TrackPlaybackSort
}

export type CatalogPlaybackScope = AlbumPlaybackScope | TrackPlaybackScope

const STORAGE_KEY = 'sonotheque.player'
const MAX_PERSISTED_LISTENING_OVERFLOW_MS = 5000
const ALBUM_PLAYBACK_SORTS: AlbumPlaybackSort[] = [
  'artist',
  'title',
  'year_asc',
  'year_desc',
  'plays',
  'last_played',
  'added',
]
const TRACK_PLAYBACK_SORTS: TrackPlaybackSort[] = [
  'album',
  'title',
  'year_asc',
  'year_desc',
  'plays',
  'last_played',
  'added',
]

interface PersistedPlayerState {
  queue?: PlayableTrack[]
  currentIndex?: number
  isPlaying?: boolean
  volume?: number
  continuousPlay?: boolean
  randomPlay?: boolean
  visualizerEnabled?: boolean
  playbackContext?: PlaybackContext
  playbackScope?: CatalogPlaybackScope | null
  playbackPosition?: number
  playbackSessionKey?: string | null
  countedPlaySessionKey?: string | null
  listenedPlaybackMs?: number
  playbackStartedAt?: string | null
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
  const visualizerEnabled = ref(persistedState.visualizerEnabled ?? true)
  const playbackContext = ref<PlaybackContext>(persistedState.playbackContext ?? null)
  const playbackScope = ref<CatalogPlaybackScope | null>(persistedState.playbackScope ?? null)
  const playbackPosition = ref(normalizePlaybackPosition(persistedState.playbackPosition))
  const playbackSessionKey = ref(persistedState.playbackSessionKey ?? createPlaybackSessionKey())
  const countedPlaySessionKey = ref(persistedState.countedPlaySessionKey ?? null)
  const listenedPlaybackMs = ref(normalizeListenedPlaybackMs(persistedState.listenedPlaybackMs))
  const playbackStartedAt = ref(
    currentIndex.value >= 0
      ? persistedState.playbackStartedAt ?? new Date().toISOString()
      : null,
  )
  const loadingNext = ref(false)

  const currentTrack = computed(() => {
    return currentIndex.value >= 0 ? queue.value[currentIndex.value] ?? null : null
  })
  const hasPrevious = computed(() => currentIndex.value > 0)
  const hasNext = computed(() => currentIndex.value >= 0 && (currentIndex.value < queue.value.length - 1 || continuousPlay.value))

  function playTrack(
    track: PlayableTrack,
    tracks: PlayableTrack[] = [track],
    context: PlaybackContext = null,
    scope: CatalogPlaybackScope | null = null,
  ) {
    const nextQueue = tracks.length ? [...tracks] : [track]
    const index = nextQueue.findIndex((item) => item.id === track.id)

    startNewPlaybackSession()
    queue.value = nextQueue
    currentIndex.value = index >= 0 ? index : 0
    isPlaying.value = true
    playbackState.value = 'loading'
    error.value = null
    playbackContext.value = context
    playbackScope.value = normalizePlaybackScope(scope)
    playbackPosition.value = 0
  }

  function playAlbum(album: AlbumDetail, scope: AlbumPlaybackScope | null = null) {
    const [firstTrack] = album.tracks
    if (!firstTrack) return

    playTrack(firstTrack, album.tracks, 'album', scope)
  }

  async function playAlbumById(albumId: number) {
    try {
      const album = await apiRequest<AlbumDetail>(withLibraryRootScope(`/catalog/albums/${albumId}`))
      playAlbum(album)
    } catch (cause) {
      setError(errorMessage(cause))
    }
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
      playbackScope.value = null
      playbackPosition.value = 0
    }
  }

  function queueTrack(track: PlayableTrack, context: PlaybackContext = null) {
    queueTracks([track], context)
  }

  function continueWithTracks(tracks: PlayableTrack[]) {
    const [firstTrack] = tracks
    if (!firstTrack) return

    if (!currentTrack.value) {
      playTrack(firstTrack, tracks, 'track-list')
      return
    }

    queue.value = [
      ...queue.value.slice(0, currentIndex.value + 1),
      ...tracks,
    ]
    playbackContext.value = 'track-list'
    playbackScope.value = null
  }

  function queueAlbum(album: AlbumDetail) {
    queueTracks(album.tracks, 'album')
  }

  function refreshQueuedTracks(tracks: PlayableTrack[]) {
    const refreshedById = new Map(tracks.map((track) => [track.id, track]))
    if (!queue.value.some((track) => refreshedById.has(track.id))) return

    queue.value = queue.value.map((track) => refreshedById.get(track.id) ?? track)
  }

  function playQueueIndex(index: number) {
    if (!Number.isInteger(index) || index < 0 || index >= queue.value.length) return

    startNewPlaybackSession()
    currentIndex.value = index
    isPlaying.value = true
    playbackState.value = 'loading'
    error.value = null
    playbackPosition.value = 0
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

    if (removedCurrentTrack) startNewPlaybackSession()
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

  async function playRandomAlbum(scope: AlbumPlaybackScope | null = null) {
    try {
      const frozenScope = normalizeAlbumPlaybackScope(scope)
      const path = catalogPlaybackPath('/catalog/playback/albums/random', frozenScope)
      const album = await apiRequest<AlbumDetail>(path)
      playAlbum(album, frozenScope)
    } catch (cause) {
      setError(errorMessage(cause))
    }
  }

  async function playRandomTrack(scope: TrackPlaybackScope | null = null) {
    try {
      const frozenScope = normalizeTrackPlaybackScope(scope)
      const path = catalogPlaybackPath('/catalog/playback/tracks/random', frozenScope)
      const track = await apiRequest<PlayableTrack>(path)
      playTrack(track, [track], 'track-list', frozenScope)
    } catch (cause) {
      setError(errorMessage(cause))
    }
  }

  function previous() {
    if (!hasPrevious.value) return
    startNewPlaybackSession()
    currentIndex.value -= 1
    isPlaying.value = true
    playbackState.value = 'loading'
    error.value = null
    playbackPosition.value = 0
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
      startNewPlaybackSession()
      currentIndex.value += 1
      isPlaying.value = true
      playbackState.value = 'loading'
      error.value = null
      playbackPosition.value = 0
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
    playbackScope.value = null
    playbackPosition.value = 0
    playbackSessionKey.value = createPlaybackSessionKey()
    countedPlaySessionKey.value = null
    listenedPlaybackMs.value = 0
    playbackStartedAt.value = null
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

  function setVisualizerEnabled(value: boolean) {
    visualizerEnabled.value = value
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

  function setListenedPlaybackMs(value: number) {
    const normalized = normalizeListenedPlaybackMs(value)
    if (normalized > listenedPlaybackMs.value) {
      listenedPlaybackMs.value = normalized
    }
  }

  function startNewPlaybackSession() {
    listenedPlaybackMs.value = 0
    playbackStartedAt.value = new Date().toISOString()
    countedPlaySessionKey.value = null
    playbackSessionKey.value = createPlaybackSessionKey()
  }

  async function loadNextAlbum(random: boolean) {
    if (!currentTrack.value?.album?.id || loadingNext.value) return

    loadingNext.value = true
    try {
      const scope = playbackScope.value?.type === 'albums' ? playbackScope.value : null
      const path = random
        ? catalogPlaybackPath('/catalog/playback/albums/random', scope, { exclude: currentTrack.value.album.id })
        : catalogPlaybackPath(`/catalog/playback/albums/${currentTrack.value.album.id}/next`, scope)
      const album = await apiRequest<AlbumDetail>(path)
      playAlbum(album, scope)
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
      const scope = playbackScope.value?.type === 'tracks' ? playbackScope.value : null
      const path = random
        ? catalogPlaybackPath('/catalog/playback/tracks/random', scope, { exclude: currentTrack.value.id })
        : catalogPlaybackPath(`/catalog/playback/tracks/${currentTrack.value.id}/next`, scope)
      const track = await apiRequest<PlayableTrack>(path)
      playTrack(track, [track], 'track-list', scope)
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
      visualizerEnabled: visualizerEnabled.value,
      playbackContext: playbackContext.value,
      playbackScope: playbackScope.value,
      playbackPosition: playbackPosition.value,
      playbackSessionKey: playbackSessionKey.value,
      countedPlaySessionKey: countedPlaySessionKey.value,
      listenedPlaybackMs: listenedPlaybackMs.value,
      playbackStartedAt: playbackStartedAt.value,
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
    visualizerEnabled,
    playbackContext,
    playbackScope,
    playbackPosition,
    playbackSessionKey,
    countedPlaySessionKey,
    listenedPlaybackMs,
    playbackStartedAt,
    loadingNext,
    hasPrevious,
    hasNext,
    playTrack,
    playAlbum,
    playAlbumById,
    queueTracks,
    queueTrack,
    continueWithTracks,
    queueAlbum,
    refreshQueuedTracks,
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
    setVisualizerEnabled,
    setPlaybackPosition,
    setPlaybackState,
    markCurrentPlayCounted,
    setListenedPlaybackMs,
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
    const listenedPlaybackMs = normalizeListenedPlaybackMs(parsedState.listenedPlaybackMs)
    const playbackStartedAt = typeof parsedState.playbackStartedAt === 'string'
      && Number.isFinite(Date.parse(parsedState.playbackStartedAt))
      ? parsedState.playbackStartedAt
      : null
    const elapsedSessionMs = playbackStartedAt === null
      ? null
      : Math.max(0, Date.now() - Date.parse(playbackStartedAt))
    const listeningStateValid = listenedPlaybackMs === 0
      || (
        elapsedSessionMs !== null
        && listenedPlaybackMs <= elapsedSessionMs + MAX_PERSISTED_LISTENING_OVERFLOW_MS
      )

    return {
      queue: Array.isArray(parsedState.queue) ? parsedState.queue : [],
      currentIndex: parsedState.currentIndex,
      isPlaying: parsedState.isPlaying === true,
      volume: parsedState.volume,
      continuousPlay: parsedState.continuousPlay,
      randomPlay: parsedState.randomPlay,
      visualizerEnabled: parsedState.visualizerEnabled,
      playbackContext: parsedState.playbackContext === 'album' || parsedState.playbackContext === 'track-list'
        ? parsedState.playbackContext
        : null,
      playbackScope: normalizePlaybackScope(parsedState.playbackScope),
      playbackPosition: parsedState.playbackPosition,
      playbackSessionKey: listeningStateValid && typeof parsedState.playbackSessionKey === 'string'
        ? parsedState.playbackSessionKey
        : null,
      countedPlaySessionKey: listeningStateValid && typeof parsedState.countedPlaySessionKey === 'string'
        ? parsedState.countedPlaySessionKey
        : null,
      listenedPlaybackMs: listeningStateValid ? listenedPlaybackMs : 0,
      playbackStartedAt: listeningStateValid ? playbackStartedAt : null,
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
      visualizerEnabled: state.visualizerEnabled ?? true,
      playbackContext: state.playbackContext ?? null,
      playbackScope: normalizePlaybackScope(state.playbackScope),
      playbackPosition: normalizePlaybackPosition(state.playbackPosition),
      playbackSessionKey: state.playbackSessionKey ?? null,
      countedPlaySessionKey: state.countedPlaySessionKey ?? null,
      listenedPlaybackMs: normalizeListenedPlaybackMs(state.listenedPlaybackMs),
      playbackStartedAt: state.playbackStartedAt ?? null,
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

function normalizeListenedPlaybackMs(value: number | undefined) {
  return Number.isFinite(value) && value !== undefined && value > 0 ? Math.round(value) : 0
}

function createPlaybackSessionKey() {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()

  return `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

function catalogPlaybackPath(
  path: string,
  scope: CatalogPlaybackScope | null,
  extraParameters: Record<string, string | number> = {},
) {
  const url = new URL(path, 'http://sonotheque.local')

  for (const [key, value] of Object.entries(extraParameters)) {
    url.searchParams.set(key, String(value))
  }

  if (scope === null) {
    return withLibraryRootScope(`${url.pathname}${url.search}`)
  }

  if (scope.libraryRootId !== null) url.searchParams.set('libraryRoot', String(scope.libraryRootId))
  if (scope.search) url.searchParams.set('search', scope.search)
  if (scope.genreId !== null) url.searchParams.set('genre', String(scope.genreId))
  if (scope.physicalCopy !== null) url.searchParams.set('physicalCopy', scope.physicalCopy)
  if (scope.type === 'albums') {
    if (scope.initial) url.searchParams.set('initial', scope.initial)
    if (scope.year !== null) url.searchParams.set('year', String(scope.year))
  } else if (scope.playStatus !== null) {
    url.searchParams.set('playStatus', scope.playStatus)
  }
  url.searchParams.set('sort', scope.sort)

  return `${url.pathname}${url.search}`
}

function normalizePlaybackScope(scope: unknown): CatalogPlaybackScope | null {
  if (!scope || typeof scope !== 'object') return null

  const value = scope as Partial<CatalogPlaybackScope>

  if (value.type === 'albums') return normalizeAlbumPlaybackScope(value)
  if (value.type === 'tracks') return normalizeTrackPlaybackScope(value)

  return null
}

function normalizeAlbumPlaybackScope(scope: unknown): AlbumPlaybackScope | null {
  if (!scope || typeof scope !== 'object') return null

  const value = scope as Partial<AlbumPlaybackScope>
  if (value.type !== 'albums') return null

  const initial = typeof value.initial === 'string' && /^(#|[A-Z])$/.test(value.initial)
    ? value.initial
    : null
  const physicalCopy = value.physicalCopy === 'owned' || value.physicalCopy === 'not_owned'
    ? value.physicalCopy
    : null
  const sort = ALBUM_PLAYBACK_SORTS.includes(value.sort as AlbumPlaybackSort)
    ? value.sort as AlbumPlaybackSort
    : 'artist'

  return {
    type: 'albums',
    libraryRootId: positiveInteger(value.libraryRootId),
    libraryRootName: typeof value.libraryRootName === 'string' && value.libraryRootName.trim()
      ? value.libraryRootName.trim()
      : null,
    search: typeof value.search === 'string' ? value.search.trim() : '',
    initial,
    year: positiveInteger(value.year),
    genreId: positiveInteger(value.genreId),
    genreName: typeof value.genreName === 'string' ? value.genreName.trim() : '',
    physicalCopy,
    sort,
  }
}

function normalizeTrackPlaybackScope(scope: unknown): TrackPlaybackScope | null {
  if (!scope || typeof scope !== 'object') return null

  const value = scope as Partial<TrackPlaybackScope>
  if (value.type !== 'tracks') return null

  const physicalCopy = value.physicalCopy === 'owned' || value.physicalCopy === 'not_owned'
    ? value.physicalCopy
    : null
  const sort = TRACK_PLAYBACK_SORTS.includes(value.sort as TrackPlaybackSort)
    ? value.sort as TrackPlaybackSort
    : 'album'

  return {
    type: 'tracks',
    libraryRootId: positiveInteger(value.libraryRootId),
    libraryRootName: typeof value.libraryRootName === 'string' && value.libraryRootName.trim()
      ? value.libraryRootName.trim()
      : null,
    search: typeof value.search === 'string' ? value.search.trim() : '',
    genreId: positiveInteger(value.genreId),
    genreName: typeof value.genreName === 'string' ? value.genreName.trim() : '',
    playStatus: value.playStatus === 'never' ? 'never' : null,
    physicalCopy,
    sort,
  }
}

function positiveInteger(value: unknown): number | null {
  return Number.isInteger(value) && Number(value) > 0 ? Number(value) : null
}
