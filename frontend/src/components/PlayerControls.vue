<script setup lang="ts">
import { computed, mergeProps } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'

import MusicVisualizer from '@/components/MusicVisualizer.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useFavoritesStore } from '@/stores/favorites'
import { useNowPlayingPanelStore } from '@/stores/nowPlayingPanel'
import { usePlayerStore } from '@/stores/player'

const props = defineProps<{
  albumArtworkThumbnailUrl: string | null
  albumArtworkUrl: string | null
  albumRoute: RouteLocationRaw | null
  albumTitle: string
  artistNames: string
  artistRoute: RouteLocationRaw | null
  audioElement: HTMLAudioElement | null
  currentTime: number
  duration: number
  playbackStateText: string | null
  showSeekLoading: boolean
  trackRoute: RouteLocationRaw | null
  trackTitle: string
}>()

const emit = defineEmits<{
  openArtwork: []
  seek: [seconds: number]
  togglePlayback: []
}>()

const collapsed = defineModel<boolean>('collapsed', { required: true })
const { t } = useI18n()
const favorites = useFavoritesStore()
const nowPlayingPanel = useNowPlayingPanelStore()
const player = usePlayerStore()
const volumeSlider = computed({
  get: () => Math.round(player.volume * 100),
  set: (value: number) => player.setVolume(value / 100),
})
const seekPosition = computed({
  get: () => props.currentTime,
  set: (value: number) => emit('seek', value),
})
const progressPercent = computed(() => props.duration > 0
  ? Math.min(100, Math.max(0, (props.currentTime / props.duration) * 100))
  : 0)
const remainingTime = computed(() => props.duration > 0
  ? `-${formatTime(Math.max(0, props.duration - props.currentTime))}`
  : '')

function formatTime(value: number) {
  const seconds = Math.max(0, Math.floor(value))

  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}
</script>

<template>
  <div v-show="collapsed" class="player-collapsed-content">
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
        @click="emit('togglePlayback')"
      />
      <TooltipIconButton
        :text="t('player.expand')"
        density="comfortable"
        :aria-label="t('player.expand')"
        icon="mdi-chevron-up"
        variant="text"
        @click="collapsed = false"
      />
    </div>
  </div>

  <div v-show="!collapsed" class="player-expanded-content">
    <MusicVisualizer
      class="player-visualizer"
      :active="!collapsed && player.playbackState === 'playing'"
      :audio-element="audioElement"
      :enabled="player.visualizerEnabled"
    />

    <div class="player-content">
      <div class="player-now-playing">
        <button
          v-if="albumArtworkUrl && albumArtworkThumbnailUrl"
          :aria-label="t('player.expandArtwork')"
          class="player-artwork-link"
          :title="t('player.expandArtwork')"
          type="button"
          @click="emit('openArtwork')"
        >
          <v-img
            :alt="albumTitle"
            class="player-artwork"
            cover
            :src="albumArtworkThumbnailUrl"
          />
        </button>
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
          @click="emit('togglePlayback')"
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
        <template #activator="{ props: tooltipProps }">
          <v-btn
            v-bind="tooltipProps"
            class="player-collapse-button"
            :aria-label="t('player.collapse')"
            icon="mdi-chevron-down"
            variant="text"
            @click="collapsed = true"
          />
        </template>
      </v-tooltip>
    </div>
  </div>
</template>

<style scoped>
.player-expanded-content {
  display: grid;
  gap: 8px;
  width: 100%;
}

.player-visualizer {
  width: 100%;
}

.player-content {
  align-items: center;
  display: grid;
  gap: 14px;
  grid-template-columns: minmax(0, 1.4fr) auto minmax(180px, 0.8fr) auto;
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
  align-items: center;
  display: flex;
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
  appearance: none;
  background: transparent;
  border: 0;
  border-radius: 8px;
  cursor: zoom-in;
  flex: 0 0 56px;
  height: 56px;
  overflow: hidden;
  padding: 0;
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
