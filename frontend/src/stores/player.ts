import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { apiRequest } from '@/api/client'
import type { AlbumDetail, Track } from '@/stores/catalog'

export type PlayableTrack = Track
export type PlaybackContext = 'album' | 'track-list' | null

export const usePlayerStore = defineStore('player', () => {
  const queue = ref<PlayableTrack[]>([])
  const currentIndex = ref(-1)
  const isPlaying = ref(false)
  const error = ref<string | null>(null)
  const volume = ref(1)
  const continuousPlay = ref(false)
  const randomPlay = ref(false)
  const playbackContext = ref<PlaybackContext>(null)
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
    error.value = null
    playbackContext.value = context
  }

  function playAlbum(album: AlbumDetail) {
    const [firstTrack] = album.tracks
    if (!firstTrack) return

    playTrack(firstTrack, album.tracks, 'album')
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
    error.value = null
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
      error.value = null
      return
    }

    if (!continuousPlay.value) {
      isPlaying.value = false
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
  }

  function resume() {
    if (!currentTrack.value) return
    isPlaying.value = true
    error.value = null
  }

  function stop() {
    currentIndex.value = -1
    queue.value = []
    isPlaying.value = false
    error.value = null
    playbackContext.value = null
  }

  function setError(message: string) {
    error.value = message
    isPlaying.value = false
  }

  function setVolume(value: number) {
    volume.value = Math.min(1, Math.max(0, value))
  }

  function setContinuousPlay(value: boolean) {
    continuousPlay.value = value
  }

  function setRandomPlay(value: boolean) {
    randomPlay.value = value
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

  return {
    queue,
    currentIndex,
    currentTrack,
    isPlaying,
    error,
    volume,
    continuousPlay,
    randomPlay,
    playbackContext,
    loadingNext,
    hasPrevious,
    hasNext,
    playTrack,
    playAlbum,
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
  }
})

function errorMessage(cause: unknown): string {
  return cause instanceof Error ? cause.message : 'Playback could not continue.'
}
