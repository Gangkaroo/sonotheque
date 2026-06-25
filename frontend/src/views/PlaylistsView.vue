<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { PlaylistFolder, PlaylistSummary } from '@/stores/playlists'
import { usePlaylistsStore } from '@/stores/playlists'

const { t } = useI18n()
const playlists = usePlaylistsStore()
const folderName = ref('')
const playlistName = ref('')
const playlistDescription = ref('')
const playlistFolderId = ref<number | null>(null)
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
const canCreateFolder = computed(() => folderName.value.trim().length > 0 && !playlists.saving)
const canCreatePlaylist = computed(() => playlistName.value.trim().length > 0 && !playlists.saving)
const canSaveFolder = computed(() => editFolderName.value.trim().length > 0 && !playlists.saving)
const canSavePlaylist = computed(() => editPlaylistName.value.trim().length > 0 && !playlists.saving)

async function createFolder() {
  if (!canCreateFolder.value) return

  await playlists.createFolder(folderName.value.trim())
  folderName.value = ''
}

async function createPlaylist() {
  if (!canCreatePlaylist.value) return

  await playlists.createPlaylist({
    name: playlistName.value.trim(),
    description: playlistDescription.value.trim() || null,
    folderId: playlistFolderId.value,
  })
  playlistName.value = ''
  playlistDescription.value = ''
  playlistFolderId.value = null
}

function openFolderDialog(folder: PlaylistFolder) {
  folderToEdit.value = folder
  editFolderName.value = folder.name
  folderDialog.value = true
}

async function saveFolder() {
  if (!folderToEdit.value || !canSaveFolder.value) return

  await playlists.updateFolder(folderToEdit.value.id, { name: editFolderName.value.trim() })
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
  if (!playlistToEdit.value || !canSavePlaylist.value) return

  await playlists.updatePlaylist(playlistToEdit.value.id, {
    name: editPlaylistName.value.trim(),
    description: editPlaylistDescription.value.trim() || null,
    folderId: editPlaylistFolderId.value,
  })
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

onMounted(() => {
  void playlists.loadAll()
})
</script>

<template>
  <PageHeader :title="t('playlists.title')" :description="t('playlists.description')" icon="mdi-playlist-music-outline" />

  <v-alert v-if="playlists.error" class="mb-6" type="error" variant="tonal">{{ playlists.error }}</v-alert>

  <v-row>
    <v-col cols="12" md="5">
      <v-card border rounded="xl">
        <v-card-title>{{ t('playlists.folders') }}</v-card-title>
        <v-card-text>
          <div class="d-flex ga-3">
            <v-text-field
              v-model="folderName"
              :disabled="playlists.saving"
              density="comfortable"
              hide-details
              :label="t('playlists.folderName')"
              variant="outlined"
              @keydown.enter.prevent="createFolder"
            />
            <v-btn
              color="primary"
              :disabled="!canCreateFolder"
              :loading="playlists.saving"
              min-width="96"
              @click="createFolder"
            >
              {{ t('playlists.create') }}
            </v-btn>
          </div>
        </v-card-text>

        <v-skeleton-loader v-if="playlists.loading" type="list-item-two-line@4" />
        <v-list v-else-if="playlists.folders.length" lines="two">
          <v-list-item v-for="folder in playlists.folders" :key="folder.id">
            <template #prepend>
              <v-avatar color="primary" variant="tonal">
                <v-icon icon="mdi-folder-music-outline" />
              </v-avatar>
            </template>
            <v-list-item-title class="font-weight-bold">{{ folder.name }}</v-list-item-title>
            <v-list-item-subtitle>{{ t('playlists.playlistCount', { count: folder.playlistCount }) }}</v-list-item-subtitle>
            <template #append>
              <div class="d-flex align-center ga-1">
                <TooltipIconButton
                  :text="t('playlists.editFolder', { name: folder.name })"
                  :aria-label="t('playlists.editFolder', { name: folder.name })"
                  :disabled="playlists.saving"
                  icon="mdi-pencil-outline"
                  variant="text"
                  @click="openFolderDialog(folder)"
                />
                <TooltipIconButton
                  :text="t('playlists.deleteFolder', { name: folder.name })"
                  :aria-label="t('playlists.deleteFolder', { name: folder.name })"
                  :disabled="playlists.saving"
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
      </v-card>
    </v-col>

    <v-col cols="12" md="7">
      <v-card border rounded="xl">
        <v-card-title>{{ t('playlists.playlists') }}</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12" md="5">
              <v-text-field
                v-model="playlistName"
                :disabled="playlists.saving"
                density="comfortable"
                hide-details
                :label="t('playlists.playlistName')"
                variant="outlined"
                @keydown.enter.prevent="createPlaylist"
              />
            </v-col>
            <v-col cols="12" md="4">
              <v-select
                v-model="playlistFolderId"
                :disabled="playlists.saving"
                density="comfortable"
                hide-details
                :items="folderOptions"
                :label="t('playlists.folder')"
                variant="outlined"
              />
            </v-col>
            <v-col cols="12" md="3">
              <v-btn
                block
                color="primary"
                :disabled="!canCreatePlaylist"
                :loading="playlists.saving"
                min-height="48"
                @click="createPlaylist"
              >
                {{ t('playlists.create') }}
              </v-btn>
            </v-col>
            <v-col cols="12">
              <v-textarea
                v-model="playlistDescription"
                :disabled="playlists.saving"
                density="comfortable"
                hide-details
                :label="t('playlists.descriptionField')"
                rows="2"
                variant="outlined"
              />
            </v-col>
          </v-row>
        </v-card-text>

        <v-skeleton-loader v-if="playlists.loading" type="list-item-three-line@5" />
        <v-list v-else-if="playlists.playlists.length" lines="three">
          <v-list-item v-for="playlist in playlists.playlists" :key="playlist.id" :to="{ name: 'playlist-detail', params: { id: playlist.id } }">
            <template #prepend>
              <v-avatar color="primary" variant="tonal">
                <v-icon icon="mdi-playlist-music-outline" />
              </v-avatar>
            </template>
            <v-list-item-title class="font-weight-bold">{{ playlist.name }}</v-list-item-title>
            <v-list-item-subtitle>
              {{ playlist.folder?.name ?? t('playlists.noFolder') }}
            </v-list-item-subtitle>
            <v-list-item-subtitle>
              <span>{{ t('playlists.trackCount', { count: playlist.trackCount }) }}</span>
              <span v-if="playlist.description"> &middot; {{ playlist.description }}</span>
            </v-list-item-subtitle>
            <template #append>
              <div class="d-flex align-center ga-1">
                <TooltipIconButton
                  :text="t('playlists.editPlaylist', { name: playlist.name })"
                  :aria-label="t('playlists.editPlaylist', { name: playlist.name })"
                  :disabled="playlists.saving"
                  icon="mdi-pencil-outline"
                  variant="text"
                  @click.prevent.stop="openPlaylistDialog(playlist)"
                />
                <TooltipIconButton
                  :text="t('playlists.deletePlaylist', { name: playlist.name })"
                  :aria-label="t('playlists.deletePlaylist', { name: playlist.name })"
                  :disabled="playlists.saving"
                  icon="mdi-delete-outline"
                  variant="text"
                  @click.prevent.stop="confirmDeletePlaylist(playlist)"
                />
              </div>
            </template>
          </v-list-item>
        </v-list>
        <v-card-text v-else>
          <EmptyCatalogState :title="t('playlists.emptyPlaylistsTitle')" :description="t('playlists.emptyPlaylistsDescription')" icon="mdi-playlist-music-outline" />
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>

  <v-dialog v-model="folderDialog" max-width="460">
    <v-card :title="t('playlists.editFolderTitle')" prepend-icon="mdi-folder-edit-outline">
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
          {{ t('settings.saveChanges') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="playlistDialog" max-width="560">
    <v-card :title="t('playlists.editPlaylistTitle')" prepend-icon="mdi-playlist-edit">
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
          {{ t('settings.saveChanges') }}
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
