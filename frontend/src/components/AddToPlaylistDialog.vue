<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

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
const playlists = usePlaylistsStore()
const selectedPlaylistId = ref<number | null>(null)
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
const title = computed(() => props.tracks.length === 1
  ? t('playlists.addTrackTitle')
  : t('playlists.addTracksTitle', { count: props.tracks.length }))
const description = computed(() => props.tracks.length === 1
  ? props.tracks[0]?.title
  : t('playlists.addTracksDescription', { count: props.tracks.length }))
const canSubmit = computed(() => selectedPlaylistId.value !== null && props.tracks.length > 0 && !playlists.saving)

async function addToPlaylist() {
  if (!canSubmit.value || selectedPlaylistId.value === null) return

  await playlists.addTracks(selectedPlaylistId.value, props.tracks.map((track) => track.id))
  const playlist = playlists.playlists.find((item) => item.id === selectedPlaylistId.value)
  successMessage.value = t('playlists.addedToPlaylist', { name: playlist?.name ?? t('playlists.selectedPlaylist') })
  successVisible.value = true
  dialogOpen.value = false
}

watch(dialogOpen, (open) => {
  if (!open) return

  selectedPlaylistId.value = null
  successMessage.value = ''
  successVisible.value = false
  void playlists.loadAll()
})
</script>

<template>
  <v-dialog v-model="dialogOpen" max-width="520">
    <v-card rounded="xl">
      <v-card-title>{{ title }}</v-card-title>
      <v-card-subtitle v-if="description">{{ description }}</v-card-subtitle>
      <v-card-text>
        <v-alert v-if="playlists.error" class="mb-4" type="error" variant="tonal">
          {{ playlists.error }}
        </v-alert>

        <v-skeleton-loader v-if="playlists.loading" type="list-item-two-line@3" />
        <template v-else-if="playlists.playlists.length">
          <v-select
            v-model="selectedPlaylistId"
            :disabled="playlists.saving"
            :items="playlistOptions"
            :label="t('playlists.selectPlaylist')"
            variant="outlined"
          />
        </template>
        <v-alert v-else type="info" variant="tonal">
          {{ t('playlists.noPlaylistsForAdd') }}
        </v-alert>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="dialogOpen = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="primary" :disabled="!canSubmit" :loading="playlists.saving" variant="flat" @click="addToPlaylist">
          {{ t('playlists.addToPlaylist') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-snackbar v-model="successVisible" color="primary" timeout="2500">
    {{ successMessage }}
  </v-snackbar>
</template>
