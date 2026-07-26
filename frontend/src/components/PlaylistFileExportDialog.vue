<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError, apiRequest } from '@/api/client'
import { withLibraryRootScope } from '@/stores/libraryRootScope'
import type { PlaylistFileExportResult } from '@/types/playlistExport'

interface ExportLocation {
  id: number
  name: string
  path: string
  isDefault: boolean
}

interface ExportConfiguration {
  defaultFormat: 'm3u' | 'm3u8'
  defaultFilename: string
  formats: Array<'m3u' | 'm3u8'>
  defaultLocationId: number | null
  locations: ExportLocation[]
  trackCount: number
}

const props = defineProps<{
  playlistId: number
  modelValue: boolean
}>()
const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [result: PlaylistFileExportResult]
}>()
const { t } = useI18n()
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const conflict = ref(false)
const overwrite = ref(false)
const configuration = ref<ExportConfiguration | null>(null)
const locationId = ref<number | null>(null)
const format = ref<'m3u' | 'm3u8'>('m3u8')
const filename = ref('')
const dialog = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})
const formatItems = [
  { title: 'M3U8', value: 'm3u8' },
  { title: 'M3U', value: 'm3u' },
]
const locationItems = computed(() => configuration.value?.locations.map((location) => ({
  title: location.name,
  value: location.id,
})) ?? [])
const selectedLocation = computed(() => configuration.value?.locations
  .find((location) => location.id === locationId.value) ?? null)

watch(() => props.modelValue, (open) => {
  if (open) void loadConfiguration()
}, { immediate: true })

async function loadConfiguration() {
  loading.value = true
  error.value = null
  conflict.value = false
  overwrite.value = false

  try {
    configuration.value = await apiRequest<ExportConfiguration>(
      withLibraryRootScope(`/playlists/${props.playlistId}/file-export`),
    )
    format.value = configuration.value.defaultFormat
    filename.value = configuration.value.defaultFilename
    locationId.value = configuration.value.defaultLocationId
      ?? configuration.value.locations[0]?.id
      ?? null
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('playlists.fileExportLoadFailed')
  } finally {
    loading.value = false
  }
}

function changeFormat(value: 'm3u' | 'm3u8') {
  format.value = value
  const stem = filename.value.replace(/\.(m3u8|m3u)$/i, '')
  filename.value = `${stem}.${value}`
  resetConflict()
}

function resetConflict() {
  conflict.value = false
  overwrite.value = false
}

async function save() {
  if (locationId.value === null || !filename.value.trim()) return

  saving.value = true
  error.value = null
  try {
    const result = await apiRequest<PlaylistFileExportResult>(
      withLibraryRootScope(`/playlists/${props.playlistId}/file-export`),
      {
        method: 'POST',
        body: JSON.stringify({
          locationId: locationId.value,
          format: format.value,
          filename: filename.value.trim(),
          overwrite: overwrite.value,
        }),
      },
    )
    emit('saved', result)
    dialog.value = false
  } catch (cause) {
    if (cause instanceof ApiError && cause.status === 409) {
      conflict.value = true
      error.value = t('playlists.fileExportExists')
    } else {
      error.value = cause instanceof Error ? cause.message : t('playlists.fileExportSaveFailed')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog v-model="dialog" max-width="660">
    <v-card prepend-icon="mdi-file-music-outline" :title="t('playlists.fileExportTitle')">
      <v-card-text>
        <p class="text-body-2 text-medium-emphasis mb-4">
          {{ t('playlists.fileExportDescription') }}
        </p>
        <v-alert v-if="error" class="mb-4" type="error" variant="tonal">
          {{ error }}
        </v-alert>
        <v-skeleton-loader v-if="loading" type="list-item-two-line, text, text" />
        <template v-else-if="configuration">
          <v-alert
            v-if="!configuration.locations.length"
            icon="mdi-folder-alert-outline"
            type="warning"
            variant="tonal"
          >
            <div>{{ t('playlists.fileExportNoLocations') }}</div>
            <v-btn
              class="mt-3"
              prepend-icon="mdi-cog-outline"
              :to="{ name: 'settings', query: { tab: 'playlists' } }"
              variant="tonal"
            >
              {{ t('playlists.fileExportOpenSettings') }}
            </v-btn>
          </v-alert>
          <template v-else>
            <v-select
              v-model="locationId"
              :items="locationItems"
              :label="t('playlists.fileExportLocation')"
              @update:model-value="resetConflict"
            />
            <v-alert
              v-if="selectedLocation"
              class="mb-4"
              density="compact"
              icon="mdi-folder-music-outline"
              type="info"
              variant="tonal"
            >
              {{ selectedLocation.path }}
            </v-alert>
            <v-select
              :items="formatItems"
              :label="t('albums.playlistFormat')"
              :model-value="format"
              @update:model-value="changeFormat"
            />
            <v-text-field
              v-model="filename"
              autofocus
              :label="t('albums.playlistFilename')"
              maxlength="255"
              @update:model-value="resetConflict"
            />
            <div class="text-caption text-medium-emphasis">
              {{ t('playlists.fileExportTrackCount', { count: configuration.trackCount }) }}
              ·
              {{ format === 'm3u8' ? t('albums.playlistM3u8Hint') : t('albums.playlistM3uHint') }}
            </div>
            <v-checkbox
              v-if="conflict"
              v-model="overwrite"
              class="mt-2"
              color="warning"
              density="compact"
              hide-details
              :label="t('albums.playlistOverwrite')"
            />
          </template>
        </template>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn :disabled="saving" @click="dialog = false">
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn
          v-if="configuration?.locations.length"
          color="primary"
          :disabled="loading || locationId === null || !filename.trim() || (conflict && !overwrite)"
          :loading="saving"
          variant="flat"
          @click="save"
        >
          {{ t('albums.playlistSave') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
