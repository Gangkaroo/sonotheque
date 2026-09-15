<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import CatalogRating from '@/components/CatalogRating.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import TrackPlaylistMembershipMenu from '@/components/TrackPlaylistMembershipMenu.vue'
import type { Track } from '@/stores/catalog'
import { usePlayerStore } from '@/stores/player'
import type { PlaylistDetail, PlaylistItem } from '@/stores/playlists'
import { usePlaylistsStore } from '@/stores/playlists'
import { formatDateTime, formatDuration } from '@/utils/formatters'

const props = defineProps<{
  canReorder: boolean
  playlist: PlaylistDetail
  targetPlaylistItemId: number | null
}>()

const { locale, t } = useI18n()
const player = usePlayerStore()
const playlists = usePlaylistsStore()

const selectionMode = ref(false)
const selectedItemIds = ref<number[]>([])
const draggedItemId = ref<number | null>(null)
const dropTargetIndex = ref<number | null>(null)
const removeSelectedDialog = ref(false)
const removeItemDialog = ref(false)
const itemToRemove = ref<PlaylistItem | null>(null)
const addToPlaylistDialog = ref(false)
const playlistTracks = ref<Track[]>([])

const tracks = computed(() => props.playlist.items.map(item => item.track))
const playableTracks = computed(() => tracks.value.filter(track => track.available !== false))
const itemIds = computed(() => props.playlist.items.map(item => item.id))
const selectedCount = computed(() => selectedItemIds.value.length)
const allSelected = computed(() => itemIds.value.length > 0 && selectedCount.value === itemIds.value.length)
const distinctTrackIds = computed(() => [...new Set(tracks.value.map(track => track.id))])

const AUTO_SCROLL_EDGE_SIZE = 96
const AUTO_SCROLL_MAX_SPEED = 22
let autoScrollFrame: number | null = null
let autoScrollSpeed = 0

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

function toggleTrack(track: Track) {
  if (track.available === false) return

  if (player.currentTrack?.id === track.id) {
    if (player.isPlaying) {
      player.pause()
    } else {
      player.resume()
    }
    return
  }

  player.playTrack(track, playableTracks.value, 'track-list')
}

function openAddToPlaylist(track: Track) {
  playlistTracks.value = [track]
  addToPlaylistDialog.value = true
}

function enterSelectionMode() {
  selectionMode.value = true
  selectedItemIds.value = []
}

function exitSelectionMode() {
  selectionMode.value = false
  selectedItemIds.value = []
}

function toggleItemSelection(itemId: number) {
  selectedItemIds.value = selectedItemIds.value.includes(itemId)
    ? selectedItemIds.value.filter(id => id !== itemId)
    : [...selectedItemIds.value, itemId]
}

function toggleAllItems() {
  selectedItemIds.value = allSelected.value ? [] : [...itemIds.value]
}

function confirmRemoveSelectedItems() {
  if (selectedItemIds.value.length) removeSelectedDialog.value = true
}

async function removeSelectedItems() {
  if (!selectedItemIds.value.length) return

  await playlists.removeItems(props.playlist.id, selectedItemIds.value)
  removeSelectedDialog.value = false
  exitSelectionMode()
}

function confirmRemoveItem(item: PlaylistItem) {
  itemToRemove.value = item
  removeItemDialog.value = true
}

async function removeSingleItem() {
  if (!itemToRemove.value) return

  const itemId = itemToRemove.value.id
  await playlists.removeItem(props.playlist.id, itemId)
  selectedItemIds.value = selectedItemIds.value.filter(id => id !== itemId)
  removeItemDialog.value = false
  itemToRemove.value = null
}

function startDragging(itemId: number, event: DragEvent) {
  if (!props.canReorder || selectionMode.value) return

  draggedItemId.value = itemId
  event.dataTransfer?.setData('text/plain', String(itemId))
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
  document.addEventListener('dragover', updateDragAutoScroll)
}

function stopDragging() {
  stopDragAutoScroll()
  draggedItemId.value = null
  dropTargetIndex.value = null
}

function updateDragAutoScroll(event: DragEvent) {
  if (draggedItemId.value === null) return

  const appBarBottom = document.querySelector<HTMLElement>('.v-app-bar')
    ?.getBoundingClientRect().bottom ?? 0
  const playerTop = document.querySelector<HTMLElement>('.player-footer')
    ?.getBoundingClientRect().top ?? window.innerHeight
  const distanceFromTop = Math.max(0, event.clientY - appBarBottom)
  const distanceFromBottom = Math.max(0, playerTop - event.clientY)
  if (distanceFromTop < AUTO_SCROLL_EDGE_SIZE) {
    autoScrollSpeed = -scrollSpeedForEdgeDistance(distanceFromTop)
  } else if (distanceFromBottom < AUTO_SCROLL_EDGE_SIZE) {
    autoScrollSpeed = scrollSpeedForEdgeDistance(distanceFromBottom)
  } else {
    autoScrollSpeed = 0
  }

  if (autoScrollSpeed !== 0 && autoScrollFrame === null) {
    autoScrollFrame = window.requestAnimationFrame(runDragAutoScroll)
  }
}

function scrollSpeedForEdgeDistance(distance: number) {
  const proximity = 1 - Math.min(AUTO_SCROLL_EDGE_SIZE, distance) / AUTO_SCROLL_EDGE_SIZE

  return Math.max(1, Math.round(AUTO_SCROLL_MAX_SPEED * proximity))
}

function runDragAutoScroll() {
  autoScrollFrame = null
  if (draggedItemId.value === null || autoScrollSpeed === 0) return

  window.scrollBy({ top: autoScrollSpeed, behavior: 'auto' })
  autoScrollFrame = window.requestAnimationFrame(runDragAutoScroll)
}

function stopDragAutoScroll() {
  document.removeEventListener('dragover', updateDragAutoScroll)
  autoScrollSpeed = 0
  if (autoScrollFrame !== null) {
    window.cancelAnimationFrame(autoScrollFrame)
    autoScrollFrame = null
  }
}

function markDropTarget(index: number, event: DragEvent) {
  if (draggedItemId.value === null || selectionMode.value) return

  const target = event.currentTarget instanceof HTMLElement ? event.currentTarget : null
  const bounds = target?.getBoundingClientRect()
  const insertAfter = bounds ? event.clientY > bounds.top + (bounds.height / 2) : false
  const nextDropIndex = insertAfter ? index + 1 : index
  dropTargetIndex.value = isNoopDropIndex(nextDropIndex) ? null : nextDropIndex
}

function itemDropClass(index: number) {
  return {
    'has-drop-before': draggedItemId.value !== null && dropTargetIndex.value === index,
    'has-drop-after': draggedItemId.value !== null
      && props.playlist.items.length === index + 1
      && dropTargetIndex.value === index + 1,
  }
}

function isNoopDropIndex(targetIndex: number) {
  const sourceIndex = draggedItemId.value === null ? -1 : itemIds.value.indexOf(draggedItemId.value)

  return sourceIndex < 0 || targetIndex === sourceIndex || targetIndex === sourceIndex + 1
}

async function dropItem() {
  if (!props.canReorder || selectionMode.value) return

  stopDragAutoScroll()
  const sourceItemId = draggedItemId.value
  const targetIndex = dropTargetIndex.value
  draggedItemId.value = null
  dropTargetIndex.value = null

  if (sourceItemId === null || targetIndex === null) return

  const currentIds = [...itemIds.value]
  const sourceIndex = currentIds.indexOf(sourceItemId)
  let insertionIndex = Math.min(currentIds.length, Math.max(0, targetIndex))

  if (sourceIndex < 0) return

  const item = currentIds[sourceIndex]
  if (item === undefined) return

  if (sourceIndex < insertionIndex) insertionIndex -= 1
  if (sourceIndex === insertionIndex) return

  currentIds.splice(sourceIndex, 1)
  currentIds.splice(insertionIndex, 0, item)
  await playlists.reorderItems(props.playlist.id, currentIds)
}

watch(
  () => props.playlist.id,
  () => {
    exitSelectionMode()
    removeSelectedDialog.value = false
    removeItemDialog.value = false
    itemToRemove.value = null
  },
)

watch(itemIds, ids => {
  selectedItemIds.value = selectedItemIds.value.filter(id => ids.includes(id))
})

watch(distinctTrackIds, trackIds => {
  if (trackIds.length) void playlists.loadMemberships(trackIds)
}, { immediate: true })

watch(
  [() => props.playlist.id, () => props.targetPlaylistItemId, itemIds],
  async ([playlistId, itemId, ids]) => {
    if (itemId === null || !ids.includes(itemId)) return

    await nextTick()
    document.getElementById(`playlist-item-${playlistId}-${itemId}`)?.scrollIntoView({
      behavior: 'smooth',
      block: 'center',
    })
  },
  { immediate: true },
)

onBeforeUnmount(stopDragAutoScroll)
</script>

<template>
  <v-alert
    v-if="!canReorder && playlist.items.length"
    class="mb-3"
    density="compact"
    icon="mdi-information-outline"
    type="info"
    variant="tonal"
  >
    {{ t('libraryScope.reorderUnavailable') }}
  </v-alert>

  <v-card v-if="playlist.items.length" border rounded="xl">
    <v-card-title class="playlist-toolbar">
      <div class="d-flex align-center ga-2">
        <span>{{ t('playlists.trackList') }}</span>
        <template v-if="selectionMode">
          <v-chip color="primary" prepend-icon="mdi-checkbox-marked-circle-outline" size="small" variant="tonal">
            {{ t('playlists.selectedTracks', { count: selectedCount }) }}
          </v-chip>
          <v-btn size="small" variant="text" @click="toggleAllItems">
            {{ allSelected ? t('albums.clearSelection') : t('playlists.selectAllTracks') }}
          </v-btn>
        </template>
      </div>
      <template v-if="selectionMode">
        <v-btn
          color="error"
          prepend-icon="mdi-delete-outline"
          :disabled="!selectedCount || playlists.saving"
          :loading="playlists.saving && selectedCount > 0"
          size="small"
          variant="tonal"
          @click="confirmRemoveSelectedItems"
        >
          {{ t('playlists.removeSelected') }}
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
      <v-btn
        v-else
        prepend-icon="mdi-checkbox-multiple-marked-outline"
        size="small"
        variant="tonal"
        @click="enterSelectionMode"
      >
        {{ t('playlists.selectTracks') }}
      </v-btn>
    </v-card-title>

    <v-list lines="three">
      <v-list-item
        v-for="(item, index) in playlist.items"
        :id="`playlist-item-${playlist.id}-${item.id}`"
        :key="item.id"
        class="playlist-item"
        :class="{
          'current-track': player.currentTrack?.id === item.track.id,
          'membership-target': targetPlaylistItemId === item.id,
          'is-dragging': draggedItemId === item.id,
          'is-unavailable': item.track.available === false,
          'selection-active': selectionMode,
          ...itemDropClass(index),
        }"
        :draggable="canReorder && !selectionMode"
        @click="selectionMode && toggleItemSelection(item.id)"
        @dragend="stopDragging"
        @dragenter="markDropTarget(index, $event)"
        @dragover.prevent="markDropTarget(index, $event)"
        @dragstart="startDragging(item.id, $event)"
        @drop="dropItem"
      >
        <template #prepend>
          <div class="playlist-prepend" :class="{ 'with-drag-handle': !selectionMode }">
            <v-checkbox-btn
              v-if="selectionMode"
              class="playlist-selection-checkbox"
              :aria-label="t('playlists.selectTrack', { title: item.track.title })"
              color="primary"
              density="compact"
              :model-value="selectedItemIds.includes(item.id)"
              @click.stop
              @update:model-value="toggleItemSelection(item.id)"
            />
            <span class="playlist-position text-caption text-medium-emphasis">{{ index + 1 }}</span>
            <v-tooltip v-if="!selectionMode" :text="t('playlists.dragTrack')" location="top">
              <template #activator="{ props: tooltipProps }">
                <v-icon
                  v-bind="tooltipProps"
                  :aria-label="t('playlists.dragTrack')"
                  class="text-medium-emphasis"
                  :class="{ 'playlist-drag-handle': canReorder }"
                  :disabled="!canReorder"
                  icon="mdi-drag"
                  size="default"
                />
              </template>
            </v-tooltip>
          </div>
        </template>

        <v-list-item-title class="font-weight-bold" :class="{ 'text-primary': player.currentTrack?.id === item.track.id }">
          <span v-if="selectionMode || item.track.available === false">{{ item.track.title }}</span>
          <RouterLink
            v-else
            class="playlist-track-link"
            :to="{
              name: 'track-detail',
              params: { id: item.track.id },
              query: { backPlaylist: playlist.id, playlistItem: item.id },
            }"
          >
            {{ item.track.title }}
          </RouterLink>
        </v-list-item-title>
        <v-list-item-subtitle>
          <template v-if="item.track.artists.length">
            <template v-for="(artist, artistIndex) in item.track.artists" :key="artist.id">
              <span v-if="artistIndex > 0">, </span>
              <span v-if="selectionMode || item.track.available === false">{{ artist.name }}</span>
              <RouterLink v-else class="playlist-track-link" :to="{ name: 'artist-detail', params: { id: artist.id } }">
                {{ artist.name }}
              </RouterLink>
            </template>
          </template>
          <span v-else>{{ t('catalog.unknownArtist') }}</span>
        </v-list-item-subtitle>
        <v-list-item-subtitle>
          <template v-if="item.track.album">
            <span v-if="selectionMode || item.track.available === false">{{ item.track.album.title }}</span>
            <RouterLink
              v-else
              class="playlist-track-link"
              :to="{ name: 'album-detail', params: { id: item.track.album.id } }"
            >
              {{ item.track.album.title }}
            </RouterLink>
            <span v-if="item.track.year !== undefined && item.track.year !== null"> · {{ item.track.year }}</span>
          </template>
          <span v-else>{{ t('catalog.unknownAlbum') }}</span>
        </v-list-item-subtitle>
        <template v-if="!selectionMode" #append>
          <div class="playlist-actions">
            <v-chip
              v-if="item.track.available === false"
              color="warning"
              prepend-icon="mdi-file-alert-outline"
              size="x-small"
              variant="tonal"
            >
              {{ t('playlists.unavailable') }}
            </v-chip>
            <span class="text-caption text-medium-emphasis">{{ formatDuration(item.track.durationMs) }}</span>
            <v-tooltip location="top">
              <template #activator="{ props: tooltipProps }">
                <span v-bind="tooltipProps" class="play-count text-caption text-medium-emphasis">
                  <v-icon class="play-count-icon" icon="mdi-headphones" size="x-small" />
                  {{ item.track.playStatistics.playCount }}
                </span>
              </template>
              <div class="play-count-tooltip">
                <div v-for="(line, lineIndex) in playCountTooltip(item.track)" :key="lineIndex">{{ line }}</div>
              </div>
            </v-tooltip>
            <CatalogRating
              v-if="item.track.available !== false"
              :entity-id="item.track.id"
              entity-type="track"
              compact
              :model-value="item.track.rating"
              responsive
              size="18"
              @update:model-value="item.track.rating = $event"
            />
            <TooltipIconButton
              :text="player.currentTrack?.id === item.track.id && player.isPlaying ? t('player.pause') : t('player.play')"
              :aria-label="player.currentTrack?.id === item.track.id && player.isPlaying ? t('player.pause') : t('player.play')"
              :color="player.currentTrack?.id === item.track.id ? 'primary' : undefined"
              :disabled="item.track.available === false"
              :icon="player.currentTrack?.id === item.track.id && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
              variant="text"
              @click="toggleTrack(item.track)"
            />
            <TooltipIconButton
              :text="t('tracks.queueTrack')"
              :aria-label="t('tracks.queueTrack')"
              :disabled="item.track.available === false"
              icon="mdi-playlist-plus"
              variant="text"
              @click="player.queueTrack(item.track, 'track-list')"
            />
            <TrackPlaylistMembershipMenu
              icon-only
              :track-id="item.track.id"
              @add-to-playlist="openAddToPlaylist(item.track)"
            />
            <TooltipIconButton
              :text="t('playlists.removeTrack')"
              :aria-label="t('playlists.removeTrack')"
              :disabled="playlists.saving"
              icon="mdi-delete-outline"
              variant="text"
              @click="confirmRemoveItem(item)"
            />
          </div>
        </template>
      </v-list-item>
    </v-list>
  </v-card>

  <EmptyCatalogState
    v-else
    :title="t('playlists.emptyPlaylistTitle')"
    :description="t('playlists.emptyPlaylistDescription')"
    icon="mdi-playlist-music-outline"
  />

  <v-dialog v-model="removeSelectedDialog" max-width="520">
    <v-card rounded="xl">
      <v-card-title>{{ t('playlists.removeSelectedTitle') }}</v-card-title>
      <v-card-text>
        {{ t('playlists.removeSelectedWarning', { count: selectedCount }) }}
      </v-card-text>
      <v-card-actions class="flex-wrap">
        <v-spacer />
        <v-btn variant="text" @click="removeSelectedDialog = false">
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn color="error" variant="flat" :loading="playlists.saving" @click="removeSelectedItems">
          {{ t('settings.remove') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="removeItemDialog" max-width="520">
    <v-card rounded="xl">
      <v-card-title>{{ t('playlists.removeTrackTitle') }}</v-card-title>
      <v-card-text>
        {{ t('playlists.removeTrackWarning', { title: itemToRemove?.track.title ?? '' }) }}
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="removeItemDialog = false">
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn color="error" variant="flat" :loading="playlists.saving" @click="removeSingleItem">
          {{ t('settings.remove') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <AddToPlaylistDialog v-model="addToPlaylistDialog" :tracks="playlistTracks" />
</template>

<style scoped>
.current-track {
  background: rgba(var(--v-theme-primary), 0.08);
}

.playlist-toolbar {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  justify-content: space-between;
}

.playlist-item {
  cursor: grab;
  position: relative;
}

.playlist-item:active {
  cursor: grabbing;
}

.playlist-item.is-dragging {
  opacity: 0.55;
}

.playlist-item.is-unavailable {
  opacity: 0.7;
}

.playlist-item.selection-active {
  cursor: pointer;
}

.playlist-item.membership-target {
  background: rgba(var(--v-theme-primary), 0.08);
  box-shadow: inset 3px 0 rgb(var(--v-theme-primary));
}

.playlist-item.has-drop-before::before,
.playlist-item.has-drop-after::after {
  background: rgb(var(--v-theme-primary));
  border-radius: 999px;
  content: "";
  height: 3px;
  left: 4.5rem;
  pointer-events: none;
  position: absolute;
  right: 1rem;
  z-index: 2;
}

.playlist-item.has-drop-before::before {
  top: 0;
}

.playlist-item.has-drop-after::after {
  bottom: 0;
}

.playlist-prepend {
  align-items: center;
  display: inline-flex;
  gap: 0.25rem;
  min-height: 1.75rem;
}

.playlist-prepend.with-drag-handle {
  margin-inline-end: 1rem;
}

.playlist-selection-checkbox {
  flex: 0 0 auto;
  margin-inline-end: 0.125rem;
}

.playlist-selection-checkbox :deep(.v-selection-control) {
  align-items: center;
  min-height: 1.75rem;
}

.playlist-selection-checkbox :deep(.v-selection-control__wrapper) {
  height: 1.75rem;
  width: 1.75rem;
}

.playlist-position {
  display: inline-flex;
  justify-content: flex-end;
  min-width: 1.75rem;
  padding-inline-end: 0.5rem;
}

.playlist-drag-handle {
  cursor: grab;
  height: 2rem;
  width: 2rem;
}

.playlist-actions {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  justify-content: flex-end;
}

.play-count {
  align-items: center;
  display: inline-flex;
  font-variant-numeric: tabular-nums;
  gap: 0.2rem;
  line-height: 1;
}

.play-count-icon {
  align-self: center;
  transform: translateY(1px);
}

.play-count-tooltip {
  line-height: 1.5;
}

.playlist-track-link {
  color: inherit;
  text-decoration: none;
}

.playlist-track-link:hover {
  text-decoration: underline;
}

@media (max-width: 480px) {
  .play-count {
    display: none;
  }
}
</style>
