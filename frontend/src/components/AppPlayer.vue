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
const progress = computed(() => duration.value ? (currentTime.value / duration.value) * 100 : 0)
const artistLine = computed(() => {
  const track = player.currentTrack
  if (!track) return ''

  const artists = track.artists.map((artist) => artist.name).join(', ')
  const album = track.album?.title

  return [artists, album].filter(Boolean).join(' · ')
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

function onEnded() {
  player.next()
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
        <div class="text-subtitle-2 font-weight-bold text-truncate">{{ player.currentTrack.title }}</div>
        <div class="text-caption text-medium-emphasis text-truncate">{{ artistLine }}</div>
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
          icon="mdi-skip-next"
          variant="text"
          @click="player.next"
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
        <v-progress-linear :model-value="progress" color="primary" rounded />
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

@media (max-width: 760px) {
  .player-content {
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .player-progress {
    grid-column: 1 / -1;
  }
}
</style>
