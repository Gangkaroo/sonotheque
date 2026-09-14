import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import { useCatalogStore, type TrackPlayStatistics } from '@/stores/catalog'
import { usePlayerStore } from '@/stores/player'
import { usePlaylistsStore } from '@/stores/playlists'
import { useStatisticsStore } from '@/stores/statistics'
import {
  playbackHandoffAction,
  playbackProgressHasStalled,
  releaseMediaSource,
} from '@/utils/mediaPlayback'
import {
  clearMediaSession,
  installMediaSessionHandlers,
  updateMediaSessionPlaybackState,
  updateMediaSessionPosition,
} from '@/utils/mediaSession'
import { PlaybackListenAccumulator } from '@/utils/playbackListenAccumulator'

const MINIMUM_COUNTED_TRACK_SECONDS = 30
const MAXIMUM_COUNTED_PLAY_THRESHOLD_SECONDS = 240
const PLAYBACK_HANDOFF_RETRY_MS = 2500
const PLAYBACK_HANDOFF_TIMEOUT_MS = 30000
const PLAYBACK_ERROR_RETRY_MS = 250
const PLAYBACK_PROGRESS_CHECK_MS = 2000
const PLAYBACK_STALL_TIMEOUT_MS = 10000
const MAXIMUM_STALL_RECOVERY_ATTEMPTS = 2

interface TrackPlayResponse {
  counted: boolean
  statistics: TrackPlayStatistics
}

export function useAudioPlaybackController() {
  const { t } = useI18n()
  const catalog = useCatalogStore()
  const player = usePlayerStore()
  const playlists = usePlaylistsStore()
  const statistics = useStatisticsStore()
  const listenedPlayback = new PlaybackListenAccumulator(player.listenedPlaybackMs)
  const audio = ref<HTMLAudioElement | null>(null)
  const currentTime = ref(player.playbackPosition)
  const duration = ref(0)
  const restoredTrackId = ref<number | null>(null)
  const resumeAfterMetadata = ref(player.isPlaying)
  const isRestoringPlayback = ref(player.isPlaying)
  const isRestoringPosition = ref(player.playbackPosition > 0)
  const isSeeking = ref(false)
  const showSeekLoading = ref(false)
  const reportedPlayKey = ref<string | null>(null)
  const currentPlayKey = computed(() => player.currentTrack
    ? `${player.currentTrack.id}:${player.playbackSessionKey}`
    : null)
  const playbackStateText = computed(() => {
    if (player.error) return player.error
    if (player.playbackState === 'ended') return t('player.states.ended')

    return null
  })
  let nextPlayReportListenedMs = 0
  let seekLoadingTimer: ReturnType<typeof window.setTimeout> | null = null
  let seekLoadingClearTimer: ReturnType<typeof window.setTimeout> | null = null
  let playbackHandoffRetryTimer: ReturnType<typeof window.setTimeout> | null = null
  let playbackHandoffTimeoutTimer: ReturnType<typeof window.setTimeout> | null = null
  let playbackErrorRetryTimer: ReturnType<typeof window.setTimeout> | null = null
  let playbackErrorRetryKey: string | null = null
  let playbackProgressTimer: ReturnType<typeof window.setInterval> | null = null
  let lastPlaybackProgressAt = Date.now()
  let lastPlaybackMediaTime = player.playbackPosition
  let stallRecoveryAttempts = 0
  let reloadOnNextResume = false
  let removeMediaSessionHandlers: () => void = () => undefined
  let playbackAttemptId = 0

  watch(
    currentPlayKey,
    (playKey) => {
      releasePreviousMediaSource(playKey)
      playbackAttemptId += 1
      clearPlaybackHandoffTimers()
      clearPlaybackErrorRetry()
      playbackErrorRetryKey = null
      clearSeekFeedback()
      restoredTrackId.value = null
      reportedPlayKey.value = null
      nextPlayReportListenedMs = 0
      listenedPlayback.reset(player.listenedPlaybackMs)
      currentTime.value = player.playbackPosition
      resetPlaybackProgressWatchdog(player.playbackPosition)
      duration.value = 0
      isRestoringPosition.value = player.playbackPosition > 0
      resumeAfterMetadata.value = player.isPlaying
      isRestoringPlayback.value = false
      if (playKey && player.isPlaying) {
        void nextTick().then(() => schedulePlaybackHandoff(playKey))
      }
    },
  )

  watch(
    () => player.isPlaying,
    async (isPlaying) => {
      if (!audio.value || !player.currentTrack) return

      if (isPlaying) {
        if (currentPlayKey.value) schedulePlaybackHandoff(currentPlayKey.value)
        if (resumeAfterMetadata.value) return
        if (reloadOnNextResume) {
          recoverStalledPlayback()
          return
        }
        await playAudio()
      } else {
        playbackAttemptId += 1
        clearPlaybackHandoffTimers()
        audio.value.pause()
      }
    },
  )

  watch(
    () => player.volume,
    (volume) => {
      if (audio.value) audio.value.volume = volume
    },
    { immediate: true },
  )

  watch(
    [() => Boolean(player.currentTrack), () => player.isPlaying],
    ([hasTrack, isPlaying]) => updateMediaSessionPlaybackState(hasTrack, isPlaying),
    { immediate: true },
  )

  watch(
    [currentTime, duration],
    ([position, mediaDuration]) => updateMediaSessionPosition(position, mediaDuration),
  )

  async function playAudio(showError = true) {
    const element = audio.value
    const playKey = currentPlayKey.value
    const attemptId = ++playbackAttemptId

    if (!element || !playKey) return

    try {
      player.setPlaybackState('loading')
      await element.play()
      if (isCurrentPlaybackAttempt(playKey, element, attemptId) && !element.paused) {
        activatePlayback(element)
      }
    } catch {
      if (!isCurrentPlaybackAttempt(playKey, element, attemptId)) return

      if (showError) {
        player.setError(t('player.playbackError'))
      } else {
        player.pause()
      }
    }
  }

  function togglePlayback() {
    if (player.isPlaying) {
      reloadOnNextResume = reloadOnNextResume || playbackProgressIsStalled(3000)
      player.pause()
    } else if (player.playbackState === 'error') {
      retryPlaybackAfterError()
    } else {
      player.resume()
    }
  }

  function retryPlaybackAfterError() {
    if (!audio.value || !player.currentTrack) return

    clearPlaybackErrorRetry()
    playbackErrorRetryKey = null
    restoredTrackId.value = null
    isRestoringPosition.value = player.playbackPosition > 0
    resumeAfterMetadata.value = true
    isRestoringPlayback.value = false
    resetPlaybackProgressWatchdog(player.playbackPosition)
    player.resume()
    audio.value.load()
  }

  function updateProgress(event?: Event) {
    if (!isCurrentMediaEvent(event)) return

    syncAudioProgress(!isRestoringPosition.value)
    observePlaybackProgress()
    observeListenedPlayback()
    maybeRecordCountedPlay()
  }

  function updateDuration(event?: Event) {
    if (!isCurrentMediaEvent(event)) return

    syncAudioProgress(false)
  }

  function syncAudioProgress(persist: boolean) {
    const audioCurrentTime = audio.value?.currentTime ?? 0
    currentTime.value = isRestoringPosition.value && !audioCurrentTime && player.playbackPosition
      ? player.playbackPosition
      : audioCurrentTime
    duration.value = Number.isFinite(audio.value?.duration) ? audio.value?.duration ?? 0 : 0
    if (persist) player.setPlaybackPosition(currentTime.value)
  }

  function seekTo(value: number) {
    if (!audio.value || !duration.value || !Number.isFinite(value)) return

    suspendListenedPlayback()
    beginSeekFeedback()
    const target = Math.min(duration.value, Math.max(0, value))
    audio.value.currentTime = target
    currentTime.value = target
    player.setPlaybackPosition(target)
  }

  function onEnded(event?: Event) {
    if (!isCurrentMediaEvent(event)) return

    suspendListenedPlayback(audio.value?.currentTime)
    maybeRecordCountedPlay()
    player.setPlaybackPosition(0)
    void player.next()
  }

  function onLoadedMetadata(event?: Event) {
    if (!isCurrentMediaEvent(event)) return

    syncAudioProgress(false)
    restorePlaybackPosition()
    maybeRecordCountedPlay()
    startRequestedPlayback()
  }

  function onCanPlay(event?: Event) {
    if (!isCurrentMediaEvent(event)) return

    if (isRestoringPosition.value) {
      syncAudioProgress(false)
      restorePlaybackPosition()
    }
    startRequestedPlayback()
  }

  function startRequestedPlayback() {
    if (resumeAfterMetadata.value && player.isPlaying) {
      resumeAfterMetadata.value = false
      const showResumeError = !isRestoringPlayback.value
      isRestoringPlayback.value = false
      void playAudio(showResumeError)
    }
  }

  function onError(event?: Event) {
    if (!isCurrentMediaEvent(event)) return

    suspendListenedPlayback(audio.value?.currentTime)
    clearPlaybackHandoffTimers()
    if (retryTransientPlaybackError()) return

    resumeAfterMetadata.value = false
    isRestoringPlayback.value = false
    isRestoringPosition.value = false
    player.setError(t('player.playbackError'))
  }

  function retryTransientPlaybackError() {
    const element = audio.value
    const playKey = currentPlayKey.value
    if (
      !element
      || !playKey
      || !player.isPlaying
      || playbackErrorRetryKey === playKey
    ) return false

    playbackErrorRetryKey = playKey
    playbackAttemptId += 1
    restoredTrackId.value = null
    isRestoringPosition.value = player.playbackPosition > 0
    resumeAfterMetadata.value = true
    isRestoringPlayback.value = false
    player.setPlaybackState('loading')
    playbackErrorRetryTimer = window.setTimeout(() => {
      playbackErrorRetryTimer = null
      if (
        playKey !== currentPlayKey.value
        || element !== audio.value
        || !player.isPlaying
      ) return

      element.load()
      schedulePlaybackHandoff(playKey)
    }, PLAYBACK_ERROR_RETRY_MS)

    return true
  }

  function onLoading(event: Event) {
    if (!isCurrentMediaEvent(event)) return

    if (event.type === 'waiting' && (isSeeking.value || audio.value?.seeking)) {
      suspendListenedPlayback()
      beginSeekFeedback()
      return
    }

    suspendListenedPlayback(audio.value?.currentTime)
    if (event.type === 'waiting' || event.type === 'stalled') {
      reloadOnNextResume = true
    }
    if (player.isPlaying) {
      persistMediaPosition()
      player.setPlaybackState('loading')
      if (
        (event.type === 'waiting' || event.type === 'stalled')
        && currentPlayKey.value
      ) {
        if (playbackHandoffTimeoutTimer) {
          schedulePlaybackHandoffRetry(currentPlayKey.value)
        } else {
          schedulePlaybackHandoff(currentPlayKey.value)
        }
      }
    }
  }

  function onPause(event?: Event) {
    if (!isCurrentMediaEvent(event)) return
    suspendListenedPlayback(audio.value?.currentTime)
    if (!player.currentTrack || player.playbackState === 'ended' || player.playbackState === 'error') return
    if (player.playbackState === 'loading' && resumeAfterMetadata.value) return

    clearPlaybackHandoffTimers()
    player.setPlaybackState('paused')
  }

  function onPlaying(event?: Event) {
    if (!isCurrentMediaEvent(event)) return

    activatePlayback(audio.value)
  }

  function activatePlayback(element: HTMLAudioElement | null) {
    clearPlaybackHandoffTimers()
    endSeekFeedback()
    player.setPlaybackState('playing')
    lastPlaybackProgressAt = Date.now()
    listenedPlayback.resume(element?.currentTime ?? currentTime.value)
    maybeRecordCountedPlay()
  }

  function onSeeking(event?: Event) {
    if (!isCurrentMediaEvent(event)) return

    suspendListenedPlayback()
    beginSeekFeedback()
  }

  function onSeeked(event?: Event) {
    if (!isCurrentMediaEvent(event)) return

    syncAudioProgress(true)
    setPlaybackProgressBaseline(audio.value?.currentTime ?? currentTime.value)
    endSeekFeedback()
    if (player.isPlaying && audio.value && !audio.value.paused && !audio.value.seeking) {
      listenedPlayback.resume(audio.value.currentTime)
    }
  }

  function persistCurrentPlaybackState() {
    if (!audio.value || !player.currentTrack) return

    suspendListenedPlayback(audio.value.currentTime)
    persistMediaPosition()
  }

  function persistMediaPosition() {
    if (!audio.value) return

    const position = audio.value.currentTime
    if (position > 0 || player.playbackPosition === 0) {
      player.setPlaybackPosition(position)
    }
  }

  function observeListenedPlayback() {
    if (
      !audio.value
      || audio.value.paused
      || audio.value.seeking
      || player.playbackState !== 'playing'
    ) return

    player.setListenedPlaybackMs(listenedPlayback.observe(audio.value.currentTime))
  }

  function suspendListenedPlayback(positionSeconds?: number) {
    player.setListenedPlaybackMs(listenedPlayback.suspend(positionSeconds))
  }

  function maybeRecordCountedPlay() {
    const playKey = currentPlayKey.value
    if (
      !playKey
      || reportedPlayKey.value === playKey
      || player.countedPlaySessionKey === player.playbackSessionKey
      || !player.currentTrack
      || !player.isPlaying
      || !duration.value
    ) return

    if (duration.value <= MINIMUM_COUNTED_TRACK_SECONDS) return

    const requiredMs = Math.min(
      duration.value / 2,
      MAXIMUM_COUNTED_PLAY_THRESHOLD_SECONDS,
    ) * 1000
    const listenedMs = player.listenedPlaybackMs
    if (listenedMs < requiredMs || listenedMs < nextPlayReportListenedMs) return

    reportedPlayKey.value = playKey
    const trackId = player.currentTrack.id
    const sessionKey = player.playbackSessionKey
    void apiRequest<TrackPlayResponse>(`/tracks/${trackId}/plays`, {
      method: 'POST',
      body: JSON.stringify({
        listenedMs,
        durationMs: Math.max(0, Math.round(duration.value * 1000)),
        playedAt: player.playbackStartedAt,
        context: player.playbackContext,
        sessionKey,
      }),
    }).then((result) => {
      if (!result.counted) {
        deferPlayReportRetry(playKey, listenedMs, 1000)
        return
      }

      player.markCurrentPlayCounted(sessionKey)
      catalog.updateTrackPlayStatistics(trackId, result.statistics)
      playlists.updateTrackPlayStatistics(trackId, result.statistics)
      statistics.markHistoryStale()
    }).catch(() => {
      deferPlayReportRetry(playKey, listenedMs, 5000)
    })
  }

  function deferPlayReportRetry(playKey: string, listenedMs: number, delayMs: number) {
    if (currentPlayKey.value !== playKey) return

    if (reportedPlayKey.value === playKey) reportedPlayKey.value = null
    nextPlayReportListenedMs = listenedMs + delayMs
  }

  function restorePlaybackPosition() {
    if (!audio.value || !player.currentTrack || restoredTrackId.value === player.currentTrack.id) {
      isRestoringPosition.value = false
      return
    }

    restoredTrackId.value = player.currentTrack.id
    const position = player.playbackPosition
    if (!position || !Number.isFinite(position)) {
      isRestoringPosition.value = false
      return
    }

    audio.value.currentTime = Math.min(position, duration.value || position)
    currentTime.value = audio.value.currentTime
    isRestoringPosition.value = false
  }

  function beginSeekFeedback() {
    isSeeking.value = true
    if (seekLoadingClearTimer) {
      window.clearTimeout(seekLoadingClearTimer)
      seekLoadingClearTimer = null
    }
    if (!showSeekLoading.value && !seekLoadingTimer) {
      seekLoadingTimer = window.setTimeout(() => {
        showSeekLoading.value = true
        seekLoadingTimer = null
      }, 120)
    }
  }

  function endSeekFeedback() {
    isSeeking.value = false
    if (seekLoadingTimer) {
      window.clearTimeout(seekLoadingTimer)
      seekLoadingTimer = null
    }
    if (!showSeekLoading.value) return

    seekLoadingClearTimer = window.setTimeout(() => {
      showSeekLoading.value = false
      seekLoadingClearTimer = null
    }, 180)
  }

  function clearSeekFeedback() {
    isSeeking.value = false
    showSeekLoading.value = false
    if (seekLoadingTimer) {
      window.clearTimeout(seekLoadingTimer)
      seekLoadingTimer = null
    }
    if (seekLoadingClearTimer) {
      window.clearTimeout(seekLoadingClearTimer)
      seekLoadingClearTimer = null
    }
  }

  function schedulePlaybackHandoff(playKey: string) {
    clearPlaybackHandoffTimers()
    if (
      playKey !== currentPlayKey.value
      || !player.isPlaying
      || player.playbackState !== 'loading'
    ) return

    schedulePlaybackHandoffRetry(playKey)
    playbackHandoffTimeoutTimer = window.setTimeout(() => {
      playbackHandoffTimeoutTimer = null
      failStalledPlaybackHandoff(playKey)
    }, PLAYBACK_HANDOFF_TIMEOUT_MS)
  }

  function schedulePlaybackHandoffRetry(playKey: string) {
    if (playbackHandoffRetryTimer) {
      window.clearTimeout(playbackHandoffRetryTimer)
    }
    playbackHandoffRetryTimer = window.setTimeout(() => {
      playbackHandoffRetryTimer = null
      recoverPlaybackHandoff(playKey)
    }, PLAYBACK_HANDOFF_RETRY_MS)
  }

  function recoverPlaybackHandoff(playKey: string) {
    const element = audio.value
    if (!isPendingPlaybackHandoff(playKey, element)) return

    const action = playbackHandoffAction(element)
    if (action === 'playing') {
      activatePlayback(element)
    } else if (action === 'play') {
      void playAudio()
    } else if (action === 'reload') {
      playbackAttemptId += 1
      resumeAfterMetadata.value = true
      restoredTrackId.value = null
      isRestoringPosition.value = player.playbackPosition > 0
      element.load()
    } else {
      schedulePlaybackHandoffRetry(playKey)
    }
  }

  function failStalledPlaybackHandoff(playKey: string) {
    const element = audio.value
    if (!isPendingPlaybackHandoff(playKey, element)) return

    clearPlaybackHandoffTimers()
    if (playbackHandoffAction(element) === 'playing') {
      activatePlayback(element)
      return
    }

    player.setError(t('player.playbackError'))
  }

  function isPendingPlaybackHandoff(
    playKey: string,
    element: HTMLAudioElement | null,
  ): element is HTMLAudioElement {
    return element !== null
      && playKey === currentPlayKey.value
      && element.dataset.playKey === playKey
      && player.isPlaying
      && player.playbackState === 'loading'
  }

  function clearPlaybackHandoffTimers() {
    if (playbackHandoffRetryTimer) {
      window.clearTimeout(playbackHandoffRetryTimer)
      playbackHandoffRetryTimer = null
    }
    if (playbackHandoffTimeoutTimer) {
      window.clearTimeout(playbackHandoffTimeoutTimer)
      playbackHandoffTimeoutTimer = null
    }
  }

  function clearPlaybackErrorRetry() {
    if (!playbackErrorRetryTimer) return

    window.clearTimeout(playbackErrorRetryTimer)
    playbackErrorRetryTimer = null
  }

  function observePlaybackProgress() {
    const mediaTime = audio.value?.currentTime ?? currentTime.value
    if (mediaTime <= lastPlaybackMediaTime + 0.05) return

    lastPlaybackMediaTime = mediaTime
    lastPlaybackProgressAt = Date.now()
    stallRecoveryAttempts = 0
    reloadOnNextResume = false
  }

  function resetPlaybackProgressWatchdog(mediaTime = 0) {
    setPlaybackProgressBaseline(mediaTime)
    stallRecoveryAttempts = 0
  }

  function setPlaybackProgressBaseline(mediaTime: number) {
    lastPlaybackMediaTime = mediaTime
    lastPlaybackProgressAt = Date.now()
    reloadOnNextResume = false
  }

  function playbackProgressIsStalled(timeoutMs = PLAYBACK_STALL_TIMEOUT_MS) {
    const element = audio.value
    if (!element || !player.isPlaying) return false

    return playbackProgressHasStalled(
      element,
      Date.now() - lastPlaybackProgressAt,
      timeoutMs,
    )
  }

  function checkPlaybackProgress() {
    if (!playbackProgressIsStalled()) return

    recoverStalledPlayback()
  }

  function recoverStalledPlayback() {
    const element = audio.value
    const playKey = currentPlayKey.value
    if (!element || !playKey || !player.isPlaying) return

    if (stallRecoveryAttempts >= MAXIMUM_STALL_RECOVERY_ATTEMPTS) {
      clearPlaybackHandoffTimers()
      player.setError(t('player.playbackError'))
      return
    }

    const recoveryPosition = Number.isFinite(element.currentTime)
      ? element.currentTime
      : currentTime.value

    stallRecoveryAttempts += 1
    reloadOnNextResume = false
    lastPlaybackMediaTime = recoveryPosition
    lastPlaybackProgressAt = Date.now()
    suspendListenedPlayback(recoveryPosition)
    player.setPlaybackPosition(recoveryPosition)
    playbackAttemptId += 1
    clearPlaybackHandoffTimers()
    clearPlaybackErrorRetry()
    playbackErrorRetryKey = null
    restoredTrackId.value = null
    isRestoringPosition.value = recoveryPosition > 0
    resumeAfterMetadata.value = true
    isRestoringPlayback.value = true
    player.setPlaybackState('loading')
    element.load()
    schedulePlaybackHandoff(playKey)
  }

  function releasePreviousMediaSource(nextPlayKey: string | null) {
    const element = audio.value
    if (!element || element.dataset.playKey === nextPlayKey) return

    releasePlayerMediaSource(element)
  }

  function releasePlayerMediaSource(element: HTMLAudioElement) {
    element.dataset.playKey = ''
    releaseMediaSource(element)
  }

  function isCurrentPlaybackAttempt(
    playKey: string,
    element: HTMLAudioElement,
    attemptId: number,
  ) {
    return playKey === currentPlayKey.value
      && element === audio.value
      && attemptId === playbackAttemptId
  }

  function isCurrentMediaEvent(event?: Event) {
    if (!event) return true
    const target = event.currentTarget

    return target instanceof HTMLAudioElement
      && target === audio.value
      && target.dataset.playKey === currentPlayKey.value
  }

  onBeforeUnmount(() => {
    persistCurrentPlaybackState()
    window.removeEventListener('beforeunload', persistCurrentPlaybackState)
    clearPlaybackHandoffTimers()
    clearPlaybackErrorRetry()
    if (playbackProgressTimer) window.clearInterval(playbackProgressTimer)
    clearSeekFeedback()
    removeMediaSessionHandlers()
    clearMediaSession()
    if (audio.value) releasePlayerMediaSource(audio.value)
  })

  onMounted(() => {
    window.addEventListener('beforeunload', persistCurrentPlaybackState)
    playbackProgressTimer = window.setInterval(checkPlaybackProgress, PLAYBACK_PROGRESS_CHECK_MS)
    removeMediaSessionHandlers = installMediaSessionHandlers({
      nextTrack: () => void player.next(),
      pause: () => player.pause(),
      play: () => player.playbackState === 'error' ? retryPlaybackAfterError() : player.resume(),
      previousTrack: () => player.previous(),
    })
  })

  return {
    audio,
    currentPlayKey,
    currentTime,
    duration,
    onCanPlay,
    onEnded,
    onError,
    onLoadedMetadata,
    onLoading,
    onPause,
    onPlaying,
    onSeeked,
    onSeeking,
    playbackStateText,
    seekTo,
    showSeekLoading,
    togglePlayback,
    updateDuration,
    updateProgress,
  }
}
