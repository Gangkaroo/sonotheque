import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import type { Track } from '@/stores/catalog'

export type PlayableTrack = Track

export const usePlayerStore = defineStore('player', () => {
  const queue = ref<PlayableTrack[]>([])
  const currentIndex = ref(-1)
  const isPlaying = ref(false)
  const error = ref<string | null>(null)
  const volume = ref(1)

  const currentTrack = computed(() => {
    return currentIndex.value >= 0 ? queue.value[currentIndex.value] ?? null : null
  })
  const hasPrevious = computed(() => currentIndex.value > 0)
  const hasNext = computed(() => currentIndex.value >= 0 && currentIndex.value < queue.value.length - 1)

  function playTrack(track: PlayableTrack, tracks: PlayableTrack[] = [track]) {
    const nextQueue = tracks.length ? [...tracks] : [track]
    const index = nextQueue.findIndex((item) => item.id === track.id)

    queue.value = nextQueue
    currentIndex.value = index >= 0 ? index : 0
    isPlaying.value = true
    error.value = null
  }

  function previous() {
    if (!hasPrevious.value) return
    currentIndex.value -= 1
    isPlaying.value = true
    error.value = null
  }

  function next() {
    if (!hasNext.value) {
      isPlaying.value = false
      return
    }

    currentIndex.value += 1
    isPlaying.value = true
    error.value = null
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
  }

  function setError(message: string) {
    error.value = message
    isPlaying.value = false
  }

  function setVolume(value: number) {
    volume.value = Math.min(1, Math.max(0, value))
  }

  return {
    queue,
    currentIndex,
    currentTrack,
    isPlaying,
    error,
    volume,
    hasPrevious,
    hasNext,
    playTrack,
    previous,
    next,
    pause,
    resume,
    stop,
    setError,
    setVolume,
  }
})
