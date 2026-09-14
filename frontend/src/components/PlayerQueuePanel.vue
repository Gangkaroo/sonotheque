<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { PlayableTrack } from '@/stores/player'
import { formatDuration, formatTotalDuration } from '@/utils/formatters'

interface QueueItem {
  index: number
  track: PlayableTrack
}

interface QueueSection {
  key: 'current' | 'upcoming' | 'previous'
  items: QueueItem[]
}

const props = defineProps<{
  currentIndex: number
  isPlaying: boolean
  playbackScopeIcon: string
  playbackScopeLabels: string[]
  playbackScopeMode: string
  queue: PlayableTrack[]
}>()

const emit = defineEmits<{
  addToPlaylist: [tracks: PlayableTrack[]]
  clear: []
  continueMood: []
  createPlaylist: []
  move: [sourceIndex: number, targetIndex: number]
  remove: [index: number]
  toggle: [index: number]
}>()

const { t } = useI18n()
const draggedQueueIndex = ref<number | null>(null)
const queueItems = computed<QueueItem[]>(() => props.queue.map((track, index) => ({ track, index })))
const queuePlayingTime = computed(() => {
  const total = props.queue.reduce((sum, track) => sum + (track.durationMs ?? 0), 0)

  return total > 0 ? formatTotalDuration(total) : null
})
const sections = computed<QueueSection[]>(() => {
  const current = queueItems.value.filter(({ index }) => index === props.currentIndex)
  const upcoming = queueItems.value.filter(({ index }) => index > props.currentIndex)
  const previous = queueItems.value.filter(({ index }) => index < props.currentIndex).reverse()

  return [
    { key: 'current', items: current },
    { key: 'upcoming', items: upcoming },
    { key: 'previous', items: previous },
  ].filter(({ items }) => items.length) as QueueSection[]
})

function queueItemKey(track: PlayableTrack, index: number) {
  return `${track.id}-${index}`
}

function sectionLabel(section: QueueSection['key']) {
  if (section === 'current') return t('player.nowPlaying')
  if (section === 'upcoming') return t('player.upNext')

  return t('player.playedEarlier')
}

function sectionIcon(section: QueueSection['key']) {
  if (section === 'current') return 'mdi-volume-high'
  if (section === 'previous') return 'mdi-history'

  return 'mdi-music-note'
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

  emit('move', sourceIndex, targetIndex)
}
</script>

<template>
  <div v-if="playbackScopeLabels.length" class="queue-playback-scope pa-4">
    <div class="d-flex align-start ga-3">
      <v-icon color="primary" :icon="playbackScopeIcon" />
      <div>
        <div class="text-subtitle-2">{{ t('player.playbackScopeTitle') }}</div>
        <div class="text-caption text-medium-emphasis">{{ playbackScopeMode }}</div>
      </div>
    </div>
    <div class="d-flex flex-wrap ga-2 mt-3">
      <v-chip
        v-for="label in playbackScopeLabels"
        :key="label"
        color="primary"
        size="small"
        variant="tonal"
      >
        {{ label }}
      </v-chip>
    </div>
  </div>
  <v-divider v-if="playbackScopeLabels.length" />

  <div class="queue-toolbar pa-4">
    <div class="text-caption text-medium-emphasis">
      {{ t('player.queueCount', { count: queue.length }) }}<span v-if="queuePlayingTime"> · {{ t('catalog.playingTime', { duration: queuePlayingTime }) }}</span>
    </div>
    <div class="d-flex align-center ga-1">
      <TooltipIconButton
        :text="t('playlists.createFromQueue')"
        :aria-label="t('playlists.createFromQueue')"
        :disabled="!queue.length"
        icon="mdi-playlist-plus"
        variant="text"
        @click="emit('createPlaylist')"
      />
      <TooltipIconButton
        :text="t('player.clearQueue')"
        :aria-label="t('player.clearQueue')"
        :disabled="!queue.length"
        icon="mdi-delete-sweep-outline"
        variant="text"
        @click="emit('clear')"
      />
    </div>
  </div>
  <v-divider />

  <v-list v-if="queue.length" lines="three" class="queue-list">
    <template v-for="section in sections" :key="section.key">
      <div v-if="section.key === 'current'" class="queue-section-header">
        <div class="text-overline text-medium-emphasis">{{ sectionLabel(section.key) }}</div>
        <v-btn
          density="comfortable"
          prepend-icon="mdi-waveform"
          size="small"
          variant="tonal"
          @click="emit('continueMood')"
        >
          {{ t('player.continueMoodAction') }}
        </v-btn>
      </div>
      <v-list-subheader v-else>{{ sectionLabel(section.key) }}</v-list-subheader>

      <v-list-item
        v-for="{ track, index } in section.items"
        :key="queueItemKey(track, index)"
        :active="section.key === 'current'"
        :color="section.key === 'current' ? 'primary' : undefined"
        class="queue-item"
        :class="{ 'is-dragging': draggedQueueIndex === index }"
        draggable="true"
        @click="emit('toggle', index)"
        @dragend="stopQueueDrag"
        @dragover.prevent
        @dragstart="startQueueDrag(index, $event)"
        @drop.stop="dropQueueItem(index)"
      >
        <template #prepend>
          <div class="queue-prepend">
            <v-tooltip :text="t('player.dragQueueItem')" location="top">
              <template #activator="{ props: tooltipProps }">
                <v-icon
                  v-bind="tooltipProps"
                  :aria-label="t('player.dragQueueItem')"
                  class="queue-drag-handle text-medium-emphasis"
                  icon="mdi-drag"
                  size="small"
                />
              </template>
            </v-tooltip>
            <v-avatar :color="section.key === 'current' ? 'primary' : undefined" variant="tonal">
              <v-icon :icon="sectionIcon(section.key)" />
            </v-avatar>
          </div>
        </template>

        <v-list-item-title class="font-weight-bold">
          <RouterLink
            class="queue-link"
            :to="{ name: 'track-detail', params: { id: track.id } }"
            @click.stop
          >
            {{ track.title }}
          </RouterLink>
        </v-list-item-title>
        <v-list-item-subtitle>
          <template v-if="track.artists.length">
            <template v-for="(artist, artistIndex) in track.artists" :key="artist.id">
              <span v-if="artistIndex > 0">, </span>
              <RouterLink
                class="queue-link"
                :to="{ name: 'artist-detail', params: { id: artist.id } }"
                @click.stop
              >
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
            <span class="text-caption text-medium-emphasis">{{ formatDuration(track.durationMs) }}</span>
            <TooltipIconButton
              :text="section.key === 'current' && isPlaying ? t('player.pause') : t('player.play')"
              :aria-label="section.key === 'current' && isPlaying ? t('player.pause') : t('player.play')"
              :color="section.key === 'current' ? 'primary' : undefined"
              :icon="section.key === 'current' && isPlaying ? 'mdi-pause' : 'mdi-play'"
              size="small"
              variant="text"
              @click.stop="emit('toggle', index)"
            />
            <TooltipIconButton
              :text="t('playlists.addTrackToPlaylist')"
              :aria-label="t('playlists.addTrackToPlaylist')"
              icon="mdi-playlist-plus"
              size="small"
              variant="text"
              @click.stop="emit('addToPlaylist', [track])"
            />
            <TooltipIconButton
              :text="t('player.removeFromQueue')"
              :aria-label="t('player.removeFromQueue')"
              icon="mdi-close"
              size="small"
              variant="text"
              @click.stop="emit('remove', index)"
            />
          </div>
        </template>
      </v-list-item>
    </template>
  </v-list>
  <div v-else class="pa-4 text-medium-emphasis">
    {{ t('player.queueEmpty') }}
  </div>
</template>

<style scoped>
.queue-toolbar {
  align-items: center;
  display: flex;
  justify-content: space-between;
}

.queue-playback-scope {
  background: rgba(var(--v-theme-primary), 0.06);
}

.queue-list {
  padding: 8px;
}

.queue-section-header {
  align-items: center;
  display: flex;
  gap: 8px;
  justify-content: space-between;
  min-height: 48px;
  padding: 4px 4px 8px 8px;
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
</style>
