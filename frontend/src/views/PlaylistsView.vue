<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { PlaylistFolder, PlaylistSummary } from '@/stores/playlists'
import { usePlaylistsStore } from '@/stores/playlists'

type PlaylistSort = 'name' | 'updated' | 'track_count'

const { t, locale } = useI18n()
const playlists = usePlaylistsStore()
const activeTab = ref<'playlists' | 'folders'>('playlists')
const playlistSearch = ref('')
const playlistSort = ref<PlaylistSort>(readPlaylistSort())
const folderDialog = ref(false)
const playlistDialog = ref(false)
const deleteFolderDialog = ref(false)
const deletePlaylistDialog = ref(false)
const folderToEdit = ref<PlaylistFolder | null>(null)
const playlistToEdit = ref<PlaylistSummary | null>(null)
const folderToDelete = ref<PlaylistFolder | null>(null)
const playlistToDelete = ref<PlaylistSummary | null>(null)
const editFolderName = ref('')
const editPlaylistName = ref('')
const editPlaylistDescription = ref('')
const editPlaylistFolderId = ref<number | null>(null)
const folderOptions = computed(() => [
  { title: t('playlists.noFolder'), value: null },
  ...playlists.folders.map((folder) => ({ title: folder.name, value: folder.id })),
])
const playlistSortOptions = computed(() => [
  { title: t('playlists.sortName'), value: 'name' },
  { title: t('playlists.sortRecentlyUpdated'), value: 'updated' },
  { title: t('playlists.sortTrackCount'), value: 'track_count' },
])
const filteredPlaylists = computed(() => {
  const search = playlistSearch.value.trim().toLocaleLowerCase(locale.value)
  if (!search) return playlists.playlists

  return playlists.playlists.filter((playlist) => [
    playlist.name,
    playlist.description,
    playlist.folder?.name,
  ].some((value) => value?.toLocaleLowerCase(locale.value).includes(search)))
})
const playlistGroups = computed(() => {
  const collator = new Intl.Collator(locale.value, { sensitivity: 'base' })
  const groups = new Map<number, { key: string, name: string, playlists: PlaylistSummary[] }>()
  const unfiled: PlaylistSummary[] = []

  for (const playlist of filteredPlaylists.value) {
    if (!playlist.folder) {
      unfiled.push(playlist)
      continue
    }

    const group = groups.get(playlist.folder.id) ?? {
      key: `folder-${playlist.folder.id}`,
      name: playlist.folder.name,
      playlists: [],
    }
    group.playlists.push(playlist)
    groups.set(playlist.folder.id, group)
  }

  const result = [...groups.values()]
    .sort((left, right) => collator.compare(left.name, right.name))
  result.forEach((group) => group.playlists.sort((left, right) => comparePlaylists(left, right, collator)))

  if (unfiled.length) {
    result.push({
      key: 'unfiled',
      name: t('playlists.noFolder'),
      playlists: unfiled.sort((left, right) => comparePlaylists(left, right, collator)),
    })
  }

  return result
})
const canSaveFolder = computed(() => editFolderName.value.trim().length > 0 && !playlists.saving)
const canSavePlaylist = computed(() => editPlaylistName.value.trim().length > 0 && !playlists.saving)
const folderDialogTitle = computed(() => folderToEdit.value
  ? t('playlists.editFolderTitle')
  : t('playlists.createFolderTitle'))
const playlistDialogTitle = computed(() => playlistToEdit.value
  ? t('playlists.editPlaylistTitle')
  : t('playlists.createPlaylistTitle'))

function openCreateFolderDialog() {
  folderToEdit.value = null
  editFolderName.value = ''
  folderDialog.value = true
}

function openCreatePlaylistDialog() {
  playlistToEdit.value = null
  editPlaylistName.value = ''
  editPlaylistDescription.value = ''
  editPlaylistFolderId.value = null
  playlistDialog.value = true
}

function openFolderDialog(folder: PlaylistFolder) {
  folderToEdit.value = folder
  editFolderName.value = folder.name
  folderDialog.value = true
}

async function saveFolder() {
  if (!canSaveFolder.value) return

  if (folderToEdit.value) {
    await playlists.updateFolder(folderToEdit.value.id, { name: editFolderName.value.trim() })
  } else {
    await playlists.createFolder(editFolderName.value.trim())
  }
  folderDialog.value = false
}

function openPlaylistDialog(playlist: PlaylistSummary) {
  playlistToEdit.value = playlist
  editPlaylistName.value = playlist.name
  editPlaylistDescription.value = playlist.description ?? ''
  editPlaylistFolderId.value = playlist.folder?.id ?? null
  playlistDialog.value = true
}

async function savePlaylist() {
  if (!canSavePlaylist.value) return

  const payload = {
    name: editPlaylistName.value.trim(),
    description: editPlaylistDescription.value.trim() || null,
    folderId: editPlaylistFolderId.value,
  }
  if (playlistToEdit.value) {
    await playlists.updatePlaylist(playlistToEdit.value.id, payload)
  } else {
    await playlists.createPlaylist(payload)
  }
  playlistDialog.value = false
}

function confirmDeleteFolder(folder: PlaylistFolder) {
  folderToDelete.value = folder
  deleteFolderDialog.value = true
}

async function deleteFolder() {
  if (!folderToDelete.value) return

  await playlists.deleteFolder(folderToDelete.value.id)
  deleteFolderDialog.value = false
  folderToDelete.value = null
}

function confirmDeletePlaylist(playlist: PlaylistSummary) {
  playlistToDelete.value = playlist
  deletePlaylistDialog.value = true
}

async function deletePlaylist() {
  if (!playlistToDelete.value) return

  await playlists.deletePlaylist(playlistToDelete.value.id)
  deletePlaylistDialog.value = false
  playlistToDelete.value = null
}

function comparePlaylists(left: PlaylistSummary, right: PlaylistSummary, collator: Intl.Collator) {
  const nameComparison = collator.compare(left.name, right.name)

  if (playlistSort.value === 'updated') {
    return timestamp(right.updatedAt) - timestamp(left.updatedAt) || nameComparison
  }

  if (playlistSort.value === 'track_count') {
    return right.trackCount - left.trackCount || nameComparison
  }

  return nameComparison
}

function timestamp(value?: string) {
  const parsed = value ? Date.parse(value) : Number.NaN

  return Number.isFinite(parsed) ? parsed : 0
}

function readPlaylistSort(): PlaylistSort {
  const stored = window.sessionStorage.getItem('sonotheque:playlist-sort')

  return stored === 'updated' || stored === 'track_count' ? stored : 'name'
}

watch(playlistSort, (value) => {
  window.sessionStorage.setItem('sonotheque:playlist-sort', value)
})

onMounted(() => {
  void playlists.loadAll()
})
</script>

<template>
  <PageHeader :title="t('playlists.title')" :description="t('playlists.description')" icon="mdi-playlist-music-outline" />

  <v-alert v-if="playlists.error" class="mb-6" type="error" variant="tonal">{{ playlists.error }}</v-alert>

  <v-card border rounded="xl">
    <v-tabs v-model="activeTab" color="primary">
      <v-tab prepend-icon="mdi-playlist-music-outline" value="playlists" @click="activeTab = 'playlists'">{{ t('playlists.playlists') }}</v-tab>
      <v-tab prepend-icon="mdi-folder-music-outline" value="folders" @click="activeTab = 'folders'">{{ t('playlists.folders') }}</v-tab>
    </v-tabs>
    <v-divider />

    <div v-if="activeTab === 'playlists'">
        <v-card-text class="playlist-toolbar d-flex flex-wrap align-center ga-3 pb-2">
          <v-text-field
            v-model="playlistSearch"
            class="playlist-search"
            clearable
            density="compact"
            hide-details
            :label="t('playlists.searchPlaylists')"
            prepend-inner-icon="mdi-magnify"
            variant="outlined"
          />
          <v-select
            v-model="playlistSort"
            class="playlist-sort"
            density="compact"
            hide-details
            :items="playlistSortOptions"
            :label="t('playlists.sortBy')"
            prepend-inner-icon="mdi-sort"
            variant="outlined"
          />
          <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreatePlaylistDialog">
            {{ t('playlists.createPlaylist') }}
          </v-btn>
        </v-card-text>

        <v-skeleton-loader v-if="playlists.loading" type="list-item-two-line@5" />
        <div v-else-if="playlistGroups.length">
          <section v-for="group in playlistGroups" :key="group.key" class="playlist-group">
            <div class="playlist-group-header d-flex align-center ga-2 px-4 text-medium-emphasis">
              <v-icon icon="mdi-folder-music-outline" size="x-small" />
              <h2 class="text-caption font-weight-bold">{{ group.name }}</h2>
            </div>
            <v-list class="py-0" density="compact" lines="two">
              <v-list-item
                v-for="playlist in group.playlists"
                :key="playlist.id"
                density="compact"
                :to="{ name: 'playlist-detail', params: { id: playlist.id } }"
              >
                <template #prepend>
                  <v-avatar color="primary" size="32" variant="tonal">
                    <v-icon icon="mdi-playlist-music-outline" size="small" />
                  </v-avatar>
                </template>
                <v-list-item-title class="font-weight-bold">{{ playlist.name }}</v-list-item-title>
                <v-list-item-subtitle>
                  <span>{{ t('playlists.trackCount', { count: playlist.trackCount }) }}</span>
                  <span v-if="playlist.description"> &middot; {{ playlist.description }}</span>
                </v-list-item-subtitle>
                <template #append>
                  <div class="d-flex align-center ga-0">
                    <TooltipIconButton
                      :text="t('playlists.editPlaylist', { name: playlist.name })"
                      :aria-label="t('playlists.editPlaylist', { name: playlist.name })"
                      :disabled="playlists.saving"
                      density="compact"
                      icon="mdi-pencil-outline"
                      variant="text"
                      @click.prevent.stop="openPlaylistDialog(playlist)"
                    />
                    <TooltipIconButton
                      :text="t('playlists.deletePlaylist', { name: playlist.name })"
                      :aria-label="t('playlists.deletePlaylist', { name: playlist.name })"
                      :disabled="playlists.saving"
                      density="compact"
                      icon="mdi-delete-outline"
                      variant="text"
                      @click.prevent.stop="confirmDeletePlaylist(playlist)"
                    />
                  </div>
                </template>
              </v-list-item>
            </v-list>
          </section>
        </div>
        <v-card-text v-else-if="playlistSearch.trim()">
          <EmptyCatalogState
            :title="t('playlists.noSearchResultsTitle')"
            :description="t('playlists.noSearchResultsDescription')"
            icon="mdi-playlist-search"
          />
        </v-card-text>
        <v-card-text v-else>
          <EmptyCatalogState :title="t('playlists.emptyPlaylistsTitle')" :description="t('playlists.emptyPlaylistsDescription')" icon="mdi-playlist-music-outline" />
        </v-card-text>
    </div>

    <div v-else>
        <v-card-text class="d-flex justify-end pb-2">
          <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateFolderDialog">
            {{ t('playlists.createFolder') }}
          </v-btn>
        </v-card-text>

        <v-skeleton-loader v-if="playlists.loading" type="list-item-two-line@4" />
        <v-list v-else-if="playlists.folders.length" class="py-0" density="compact" lines="two">
          <v-list-item v-for="folder in playlists.folders" :key="folder.id" density="compact">
            <template #prepend>
              <v-avatar color="primary" size="32" variant="tonal">
                <v-icon icon="mdi-folder-music-outline" size="small" />
              </v-avatar>
            </template>
            <v-list-item-title class="font-weight-bold">{{ folder.name }}</v-list-item-title>
            <v-list-item-subtitle>{{ t('playlists.playlistCount', { count: folder.playlistCount }) }}</v-list-item-subtitle>
            <template #append>
              <div class="d-flex align-center ga-0">
                <TooltipIconButton
                  :text="t('playlists.editFolder', { name: folder.name })"
                  :aria-label="t('playlists.editFolder', { name: folder.name })"
                  :disabled="playlists.saving"
                  density="compact"
                  icon="mdi-pencil-outline"
                  variant="text"
                  @click="openFolderDialog(folder)"
                />
                <TooltipIconButton
                  :text="t('playlists.deleteFolder', { name: folder.name })"
                  :aria-label="t('playlists.deleteFolder', { name: folder.name })"
                  :disabled="playlists.saving"
                  density="compact"
                  icon="mdi-delete-outline"
                  variant="text"
                  @click="confirmDeleteFolder(folder)"
                />
              </div>
            </template>
          </v-list-item>
        </v-list>
        <v-card-text v-else>
          <EmptyCatalogState :title="t('playlists.emptyFoldersTitle')" :description="t('playlists.emptyFoldersDescription')" icon="mdi-folder-music-outline" />
        </v-card-text>
    </div>
  </v-card>

  <v-dialog v-model="folderDialog" max-width="460">
    <v-card :title="folderDialogTitle" :prepend-icon="folderToEdit ? 'mdi-folder-edit-outline' : 'mdi-folder-plus-outline'">
      <v-card-text>
        <v-text-field
          v-model="editFolderName"
          autofocus
          :disabled="playlists.saving"
          :label="t('playlists.folderName')"
          variant="outlined"
          @keydown.enter.prevent="saveFolder"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="folderDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="primary" :disabled="!canSaveFolder" :loading="playlists.saving" variant="flat" @click="saveFolder">
          {{ folderToEdit ? t('settings.saveChanges') : t('playlists.create') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="playlistDialog" max-width="560">
    <v-card :title="playlistDialogTitle" :prepend-icon="playlistToEdit ? 'mdi-playlist-edit' : 'mdi-playlist-plus'">
      <v-card-text>
        <v-row dense>
          <v-col cols="12">
            <v-text-field
              v-model="editPlaylistName"
              autofocus
              :disabled="playlists.saving"
              :label="t('playlists.playlistName')"
              variant="outlined"
              @keydown.enter.prevent="savePlaylist"
            />
          </v-col>
          <v-col cols="12">
            <v-select
              v-model="editPlaylistFolderId"
              :disabled="playlists.saving"
              :items="folderOptions"
              :label="t('playlists.folder')"
              variant="outlined"
            />
          </v-col>
          <v-col cols="12">
            <v-textarea
              v-model="editPlaylistDescription"
              :disabled="playlists.saving"
              :label="t('playlists.descriptionField')"
              rows="3"
              variant="outlined"
            />
          </v-col>
        </v-row>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="playlistDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="primary" :disabled="!canSavePlaylist" :loading="playlists.saving" variant="flat" @click="savePlaylist">
          {{ playlistToEdit ? t('settings.saveChanges') : t('playlists.create') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="deleteFolderDialog" max-width="520">
    <v-card :title="t('playlists.deleteFolderTitle')" prepend-icon="mdi-alert-outline">
      <v-card-text>
        {{ t('playlists.deleteFolderWarning', { name: folderToDelete?.name ?? '' }) }}
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="deleteFolderDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="error" :loading="playlists.saving" variant="flat" @click="deleteFolder">
          {{ t('settings.remove') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="deletePlaylistDialog" max-width="520">
    <v-card :title="t('playlists.deletePlaylistTitle')" prepend-icon="mdi-alert-outline">
      <v-card-text>
        {{ t('playlists.deletePlaylistWarning', { name: playlistToDelete?.name ?? '' }) }}
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="deletePlaylistDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="error" :loading="playlists.saving" variant="flat" @click="deletePlaylist">
          {{ t('settings.remove') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.playlist-search {
  flex: 1 1 260px;
}

.playlist-sort {
  flex: 0 0 15rem;
}

@media (max-width: 599px) {
  .playlist-sort {
    flex-basis: 100%;
  }
}

.playlist-group + .playlist-group {
  border-top: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.playlist-group-header {
  min-height: 28px;
}
</style>
