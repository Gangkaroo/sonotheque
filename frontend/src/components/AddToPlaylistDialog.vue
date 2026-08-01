<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import type { Track } from '@/stores/catalog'
import { usePlaylistsStore } from '@/stores/playlists'

const props = defineProps<{
  modelValue: boolean
  tracks: Track[]
}>()
const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const { t } = useI18n()
const router = useRouter()
const playlists = usePlaylistsStore()
const selectedPlaylistId = ref<number | null>(null)
const creatingPlaylist = ref(false)
const newPlaylistName = ref('')
const newPlaylistDescription = ref('')
const newPlaylistFolderId = ref<number | null>(null)
const submitting = ref(false)
const submitAction = ref<'add' | 'open' | null>(null)
const submitError = ref('')
const successMessage = ref('')
const successVisible = ref(false)
const dialogOpen = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})
const playlistOptions = computed(() => playlists.playlists.map((playlist) => ({
  title: playlist.folder ? `${playlist.folder.name} / ${playlist.name}` : playlist.name,
  value: playlist.id,
})))
const folderOptions = computed(() => [
  { title: t('playlists.noFolder'), value: null },
  ...playlists.folders.map((folder) => ({ title: folder.name, value: folder.id })),
])
const title = computed(() => props.tracks.length === 1
  ? t('playlists.addTrackTitle')
  : t('playlists.addTracksTitle', { count: props.tracks.length }))
const description = computed(() => props.tracks.length === 1
  ? props.tracks[0]?.title
  : t('playlists.addTracksDescription', { count: props.tracks.length }))
const canSubmit = computed(() => {
  if (!props.tracks.length || playlists.saving || submitting.value) return false

  return creatingPlaylist.value
    ? newPlaylistName.value.trim().length > 0
    : selectedPlaylistId.value !== null
})
const addButtonText = computed(() => creatingPlaylist.value
  ? t('playlists.createAndAdd')
  : t('playlists.addToPlaylist'))
const addAndOpenButtonText = computed(() => creatingPlaylist.value
  ? t('playlists.createAddAndOpen')
  : t('playlists.addAndOpenPlaylist'))

async function submit(openAfterAdding = false) {
  if (!canSubmit.value) return

  submitting.value = true
  submitAction.value = openAfterAdding ? 'open' : 'add'
  submitError.value = ''
  try {
    const trackIds = props.tracks.map((track) => track.id)
    let firstAddedItemId: number | null = null
    const playlist = creatingPlaylist.value
      ? await playlists.createPlaylist({
          name: newPlaylistName.value.trim(),
          description: newPlaylistDescription.value.trim() || null,
          folderId: newPlaylistFolderId.value,
          trackIds,
        })
      : playlists.playlists.find((item) => item.id === selectedPlaylistId.value)

    if (!playlist) return
    if (!creatingPlaylist.value) {
      const items = await playlists.addTracks(playlist.id, trackIds)
      firstAddedItemId = items[0]?.id ?? null
    }

    successMessage.value = t('playlists.addedToPlaylist', { name: playlist.name })
    successVisible.value = true
    dialogOpen.value = false
    if (openAfterAdding) {
      await router.push({
        name: 'playlist-detail',
        params: { id: playlist.id },
        query: firstAddedItemId === null ? {} : { playlistItem: firstAddedItemId },
      })
    }
  } catch (cause) {
    submitError.value = cause instanceof Error ? cause.message : t('playlists.addToPlaylistFailed')
  } finally {
    submitting.value = false
    submitAction.value = null
  }
}

function showCreatePlaylist() {
  creatingPlaylist.value = true
  selectedPlaylistId.value = null
  submitError.value = ''
}

function showExistingPlaylists() {
  creatingPlaylist.value = false
  submitError.value = ''
}

watch(dialogOpen, async (open) => {
  if (!open) return

  selectedPlaylistId.value = null
  creatingPlaylist.value = false
  newPlaylistName.value = ''
  newPlaylistDescription.value = ''
  newPlaylistFolderId.value = null
  submitError.value = ''
  successMessage.value = ''
  successVisible.value = false
  await playlists.loadAll()
  if (dialogOpen.value && !playlists.playlists.length) creatingPlaylist.value = true
})
</script>

<template>
  <v-dialog v-model="dialogOpen" max-width="520">
    <v-card rounded="xl">
      <v-card-title>{{ title }}</v-card-title>
      <v-card-subtitle v-if="description">{{ description }}</v-card-subtitle>
      <v-card-text>
        <v-alert v-if="playlists.error || submitError" class="mb-4" type="error" variant="tonal">
          {{ submitError || playlists.error }}
        </v-alert>

        <v-skeleton-loader v-if="playlists.loading" type="list-item-two-line@3" />
        <template v-else-if="creatingPlaylist">
          <v-text-field
            v-model="newPlaylistName"
            autofocus
            :disabled="playlists.saving || submitting"
            :label="t('playlists.playlistName')"
            variant="outlined"
            @keydown.enter.prevent="void submit(false)"
          />
          <v-select
            v-model="newPlaylistFolderId"
            :disabled="playlists.saving || submitting"
            :items="folderOptions"
            :label="t('playlists.folder')"
            variant="outlined"
          />
          <v-textarea
            v-model="newPlaylistDescription"
            :disabled="playlists.saving || submitting"
            :label="t('playlists.descriptionField')"
            rows="2"
            variant="outlined"
          />
          <v-btn
            v-if="playlists.playlists.length"
            prepend-icon="mdi-arrow-left"
            variant="text"
            @click="showExistingPlaylists"
          >
            {{ t('playlists.chooseExistingPlaylist') }}
          </v-btn>
        </template>
        <template v-else>
          <v-autocomplete
            v-model="selectedPlaylistId"
            auto-select-first
            clearable
            :disabled="playlists.saving || submitting"
            :items="playlistOptions"
            :label="t('playlists.selectPlaylist')"
            :no-data-text="t('playlists.noMatchingPlaylists')"
            variant="outlined"
          />
          <v-btn prepend-icon="mdi-plus" variant="tonal" @click="showCreatePlaylist">
            {{ t('playlists.createNewPlaylist') }}
          </v-btn>
        </template>
      </v-card-text>
      <v-card-actions class="flex-wrap ga-2">
        <v-spacer />
        <v-btn variant="text" @click="dialogOpen = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn
          color="primary"
          :disabled="!canSubmit"
          :loading="submitAction === 'open'"
          prepend-icon="mdi-arrow-right-circle-outline"
          variant="tonal"
          @click="void submit(true)"
        >
          {{ addAndOpenButtonText }}
        </v-btn>
        <v-btn
          color="primary"
          :disabled="!canSubmit"
          :loading="submitAction === 'add'"
          variant="flat"
          @click="void submit(false)"
        >
          {{ addButtonText }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-snackbar v-model="successVisible" color="primary" timeout="2500">
    {{ successMessage }}
  </v-snackbar>
</template>
