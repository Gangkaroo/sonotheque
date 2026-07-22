<script setup lang="ts">
import { computed, mergeProps, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import MusicVisualizer from '@/components/MusicVisualizer.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCatalogStore, type TrackPlayStatistics } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { useNowPlayingPanelStore } from '@/stores/nowPlayingPanel'
import {
  useOnlineEnrichmentStore,
  type EnrichmentErrorCode,
  type EnrichmentStatus,
} from '@/stores/onlineEnrichment'
import { usePlayerStore } from '@/stores/player'
import { usePlaylistsStore } from '@/stores/playlists'
import { openExternalUrl } from '@/utils/externalLinks'
import { formatDuration as queueDuration, formatTotalDuration } from '@/utils/formatters'
import { releaseMediaSource } from '@/utils/mediaPlayback'
import { activeSynchronizedLyricIndex, parseSynchronizedLyrics } from '@/utils/synchronizedLyrics'

const { locale, t } = useI18n()
const MINIMUM_COUNTED_TRACK_SECONDS = 30
const MAXIMUM_COUNTED_PLAY_THRESHOLD_SECONDS = 240
const PLAYBACK_HANDOFF_RETRY_MS = 2500
const PLAYBACK_HANDOFF_TIMEOUT_MS = 10000
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const nowPlayingPanel = useNowPlayingPanelStore()
const enrichment = useOnlineEnrichmentStore()
const player = usePlayerStore()
const playlists = usePlaylistsStore()
const audio = ref<HTMLAudioElement | null>(null)
const playerCollapsed = ref(false)
const draggedQueueIndex = ref<number | null>(null)
const addToPlaylistDialog = ref(false)
const playlistTracks = ref<typeof player.queue>([])
const createQueuePlaylistDialog = ref(false)
const queuePlaylistName = ref('')
const queuePlaylistDescription = ref('')
const queuePlaylistFolderId = ref<number | null>(null)
const queuePlaylistSuccess = ref('')
const queuePlaylistSuccessVisible = ref(false)
const currentTime = ref(player.playbackPosition)
const duration = ref(0)
const restoredTrackId = ref<number | null>(null)
const resumeAfterMetadata = ref(player.isPlaying)
const isRestoringPlayback = ref(player.isPlaying)
const isRestoringPosition = ref(player.playbackPosition > 0)
const isSeeking = ref(false)
const showSeekLoading = ref(false)
const lyricsContainer = ref<HTMLElement | null>(null)
const artistDescriptionExpanded = ref(false)
const albumDescriptionExpanded = ref(false)
const reportedPlayKey = ref<string | null>(null)
let seekLoadingTimer: ReturnType<typeof window.setTimeout> | null = null
let seekLoadingClearTimer: ReturnType<typeof window.setTimeout> | null = null
let playbackHandoffRetryTimer: ReturnType<typeof window.setTimeout> | null = null
let playbackHandoffTimeoutTimer: ReturnType<typeof window.setTimeout> | null = null
let playbackAttemptId = 0
const volumeSlider = computed({
  get: () => Math.round(player.volume * 100),
  set: (value: number) => player.setVolume(value / 100),
})
const nowPlayingDrawerOpen = computed({
  get: () => nowPlayingPanel.isOpen,
  set: (value: boolean) => {
    if (!value) nowPlayingPanel.close()
  },
})
const seekPosition = computed({
  get: () => currentTime.value,
  set: (value: number) => seekTo(value),
})
const progressPercent = computed(() => duration.value > 0 ? Math.min(100, Math.max(0, (currentTime.value / duration.value) * 100)) : 0)
const remainingTime = computed(() => duration.value > 0 ? `-${formatTime(Math.max(0, duration.value - currentTime.value))}` : '')
const primaryArtist = computed(() => player.currentTrack?.artists[0] ?? null)
const albumArtworkThumbnailUrl = computed(() => player.currentTrack?.album?.artworkThumbnailUrl ?? null)
const artistNames = computed(() => player.currentTrack?.artists.map((artist) => artist.name).join(', ') || t('catalog.unknownArtist'))
const albumReleaseYear = computed(() => player.currentTrack?.album?.originalReleaseYear ?? player.currentTrack?.year ?? null)
const albumTitle = computed(() => {
  const title = player.currentTrack?.album?.title ?? t('catalog.unknownAlbum')

  return albumReleaseYear.value ? `${title} (${albumReleaseYear.value})` : title
})
const trackTitle = computed(() => {
  const title = player.currentTrack?.title ?? ''

  return !player.currentTrack?.album && player.currentTrack?.year ? `${title} (${player.currentTrack.year})` : title
})
const trackRoute = computed(() => {
  const trackId = player.currentTrack?.id

  return trackId ? { name: 'track-detail', params: { id: trackId } } : null
})
const albumRoute = computed(() => {
  const albumId = player.currentTrack?.album?.id

  return albumId ? { name: 'album-detail', params: { id: albumId } } : null
})
const artistRoute = computed(() => {
  return primaryArtist.value
    ? { name: 'artist-detail', params: { id: primaryArtist.value.id } }
    : null
})
const playbackStateText = computed(() => {
  if (player.error) return player.error

  if (player.playbackState === 'ended') return t('player.states.ended')

  return null
})
const queueItems = computed(() => player.queue.map((track, index) => ({ track, index })))
const queuePlayingTime = computed(() => {
  const total = player.queue.reduce((sum, track) => sum + (track.durationMs ?? 0), 0)

  return total > 0 ? formatTotalDuration(total) : null
})
const nowPlayingQueueItem = computed(() => queueItems.value.find((item) => item.index === player.currentIndex) ?? null)
const upcomingQueueItems = computed(() => queueItems.value.filter((item) => item.index > player.currentIndex))
const previousQueueItems = computed(() => queueItems.value.filter((item) => item.index < player.currentIndex).reverse())
const currentPlayKey = computed(() => player.currentTrack ? `${player.currentTrack.id}:${player.playbackSessionKey}` : null)
const playlistFolderOptions = computed(() => [
  { title: t('playlists.noFolder'), value: null },
  ...playlists.folders.map((folder) => ({ title: folder.name, value: folder.id })),
])
const canCreateQueuePlaylist = computed(() => queuePlaylistName.value.trim().length > 0 && player.queue.length > 0 && !playlists.saving)
const artistDescription = computed(() => enrichment.information?.artist.data?.biography ?? '')
const albumDescription = computed(() => enrichment.information?.album.data?.summary ?? '')
const artistDescriptionIsLong = computed(() => artistDescription.value.length > 500)
const albumDescriptionIsLong = computed(() => albumDescription.value.length > 500)
const synchronizedLyricLines = computed(() => parseSynchronizedLyrics(
  enrichment.lyrics?.data?.synchronizedLyrics,
))
const activeLyricIndex = computed(() => activeSynchronizedLyricIndex(
  synchronizedLyricLines.value,
  currentTime.value,
))

interface TrackPlayResponse {
  counted: boolean
  statistics: TrackPlayStatistics
}

watch(
  () => currentPlayKey.value,
  (playKey) => {
    releasePreviousMediaSource(playKey)
    playbackAttemptId += 1
    clearPlaybackHandoffTimers()
    clearSeekFeedback()
    restoredTrackId.value = null
    reportedPlayKey.value = null
    currentTime.value = player.playbackPosition
    duration.value = 0
    isRestoringPosition.value = player.playbackPosition > 0
    resumeAfterMetadata.value = player.isPlaying
    isRestoringPlayback.value = false
    artistDescriptionExpanded.value = false
    albumDescriptionExpanded.value = false
    if (playKey && player.isPlaying) {
      void nextTick().then(() => schedulePlaybackHandoff(playKey))
    }
  },
  { flush: 'sync' },
)

watch(
  [
    () => nowPlayingPanel.isOpen,
    () => nowPlayingPanel.activeTab,
    () => player.currentTrack?.id,
    () => locale.value,
  ],
  ([isOpen, activeTab, trackId, language]) => {
    if (!isOpen || !trackId) return

    if (activeTab === 'info') {
      void enrichment.loadInformation(trackId, language)
      void enrichment.loadIdentity(trackId)
    }
    if (activeTab === 'lyrics') {
      void enrichment.loadLyrics(trackId).then(() => scrollActiveLyricIntoView(activeLyricIndex.value))
    }
  },
  { immediate: true },
)

function enrichmentStateText(status: EnrichmentStatus | undefined, errorCode?: EnrichmentErrorCode | null) {
  if (status === 'error' && errorCode) return t(`player.enrichmentErrors.${errorCode}`)

  return status ? t(`player.enrichmentStates.${status}`) : ''
}

function identityMatchText(method?: 'search' | 'tag' | null, confidence?: number | null) {
  if (method === 'tag') return t('player.matchFromTags')
  if (method === 'search' && confidence) return t('player.matchFromSearch', { confidence })

  return null
}

function activePeriod(from?: string | null, to?: string | null) {
  if (!from && !to) return null

  return `${from ?? '?'} - ${to ?? t('player.present')}`
}

watch(
  () => player.isPlaying,
  async (isPlaying) => {
    if (!audio.value || !player.currentTrack) return

    if (isPlaying) {
      if (currentPlayKey.value) schedulePlaybackHandoff(currentPlayKey.value)
      if (resumeAfterMetadata.value) return
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

watch(createQueuePlaylistDialog, (open) => {
  if (!open) return

  queuePlaylistName.value = ''
  queuePlaylistDescription.value = ''
  queuePlaylistFolderId.value = null
  void playlists.loadAll()
})

watch([
  activeLyricIndex,
  () => nowPlayingPanel.isOpen,
  () => nowPlayingPanel.activeTab,
], ([index, isOpen, activeTab]) => {
  if (typeof index !== 'number' || index < 0 || !isOpen || activeTab !== 'lyrics') return

  void scrollActiveLyricIntoView(index)
})

async function scrollActiveLyricIntoView(index: number) {
  if (index < 0) return

  await nextTick()
  const line = lyricsContainer.value
    ?.querySelector<HTMLElement>(`[data-lyric-index="${index}"]`)
  const drawer = line?.closest<HTMLElement>('.v-navigation-drawer__content')
  if (!line || !drawer) return

  const lineBounds = line.getBoundingClientRect()
  const drawerBounds = drawer.getBoundingClientRect()
  drawer.scrollTo({
    top: drawer.scrollTop
      + lineBounds.top
      - drawerBounds.top
      - ((drawer.clientHeight - lineBounds.height) / 2),
    behavior: player.isPlaying ? 'smooth' : 'auto',
  })
}

async function playAudio(showError = true) {
  const element = audio.value
  const playKey = currentPlayKey.value
  const attemptId = ++playbackAttemptId

  if (!element || !playKey) return

  try {
    player.setPlaybackState('loading')
    await element.play()
    if (isCurrentPlaybackAttempt(playKey, element, attemptId) && !element.paused) {
      clearPlaybackHandoffTimers()
      player.setPlaybackState('playing')
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
    player.pause()
  } else if (player.playbackState === 'error') {
    retryPlaybackAfterError()
  } else {
    player.resume()
  }
}

function retryPlaybackAfterError() {
  if (!audio.value || !player.currentTrack) return

  restoredTrackId.value = null
  isRestoringPosition.value = player.playbackPosition > 0
  resumeAfterMetadata.value = true
  isRestoringPlayback.value = false
  player.resume()
  audio.value.load()
}

function updateProgress(event?: Event) {
  if (!isCurrentMediaEvent(event)) return

  syncAudioProgress(!isRestoringPosition.value)
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

  beginSeekFeedback()
  const target = Math.min(duration.value, Math.max(0, value))
  audio.value.currentTime = target
  currentTime.value = target
  player.setPlaybackPosition(target)
}

function onEnded() {
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

  clearPlaybackHandoffTimers()
  resumeAfterMetadata.value = false
  isRestoringPlayback.value = false
  isRestoringPosition.value = false
  player.setError(t('player.playbackError'))
}

function onLoading(event: Event) {
  if (!isCurrentMediaEvent(event)) return

  if (event.type === 'waiting' && (isSeeking.value || audio.value?.seeking)) {
    beginSeekFeedback()
    return
  }

  if (player.isPlaying) player.setPlaybackState('loading')
}

function onPause(event?: Event) {
  if (!isCurrentMediaEvent(event)) return
  if (!player.currentTrack || player.playbackState === 'ended' || player.playbackState === 'error') return
  if (player.playbackState === 'loading' && resumeAfterMetadata.value) return

  clearPlaybackHandoffTimers()
  player.setPlaybackState('paused')
}

function onPlaying(event?: Event) {
  if (!isCurrentMediaEvent(event)) return

  clearPlaybackHandoffTimers()
  endSeekFeedback()
  player.setPlaybackState('playing')
  maybeRecordCountedPlay()
}

function onSeeking(event?: Event) {
  if (!isCurrentMediaEvent(event)) return

  beginSeekFeedback()
}

function onSeeked(event?: Event) {
  if (!isCurrentMediaEvent(event)) return

  syncAudioProgress(true)
  endSeekFeedback()
}

function persistCurrentPlaybackPosition() {
  if (!audio.value || !player.currentTrack) return

  player.setPlaybackPosition(audio.value.currentTime)
}

function formatTime(value: number) {
  const seconds = Math.max(0, Math.floor(value))
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function queueItemKey(track: typeof player.queue[number], index: number) {
  return `${track.id}-${index}`
}

function toggleQueueItem(index: number) {
  if (index === player.currentIndex) {
    if (player.isPlaying) {
      player.pause()
    } else {
      player.resume()
    }
    return
  }

  player.playQueueIndex(index)
}

function startQueueDrag(index: number, event: DragEvent) {
  draggedQueueIndex.value = index
  event.dataTransfer?.setData('text/plain', String(index))
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function stopQueueDrag() {
  draggedQueueIndex.value = null
}

function dropQueueItem(targetIndex: number) {
  const sourceIndex = draggedQueueIndex.value
  draggedQueueIndex.value = null

  if (sourceIndex === null) return

  player.moveQueuedTrack(sourceIndex, targetIndex)
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

  const requiredSeconds = Math.min(duration.value / 2, MAXIMUM_COUNTED_PLAY_THRESHOLD_SECONDS)
  if (currentTime.value < requiredSeconds) return

  reportedPlayKey.value = playKey
  const trackId = player.currentTrack.id
  const sessionKey = player.playbackSessionKey
  void apiRequest<TrackPlayResponse>(`/tracks/${trackId}/plays`, {
    method: 'POST',
    body: JSON.stringify({
      listenedMs: Math.max(0, Math.round(currentTime.value * 1000)),
      durationMs: Math.max(0, Math.round(duration.value * 1000)),
      playedAt: new Date(Date.now() - (currentTime.value * 1000)).toISOString(),
      context: player.playbackContext,
      sessionKey,
    }),
  }).then((result) => {
    player.markCurrentPlayCounted(sessionKey)
    catalog.updateTrackPlayStatistics(trackId, result.statistics)
  })
}

function openAddToPlaylist(tracks: typeof player.queue) {
  playlistTracks.value = [...tracks]
  addToPlaylistDialog.value = true
}

async function createPlaylistFromQueue() {
  if (!canCreateQueuePlaylist.value) return

  const playlist = await playlists.createPlaylist({
    name: queuePlaylistName.value.trim(),
    description: queuePlaylistDescription.value.trim() || null,
    folderId: queuePlaylistFolderId.value,
  })
  await playlists.addTracks(playlist.id, player.queue.map((track) => track.id))
  queuePlaylistSuccess.value = t('playlists.createdFromQueue', { name: playlist.name })
  queuePlaylistSuccessVisible.value = true
  createQueuePlaylistDialog.value = false
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

  playbackHandoffRetryTimer = window.setTimeout(() => {
    playbackHandoffRetryTimer = null
    recoverPlaybackHandoff(playKey)
  }, PLAYBACK_HANDOFF_RETRY_MS)
  playbackHandoffTimeoutTimer = window.setTimeout(() => {
    playbackHandoffTimeoutTimer = null
    failStalledPlaybackHandoff(playKey)
  }, PLAYBACK_HANDOFF_TIMEOUT_MS)
}

function recoverPlaybackHandoff(playKey: string) {
  const element = audio.value
  if (!isPendingPlaybackHandoff(playKey, element)) return

  if (!element.paused) {
    clearPlaybackHandoffTimers()
    player.setPlaybackState('playing')
    return
  }

  playbackAttemptId += 1
  resumeAfterMetadata.value = true
  restoredTrackId.value = null
  isRestoringPosition.value = player.playbackPosition > 0
  element.load()
}

function failStalledPlaybackHandoff(playKey: string) {
  const element = audio.value
  if (!isPendingPlaybackHandoff(playKey, element)) return

  clearPlaybackHandoffTimers()
  if (!element.paused) {
    player.setPlaybackState('playing')
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
  persistCurrentPlaybackPosition()
  window.removeEventListener('beforeunload', persistCurrentPlaybackPosition)
  clearPlaybackHandoffTimers()
  clearSeekFeedback()
  if (audio.value) releasePlayerMediaSource(audio.value)
})

onMounted(() => {
  window.addEventListener('beforeunload', persistCurrentPlaybackPosition)
})
</script>

<template>
  <v-footer v-if="player.currentTrack" app border class="player-footer" :class="{ 'is-collapsed': playerCollapsed }">
    <audio
      :key="currentPlayKey ?? undefined"
      ref="audio"
      :data-play-key="currentPlayKey ?? ''"
      :src="player.currentTrack.streamUrl"
      preload="metadata"
      :volume="player.volume"
      @canplay="onCanPlay"
      @durationchange="updateDuration"
      @ended="onEnded"
      @error="onError"
      @loadedmetadata="onLoadedMetadata"
      @loadstart="onLoading"
      @pause="onPause"
      @playing="onPlaying"
      @seeked="onSeeked"
      @seeking="onSeeking"
      @stalled="onLoading"
      @timeupdate="updateProgress"
      @waiting="onLoading"
    />

    <div v-if="playerCollapsed" class="player-collapsed-content">
      <div class="player-collapsed-progress" aria-hidden="true">
        <div class="player-collapsed-progress-fill" :style="{ width: `${progressPercent}%` }" />
      </div>

      <div class="player-collapsed-meta">
        <RouterLink
          v-if="trackRoute"
          class="player-collapsed-track player-meta-row player-meta-link text-body-2 font-weight-bold"
          :to="trackRoute"
        >
          <v-icon class="player-meta-icon" icon="mdi-music-note" size="small" />
          <span class="text-truncate">{{ trackTitle }}</span>
        </RouterLink>
        <div v-else class="player-collapsed-track player-meta-row text-body-2 font-weight-bold">
          <v-icon class="player-meta-icon" icon="mdi-music-note" size="small" />
          <span class="text-truncate">{{ trackTitle }}</span>
        </div>
        <div class="player-collapsed-secondary text-caption text-medium-emphasis">
          <span class="player-collapsed-artist">
            <span aria-hidden="true">&middot;</span>
            <RouterLink v-if="artistRoute" class="player-meta-link text-truncate" :to="artistRoute">
              {{ artistNames }}
            </RouterLink>
            <span v-else class="text-truncate">{{ artistNames }}</span>
          </span>
          <span class="player-collapsed-album">
            <span aria-hidden="true">&middot;</span>
            <RouterLink v-if="albumRoute" class="player-meta-link text-truncate" :to="albumRoute">
              {{ albumTitle }}
            </RouterLink>
            <span v-else class="text-truncate">{{ albumTitle }}</span>
          </span>
        </div>
      </div>

      <div class="player-collapsed-actions">
        <span v-if="remainingTime" class="player-collapsed-remaining">{{ remainingTime }}</span>
        <TooltipIconButton
          :text="player.isPlaying ? t('player.pause') : t('player.play')"
          density="comfortable"
          :aria-label="player.isPlaying ? t('player.pause') : t('player.play')"
          :icon="player.isPlaying ? 'mdi-pause' : 'mdi-play'"
          variant="text"
          @click="togglePlayback"
        />
        <TooltipIconButton
          :text="t('player.expand')"
          density="comfortable"
          :aria-label="t('player.expand')"
          icon="mdi-chevron-up"
          variant="text"
          @click="playerCollapsed = false"
        />
      </div>
    </div>

    <div v-else class="player-expanded-content">
      <MusicVisualizer
        class="player-visualizer"
        :active="player.playbackState === 'playing'"
        :audio-element="audio"
        :enabled="player.visualizerEnabled"
      />

      <div class="player-content">
        <div class="player-now-playing">
        <RouterLink
          v-if="albumRoute && albumArtworkThumbnailUrl"
          class="player-artwork-link"
          :to="albumRoute"
        >
          <v-img
            :alt="albumTitle"
            class="player-artwork"
            cover
            :src="albumArtworkThumbnailUrl"
          />
        </RouterLink>
        <div class="player-meta">
          <RouterLink
            v-if="trackRoute"
            class="player-meta-row player-meta-link text-subtitle-2 font-weight-bold"
            :to="trackRoute"
          >
            <v-icon class="player-meta-icon" icon="mdi-music-note" size="small" />
            <span class="text-truncate">{{ trackTitle }}</span>
          </RouterLink>
          <div v-else class="player-meta-row text-subtitle-2 font-weight-bold">
            <v-icon class="player-meta-icon" icon="mdi-music-note" size="small" />
            <span class="text-truncate">{{ trackTitle }}</span>
          </div>
          <div class="player-meta-row text-caption text-medium-emphasis">
            <v-icon class="player-meta-icon" icon="mdi-account-music-outline" size="small" />
            <RouterLink v-if="artistRoute" class="player-meta-link text-truncate" :to="artistRoute">
              {{ artistNames }}
            </RouterLink>
            <span v-else class="text-truncate">{{ artistNames }}</span>
          </div>
          <div class="player-meta-row text-caption text-medium-emphasis">
            <v-icon class="player-meta-icon" icon="mdi-album" size="small" />
            <RouterLink v-if="albumRoute" class="player-meta-link text-truncate" :to="albumRoute">
              {{ albumTitle }}
            </RouterLink>
            <span v-else class="text-truncate">{{ albumTitle }}</span>
          </div>
          <div
            v-if="playbackStateText"
            class="text-caption text-truncate"
            :class="player.playbackState === 'error' ? 'text-error' : 'text-medium-emphasis'"
          >
            {{ playbackStateText }}
          </div>
        </div>
      </div>

      <div class="player-controls">
        <TooltipIconButton
          :text="t('player.previous')"
          :aria-label="t('player.previous')"
          :disabled="!player.hasPrevious"
          icon="mdi-skip-previous"
          variant="text"
          @click="player.previous"
        />
        <TooltipIconButton
          :text="player.isPlaying ? t('player.pause') : t('player.play')"
          color="primary"
          :aria-label="player.isPlaying ? t('player.pause') : t('player.play')"
          :icon="player.isPlaying ? 'mdi-pause' : 'mdi-play'"
          variant="flat"
          @click="togglePlayback"
        />
        <TooltipIconButton
          :text="t('player.next')"
          :aria-label="t('player.next')"
          :disabled="!player.hasNext"
          :icon="player.loadingNext ? 'mdi-loading' : 'mdi-skip-next'"
          variant="text"
          @click="void player.next()"
        />
        <v-badge
          :content="player.queue.length"
          :model-value="player.queue.length > 0"
          color="primary"
          offset-x="4"
          offset-y="4"
        >
          <TooltipIconButton
            :text="t('player.queue')"
            :aria-label="t('player.queue')"
            icon="mdi-playlist-music-outline"
            variant="text"
            @click="nowPlayingPanel.toggle('queue')"
          />
        </v-badge>
        <TooltipIconButton
          :text="player.currentTrack && favorites.isTrackFavorite(player.currentTrack.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
          :aria-label="player.currentTrack && favorites.isTrackFavorite(player.currentTrack.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
          :color="player.currentTrack && favorites.isTrackFavorite(player.currentTrack.id) ? 'primary' : undefined"
          :icon="player.currentTrack && favorites.isTrackFavorite(player.currentTrack.id) ? 'mdi-heart' : 'mdi-heart-outline'"
          variant="text"
          @click="player.currentTrack ? void favorites.toggleTrack(player.currentTrack.id) : undefined"
        />
        <v-menu location="top" :close-on-content-click="false">
          <template #activator="{ props: menuProps }">
            <v-tooltip :text="t('player.settings')" location="top">
              <template #activator="{ props: tooltipProps }">
                <v-btn
                  v-bind="mergeProps(menuProps, tooltipProps)"
                  :aria-label="t('player.settings')"
                  icon="mdi-cog-outline"
                  variant="text"
                />
              </template>
            </v-tooltip>
          </template>
          <v-card class="player-settings pa-4" min-width="260">
            <v-switch
              v-model="player.continuousPlay"
              color="primary"
              hide-details
              :label="t('player.continuousPlay')"
            />
            <v-switch
              v-model="player.randomPlay"
              color="primary"
              hide-details
              :label="t('player.randomPlay')"
            />
            <v-switch
              v-model="player.visualizerEnabled"
              color="primary"
              hide-details
              :label="t('player.visualizer')"
            />
            <v-divider class="my-3" />
            <div class="d-flex align-center ga-3">
              <v-icon icon="mdi-volume-high" />
              <div class="text-body-2 font-weight-medium">{{ t('player.volume') }}</div>
              <div class="text-caption text-medium-emphasis ml-auto">{{ volumeSlider }}%</div>
            </div>
            <v-slider
              v-model="volumeSlider"
              :aria-label="t('player.volume')"
              class="mt-2"
              color="primary"
              hide-details
              max="100"
              min="0"
              step="1"
            />
          </v-card>
        </v-menu>
      </div>

      <div
        class="player-progress"
        :class="{
          'is-loading': player.playbackState === 'loading',
          'is-paused': player.playbackState === 'paused',
          'is-seeking': showSeekLoading,
        }"
      >
        <v-slider
          v-model="seekPosition"
          :aria-label="t('player.seek')"
          :disabled="!duration"
          color="primary"
          density="compact"
          hide-details
          :max="duration || 0"
          min="0"
          step="1"
          thumb-label
        >
          <template #thumb-label="{ modelValue }">
            {{ formatTime(modelValue) }}
          </template>
        </v-slider>
        <div v-if="showSeekLoading || player.playbackState === 'loading'" class="player-progress-loading" aria-hidden="true" />
        <div class="text-caption text-medium-emphasis">{{ formatTime(currentTime) }} / {{ formatTime(duration) }}</div>
      </div>

      <v-tooltip :text="t('player.collapse')" location="top">
        <template #activator="{ props }">
          <v-btn
            v-bind="props"
            class="player-collapse-button"
            :aria-label="t('player.collapse')"
            icon="mdi-chevron-down"
            variant="text"
            @click="playerCollapsed = true"
          />
        </template>
      </v-tooltip>
      </div>
    </div>
  </v-footer>

  <v-navigation-drawer
    v-model="nowPlayingDrawerOpen"
    class="now-playing-drawer"
    location="right"
    temporary
    width="520"
  >
    <div class="now-playing-drawer-header pa-4">
      <RouterLink
        v-if="albumRoute && albumArtworkThumbnailUrl"
        class="now-playing-drawer-artwork"
        :to="albumRoute"
        @click="nowPlayingPanel.close"
      >
        <v-img :alt="albumTitle" cover :src="albumArtworkThumbnailUrl" />
      </RouterLink>
      <div v-else class="now-playing-drawer-artwork now-playing-drawer-artwork-placeholder">
        <v-icon icon="mdi-album" size="28" />
      </div>

      <div class="now-playing-drawer-meta">
        <RouterLink
          v-if="trackRoute"
          class="now-playing-drawer-link text-subtitle-1 font-weight-bold"
          :to="trackRoute"
          @click="nowPlayingPanel.close"
        >
          {{ trackTitle }}
        </RouterLink>
        <div v-else class="text-subtitle-1 font-weight-bold text-truncate">{{ trackTitle }}</div>
        <RouterLink
          v-if="artistRoute"
          class="now-playing-drawer-link text-body-2 text-medium-emphasis"
          :to="artistRoute"
          @click="nowPlayingPanel.close"
        >
          {{ artistNames }}
        </RouterLink>
        <div v-else class="text-body-2 text-medium-emphasis text-truncate">{{ artistNames }}</div>
        <RouterLink
          v-if="albumRoute"
          class="now-playing-drawer-link text-caption text-medium-emphasis"
          :to="albumRoute"
          @click="nowPlayingPanel.close"
        >
          {{ albumTitle }}
        </RouterLink>
        <div v-else class="text-caption text-medium-emphasis text-truncate">{{ albumTitle }}</div>
      </div>

      <TooltipIconButton
        :text="t('player.closeNowPlaying')"
        :aria-label="t('player.closeNowPlaying')"
        icon="mdi-close"
        variant="text"
        @click="nowPlayingPanel.close"
      />
    </div>

    <v-tabs v-model="nowPlayingPanel.activeTab" color="primary" grow>
      <v-tab prepend-icon="mdi-playlist-music-outline" value="queue">{{ t('player.queue') }}</v-tab>
      <v-tab prepend-icon="mdi-information-outline" value="info">{{ t('player.info') }}</v-tab>
      <v-tab prepend-icon="mdi-text-box-outline" value="lyrics">{{ t('player.lyrics') }}</v-tab>
    </v-tabs>
    <v-divider />

    <v-window v-model="nowPlayingPanel.activeTab">
      <v-window-item value="queue">
        <div class="queue-toolbar pa-4">
          <div class="text-caption text-medium-emphasis">
            {{ t('player.queueCount', { count: player.queue.length }) }}<span v-if="queuePlayingTime"> · {{ t('catalog.playingTime', { duration: queuePlayingTime }) }}</span>
          </div>
          <div class="d-flex align-center ga-1">
        <TooltipIconButton
          :text="t('playlists.createFromQueue')"
          :aria-label="t('playlists.createFromQueue')"
          :disabled="!player.queue.length"
          icon="mdi-playlist-plus"
          variant="text"
          @click="createQueuePlaylistDialog = true"
        />
        <TooltipIconButton
          :text="t('player.clearQueue')"
          :aria-label="t('player.clearQueue')"
          :disabled="!player.queue.length"
          icon="mdi-delete-sweep-outline"
          variant="text"
          @click="player.clearQueue"
        />
          </div>
        </div>
        <v-divider />

    <v-list v-if="player.queue.length" lines="three" class="queue-list">
      <template v-if="nowPlayingQueueItem">
        <v-list-subheader>{{ t('player.nowPlaying') }}</v-list-subheader>
        <v-list-item
          :key="queueItemKey(nowPlayingQueueItem.track, nowPlayingQueueItem.index)"
          active
          color="primary"
          class="queue-item"
          :class="{ 'is-dragging': draggedQueueIndex === nowPlayingQueueItem.index }"
          draggable="true"
          @click="toggleQueueItem(nowPlayingQueueItem.index)"
          @dragend="stopQueueDrag"
          @dragover.prevent
          @dragstart="startQueueDrag(nowPlayingQueueItem.index, $event)"
          @drop.stop="dropQueueItem(nowPlayingQueueItem.index)"
        >
          <template #prepend>
            <div class="queue-prepend">
              <v-tooltip :text="t('player.dragQueueItem')" location="top">
                <template #activator="{ props }">
                  <v-icon
                    v-bind="props"
                    :aria-label="t('player.dragQueueItem')"
                    class="queue-drag-handle text-medium-emphasis"
                    icon="mdi-drag"
                    size="small"
                  />
                </template>
              </v-tooltip>
              <v-avatar color="primary" variant="tonal">
                <v-icon icon="mdi-volume-high" />
              </v-avatar>
            </div>
          </template>
          <v-list-item-title class="font-weight-bold">
            <RouterLink
              class="queue-link"
              :to="{ name: 'track-detail', params: { id: nowPlayingQueueItem.track.id } }"
              @click.stop
            >
              {{ nowPlayingQueueItem.track.title }}
            </RouterLink>
          </v-list-item-title>
          <v-list-item-subtitle>
            <template v-if="nowPlayingQueueItem.track.artists.length">
              <template v-for="(artist, artistIndex) in nowPlayingQueueItem.track.artists" :key="artist.id">
                <span v-if="artistIndex > 0">, </span>
                <RouterLink class="queue-link" :to="{ name: 'artist-detail', params: { id: artist.id } }" @click.stop>
                  {{ artist.name }}
                </RouterLink>
              </template>
            </template>
            <span v-else>{{ t('catalog.unknownArtist') }}</span>
          </v-list-item-subtitle>
          <v-list-item-subtitle>
            <RouterLink
              v-if="nowPlayingQueueItem.track.album"
              class="queue-link"
              :to="{ name: 'album-detail', params: { id: nowPlayingQueueItem.track.album.id } }"
              @click.stop
            >
              {{ nowPlayingQueueItem.track.album.title }}
            </RouterLink>
            <span v-else>{{ t('catalog.unknownAlbum') }}</span>
          </v-list-item-subtitle>
          <template #append>
            <div class="queue-actions">
              <span class="text-caption text-medium-emphasis">{{ queueDuration(nowPlayingQueueItem.track.durationMs) }}</span>
              <TooltipIconButton
                :text="player.isPlaying ? t('player.pause') : t('player.play')"
                :aria-label="player.isPlaying ? t('player.pause') : t('player.play')"
                color="primary"
                :icon="player.isPlaying ? 'mdi-pause' : 'mdi-play'"
                size="small"
                variant="text"
                @click.stop="toggleQueueItem(nowPlayingQueueItem.index)"
              />
              <TooltipIconButton
                :text="t('playlists.addTrackToPlaylist')"
                :aria-label="t('playlists.addTrackToPlaylist')"
                icon="mdi-playlist-plus"
                size="small"
                variant="text"
                @click.stop="openAddToPlaylist([nowPlayingQueueItem.track])"
              />
              <TooltipIconButton
                :text="t('player.removeFromQueue')"
                :aria-label="t('player.removeFromQueue')"
                icon="mdi-close"
                size="small"
                variant="text"
                @click.stop="player.removeQueuedTrack(nowPlayingQueueItem.index)"
              />
            </div>
          </template>
        </v-list-item>
      </template>

      <template v-if="upcomingQueueItems.length">
        <v-list-subheader>{{ t('player.upNext') }}</v-list-subheader>
        <v-list-item
          v-for="{ track, index } in upcomingQueueItems"
          :key="queueItemKey(track, index)"
          class="queue-item"
          :class="{ 'is-dragging': draggedQueueIndex === index }"
          draggable="true"
          @click="toggleQueueItem(index)"
          @dragend="stopQueueDrag"
          @dragover.prevent
          @dragstart="startQueueDrag(index, $event)"
          @drop.stop="dropQueueItem(index)"
        >
          <template #prepend>
            <div class="queue-prepend">
              <v-tooltip :text="t('player.dragQueueItem')" location="top">
                <template #activator="{ props }">
                  <v-icon
                    v-bind="props"
                    :aria-label="t('player.dragQueueItem')"
                    class="queue-drag-handle text-medium-emphasis"
                    icon="mdi-drag"
                    size="small"
                  />
                </template>
              </v-tooltip>
              <v-avatar variant="tonal">
                <v-icon icon="mdi-music-note" />
              </v-avatar>
            </div>
          </template>
          <v-list-item-title class="font-weight-bold">
            <RouterLink class="queue-link" :to="{ name: 'track-detail', params: { id: track.id } }" @click.stop>
              {{ track.title }}
            </RouterLink>
          </v-list-item-title>
          <v-list-item-subtitle>
            <template v-if="track.artists.length">
              <template v-for="(artist, artistIndex) in track.artists" :key="artist.id">
                <span v-if="artistIndex > 0">, </span>
                <RouterLink class="queue-link" :to="{ name: 'artist-detail', params: { id: artist.id } }" @click.stop>
                  {{ artist.name }}
                </RouterLink>
              </template>
            </template>
            <span v-else>{{ t('catalog.unknownArtist') }}</span>
          </v-list-item-subtitle>
          <v-list-item-subtitle>
            <RouterLink
              v-if="track.album"
              class="queue-link"
              :to="{ name: 'album-detail', params: { id: track.album.id } }"
              @click.stop
            >
              {{ track.album.title }}
            </RouterLink>
            <span v-else>{{ t('catalog.unknownAlbum') }}</span>
          </v-list-item-subtitle>
          <template #append>
            <div class="queue-actions">
              <span class="text-caption text-medium-emphasis">{{ queueDuration(track.durationMs) }}</span>
              <TooltipIconButton
                :text="t('player.play')"
                :aria-label="t('player.play')"
                icon="mdi-play"
                size="small"
                variant="text"
                @click.stop="toggleQueueItem(index)"
              />
              <TooltipIconButton
                :text="t('playlists.addTrackToPlaylist')"
                :aria-label="t('playlists.addTrackToPlaylist')"
                icon="mdi-playlist-plus"
                size="small"
                variant="text"
                @click.stop="openAddToPlaylist([track])"
              />
              <TooltipIconButton
                :text="t('player.removeFromQueue')"
                :aria-label="t('player.removeFromQueue')"
                icon="mdi-close"
                size="small"
                variant="text"
                @click.stop="player.removeQueuedTrack(index)"
              />
            </div>
          </template>
        </v-list-item>
      </template>

      <template v-if="previousQueueItems.length">
        <v-list-subheader>{{ t('player.playedEarlier') }}</v-list-subheader>
        <v-list-item
          v-for="{ track, index } in previousQueueItems"
          :key="queueItemKey(track, index)"
          class="queue-item"
          :class="{ 'is-dragging': draggedQueueIndex === index }"
          draggable="true"
          @click="toggleQueueItem(index)"
          @dragend="stopQueueDrag"
          @dragover.prevent
          @dragstart="startQueueDrag(index, $event)"
          @drop.stop="dropQueueItem(index)"
        >
          <template #prepend>
            <div class="queue-prepend">
              <v-tooltip :text="t('player.dragQueueItem')" location="top">
                <template #activator="{ props }">
                  <v-icon
                    v-bind="props"
                    :aria-label="t('player.dragQueueItem')"
                    class="queue-drag-handle text-medium-emphasis"
                    icon="mdi-drag"
                    size="small"
                  />
                </template>
              </v-tooltip>
              <v-avatar variant="tonal">
                <v-icon icon="mdi-history" />
              </v-avatar>
            </div>
          </template>
          <v-list-item-title class="font-weight-bold">
            <RouterLink class="queue-link" :to="{ name: 'track-detail', params: { id: track.id } }" @click.stop>
              {{ track.title }}
            </RouterLink>
          </v-list-item-title>
          <v-list-item-subtitle>
            <template v-if="track.artists.length">
              <template v-for="(artist, artistIndex) in track.artists" :key="artist.id">
                <span v-if="artistIndex > 0">, </span>
                <RouterLink class="queue-link" :to="{ name: 'artist-detail', params: { id: artist.id } }" @click.stop>
                  {{ artist.name }}
                </RouterLink>
              </template>
            </template>
            <span v-else>{{ t('catalog.unknownArtist') }}</span>
          </v-list-item-subtitle>
          <v-list-item-subtitle>
            <RouterLink
              v-if="track.album"
              class="queue-link"
              :to="{ name: 'album-detail', params: { id: track.album.id } }"
              @click.stop
            >
              {{ track.album.title }}
            </RouterLink>
            <span v-else>{{ t('catalog.unknownAlbum') }}</span>
          </v-list-item-subtitle>
          <template #append>
            <div class="queue-actions">
              <span class="text-caption text-medium-emphasis">{{ queueDuration(track.durationMs) }}</span>
              <TooltipIconButton
                :text="t('player.play')"
                :aria-label="t('player.play')"
                icon="mdi-play"
                size="small"
                variant="text"
                @click.stop="toggleQueueItem(index)"
              />
              <TooltipIconButton
                :text="t('playlists.addTrackToPlaylist')"
                :aria-label="t('playlists.addTrackToPlaylist')"
                icon="mdi-playlist-plus"
                size="small"
                variant="text"
                @click.stop="openAddToPlaylist([track])"
              />
              <TooltipIconButton
                :text="t('player.removeFromQueue')"
                :aria-label="t('player.removeFromQueue')"
                icon="mdi-close"
                size="small"
                variant="text"
                @click.stop="player.removeQueuedTrack(index)"
              />
            </div>
          </template>
        </v-list-item>
      </template>
        </v-list>
        <div v-else class="pa-4 text-medium-emphasis">
          {{ t('player.queueEmpty') }}
        </div>
      </v-window-item>

      <v-window-item value="info">
        <div class="now-playing-tab-content pa-4">
          <v-skeleton-loader
            v-if="enrichment.informationLoading && enrichment.identityLoading"
            type="article@2"
          />
          <v-alert
            v-if="enrichment.informationError"
            class="mb-4"
            type="error"
            variant="tonal"
          >
            {{ enrichment.informationError }}
          </v-alert>
          <v-alert
            v-if="enrichment.identityError"
            class="mb-4"
            type="warning"
            variant="tonal"
          >
            {{ enrichment.identityError }}
          </v-alert>

          <template v-if="!enrichment.informationLoading || !enrichment.identityLoading">
            <v-alert
              v-if="
                enrichment.information?.artist.stale
                  || enrichment.information?.album.stale
                  || enrichment.identity?.artist.stale
                  || enrichment.identity?.album.stale
              "
              class="mb-4"
              icon="mdi-cached"
              :text="t('player.enrichmentStale')"
              type="info"
              variant="tonal"
            />
            <v-alert
              v-if="enrichment.information?.artist.status === 'disabled'"
              class="mb-4"
              icon="mdi-cloud-off-outline"
              :text="t('player.enrichmentDisabledDescription')"
              :title="t('player.enrichmentDisabledTitle')"
              variant="tonal"
            />

            <v-card class="mb-4" rounded="lg" variant="outlined">
              <v-card-item prepend-icon="mdi-account-music-outline">
                <v-card-title>{{ t('player.artistInformation') }}</v-card-title>
                <v-card-subtitle>
                  {{ enrichment.identity?.artist.data?.name ?? enrichment.information?.artist.data?.name ?? artistNames }}
                </v-card-subtitle>
              </v-card-item>
              <v-card-text>
                <v-progress-linear
                  v-if="enrichment.identityLoading"
                  class="mb-4"
                  color="primary"
                  indeterminate
                />
                <div
                  v-if="enrichment.identity?.artist.status === 'ready'"
                  class="d-flex flex-wrap align-center ga-2 mb-4"
                >
                  <v-chip
                    v-if="identityMatchText(
                      enrichment.identity.artist.data?.matchMethod,
                      enrichment.identity.artist.data?.matchConfidence,
                    )"
                    prepend-icon="mdi-check-decagram-outline"
                    size="small"
                    variant="tonal"
                  >
                    {{ identityMatchText(
                      enrichment.identity.artist.data?.matchMethod,
                      enrichment.identity.artist.data?.matchConfidence,
                    ) }}
                  </v-chip>
                  <v-chip v-if="enrichment.identity.artist.data?.country" size="small" variant="outlined">
                    {{ t('player.country') }}: {{ enrichment.identity.artist.data.country }}
                  </v-chip>
                  <v-chip
                    v-if="activePeriod(
                      enrichment.identity.artist.data?.activeFrom,
                      enrichment.identity.artist.data?.activeTo,
                    )"
                    size="small"
                    variant="outlined"
                  >
                    {{ t('player.activePeriod') }}:
                    {{ activePeriod(
                      enrichment.identity.artist.data?.activeFrom,
                      enrichment.identity.artist.data?.activeTo,
                    ) }}
                  </v-chip>
                  <v-btn
                    v-if="enrichment.identity.artist.data?.attribution.sourceUrl"
                    append-icon="mdi-open-in-new"
                    size="small"
                    variant="text"
                    @click="openExternalUrl(enrichment.identity.artist.data.attribution.sourceUrl)"
                  >
                    MusicBrainz
                  </v-btn>
                </div>
                <v-alert
                  v-else-if="enrichment.identity?.artist.status === 'ambiguous'"
                  class="mb-4"
                  density="compact"
                  type="warning"
                  variant="tonal"
                >
                  {{ t('player.enrichmentStates.ambiguous') }}
                </v-alert>
                <template v-if="artistDescription">
                  <p
                    class="enrichment-copy"
                    :class="{ 'enrichment-copy--collapsed': artistDescriptionIsLong && !artistDescriptionExpanded }"
                  >
                    {{ artistDescription }}
                  </p>
                  <v-btn
                    v-if="artistDescriptionIsLong"
                    class="mt-1 px-0"
                    :append-icon="artistDescriptionExpanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                    size="small"
                    variant="text"
                    @click="artistDescriptionExpanded = !artistDescriptionExpanded"
                  >
                    {{ artistDescriptionExpanded ? t('player.showLess') : t('player.showMore') }}
                  </v-btn>
                </template>
                <div v-if="enrichment.information?.artist.data?.tags.length" class="d-flex flex-wrap ga-2 mt-4">
                  <v-chip v-for="tag in enrichment.information?.artist.data?.tags ?? []" :key="tag" size="small" variant="tonal">
                    {{ tag }}
                  </v-chip>
                </div>
                <v-btn
                  v-if="enrichment.information?.artist.data?.attribution.sourceUrl"
                  class="mt-4 px-0"
                  append-icon="mdi-open-in-new"
                  variant="text"
                  @click="openExternalUrl(enrichment.information?.artist.data?.attribution.sourceUrl)"
                >
                  {{ t('player.source', { source: enrichment.information?.artist.data?.attribution.label }) }}
                </v-btn>
                <div
                  v-else-if="!enrichment.informationLoading && enrichment.information?.artist.status !== 'ready'"
                  class="text-medium-emphasis"
                >
                  {{
                  enrichmentStateText(
                    enrichment.information?.artist.status,
                    enrichment.information?.artist.errorCode,
                  )
                  }}
                </div>
              </v-card-text>
            </v-card>

            <v-card rounded="lg" variant="outlined">
              <v-card-item prepend-icon="mdi-album">
                <v-card-title>{{ t('player.albumInformation') }}</v-card-title>
                <v-card-subtitle>
                  {{ enrichment.identity?.album.data?.title ?? enrichment.information?.album.data?.title ?? albumTitle }}
                </v-card-subtitle>
              </v-card-item>
              <v-card-text>
                <v-progress-linear
                  v-if="enrichment.identityLoading"
                  class="mb-4"
                  color="primary"
                  indeterminate
                />
                <div
                  v-if="enrichment.identity?.album.status === 'ready'"
                  class="d-flex flex-wrap align-center ga-2 mb-4"
                >
                  <v-chip
                    v-if="identityMatchText(
                      enrichment.identity.album.data?.matchMethod,
                      enrichment.identity.album.data?.matchConfidence,
                    )"
                    prepend-icon="mdi-check-decagram-outline"
                    size="small"
                    variant="tonal"
                  >
                    {{ identityMatchText(
                      enrichment.identity.album.data?.matchMethod,
                      enrichment.identity.album.data?.matchConfidence,
                    ) }}
                  </v-chip>
                  <v-chip v-if="enrichment.identity.album.data?.releaseDate" size="small" variant="outlined">
                    {{ t('player.releaseDate') }}: {{ enrichment.identity.album.data.releaseDate }}
                  </v-chip>
                  <v-chip v-if="enrichment.identity.album.data?.label" size="small" variant="outlined">
                    {{ t('player.label') }}: {{ enrichment.identity.album.data.label }}
                  </v-chip>
                  <v-chip v-if="enrichment.identity.album.data?.releaseType" size="small" variant="outlined">
                    {{ t('player.releaseType') }}: {{ enrichment.identity.album.data.releaseType }}
                  </v-chip>
                  <v-btn
                    v-if="enrichment.identity.album.data?.attribution.sourceUrl"
                    append-icon="mdi-open-in-new"
                    size="small"
                    variant="text"
                    @click="openExternalUrl(enrichment.identity.album.data.attribution.sourceUrl)"
                  >
                    MusicBrainz
                  </v-btn>
                </div>
                <v-alert
                  v-else-if="enrichment.identity?.album.status === 'ambiguous'"
                  class="mb-4"
                  density="compact"
                  type="warning"
                  variant="tonal"
                >
                  {{ t('player.enrichmentStates.ambiguous') }}
                </v-alert>
                <template v-if="albumDescription">
                  <p
                    class="enrichment-copy"
                    :class="{ 'enrichment-copy--collapsed': albumDescriptionIsLong && !albumDescriptionExpanded }"
                  >
                    {{ albumDescription }}
                  </p>
                  <v-btn
                    v-if="albumDescriptionIsLong"
                    class="mt-1 px-0"
                    :append-icon="albumDescriptionExpanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                    size="small"
                    variant="text"
                    @click="albumDescriptionExpanded = !albumDescriptionExpanded"
                  >
                    {{ albumDescriptionExpanded ? t('player.showLess') : t('player.showMore') }}
                  </v-btn>
                </template>
                <div v-if="enrichment.information?.album.data?.tags.length" class="d-flex flex-wrap ga-2 mt-4">
                  <v-chip v-for="tag in enrichment.information?.album.data?.tags ?? []" :key="tag" size="small" variant="tonal">
                    {{ tag }}
                  </v-chip>
                </div>
                <v-btn
                  v-if="enrichment.information?.album.data?.attribution.sourceUrl"
                  class="mt-4 px-0"
                  append-icon="mdi-open-in-new"
                  variant="text"
                  @click="openExternalUrl(enrichment.information?.album.data?.attribution.sourceUrl)"
                >
                  {{ t('player.source', { source: enrichment.information?.album.data?.attribution.label }) }}
                </v-btn>
                <div
                  v-else-if="!enrichment.informationLoading && enrichment.information?.album.status !== 'ready'"
                  class="text-medium-emphasis"
                >
                  {{
                  enrichmentStateText(
                    enrichment.information?.album.status,
                    enrichment.information?.album.errorCode,
                  )
                  }}
                </div>
              </v-card-text>
            </v-card>
          </template>
        </div>
      </v-window-item>

      <v-window-item eager value="lyrics">
        <div v-if="enrichment.lyricsLoading" class="pa-4">
          <v-skeleton-loader type="article" />
        </div>
        <div v-else-if="enrichment.lyricsError" class="pa-4">
          <v-alert type="error" variant="tonal">{{ enrichment.lyricsError }}</v-alert>
        </div>
        <div v-else-if="enrichment.lyrics?.status === 'ready'" class="now-playing-tab-content pa-4">
          <v-alert
            v-if="enrichment.lyrics.stale"
            class="mb-4"
            icon="mdi-cached"
            :text="t('player.enrichmentStale')"
            type="info"
            variant="tonal"
          />
          <div v-if="enrichment.lyrics.data?.instrumental" class="now-playing-empty-state pa-8 text-center">
            <v-icon class="mb-4" color="primary" icon="mdi-music-note-off-outline" size="48" />
            <div class="text-h6 font-weight-bold">{{ t('player.instrumentalTrack') }}</div>
          </div>
          <div
            v-else-if="synchronizedLyricLines.length"
            ref="lyricsContainer"
            class="synchronized-lyrics"
          >
            <button
              v-for="(line, index) in synchronizedLyricLines"
              :key="`${line.timeSeconds}-${index}`"
              :aria-current="index === activeLyricIndex ? 'true' : undefined"
              :aria-label="t('player.seekToLyric', { time: formatTime(line.timeSeconds), lyric: line.text })"
              class="synchronized-lyric-line"
              :class="{
                'synchronized-lyric-line--active': index === activeLyricIndex,
                'synchronized-lyric-line--past': index < activeLyricIndex,
              }"
              :data-lyric-index="index"
              type="button"
              @click="seekTo(line.timeSeconds)"
            >
              <span class="synchronized-lyric-time">{{ formatTime(line.timeSeconds) }}</span>
              <span>{{ line.text }}</span>
            </button>
          </div>
          <pre v-else class="lyrics-copy">{{ enrichment.lyrics.data?.plainLyrics }}</pre>
          <v-btn
            v-if="enrichment.lyrics.data?.attribution.sourceUrl"
            class="mt-4 px-0"
            append-icon="mdi-open-in-new"
            variant="text"
            @click="openExternalUrl(enrichment.lyrics.data.attribution.sourceUrl)"
          >
            {{ t('player.source', { source: enrichment.lyrics.data.attribution.label }) }}
          </v-btn>
        </div>
        <div v-else class="now-playing-empty-state pa-8 text-center">
          <v-icon class="mb-4" color="primary" icon="mdi-text-box-search-outline" size="48" />
          <div class="text-h6 font-weight-bold">{{ t('player.lyricsUnavailableTitle') }}</div>
          <div class="text-body-2 text-medium-emphasis mt-2">
            {{ enrichmentStateText(enrichment.lyrics?.status, enrichment.lyrics?.errorCode) }}
          </div>
        </div>
      </v-window-item>
    </v-window>
  </v-navigation-drawer>

  <AddToPlaylistDialog v-model="addToPlaylistDialog" :tracks="playlistTracks" />

  <v-dialog v-model="createQueuePlaylistDialog" max-width="560">
    <v-card rounded="xl" prepend-icon="mdi-playlist-plus" :title="t('playlists.createFromQueueTitle')">
      <v-card-text>
        <v-alert v-if="playlists.error" class="mb-4" type="error" variant="tonal">
          {{ playlists.error }}
        </v-alert>
        <v-row dense>
          <v-col cols="12">
            <v-text-field
              v-model="queuePlaylistName"
              autofocus
              :disabled="playlists.saving"
              :label="t('playlists.playlistName')"
              variant="outlined"
              @keydown.enter.prevent="createPlaylistFromQueue"
            />
          </v-col>
          <v-col cols="12">
            <v-select
              v-model="queuePlaylistFolderId"
              :disabled="playlists.saving"
              :items="playlistFolderOptions"
              :label="t('playlists.folder')"
              variant="outlined"
            />
          </v-col>
          <v-col cols="12">
            <v-textarea
              v-model="queuePlaylistDescription"
              :disabled="playlists.saving"
              :label="t('playlists.descriptionField')"
              rows="3"
              variant="outlined"
            />
          </v-col>
        </v-row>
        <div class="text-caption text-medium-emphasis">
          {{ t('playlists.createFromQueueDescription', { count: player.queue.length }) }}
        </div>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="createQueuePlaylistDialog = false">
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn color="primary" :disabled="!canCreateQueuePlaylist" :loading="playlists.saving" variant="flat" @click="createPlaylistFromQueue">
          {{ t('playlists.createFromQueue') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-snackbar v-model="queuePlaylistSuccessVisible" color="primary" timeout="2500">
    {{ queuePlaylistSuccess }}
  </v-snackbar>
</template>

<style scoped>
.player-footer {
  display: block;
  padding: 10px 18px;
}

.player-expanded-content {
  display: grid;
  gap: 8px;
  width: 100%;
}

.player-visualizer {
  width: 100%;
}

.player-footer.is-collapsed {
  padding-block: 6px;
  position: relative;
}

.player-content {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) auto minmax(180px, 0.8fr) auto;
  align-items: center;
  gap: 14px;
  width: 100%;
}

.player-collapsed-content {
  align-items: center;
  display: grid;
  gap: 12px;
  grid-template-columns: minmax(0, 1fr) auto;
  width: 100%;
}

.player-collapsed-meta {
  align-items: center;
  display: flex;
  gap: 6px;
  min-width: 0;
}

.player-collapsed-track {
  flex: 0 1 auto;
  max-width: 45%;
}

.player-collapsed-secondary {
  align-items: center;
  display: flex;
  flex: 1 1 auto;
  gap: 6px;
  min-width: 0;
}

.player-collapsed-artist,
.player-collapsed-album {
  align-items: center;
  display: flex;
  gap: 6px;
  min-width: 0;
}

.player-collapsed-artist {
  flex: 0 1 auto;
}

.player-collapsed-album {
  flex: 1 1 auto;
}

.player-collapsed-progress {
  background: rgba(var(--v-theme-primary), 0.08);
  height: 3px;
  left: 0;
  overflow: visible;
  position: absolute;
  right: 0;
  top: 0;
}

.player-collapsed-progress-fill {
  background: rgb(var(--v-theme-primary));
  height: 100%;
  min-width: 2px;
  transition: width 0.2s linear;
}

.player-collapsed-remaining {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 999px;
  color: rgb(var(--v-theme-on-surface));
  flex: 0 0 auto;
  font-size: 0.68rem;
  line-height: 1.2;
  padding: 1px 6px;
}

.player-collapsed-actions {
  align-items: center;
  display: flex;
  gap: 4px;
  white-space: nowrap;
}

.player-controls {
  display: flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}

.player-collapse-button {
  align-self: center;
  height: 32px;
  justify-self: end;
  min-width: 32px;
  width: 32px;
}

.player-now-playing {
  align-items: center;
  display: flex;
  gap: 10px;
  min-width: 0;
}

.player-artwork-link {
  border-radius: 8px;
  flex: 0 0 56px;
  height: 56px;
  overflow: hidden;
  width: 56px;
}

.player-artwork {
  height: 100%;
  width: 100%;
}

.player-meta {
  flex: 1 1 auto;
  min-width: 0;
}

.player-meta-row {
  align-items: center;
  display: flex;
  gap: 6px;
  min-width: 0;
}

.player-meta-icon {
  flex: 0 0 auto;
}

.player-progress {
  display: grid;
  gap: 4px;
  position: relative;
}

.player-progress.is-paused :deep(.v-slider-track__fill) {
  opacity: 0.55;
}

.player-progress.is-paused :deep(.v-slider-thumb__surface) {
  box-shadow: 0 0 0 6px rgba(var(--v-theme-primary), 0.12);
}

.player-progress-loading {
  animation: player-seek-loading 0.85s ease-in-out infinite;
  background: linear-gradient(90deg, transparent, rgb(var(--v-theme-primary)), transparent);
  border-radius: 999px;
  height: 3px;
  left: 12px;
  opacity: 0.75;
  pointer-events: none;
  position: absolute;
  right: 12px;
  top: 16px;
}

.player-meta-link {
  color: inherit;
  display: inline-block;
  max-width: 100%;
  overflow: hidden;
  text-decoration: none;
  text-overflow: ellipsis;
  vertical-align: bottom;
  white-space: nowrap;
}

.player-meta-link:hover {
  text-decoration: underline;
}

.player-meta-row.player-meta-link {
  display: flex;
}

.now-playing-drawer-header {
  align-items: center;
  display: grid;
  gap: 12px;
  grid-template-columns: 56px minmax(0, 1fr) auto;
}

.now-playing-drawer-artwork {
  border-radius: 10px;
  height: 56px;
  overflow: hidden;
  width: 56px;
}

.now-playing-drawer-artwork-placeholder {
  align-items: center;
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
  display: flex;
  justify-content: center;
}

.now-playing-drawer-meta {
  display: grid;
  min-width: 0;
}

.now-playing-drawer-link {
  color: inherit;
  overflow: hidden;
  text-decoration: none;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.now-playing-drawer-link:hover {
  text-decoration: underline;
}

.queue-toolbar {
  align-items: center;
  display: flex;
  justify-content: space-between;
}

.now-playing-tab-content {
  max-width: 100%;
}

.enrichment-copy,
.lyrics-copy {
  line-height: 1.65;
  white-space: pre-wrap;
}

.enrichment-copy--collapsed {
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 8;
  display: -webkit-box;
  overflow: hidden;
}

.lyrics-copy {
  font: inherit;
  margin: 0;
}

.synchronized-lyrics {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding-block: 1rem;
  scroll-behavior: smooth;
}

.synchronized-lyric-line {
  align-items: baseline;
  background: transparent;
  border: 0;
  border-radius: 10px;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
  display: grid;
  font: inherit;
  gap: 0.75rem;
  grid-template-columns: 2.75rem minmax(0, 1fr);
  line-height: 1.5;
  opacity: 0.58;
  padding: 0.55rem 0.75rem;
  text-align: left;
  transition: background-color 160ms ease, color 160ms ease, opacity 160ms ease;
  width: 100%;
}

.synchronized-lyric-line:hover,
.synchronized-lyric-line:focus-visible {
  background: rgba(var(--v-theme-primary), 0.08);
  opacity: 1;
  outline: none;
}

.synchronized-lyric-line--past {
  opacity: 0.4;
}

.synchronized-lyric-line--active {
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary));
  font-weight: 700;
  opacity: 1;
}

.synchronized-lyric-time {
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  opacity: 0.72;
}

.synchronized-lyric-line > span:last-child {
  min-width: 0;
  overflow-wrap: anywhere;
  white-space: normal;
}

.now-playing-empty-state {
  margin-inline: auto;
  max-width: 420px;
}

.queue-list {
  padding: 8px;
}

.queue-item {
  border-radius: 12px;
  cursor: grab;
}

.queue-item:active {
  cursor: grabbing;
}

.queue-item.is-dragging {
  opacity: 0.55;
}

.queue-prepend {
  align-items: center;
  display: flex;
  gap: 0.5rem;
  padding-inline-end: calc(0.35rem + 5px);
}

.queue-drag-handle {
  cursor: grab;
}

.queue-actions {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.125rem;
  justify-content: flex-end;
}

.queue-link {
  color: inherit;
  text-decoration: none;
}

.queue-link:hover {
  text-decoration: underline;
}

@keyframes player-seek-loading {
  0% {
    transform: translateX(-24%) scaleX(0.35);
  }

  50% {
    transform: translateX(0) scaleX(0.85);
  }

  100% {
    transform: translateX(24%) scaleX(0.35);
  }
}

@media (max-width: 760px) {
  .player-content {
    grid-template-columns: minmax(0, 1fr) auto auto;
  }

  .player-progress {
    grid-column: 1 / -2;
  }

  .player-collapse-button {
    grid-column: -2 / -1;
    grid-row: 2;
  }

  .player-artwork-link {
    display: none;
  }

}

@media (max-width: 620px) {
  .player-visualizer {
    height: 38px;
  }

  .player-content {
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .player-now-playing {
    grid-column: 1 / -1;
  }

  .player-controls {
    flex-wrap: wrap;
    grid-column: 1 / 2;
    grid-row: 2;
    justify-self: start;
  }

  .player-collapse-button {
    align-self: start;
    grid-column: 2 / 3;
    grid-row: 2;
  }

  .player-progress {
    grid-column: 1 / -1;
    grid-row: 3;
  }

  .player-collapsed-content {
    align-items: start;
  }

  .player-collapsed-meta {
    align-items: start;
    flex-direction: column;
    gap: 1px;
  }

  .player-collapsed-track {
    max-width: 100%;
    width: 100%;
  }

  .player-collapsed-secondary {
    padding-inline-start: 22px;
    width: 100%;
  }

  .player-collapsed-actions {
    padding-block-start: 2px;
  }
}

@media (max-width: 560px) {
  :deep(.now-playing-drawer) {
    max-width: 100vw;
  }
}

@media (max-width: 520px) {
  .player-collapsed-album {
    display: none;
  }
}

@media (max-width: 380px) {
  .player-collapsed-track {
    max-width: none;
  }

  .player-collapsed-artist {
    display: none;
  }
}
</style>
