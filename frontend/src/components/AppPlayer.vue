<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { usePlayerStore } from '@/stores/player'

const { t } = useI18n()
const player = usePlayerStore()
const audio = ref<HTMLAudioElement | null>(null)
const currentTime = ref(0)
const duration = ref(0)
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
const albumRoute = computed(() => {
  const albumId = player.currentTrack?.album?.id

  return albumId ? { name: 'album-detail', params: { id: albumId } } : null
})
const artistAlbumsRoute = computed(() => {
  return primaryArtist.value ? { name: 'albums', query: { search: primaryArtist.value.name } } : null
})

watch(
  () => player.currentTrack?.id,
  async () => {
    currentTime.value = 0
    duration.value = 0
    await nextTick()
    audio.value?.load()
    if (player.isPlaying) await playAudio()
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

async function playAudio() {
  try {
    await audio.value?.play()
  } catch {
    player.setError(t('player.playbackError'))
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
  currentTime.value = audio.value?.currentTime ?? 0
  duration.value = Number.isFinite(audio.value?.duration) ? audio.value?.duration ?? 0 : 0
}

function seekTo(value: number) {
  if (!audio.value || !duration.value || !Number.isFinite(value)) return

  const target = Math.min(duration.value, Math.max(0, value))
  audio.value.currentTime = target
  currentTime.value = target
}

function onEnded() {
  void player.next()
}

function onError() {
  player.setError(t('player.playbackError'))
}

function formatTime(value: number) {
  const seconds = Math.max(0, Math.floor(value))
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}
</script>

<template>
  <v-footer v-if="player.currentTrack" app border class="player-footer">
    <audio
      ref="audio"
      :src="player.currentTrack.streamUrl"
      preload="metadata"
      :volume="player.volume"
      @durationchange="updateProgress"
      @ended="onEnded"
      @error="onError"
      @loadedmetadata="updateProgress"
      @timeupdate="updateProgress"
    />

    <div class="player-content">
      <div class="player-meta">
        <RouterLink
          v-if="albumRoute"
          class="player-meta-link text-subtitle-2 font-weight-bold text-truncate"
          :to="albumRoute"
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
        <div v-if="player.error" class="text-caption text-error text-truncate">{{ player.error }}</div>
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

      <div class="player-progress">
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
        <div class="text-caption text-medium-emphasis">{{ formatTime(currentTime) }} / {{ formatTime(duration) }}</div>
      </div>
    </div>
  </v-footer>
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

@media (max-width: 760px) {
  .player-content {
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .player-progress {
    grid-column: 1 / -1;
  }
}
</style>
