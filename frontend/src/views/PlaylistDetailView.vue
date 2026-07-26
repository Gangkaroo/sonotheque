<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PlaylistFileExportDialog from '@/components/PlaylistFileExportDialog.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { Track } from '@/stores/catalog'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { usePlayerStore } from '@/stores/player'
import type { PlaylistItem } from '@/stores/playlists'
import { usePlaylistsStore } from '@/stores/playlists'
import type { PlaylistFileExportResult } from '@/types/playlistExport'
import { formatDuration as duration, formatTotalDuration } from '@/utils/formatters'

const { t } = useI18n()
const route = useRoute()
const playlists = usePlaylistsStore()
const player = usePlayerStore()
const libraryRootScope = useLibraryRootScopeStore()

const playlistId = computed(() => Number(route.params.id))
const targetPlaylistItemId = computed(() => {
  const value = typeof route.query.playlistItem === 'string' ? Number(route.query.playlistItem) : NaN

  return Number.isInteger(value) && value > 0 ? value : null
})
const playlist = computed(() => playlists.current)
const tracks = computed(() => playlist.value?.items.map((item) => item.track) ?? [])
const playlistPlayingTime = computed(() => {
  const total = tracks.value.reduce((sum, track) => sum + (track.durationMs ?? 0), 0)

  return total > 0 ? formatTotalDuration(total) : null
})
const selectionMode = ref(false)
const selectedItemIds = ref<number[]>([])
const draggedItemId = ref<number | null>(null)
const dropTargetIndex = ref<number | null>(null)
const removeSelectedDialog = ref(false)
const removeItemDialog = ref(false)
const itemToRemove = ref<PlaylistItem | null>(null)
const exportDialog = ref(false)
const exportMessage = ref('')
const exportMessageVisible = ref(false)
const itemIds = computed(() => playlist.value?.items.map((item) => item.id) ?? [])
const selectedCount = computed(() => selectedItemIds.value.length)
const allSelected = computed(() => itemIds.value.length > 0 && selectedCount.value === itemIds.value.length)
const canReorder = computed(() => libraryRootScope.selectedRootId === null)

function playPlaylist() {
  const [firstTrack] = tracks.value
  if (!firstTrack) return

  player.playTrack(firstTrack, tracks.value, 'track-list')
}

function queuePlaylist() {
  player.queueTracks(tracks.value, 'track-list')
}

function handlePlaylistExported(result: PlaylistFileExportResult) {
  exportMessage.value = t('playlists.fileExportSaved', {
    filename: result.filename,
    location: result.location.name,
  })
  exportMessageVisible.value = true
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

  player.playTrack(track, tracks.value, 'track-list')
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
    ? selectedItemIds.value.filter((id) => id !== itemId)
    : [...selectedItemIds.value, itemId]
}

function toggleAllItems() {
  selectedItemIds.value = allSelected.value ? [] : [...itemIds.value]
}

function confirmRemoveSelectedItems() {
  if (!selectedItemIds.value.length) return

  removeSelectedDialog.value = true
}

async function removeSelectedItems() {
  if (!playlist.value || !selectedItemIds.value.length) return

  await playlists.removeItems(playlist.value.id, selectedItemIds.value)
  removeSelectedDialog.value = false
  exitSelectionMode()
}

function confirmRemoveItem(item: PlaylistItem) {
  itemToRemove.value = item
  removeItemDialog.value = true
}

async function removeSingleItem() {
  if (!playlist.value || !itemToRemove.value) return

  const itemId = itemToRemove.value.id
  await playlists.removeItem(playlist.value.id, itemId)
  selectedItemIds.value = selectedItemIds.value.filter((id) => id !== itemId)
  removeItemDialog.value = false
  itemToRemove.value = null
}

function startDragging(itemId: number, event: DragEvent) {
  if (!canReorder.value || selectionMode.value) return

  draggedItemId.value = itemId
  event.dataTransfer?.setData('text/plain', String(itemId))
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function stopDragging() {
  draggedItemId.value = null
  dropTargetIndex.value = null
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
      && playlist.value?.items.length === index + 1
      && dropTargetIndex.value === index + 1,
  }
}

function isNoopDropIndex(targetIndex: number) {
  const sourceIndex = draggedItemId.value === null ? -1 : itemIds.value.indexOf(draggedItemId.value)

  return sourceIndex < 0 || targetIndex === sourceIndex || targetIndex === sourceIndex + 1
}

async function dropItem() {
  if (!canReorder.value || selectionMode.value) return

  const sourceItemId = draggedItemId.value
  const targetIndex = dropTargetIndex.value
  draggedItemId.value = null
  dropTargetIndex.value = null

  if (!playlist.value || sourceItemId === null || targetIndex === null) return

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
  await playlists.reorderItems(playlist.value.id, currentIds)
}

watch(playlistId, (id) => {
  if (Number.isInteger(id) && id > 0) void playlists.loadPlaylist(id)
}, { immediate: true })

watch(() => playlist.value?.id, () => {
  exitSelectionMode()
  removeSelectedDialog.value = false
  removeItemDialog.value = false
  itemToRemove.value = null
})

watch(itemIds, (ids) => {
  selectedItemIds.value = selectedItemIds.value.filter((id) => ids.includes(id))
})

watch([() => playlist.value?.id, targetPlaylistItemId], async ([, itemId]) => {
  if (itemId === null) return

  await nextTick()
  document.getElementById(`playlist-item-${itemId}`)?.scrollIntoView({
    behavior: 'smooth',
    block: 'center',
  })
}, { immediate: true })
</script>

<template>
  <v-btn class="mb-4" variant="text" prepend-icon="mdi-arrow-left" :to="{ name: 'playlists' }">
    {{ t('playlists.back') }}
  </v-btn>

  <v-alert v-if="playlists.error" type="error" variant="tonal">
    {{ playlists.error }}
  </v-alert>

  <v-skeleton-loader v-else-if="playlists.loading" type="card, list-item-three-line@8" />

  <template v-else-if="playlist">
    <v-card class="mb-6" border rounded="xl">
      <v-card-item>
        <template #prepend>
          <v-avatar color="primary" variant="tonal" size="44">
            <v-icon icon="mdi-playlist-music-outline" />
          </v-avatar>
        </template>
        <v-card-title>{{ playlist.name }}</v-card-title>
        <v-card-subtitle>
          {{ playlist.folder?.name ?? t('playlists.noFolder') }} · {{ t('playlists.trackCount', { count: playlist.trackCount }) }}<span v-if="playlistPlayingTime"> · {{ t('catalog.playingTime', { duration: playlistPlayingTime }) }}</span>
        </v-card-subtitle>
      </v-card-item>
      <v-card-text v-if="playlist.description" class="text-medium-emphasis">
        {{ playlist.description }}
      </v-card-text>
      <v-card-actions>
        <v-btn color="primary" variant="flat" prepend-icon="mdi-play" :disabled="!tracks.length" @click="playPlaylist">
          {{ t('playlists.playPlaylist') }}
        </v-btn>
        <v-btn color="primary" variant="tonal" prepend-icon="mdi-playlist-plus" :disabled="!tracks.length" @click="queuePlaylist">
          {{ t('playlists.queuePlaylist') }}
        </v-btn>
        <v-btn
          prepend-icon="mdi-file-music-outline"
          :disabled="!tracks.length"
          variant="tonal"
          @click="exportDialog = true"
        >
          {{ t('playlists.fileExportAction') }}
        </v-btn>
      </v-card-actions>
    </v-card>

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
          :key="item.id"
          :id="`playlist-item-${item.id}`"
          class="playlist-item"
          :class="{
            'current-track': player.currentTrack?.id === item.track.id,
            'membership-target': targetPlaylistItemId === item.id,
            'is-dragging': draggedItemId === item.id,
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
                <template #activator="{ props }">
                  <v-icon
                    v-bind="props"
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
            <span v-if="selectionMode">{{ item.track.title }}</span>
            <RouterLink v-else class="playlist-track-link" :to="{ name: 'track-detail', params: { id: item.track.id } }">
              {{ item.track.title }}
            </RouterLink>
          </v-list-item-title>
          <v-list-item-subtitle>
            <template v-if="item.track.artists.length">
              <template v-for="(artist, artistIndex) in item.track.artists" :key="artist.id">
                <span v-if="artistIndex > 0">, </span>
                <span v-if="selectionMode">{{ artist.name }}</span>
                <RouterLink v-else class="playlist-track-link" :to="{ name: 'artist-detail', params: { id: artist.id } }">
                  {{ artist.name }}
                </RouterLink>
              </template>
            </template>
            <span v-else>{{ t('catalog.unknownArtist') }}</span>
          </v-list-item-subtitle>
          <v-list-item-subtitle>
            <template v-if="item.track.album">
              <span v-if="selectionMode">{{ item.track.album.title }}</span>
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
              <span class="text-caption text-medium-emphasis">{{ duration(item.track.durationMs) }}</span>
              <TooltipIconButton
                :text="player.currentTrack?.id === item.track.id && player.isPlaying ? t('player.pause') : t('player.play')"
                :aria-label="player.currentTrack?.id === item.track.id && player.isPlaying ? t('player.pause') : t('player.play')"
                :color="player.currentTrack?.id === item.track.id ? 'primary' : undefined"
                :icon="player.currentTrack?.id === item.track.id && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
                variant="text"
                @click="toggleTrack(item.track)"
              />
              <TooltipIconButton
                :text="t('tracks.queueTrack')"
                :aria-label="t('tracks.queueTrack')"
                icon="mdi-playlist-plus"
                variant="text"
                @click="player.queueTrack(item.track, 'track-list')"
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
  </template>

  <EmptyCatalogState v-else :title="t('playlists.notFoundTitle')" :description="t('playlists.notFoundDescription')" icon="mdi-playlist-remove" />

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

  <PlaylistFileExportDialog
    v-if="playlist"
    v-model="exportDialog"
    :playlist-id="playlist.id"
    @saved="handlePlaylistExported"
  />

  <v-snackbar v-model="exportMessageVisible" color="success" timeout="3000">
    {{ exportMessage }}
  </v-snackbar>
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

.playlist-item.selection-active {
  cursor: pointer;
}

.playlist-item.membership-target {
  box-shadow: inset 3px 0 rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.08);
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
  height: 2rem;
  cursor: grab;
  width: 2rem;
}

.playlist-actions {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  justify-content: flex-end;
}

.playlist-track-link {
  color: inherit;
  text-decoration: none;
}

.playlist-track-link:hover {
  text-decoration: underline;
}
</style>
