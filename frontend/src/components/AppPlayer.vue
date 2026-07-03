<script setup lang="ts">
import { computed, mergeProps, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCatalogStore, type TrackPlayStatistics } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'
import { usePlaylistsStore } from '@/stores/playlists'

const { t } = useI18n()
const MINIMUM_COUNTED_TRACK_SECONDS = 30
const MAXIMUM_COUNTED_PLAY_THRESHOLD_SECONDS = 240
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const playlists = usePlaylistsStore()
const audio = ref<HTMLAudioElement | null>(null)
const queueDrawer = ref(false)
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
const reportedPlayKey = ref<string | null>(null)
let seekLoadingTimer: ReturnType<typeof window.setTimeout> | null = null
let seekLoadingClearTimer: ReturnType<typeof window.setTimeout> | null = null
const volumeSlider = computed({
  get: () => Math.round(player.volume * 100),
  set: (value: number) => player.setVolume(value / 100),
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
const artistAlbumsRoute = computed(() => {
  return primaryArtist.value
    ? { name: 'albums', query: { artist: primaryArtist.value.id, artistName: primaryArtist.value.name } }
    : null
})
const playbackStateText = computed(() => {
  if (player.error) return player.error

  if (player.playbackState === 'ended') return t('player.states.ended')

  return null
})
const queueItems = computed(() => player.queue.map((track, index) => ({ track, index })))
const nowPlayingQueueItem = computed(() => queueItems.value.find((item) => item.index === player.currentIndex) ?? null)
const upcomingQueueItems = computed(() => queueItems.value.filter((item) => item.index > player.currentIndex))
const previousQueueItems = computed(() => queueItems.value.filter((item) => item.index < player.currentIndex).reverse())
const currentPlayKey = computed(() => player.currentTrack ? `${player.currentTrack.id}:${player.playbackSessionKey}` : null)
const playlistFolderOptions = computed(() => [
  { title: t('playlists.noFolder'), value: null },
  ...playlists.folders.map((folder) => ({ title: folder.name, value: folder.id })),
])
const canCreateQueuePlaylist = computed(() => queuePlaylistName.value.trim().length > 0 && player.queue.length > 0 && !playlists.saving)

interface TrackPlayResponse {
  counted: boolean
  statistics: TrackPlayStatistics
}

watch(
  () => currentPlayKey.value,
  () => {
    restoredTrackId.value = null
    reportedPlayKey.value = null
    currentTime.value = player.playbackPosition
    duration.value = 0
    isRestoringPosition.value = player.playbackPosition > 0
    resumeAfterMetadata.value = player.isPlaying
    isRestoringPlayback.value = false
  },
  { flush: 'sync' },
)

watch(
  () => player.isPlaying,
  async (isPlaying) => {
    if (!audio.value || !player.currentTrack) return

    if (isPlaying) {
      if (resumeAfterMetadata.value) return
      await playAudio()
    } else {
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

async function playAudio(showError = true) {
  try {
    player.setPlaybackState('loading')
    await audio.value?.play()
  } catch {
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

function updateProgress() {
  syncAudioProgress(!isRestoringPosition.value)
  maybeRecordCountedPlay()
}

function updateDuration() {
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

function onLoadedMetadata() {
  syncAudioProgress(false)
  restorePlaybackPosition()
  maybeRecordCountedPlay()
  if (resumeAfterMetadata.value && player.isPlaying) {
    resumeAfterMetadata.value = false
    const showResumeError = !isRestoringPlayback.value
    isRestoringPlayback.value = false
    void playAudio(showResumeError)
  }
}

function onError() {
  resumeAfterMetadata.value = false
  isRestoringPlayback.value = false
  isRestoringPosition.value = false
  player.setError(t('player.playbackError'))
}

function onLoading(event: Event) {
  if (event.type === 'waiting' && (isSeeking.value || audio.value?.seeking)) {
    beginSeekFeedback()
    return
  }

  if (player.isPlaying) player.setPlaybackState('loading')
}

function onPause() {
  if (!player.currentTrack || player.playbackState === 'ended' || player.playbackState === 'error') return

  player.setPlaybackState('paused')
}

function onPlaying() {
  endSeekFeedback()
  player.setPlaybackState('playing')
  maybeRecordCountedPlay()
}

function onSeeking() {
  beginSeekFeedback()
}

function onSeeked() {
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

function queueDuration(milliseconds?: number) {
  if (!milliseconds) return '-'
  const seconds = Math.round(milliseconds / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function queueItemKey(track: typeof player.queue[number], index: number) {
  return `${track.id}-${index}`
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

function openCurrentTrackPlaylistDialog() {
  if (!player.currentTrack) return

  openAddToPlaylist([player.currentTrack])
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

onBeforeUnmount(() => {
  persistCurrentPlaybackPosition()
  window.removeEventListener('beforeunload', persistCurrentPlaybackPosition)
  if (seekLoadingTimer) window.clearTimeout(seekLoadingTimer)
  if (seekLoadingClearTimer) window.clearTimeout(seekLoadingClearTimer)
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
      :src="player.currentTrack.streamUrl"
      preload="metadata"
      :volume="player.volume"
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
            <RouterLink v-if="artistAlbumsRoute" class="player-meta-link text-truncate" :to="artistAlbumsRoute">
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

    <div v-else class="player-content">
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
            <RouterLink v-if="artistAlbumsRoute" class="player-meta-link text-truncate" :to="artistAlbumsRoute">
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
            @click="queueDrawer = true"
          />
        </v-badge>
        <TooltipIconButton
          :text="t('playlists.addTrackToPlaylist')"
          :aria-label="t('playlists.addTrackToPlaylist')"
          :disabled="!player.currentTrack"
          icon="mdi-playlist-plus"
          variant="text"
          @click="openCurrentTrackPlaylistDialog"
        />
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
  </v-footer>

  <v-navigation-drawer
    v-model="queueDrawer"
    class="queue-drawer"
    location="right"
    temporary
    width="520"
  >
    <div class="d-flex align-center justify-space-between pa-4">
      <div>
        <div class="text-h6 font-weight-bold">{{ t('player.queue') }}</div>
        <div class="text-caption text-medium-emphasis">{{ t('player.queueCount', { count: player.queue.length }) }}</div>
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
        <TooltipIconButton
          :text="t('player.closeQueue')"
          :aria-label="t('player.closeQueue')"
          icon="mdi-close"
          variant="text"
          @click="queueDrawer = false"
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
          active-color="primary"
          class="queue-item"
          :class="{ 'is-dragging': draggedQueueIndex === nowPlayingQueueItem.index }"
          draggable="true"
          @click="player.playQueueIndex(nowPlayingQueueItem.index)"
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
                <RouterLink class="queue-link" :to="{ name: 'albums', query: { artist: artist.id, artistName: artist.name } }" @click.stop>
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
          @click="player.playQueueIndex(index)"
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
                <RouterLink class="queue-link" :to="{ name: 'albums', query: { artist: artist.id, artistName: artist.name } }" @click.stop>
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
          @click="player.playQueueIndex(index)"
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
                <RouterLink class="queue-link" :to="{ name: 'albums', query: { artist: artist.id, artistName: artist.name } }" @click.stop>
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
  padding: 10px 18px;
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
  :deep(.queue-drawer) {
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
