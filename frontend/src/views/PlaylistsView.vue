<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import { usePlaylistsStore } from '@/stores/playlists'

const { t } = useI18n()
const playlists = usePlaylistsStore()
const folderName = ref('')
const playlistName = ref('')
const playlistDescription = ref('')
const playlistFolderId = ref<number | null>(null)
const folderOptions = computed(() => [
  { title: t('playlists.noFolder'), value: null },
  ...playlists.folders.map((folder) => ({ title: folder.name, value: folder.id })),
])
const canCreateFolder = computed(() => folderName.value.trim().length > 0 && !playlists.saving)
const canCreatePlaylist = computed(() => playlistName.value.trim().length > 0 && !playlists.saving)

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
              <v-btn
                :aria-label="t('playlists.deleteFolder', { name: folder.name })"
                :disabled="playlists.saving"
                icon="mdi-delete-outline"
                variant="text"
                @click="void playlists.deleteFolder(folder.id)"
              />
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
              <v-btn
                :aria-label="t('playlists.deletePlaylist', { name: playlist.name })"
                :disabled="playlists.saving"
                icon="mdi-delete-outline"
                variant="text"
                @click.prevent.stop="void playlists.deletePlaylist(playlist.id)"
              />
            </template>
          </v-list-item>
        </v-list>
        <v-card-text v-else>
          <EmptyCatalogState :title="t('playlists.emptyPlaylistsTitle')" :description="t('playlists.emptyPlaylistsDescription')" icon="mdi-playlist-music-outline" />
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>
</template>
