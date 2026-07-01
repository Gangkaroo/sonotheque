<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { Track } from '@/stores/catalog'
import { usePlayerStore } from '@/stores/player'
import type { PlaylistItem } from '@/stores/playlists'
import { usePlaylistsStore } from '@/stores/playlists'

const { t } = useI18n()
const route = useRoute()
const playlists = usePlaylistsStore()
const player = usePlayerStore()

const playlistId = computed(() => Number(route.params.id))
const playlist = computed(() => playlists.current)
const tracks = computed(() => playlist.value?.items.map((item) => item.track) ?? [])
const selectedItemIds = ref<number[]>([])
const draggedItemId = ref<number | null>(null)
const dropTargetItemId = ref<number | null>(null)
const removeSelectedDialog = ref(false)
const removeItemDialog = ref(false)
const itemToRemove = ref<PlaylistItem | null>(null)
const itemIds = computed(() => playlist.value?.items.map((item) => item.id) ?? [])
const selectedCount = computed(() => selectedItemIds.value.length)
const allSelected = computed(() => itemIds.value.length > 0 && selectedCount.value === itemIds.value.length)

function duration(milliseconds?: number) {
  if (!milliseconds) return '-'
  const seconds = Math.round(milliseconds / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function playPlaylist() {
  const [firstTrack] = tracks.value
  if (!firstTrack) return

  player.playTrack(firstTrack, tracks.value, 'track-list')
}

function queuePlaylist() {
  player.queueTracks(tracks.value, 'track-list')
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
  selectedItemIds.value = []
  removeSelectedDialog.value = false
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

async function moveItem(itemId: number, direction: -1 | 1) {
  const currentIds = [...itemIds.value]
  const index = currentIds.indexOf(itemId)
  const nextIndex = index + direction

  if (!playlist.value || index < 0 || nextIndex < 0 || nextIndex >= currentIds.length) return

  const item = currentIds[index]
  if (item === undefined) return

  currentIds.splice(index, 1)
  currentIds.splice(nextIndex, 0, item)
  await playlists.reorderItems(playlist.value.id, currentIds)
}

function startDragging(itemId: number, event: DragEvent) {
  draggedItemId.value = itemId
  event.dataTransfer?.setData('text/plain', String(itemId))
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function stopDragging() {
  draggedItemId.value = null
  dropTargetItemId.value = null
}

function markDropTarget(itemId: number) {
  if (draggedItemId.value !== null && draggedItemId.value !== itemId) {
    dropTargetItemId.value = itemId
  }
}

function clearDropTarget(itemId: number) {
  if (dropTargetItemId.value === itemId) dropTargetItemId.value = null
}

async function dropItem(targetItemId: number) {
  const sourceItemId = draggedItemId.value
  draggedItemId.value = null
  dropTargetItemId.value = null

  if (!playlist.value || sourceItemId === null || sourceItemId === targetItemId) return

  const currentIds = [...itemIds.value]
  const sourceIndex = currentIds.indexOf(sourceItemId)
  const targetIndex = currentIds.indexOf(targetItemId)

  if (sourceIndex < 0 || targetIndex < 0) return

  const item = currentIds[sourceIndex]
  if (item === undefined) return

  currentIds.splice(sourceIndex, 1)
  currentIds.splice(targetIndex, 0, item)
  await playlists.reorderItems(playlist.value.id, currentIds)
}

watch(playlistId, (id) => {
  if (Number.isInteger(id) && id > 0) void playlists.loadPlaylist(id)
}, { immediate: true })

watch(() => playlist.value?.id, () => {
  selectedItemIds.value = []
  removeSelectedDialog.value = false
  removeItemDialog.value = false
  itemToRemove.value = null
})

watch(itemIds, (ids) => {
  selectedItemIds.value = selectedItemIds.value.filter((id) => ids.includes(id))
})
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
          {{ playlist.folder?.name ?? t('playlists.noFolder') }} · {{ t('playlists.trackCount', { count: playlist.trackCount }) }}
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
      </v-card-actions>
    </v-card>

    <v-card v-if="playlist.items.length" border rounded="xl">
      <v-card-title class="playlist-toolbar">
        <div class="d-flex align-center ga-2">
          <v-checkbox-btn
            :aria-label="t('playlists.selectAllTracks')"
            :model-value="allSelected"
            color="primary"
            density="compact"
            @click.stop="toggleAllItems"
          />
          <span>{{ t('playlists.trackList') }}</span>
          <v-chip v-if="selectedCount" color="primary" size="small" variant="tonal">
            {{ t('playlists.selectedTracks', { count: selectedCount }) }}
          </v-chip>
        </div>
        <v-btn
          color="error"
          prepend-icon="mdi-delete-outline"
          :disabled="!selectedCount || playlists.saving"
          :loading="playlists.saving && selectedCount > 0"
          variant="tonal"
          @click="confirmRemoveSelectedItems"
        >
          {{ t('playlists.removeSelected') }}
        </v-btn>
      </v-card-title>

      <v-list lines="three">
        <v-list-item
          v-for="(item, index) in playlist.items"
          :key="item.id"
          class="playlist-item"
          :class="{
            'current-track': player.currentTrack?.id === item.track.id,
            'is-dragging': draggedItemId === item.id,
            'is-drop-target': dropTargetItemId === item.id && draggedItemId !== item.id,
          }"
          draggable="true"
          @dragend="stopDragging"
          @dragenter="markDropTarget(item.id)"
          @dragleave="clearDropTarget(item.id)"
          @dragover.prevent
          @dragstart="startDragging(item.id, $event)"
          @drop="dropItem(item.id)"
        >
          <template #prepend>
            <div class="d-flex align-center ga-1">
              <v-checkbox-btn
                v-model="selectedItemIds"
                :aria-label="t('playlists.selectTrack', { title: item.track.title })"
                :value="item.id"
                color="primary"
                density="compact"
              />
              <v-tooltip :text="t('playlists.dragTrack')" location="top">
                <template #activator="{ props }">
                  <v-icon
                    v-bind="props"
                    :aria-label="t('playlists.dragTrack')"
                    class="playlist-drag-handle text-medium-emphasis"
                    icon="mdi-drag"
                    size="small"
                  />
                </template>
              </v-tooltip>
            </div>
          </template>

          <v-list-item-title class="font-weight-bold" :class="{ 'text-primary': player.currentTrack?.id === item.track.id }">
            <RouterLink class="playlist-track-link" :to="{ name: 'track-detail', params: { id: item.track.id } }">
              {{ item.track.title }}
            </RouterLink>
          </v-list-item-title>
          <v-list-item-subtitle>
            <template v-if="item.track.artists.length">
              <template v-for="(artist, artistIndex) in item.track.artists" :key="artist.id">
                <span v-if="artistIndex > 0">, </span>
                <RouterLink class="playlist-track-link" :to="{ name: 'albums', query: { artist: artist.id, artistName: artist.name } }">
                  {{ artist.name }}
                </RouterLink>
              </template>
            </template>
            <span v-else>{{ t('catalog.unknownArtist') }}</span>
          </v-list-item-subtitle>
          <v-list-item-subtitle>
            <RouterLink
              v-if="item.track.album"
              class="playlist-track-link"
              :to="{ name: 'album-detail', params: { id: item.track.album.id } }"
            >
              {{ item.track.album.title }}
            </RouterLink>
            <span v-else>{{ t('catalog.unknownAlbum') }}</span>
          </v-list-item-subtitle>
          <template #append>
            <div class="playlist-actions">
              <span class="text-caption text-medium-emphasis">{{ duration(item.track.durationMs) }}</span>
              <TooltipIconButton
                :text="t('playlists.moveTrackUp')"
                :aria-label="t('playlists.moveTrackUp')"
                :disabled="index === 0 || playlists.saving"
                icon="mdi-arrow-up"
                variant="text"
                @click="moveItem(item.id, -1)"
              />
              <TooltipIconButton
                :text="t('playlists.moveTrackDown')"
                :aria-label="t('playlists.moveTrackDown')"
                :disabled="index === playlist.items.length - 1 || playlists.saving"
                icon="mdi-arrow-down"
                variant="text"
                @click="moveItem(item.id, 1)"
              />
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
      <v-card-actions>
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
}

.playlist-item:active {
  cursor: grabbing;
}

.playlist-item.is-dragging {
  opacity: 0.55;
}

.playlist-item.is-drop-target {
  background: rgba(var(--v-theme-primary), 0.06);
  outline: 2px solid rgba(var(--v-theme-primary), 0.45);
  outline-offset: -4px;
}

.playlist-drag-handle {
  cursor: grab;
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
