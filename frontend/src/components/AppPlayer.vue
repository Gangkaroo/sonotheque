<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'

const { t } = useI18n()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const audio = ref<HTMLAudioElement | null>(null)
const queueDrawer = ref(false)
const currentTime = ref(0)
const duration = ref(0)
const restoredTrackId = ref<number | null>(null)
const resumeAfterMetadata = ref(false)
const isRestoringPlayback = ref(false)
const isRestoringPosition = ref(false)
const isSeeking = ref(false)
const showSeekLoading = ref(false)
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
const artistLine = computed(() => {
  const track = player.currentTrack
  if (!track) return ''

  const artists = track.artists.map((artist) => artist.name).join(', ')
  const album = track.album?.title

  return [artists, album].filter(Boolean).join(' · ')
})

const primaryArtist = computed(() => player.currentTrack?.artists[0] ?? null)
const artistNames = computed(() => player.currentTrack?.artists.map((artist) => artist.name).join(', ') ?? '')
const trackRoute = computed(() => {
  const trackId = player.currentTrack?.id

  return trackId ? { name: 'track-detail', params: { id: trackId } } : null
})
const albumRoute = computed(() => {
  const albumId = player.currentTrack?.album?.id

  return albumId ? { name: 'album-detail', params: { id: albumId } } : null
})
const artistAlbumsRoute = computed(() => {
  return primaryArtist.value ? { name: 'albums', query: { search: primaryArtist.value.name } } : null
})
const playbackStateText = computed(() => {
  if (player.error) return player.error

  if (player.playbackState === 'loading') return isSeeking.value || showSeekLoading.value ? null : t('player.states.loading')
  if (player.playbackState === 'paused') return t('player.states.paused')
  if (player.playbackState === 'ended') return t('player.states.ended')

  return null
})

watch(
  () => player.currentTrack?.id,
  async () => {
    restoredTrackId.value = null
    currentTime.value = player.playbackPosition
    duration.value = 0
    isRestoringPosition.value = player.playbackPosition > 0
    await nextTick()
    resumeAfterMetadata.value = player.isPlaying
    isRestoringPlayback.value = false
    audio.value?.load()
  },
)

watch(
  () => player.isPlaying,
  async (isPlaying) => {
    if (!audio.value || !player.currentTrack) return

    if (isPlaying) {
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
  } else {
    player.resume()
  }
}

function updateProgress() {
  syncAudioProgress(!isRestoringPosition.value)
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

function queueArtistLine(track: typeof player.queue[number]) {
  return track.artists.map((artist) => artist.name).join(', ') || t('catalog.unknownArtist')
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

onMounted(async () => {
  window.addEventListener('beforeunload', persistCurrentPlaybackPosition)
  if (!player.currentTrack) return

  currentTime.value = player.playbackPosition
  isRestoringPosition.value = player.playbackPosition > 0
  await nextTick()
  resumeAfterMetadata.value = player.isPlaying
  isRestoringPlayback.value = player.isPlaying
  audio.value?.load()
})
</script>

<template>
  <v-footer v-if="player.currentTrack" app border class="player-footer">
    <audio
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

    <div class="player-content">
      <div class="player-meta">
        <RouterLink
          v-if="trackRoute"
          class="player-meta-link text-subtitle-2 font-weight-bold text-truncate"
          :to="trackRoute"
        >
          {{ player.currentTrack.title }}
        </RouterLink>
        <div v-else class="text-subtitle-2 font-weight-bold text-truncate">{{ player.currentTrack.title }}</div>
        <div class="text-caption text-medium-emphasis text-truncate">
          <RouterLink v-if="artistAlbumsRoute" class="player-meta-link" :to="artistAlbumsRoute">
            {{ artistNames }}
          </RouterLink>
          <span v-else-if="!albumRoute">{{ artistLine }}</span>
          <template v-if="albumRoute && player.currentTrack.album">
            <span v-if="artistAlbumsRoute"> · </span>
            <RouterLink class="player-meta-link" :to="albumRoute">
              {{ player.currentTrack.album.title }}
            </RouterLink>
          </template>
        </div>
        <div
          v-if="playbackStateText"
          class="text-caption text-truncate"
          :class="player.playbackState === 'error' ? 'text-error' : 'text-medium-emphasis'"
        >
          {{ playbackStateText }}
        </div>
      </div>

      <div class="player-controls">
        <v-btn
          :aria-label="t('player.previous')"
          :disabled="!player.hasPrevious"
          icon="mdi-skip-previous"
          variant="text"
          @click="player.previous"
        />
        <v-btn
          color="primary"
          :aria-label="player.isPlaying ? t('player.pause') : t('player.play')"
          :icon="player.isPlaying ? 'mdi-pause' : 'mdi-play'"
          variant="flat"
          @click="togglePlayback"
        />
        <v-btn
          :aria-label="t('player.next')"
          :disabled="!player.hasNext"
          :icon="player.loadingNext ? 'mdi-loading' : 'mdi-skip-next'"
          variant="text"
          @click="void player.next()"
        />
        <v-badge :content="player.queue.length" :model-value="player.queue.length > 0" color="primary">
          <v-btn
            :aria-label="t('player.queue')"
            icon="mdi-playlist-music-outline"
            variant="text"
            @click="queueDrawer = true"
          />
        </v-badge>
        <v-btn
          :aria-label="player.currentTrack && favorites.isTrackFavorite(player.currentTrack.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
          :color="player.currentTrack && favorites.isTrackFavorite(player.currentTrack.id) ? 'primary' : undefined"
          :icon="player.currentTrack && favorites.isTrackFavorite(player.currentTrack.id) ? 'mdi-heart' : 'mdi-heart-outline'"
          variant="text"
          @click="player.currentTrack ? void favorites.toggleTrack(player.currentTrack.id) : undefined"
        />
        <v-menu location="top" :close-on-content-click="false">
          <template #activator="{ props }">
            <v-btn
              v-bind="props"
              :aria-label="t('player.settings')"
              icon="mdi-cog-outline"
              variant="text"
            />
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
        <v-btn :aria-label="t('player.close')" icon="mdi-close" variant="text" @click="player.stop" />
      </div>

      <div class="player-progress" :class="{ 'is-seeking': showSeekLoading }">
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
        />
        <div v-if="showSeekLoading" class="player-progress-loading" aria-hidden="true" />
        <div class="text-caption text-medium-emphasis">{{ formatTime(currentTime) }} / {{ formatTime(duration) }}</div>
      </div>
    </div>
  </v-footer>

  <v-navigation-drawer
    v-model="queueDrawer"
    location="right"
    temporary
    width="420"
  >
    <div class="d-flex align-center justify-space-between pa-4">
      <div>
        <div class="text-h6 font-weight-bold">{{ t('player.queue') }}</div>
        <div class="text-caption text-medium-emphasis">{{ t('player.queueCount', { count: player.queue.length }) }}</div>
      </div>
      <v-btn :aria-label="t('player.closeQueue')" icon="mdi-close" variant="text" @click="queueDrawer = false" />
    </div>
    <v-divider />

    <v-list v-if="player.queue.length" lines="three" class="queue-list">
      <v-list-item
        v-for="(track, index) in player.queue"
        :key="`${track.id}-${index}`"
        :active="index === player.currentIndex"
        active-color="primary"
        class="queue-item"
        @click="player.playQueueIndex(index)"
      >
        <template #prepend>
          <v-avatar :color="index === player.currentIndex ? 'primary' : undefined" variant="tonal">
            <v-icon :icon="index === player.currentIndex ? 'mdi-volume-high' : 'mdi-music-note'" />
          </v-avatar>
        </template>
        <v-list-item-title class="font-weight-bold">{{ track.title }}</v-list-item-title>
        <v-list-item-subtitle>{{ queueArtistLine(track) }}</v-list-item-subtitle>
        <v-list-item-subtitle>
          {{ track.album?.title ?? t('catalog.unknownAlbum') }}
        </v-list-item-subtitle>
        <template #append>
          <div class="d-flex align-center ga-1">
            <span class="text-caption text-medium-emphasis">{{ queueDuration(track.durationMs) }}</span>
            <v-btn
              :aria-label="t('player.removeFromQueue')"
              :disabled="index === player.currentIndex"
              icon="mdi-close"
              size="small"
              variant="text"
              @click.stop="player.removeQueuedTrack(index)"
            />
          </div>
        </template>
      </v-list-item>
    </v-list>
    <div v-else class="pa-4 text-medium-emphasis">
      {{ t('player.queueEmpty') }}
    </div>
  </v-navigation-drawer>
</template>

<style scoped>
.player-footer {
  padding: 10px 18px;
}

.player-content {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) auto minmax(180px, 0.8fr);
  align-items: center;
  gap: 18px;
  width: 100%;
}

.player-controls {
  display: flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}

.player-progress {
  display: grid;
  gap: 4px;
  position: relative;
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

.queue-list {
  padding: 8px;
}

.queue-item {
  border-radius: 12px;
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
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .player-progress {
    grid-column: 1 / -1;
  }
}
</style>
