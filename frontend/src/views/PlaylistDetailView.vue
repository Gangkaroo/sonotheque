<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import CatalogRating from '@/components/CatalogRating.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PlaylistFileExportDialog from '@/components/PlaylistFileExportDialog.vue'
import PlaylistSimilarityOrderDialog from '@/components/PlaylistSimilarityOrderDialog.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import TrackPlaylistMembershipMenu from '@/components/TrackPlaylistMembershipMenu.vue'
import type { Track } from '@/stores/catalog'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { usePlayerStore } from '@/stores/player'
import type { PlaylistItem } from '@/stores/playlists'
import { usePlaylistsStore } from '@/stores/playlists'
import type { PlaylistFileExportResult } from '@/types/playlistExport'
import { formatDateTime, formatDuration as duration, formatTotalDuration } from '@/utils/formatters'

const { locale, t } = useI18n()
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
const playableTracks = computed(() => tracks.value.filter((track) => track.available !== false))
const playlistPlayingTime = computed(() => {
  const total = tracks.value.reduce((sum, track) => sum + (track.durationMs ?? 0), 0)

  return total > 0 ? formatTotalDuration(total) : null
})
const distinctTracks = computed(() => [...new Map(
  tracks.value.map((track) => [track.id, track]),
).values()])
const playlistPlaybackStats = computed(() => {
  const totalTrackPlays = distinctTracks.value.reduce(
    (total, track) => total + track.playStatistics.playCount,
    0,
  )
  if (!totalTrackPlays) return []

  const playedTracks = distinctTracks.value.filter((track) => track.playStatistics.playCount > 0).length
  const lastPlayedAt = distinctTracks.value
    .map((track) => track.playStatistics.lastPlayedAt)
    .filter((value): value is string => Boolean(value))
    .sort((left, right) => new Date(right).getTime() - new Date(left).getTime())[0] ?? null

  return [
    {
      key: 'totalTrackPlays',
      label: t('playlists.totalTrackPlays'),
      value: t('tracks.playCountTooltip', { count: totalTrackPlays }),
      icon: 'mdi-headphones-box',
    },
    {
      key: 'playedTracks',
      label: t('playlists.playedTracks'),
      value: t('playlists.playedTracksCount', { played: playedTracks, total: distinctTracks.value.length }),
      icon: 'mdi-music-note-outline',
    },
    {
      key: 'lastPlayedAt',
      label: t('tracks.lastPlayedAt'),
      value: formatDate(lastPlayedAt),
      icon: 'mdi-calendar-clock',
    },
  ]
})
const selectionMode = ref(false)
const selectedItemIds = ref<number[]>([])
const draggedItemId = ref<number | null>(null)
const dropTargetIndex = ref<number | null>(null)
const removeSelectedDialog = ref(false)
const removeItemDialog = ref(false)
const itemToRemove = ref<PlaylistItem | null>(null)
const exportDialog = ref(false)
const similarityOrderDialog = ref(false)
const exportMessage = ref('')
const exportMessageVisible = ref(false)
const addToPlaylistDialog = ref(false)
const playlistTracks = ref<Track[]>([])
const itemIds = computed(() => playlist.value?.items.map((item) => item.id) ?? [])
const selectedCount = computed(() => selectedItemIds.value.length)
const allSelected = computed(() => itemIds.value.length > 0 && selectedCount.value === itemIds.value.length)
const canReorder = computed(() => libraryRootScope.selectedRootId === null)
const AUTO_SCROLL_EDGE_SIZE = 96
const AUTO_SCROLL_MAX_SPEED = 22
let autoScrollFrame: number | null = null
let autoScrollSpeed = 0

function formatDate(value?: string | null) {
  return formatDateTime(value, locale.value)
}

function playCountTooltip(track: Track) {
  return [
    t('tracks.playCountTooltip', { count: track.playStatistics.playCount }),
    t('tracks.firstPlayedAtTooltip', { value: formatDate(track.playStatistics.firstPlayedAt) }),
    t('tracks.lastPlayedAtTooltip', { value: formatDate(track.playStatistics.lastPlayedAt) }),
  ]
}

function playPlaylist() {
  const [firstTrack] = playableTracks.value
  if (!firstTrack) return

  player.playTrack(firstTrack, playableTracks.value, 'track-list')
}

function queuePlaylist() {
  player.queueTracks(playableTracks.value, 'track-list')
}

function openAddToPlaylist(track: Track) {
  playlistTracks.value = [track]
  addToPlaylistDialog.value = true
}

function handlePlaylistExported(result: PlaylistFileExportResult) {
  exportMessage.value = t('playlists.fileExportSaved', {
    filename: result.filename,
    location: result.location.name,
  })
  exportMessageVisible.value = true
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

  stopDragAutoScroll()
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

watch(
  () => distinctTracks.value.map((track) => track.id),
  (trackIds) => {
    if (trackIds.length) void playlists.loadMemberships(trackIds)
  },
  { immediate: true },
)

watch([() => playlist.value?.id, targetPlaylistItemId, itemIds], async ([loadedPlaylistId, itemId, ids]) => {
  if (loadedPlaylistId !== playlistId.value || itemId === null || !ids.includes(itemId)) return

  await nextTick()
  document.getElementById(`playlist-item-${itemId}`)?.scrollIntoView({
    behavior: 'smooth',
    block: 'center',
  })
}, { immediate: true })

onBeforeUnmount(stopDragAutoScroll)
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
      <v-card-text v-if="playlist.description || playlistPlaybackStats.length">
        <p v-if="playlist.description" class="text-medium-emphasis mb-0">{{ playlist.description }}</p>
        <div
          v-if="playlistPlaybackStats.length"
          class="playlist-stat-grid"
          :class="{ 'mt-4': playlist.description }"
          :title="t('playlists.playStatisticsHint')"
        >
          <div v-for="stat in playlistPlaybackStats" :key="stat.key" class="playlist-stat-tile">
            <v-icon color="primary" :icon="stat.icon" size="small" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ stat.label }}</div>
              <div class="text-body-2 font-weight-medium">{{ stat.value }}</div>
            </div>
          </div>
        </div>
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
        <v-btn
          prepend-icon="mdi-vector-polyline"
          :disabled="!canReorder || playlist.items.length < 2"
          variant="tonal"
          @click="similarityOrderDialog = true"
        >
          {{ t('playlists.similarityOrderAction') }}
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
              <span class="text-caption text-medium-emphasis">{{ duration(item.track.durationMs) }}</span>
              <v-tooltip location="top">
                <template #activator="{ props }">
                  <span v-bind="props" class="play-count text-caption text-medium-emphasis">
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

  <PlaylistSimilarityOrderDialog
    v-if="playlist"
    v-model="similarityOrderDialog"
    :playlist="playlist"
  />

  <AddToPlaylistDialog v-model="addToPlaylistDialog" :tracks="playlistTracks" />

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

.playlist-stat-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
}

.playlist-stat-tile {
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  display: flex;
  gap: 10px;
  min-width: 0;
  padding: 10px;
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
