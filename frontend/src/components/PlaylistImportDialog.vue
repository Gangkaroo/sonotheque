<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import FolderBrowserDialog from '@/components/FolderBrowserDialog.vue'
import type { PlaylistImportResult } from '@/stores/playlists'
import { usePlaylistsStore } from '@/stores/playlists'

const props = defineProps<{
  modelValue: boolean
}>()
const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  imported: [result: PlaylistImportResult]
}>()
const { t } = useI18n()
const playlists = usePlaylistsStore()
const fileBrowser = ref(false)
const selectedPath = ref('')
const name = ref('')
const folderId = ref<number | null>(null)
const initialDirectory = ref('')
const folderOptions = computed(() => [
  { title: t('playlists.noFolder'), value: null },
  ...playlists.folders.map((folder) => ({ title: folder.name, value: folder.id })),
])
const canImport = computed(() => (
  selectedPath.value.length > 0
  && name.value.trim().length > 0
  && !playlists.saving
))

watch(() => props.modelValue, (open) => {
  if (!open) return

  selectedPath.value = ''
  name.value = ''
  folderId.value = null
  initialDirectory.value = window.sessionStorage.getItem('sonotheque:playlist-import-directory') ?? ''
})

function selectFile(path: string) {
  selectedPath.value = path
  initialDirectory.value = path.replace(/[\\/][^\\/]+$/, '')
  window.sessionStorage.setItem('sonotheque:playlist-import-directory', initialDirectory.value)
  name.value = path
    .split(/[\\/]/)
    .pop()
    ?.replace(/\.m3u8?$/i, '') ?? ''
}

async function importPlaylist() {
  if (!canImport.value) return

  const result = await playlists.importPlaylist({
    path: selectedPath.value,
    name: name.value.trim(),
    folderId: folderId.value,
  })
  emit('imported', result)
  close()
}

function close() {
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="620" @update:model-value="emit('update:modelValue', $event)">
    <v-card :title="t('playlists.importTitle')" prepend-icon="mdi-playlist-plus">
      <v-card-text>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('playlists.importDescription') }}</p>
        <v-alert v-if="playlists.error" class="mb-4" type="error" variant="tonal">
          {{ playlists.error }}
        </v-alert>
        <v-text-field
          :model-value="selectedPath"
          :label="t('playlists.importFile')"
          prepend-inner-icon="mdi-file-music-outline"
          readonly
          variant="outlined"
        >
          <template #append-inner>
            <v-btn
              :aria-label="t('playlists.chooseImportFile')"
              icon="mdi-folder-open-outline"
              size="small"
              variant="text"
              @click="fileBrowser = true"
            />
          </template>
        </v-text-field>
        <v-text-field
          v-model="name"
          :disabled="playlists.saving"
          :label="t('playlists.playlistName')"
          variant="outlined"
        />
        <v-select
          v-model="folderId"
          :disabled="playlists.saving"
          :items="folderOptions"
          :label="t('playlists.folder')"
          variant="outlined"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="close">{{ t('settings.cancel') }}</v-btn>
        <v-btn
          color="primary"
          :disabled="!canImport"
          :loading="playlists.saving"
          variant="flat"
          @click="importPlaylist"
        >
          {{ t('playlists.importAction') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <FolderBrowserDialog
    v-model="fileBrowser"
    :initial-path="initialDirectory"
    playlist-files
    :title="t('playlists.chooseImportFile')"
    @select="selectFile"
  />
</template>
