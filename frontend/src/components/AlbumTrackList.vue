<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import CatalogRating from '@/components/CatalogRating.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import TrackBatchMetadataDialog from '@/components/TrackBatchMetadataDialog.vue'
import TrackPlaylistMembershipMenu from '@/components/TrackPlaylistMembershipMenu.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { Track } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'
import { usePlaylistsStore } from '@/stores/playlists'
import { formatDateTime, formatDuration } from '@/utils/formatters'

const props = defineProps<{
  albumId: number
  tracks: Track[]
}>()
const emit = defineEmits<{
  addToPlaylist: [tracks: Track[]]
  metadataCompleted: []
}>()

const { locale, t } = useI18n()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const playlists = usePlaylistsStore()

const selectionMode = ref(false)
const selectedTrackIds = ref<number[]>([])
const batchMetadataDialog = ref(false)
const message = ref('')
const messageVisible = ref(false)
const messageColor = ref<'error' | 'success'>('success')

const selectedTracks = computed(() => {
  const selected = new Set(selectedTrackIds.value)

  return props.tracks.filter(track => selected.has(track.id))
})
const allTracksSelected = computed(() => (
  props.tracks.length > 0 && selectedTrackIds.value.length === props.tracks.length
))

function playCountTooltip(track: Track) {
  return [
    t('tracks.playCountTooltip', { count: track.playStatistics.playCount }),
    t('tracks.firstPlayedAtTooltip', {
      value: formatDateTime(track.playStatistics.firstPlayedAt, locale.value),
    }),
    t('tracks.lastPlayedAtTooltip', {
      value: formatDateTime(track.playStatistics.lastPlayedAt, locale.value),
    }),
  ]
}

function trackNumber(track: Track) {
  const parts = [track.discNumber, track.trackNumber]
    .filter(part => part !== undefined && part !== null)

  return parts.length ? parts.join('.') : '-'
}

function isCurrentTrack(track: Track) {
  return player.currentTrack?.id === track.id
}

function toggleTrack(track: Track) {
  if (player.currentTrack?.id === track.id) {
    if (player.isPlaying) {
      player.pause()
    } else {
      player.resume()
    }
    return
  }

  player.playTrack(track, props.tracks, 'album')
}

function enterSelectionMode() {
  selectionMode.value = true
  selectedTrackIds.value = []
}

function exitSelectionMode() {
  selectionMode.value = false
  selectedTrackIds.value = []
}

function toggleTrackSelection(trackId: number) {
  selectedTrackIds.value = selectedTrackIds.value.includes(trackId)
    ? selectedTrackIds.value.filter(id => id !== trackId)
    : [...selectedTrackIds.value, trackId]
}

function toggleAllTracks() {
  selectedTrackIds.value = allTracksSelected.value
    ? []
    : props.tracks.map(track => track.id)
}

function playSelectedTracks() {
  const [firstTrack] = selectedTracks.value
  if (!firstTrack) return

  player.playTrack(firstTrack, selectedTracks.value, 'album')
}

function queueSelectedTracks() {
  player.queueTracks(selectedTracks.value, 'album')
}

function addSelectedTracksToPlaylist() {
  emit('addToPlaylist', [...selectedTracks.value])
}

function addTrackToPlaylist(track: Track) {
  emit('addToPlaylist', [track])
}

async function addSelectedTracksToFavorites() {
  if (!selectedTrackIds.value.length) return

  try {
    await favorites.setTracksFavorite(selectedTrackIds.value)
    showMessage(
      t('albums.selectedTracksFavorited', { count: selectedTrackIds.value.length }),
      'success',
    )
  } catch (cause) {
    showMessage(
      cause instanceof Error ? cause.message : t('albums.selectionActionFailed'),
      'error',
    )
  }
}

function openBatchMetadataEditor() {
  if (selectedTrackIds.value.length) batchMetadataDialog.value = true
}

function batchMetadataCompleted(count: number) {
  showMessage(t('albums.batchMetadataCompleted', { count }), 'success')
  exitSelectionMode()
  emit('metadataCompleted')
}

function showMessage(value: string, color: 'error' | 'success') {
  message.value = value
  messageColor.value = color
  messageVisible.value = true
}

watch(
  () => props.albumId,
  () => exitSelectionMode(),
)
watch(
  () => props.tracks.map(track => track.id),
  trackIds => {
    if (trackIds.length) void playlists.loadMemberships(trackIds)
  },
  { immediate: true },
)
</script>

<template>
  <div v-if="tracks.length" class="selection-toolbar mb-2">
    <v-btn
      v-if="!selectionMode"
      prepend-icon="mdi-checkbox-multiple-marked-outline"
      size="small"
      variant="tonal"
      @click="enterSelectionMode"
    >
      {{ t('albums.selectTracks') }}
    </v-btn>
    <template v-else>
      <v-chip color="primary" prepend-icon="mdi-checkbox-marked-circle-outline" size="small" variant="tonal">
        {{ t('albums.selectedTracks', { count: selectedTrackIds.length }) }}
      </v-chip>
      <v-btn size="small" variant="text" @click="toggleAllTracks">
        {{ allTracksSelected ? t('albums.clearSelection') : t('albums.selectAllTracks') }}
      </v-btn>
      <v-spacer />
      <TooltipIconButton
        :text="t('albums.playSelected')"
        :aria-label="t('albums.playSelected')"
        :disabled="!selectedTrackIds.length"
        icon="mdi-play"
        size="small"
        variant="tonal"
        @click="playSelectedTracks"
      />
      <TooltipIconButton
        :text="t('albums.queueSelected')"
        :aria-label="t('albums.queueSelected')"
        :disabled="!selectedTrackIds.length"
        icon="mdi-playlist-plus"
        size="small"
        variant="tonal"
        @click="queueSelectedTracks"
      />
      <TooltipIconButton
        :text="t('playlists.addTracksTitle', { count: selectedTrackIds.length })"
        :aria-label="t('playlists.addTracksTitle', { count: selectedTrackIds.length })"
        :disabled="!selectedTrackIds.length"
        icon="mdi-playlist-music"
        size="small"
        variant="tonal"
        @click="addSelectedTracksToPlaylist"
      />
      <TooltipIconButton
        :text="t('albums.addSelectedToFavorites')"
        :aria-label="t('albums.addSelectedToFavorites')"
        :disabled="!selectedTrackIds.length"
        icon="mdi-heart-plus-outline"
        size="small"
        variant="tonal"
        @click="void addSelectedTracksToFavorites()"
      />
      <v-btn
        color="primary"
        :disabled="!selectedTrackIds.length"
        prepend-icon="mdi-tag-edit-outline"
        size="small"
        variant="tonal"
        @click="void openBatchMetadataEditor()"
      >
        {{ t('albums.editSelectedMetadata') }}
      </v-btn>
      <TooltipIconButton
        :text="t('settings.cancel')"
        :aria-label="t('settings.cancel')"
        icon="mdi-close"
        size="small"
        variant="text"
        @click="exitSelectionMode"
      />
    </template>
  </div>

  <v-list v-if="tracks.length" border rounded="xl" lines="two">
    <v-list-item
      v-for="track in tracks"
      :key="track.id"
      class="track-list-item"
      :class="{ 'current-track': isCurrentTrack(track), 'selection-active': selectionMode }"
      @click="selectionMode && toggleTrackSelection(track.id)"
    >
      <template #prepend>
        <v-checkbox-btn
          v-if="selectionMode"
          class="track-selection-checkbox"
          color="primary"
          :model-value="selectedTrackIds.includes(track.id)"
          @click.stop
          @update:model-value="toggleTrackSelection(track.id)"
        />
        <span
          class="track-number"
          :class="isCurrentTrack(track) ? 'text-primary font-weight-bold' : 'text-medium-emphasis'"
        >
          {{ trackNumber(track) }}
        </span>
      </template>
      <v-list-item-title class="font-weight-bold" :class="{ 'text-primary': isCurrentTrack(track) }">
        <span v-if="selectionMode">{{ track.title }}</span>
        <RouterLink
          v-else
          class="track-detail-link"
          :to="{ name: 'track-detail', params: { id: track.id }, query: { backAlbum: albumId } }"
        >
          {{ track.title }}
        </RouterLink>
      </v-list-item-title>
      <v-list-item-subtitle>
        <template v-if="track.artists.length">
          <template v-for="(artist, artistIndex) in track.artists" :key="artist.id">
            <span v-if="artistIndex > 0">, </span>
            <RouterLink
              class="artist-link"
              :to="{ name: 'artist-detail', params: { id: artist.id } }"
              @click.stop
            >
              {{ artist.name }}
            </RouterLink>
          </template>
        </template>
        <span v-else>{{ t('catalog.unknownArtist') }}</span>
      </v-list-item-subtitle>
      <template v-if="!selectionMode" #append>
        <div class="track-actions">
        <span class="text-caption text-medium-emphasis">{{ formatDuration(track.durationMs) }}</span>
          <v-tooltip location="top">
            <template #activator="{ props }">
              <span v-bind="props" class="play-count text-caption text-medium-emphasis">
                <v-icon class="play-count-icon" icon="mdi-headphones" size="x-small" />
                {{ track.playStatistics.playCount }}
              </span>
            </template>
            <div class="play-count-tooltip">
              <div v-for="(line, index) in playCountTooltip(track)" :key="index">{{ line }}</div>
            </div>
          </v-tooltip>
          <CatalogRating
            :entity-id="track.id"
            entity-type="track"
            compact
            :model-value="track.rating"
            responsive
            size="18"
            @update:model-value="track.rating = $event"
          />
          <TooltipIconButton
            :text="isCurrentTrack(track) && player.isPlaying ? t('player.pause') : t('player.play')"
            :aria-label="isCurrentTrack(track) && player.isPlaying ? t('player.pause') : t('player.play')"
            :color="isCurrentTrack(track) ? 'primary' : undefined"
            density="comfortable"
            :icon="isCurrentTrack(track) && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
            variant="text"
            @click="toggleTrack(track)"
          />
          <TrackPlaylistMembershipMenu
            icon-only
            :track-id="track.id"
            @add-to-playlist="addTrackToPlaylist(track)"
          />
          <TooltipIconButton
            :text="favorites.isTrackFavorite(track.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
            :aria-label="favorites.isTrackFavorite(track.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
            :color="favorites.isTrackFavorite(track.id) ? 'primary' : undefined"
            density="comfortable"
            :icon="favorites.isTrackFavorite(track.id) ? 'mdi-heart' : 'mdi-heart-outline'"
            variant="text"
            @click="void favorites.toggleTrack(track.id)"
          />
        </div>
      </template>
    </v-list-item>
  </v-list>
  <EmptyCatalogState v-else :title="t('albums.noTracksTitle')" :description="t('catalog.scanPrompt')" icon="mdi-music-note-outline" />

  <TrackBatchMetadataDialog
    v-model="batchMetadataDialog"
    :album-id="albumId"
    :tracks="selectedTracks"
    @completed="batchMetadataCompleted"
  />

  <v-snackbar v-model="messageVisible" :color="messageColor" timeout="3000">
    {{ message }}
  </v-snackbar>
</template>

<style scoped>
.current-track {
  background: rgba(var(--v-theme-primary), 0.08);
}

.track-list-item {
  transition: background-color 120ms ease;
}

.track-list-item:hover {
  background: rgba(var(--v-theme-on-surface), 0.04);
}

.track-list-item.current-track:hover {
  background: rgba(var(--v-theme-primary), 0.12);
}

.selection-toolbar {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
  min-height: 36px;
}

.selection-active {
  cursor: pointer;
}

.track-selection-checkbox {
  margin-inline-end: 4px;
}

.track-number {
  display: inline-flex;
  font-variant-numeric: tabular-nums;
  justify-content: end;
  min-width: 2.5rem;
  padding-inline-end: 0.75rem;
}

.track-detail-link,
.artist-link {
  color: inherit;
  text-decoration: none;
}

.track-detail-link:hover,
.artist-link:hover {
  text-decoration: underline;
}

.play-count {
  align-items: center;
  display: inline-flex;
  gap: 0.2rem;
  font-variant-numeric: tabular-nums;
  line-height: 1;
}

.play-count-icon {
  align-self: center;
  transform: translateY(1px);
}

.play-count-tooltip {
  line-height: 1.5;
}

.track-actions {
  align-items: center;
  display: flex;
  gap: 4px;
}

.track-actions :deep(.v-btn) {
  min-width: 34px;
  padding-inline: 0;
}

@media (max-width: 480px) {
  .play-count {
    display: none;
  }
}
</style>
