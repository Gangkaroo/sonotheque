<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'
import { type TrashTrack, useTrashStore } from '@/stores/trash'
import { formatDateTime } from '@/utils/formatters'

const { locale, t } = useI18n()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const trash = useTrashStore()
const page = ref(1)
const searchInput = ref('')
const appliedSearch = ref('')
const selectionMode = ref(false)
const selectedIds = ref<number[]>([])
const deleteDialog = ref(false)
const pendingDeletion = ref<TrashTrack[]>([])
const selectedSet = computed(() => new Set(selectedIds.value))
const visibleTrackIds = computed(() => trash.tracks.items.map((track) => track.id))
const allVisibleSelected = computed(() => (
  visibleTrackIds.value.length > 0
  && visibleTrackIds.value.every((id) => selectedSet.value.has(id))
))
const someVisibleSelected = computed(() => (
  visibleTrackIds.value.some((id) => selectedSet.value.has(id))
))

onMounted(() => {
  void load()
})

function load(nextPage = page.value) {
  page.value = nextPage
  selectedIds.value = []
  void trash.load(nextPage, appliedSearch.value)
}

function applySearch() {
  appliedSearch.value = searchInput.value.trim()
  load(1)
}

function clearSearch() {
  searchInput.value = ''
  appliedSearch.value = ''
  load(1)
}

function setSelectionMode(value: boolean) {
  selectionMode.value = value
  if (!value) selectedIds.value = []
}

function toggleSelection(trackId: number) {
  selectedIds.value = selectedSet.value.has(trackId)
    ? selectedIds.value.filter((id) => id !== trackId)
    : [...selectedIds.value, trackId]
}

function toggleAllVisible(selected: boolean | null) {
  const visibleIds = new Set(visibleTrackIds.value)

  if (selected) {
    selectedIds.value = [...new Set([...selectedIds.value, ...visibleIds])]
    return
  }

  selectedIds.value = selectedIds.value.filter((id) => !visibleIds.has(id))
}

function requestDeletion(tracks: TrashTrack[]) {
  pendingDeletion.value = tracks
  deleteDialog.value = true
}

async function confirmDeletion() {
  const ids = pendingDeletion.value.map((track) => track.id)

  try {
    await trash.deleteTracks(ids)
  } catch {
    return
  }

  const deletedIds = new Set(ids)
  for (let index = player.queue.length - 1; index >= 0; index -= 1) {
    if (deletedIds.has(player.queue[index]?.id ?? -1)) player.removeQueuedTrack(index)
  }

  deleteDialog.value = false
  pendingDeletion.value = []
  selectedIds.value = selectedIds.value.filter((id) => !deletedIds.has(id))
  catalog.invalidateMetrics()
  void favorites.loadIds(true)

  const nextPage = trash.tracks.items.length <= ids.length && page.value > 1
    ? page.value - 1
    : page.value
  load(nextPage)
}

function artists(track: TrashTrack) {
  return track.artists.map((artist) => artist.name).join(', ') || t('catalog.unknownArtist')
}

function markedMissingAt(track: TrashTrack) {
  return track.markedMissingAt
    ? formatDateTime(track.markedMissingAt, locale.value)
    : t('trash.unknownMissingDate')
}
</script>

<template>
  <PageHeader
    :title="t('trash.title')"
    :description="t('trash.description')"
    icon="mdi-delete-clock-outline"
    :count="trash.tracks.total"
  />

  <v-alert
    class="mb-5"
    icon="mdi-information-outline"
    :text="t('trash.retentionHint')"
    type="info"
    variant="tonal"
  />
  <v-alert v-if="trash.error" class="mb-5" type="error" variant="tonal">{{ trash.error }}</v-alert>

  <v-card border rounded="xl">
    <v-card-text class="trash-toolbar">
      <v-text-field
        v-model="searchInput"
        clearable
        density="compact"
        hide-details
        :label="t('trash.search')"
        prepend-inner-icon="mdi-magnify"
        variant="outlined"
        @click:clear="clearSearch"
        @keyup.enter="applySearch"
      />
      <v-btn color="primary" variant="tonal" @click="applySearch">{{ t('trash.searchAction') }}</v-btn>
      <v-btn
        :prepend-icon="selectionMode ? 'mdi-close' : 'mdi-checkbox-multiple-marked-outline'"
        variant="text"
        @click="setSelectionMode(!selectionMode)"
      >
        {{ selectionMode ? t('trash.cancelSelection') : t('trash.selectTracks') }}
      </v-btn>
      <v-checkbox
        v-if="selectionMode"
        class="trash-select-all"
        color="primary"
        density="compact"
        hide-details
        :indeterminate="someVisibleSelected && !allVisibleSelected"
        :label="allVisibleSelected ? t('trash.clearPageSelection') : t('trash.selectAllPage')"
        :model-value="allVisibleSelected"
        @update:model-value="toggleAllVisible"
      />
      <v-btn
        v-if="selectionMode"
        color="error"
        :disabled="selectedIds.length === 0"
        prepend-icon="mdi-delete-forever-outline"
        variant="tonal"
        @click="requestDeletion(trash.tracks.items.filter((track) => selectedSet.has(track.id)))"
      >
        {{ t('trash.deleteSelected', { count: selectedIds.length }) }}
      </v-btn>
    </v-card-text>

    <v-divider />

    <v-skeleton-loader v-if="trash.loading" type="list-item-three-line@8" />
    <v-list v-else-if="trash.tracks.items.length" lines="three">
      <v-list-item v-for="track in trash.tracks.items" :key="track.id" class="trash-track">
        <template #prepend>
          <v-checkbox-btn
            v-if="selectionMode"
            :aria-label="t('trash.selectTrack', { title: track.title })"
            :model-value="selectedSet.has(track.id)"
            @update:model-value="toggleSelection(track.id)"
          />
          <v-icon v-else color="medium-emphasis" icon="mdi-music-note-off-outline" />
        </template>

        <v-list-item-title class="font-weight-bold">
          <RouterLink class="trash-link" :to="{ name: 'track-detail', params: { id: track.id } }">
            {{ track.title }}
          </RouterLink>
        </v-list-item-title>
        <v-list-item-subtitle>
          {{ artists(track) }}
          <template v-if="track.album">
            <span aria-hidden="true"> · </span>{{ track.album.title }}
          </template>
        </v-list-item-subtitle>
        <v-list-item-subtitle class="trash-path">
          {{ track.libraryRoot?.name ?? t('trash.unknownRoot') }}
          <span aria-hidden="true"> · </span>
          {{ track.relativePath ?? t('trash.unknownPath') }}
        </v-list-item-subtitle>

        <template #append>
          <div class="trash-meta">
            <div class="text-caption text-medium-emphasis">
              {{ t('trash.markedMissingAt', { value: markedMissingAt(track) }) }}
            </div>
            <div class="trash-chips">
              <v-chip v-if="track.playlistCount" size="small" variant="tonal">
                {{ t('trash.playlistCount', { count: track.playlistCount }) }}
              </v-chip>
              <v-chip v-if="track.playCount" size="small" variant="tonal">
                {{ t('trash.playCount', { count: track.playCount }) }}
              </v-chip>
              <TooltipIconButton
                :aria-label="t('trash.deleteTrack', { title: track.title })"
                color="error"
                density="comfortable"
                icon="mdi-delete-forever-outline"
                :text="t('trash.deleteTrack', { title: track.title })"
                variant="text"
                @click="requestDeletion([track])"
              />
            </div>
          </div>
        </template>
      </v-list-item>
    </v-list>

    <v-card-text v-else>
      <EmptyCatalogState
        :title="appliedSearch ? t('trash.noSearchResultsTitle') : t('trash.emptyTitle')"
        :description="appliedSearch ? t('trash.noSearchResultsDescription') : t('trash.emptyDescription')"
        icon="mdi-delete-empty-outline"
      />
    </v-card-text>

    <template v-if="trash.tracks.lastPage > 1">
      <v-divider />
      <v-card-actions class="justify-center">
        <v-pagination v-model="page" :length="trash.tracks.lastPage" @update:model-value="load" />
      </v-card-actions>
    </template>
  </v-card>

  <v-dialog v-model="deleteDialog" max-width="560">
    <v-card prepend-icon="mdi-delete-forever-outline" :title="t('trash.confirmTitle')">
      <v-card-text>
        <p>{{ t('trash.confirmDescription', { count: pendingDeletion.length }) }}</p>
        <v-alert
          class="mt-4"
          :text="t('trash.confirmConsequences')"
          type="warning"
          variant="tonal"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn :disabled="trash.deleting" @click="deleteDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn
          color="error"
          :loading="trash.deleting"
          variant="flat"
          @click="confirmDeletion"
        >
          {{ t('trash.deletePermanently') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.trash-toolbar {
  display: grid;
  grid-template-columns: minmax(220px, 1fr) auto auto auto auto;
  gap: 12px;
  align-items: center;
}

.trash-select-all {
  white-space: nowrap;
}

.trash-track {
  min-height: 82px;
}

.trash-link {
  color: inherit;
  text-decoration: none;
}

.trash-link:hover {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
}

.trash-path {
  max-width: 760px;
}

.trash-meta {
  display: flex;
  min-width: 210px;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}

.trash-chips {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 6px;
}

@media (max-width: 900px) {
  .trash-toolbar {
    grid-template-columns: 1fr auto;
  }

  .trash-meta {
    min-width: 0;
  }

  .trash-meta > .text-caption,
  .trash-chips .v-chip {
    display: none;
  }
}

@media (max-width: 600px) {
  .trash-toolbar {
    grid-template-columns: 1fr;
  }
}
</style>
