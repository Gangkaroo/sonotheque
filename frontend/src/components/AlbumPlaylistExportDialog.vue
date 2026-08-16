<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError, apiRequest } from '@/api/client'

interface ExportConfiguration {
  defaultFormat: 'm3u' | 'm3u8'
  defaultFilename: string
  formats: Array<'m3u' | 'm3u8'>
  directory: {
    libraryRoot: string | null
    relativePath: string
  }
  locations: Array<{
    id: number
    name: string
    path: string
    isDefault: boolean
  }>
}

interface ExportResult {
  format: 'm3u' | 'm3u8'
  filename: string
  trackCount: number
  sizeBytes: number
  relativePath: string | null
}

const props = defineProps<{
  albumId: number
  modelValue: boolean
}>()
const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [result: ExportResult]
}>()
const { t } = useI18n()
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const conflict = ref(false)
const overwrite = ref(false)
const configuration = ref<ExportConfiguration | null>(null)
const format = ref<'m3u' | 'm3u8'>('m3u8')
const filename = ref('')
const destinationValue = ref<string>('album')
const dialog = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})
const formatItems = computed(() => [
  { title: 'M3U8', value: 'm3u8' },
  { title: 'M3U', value: 'm3u' },
])
const destinationItems = computed(() => [
  { title: t('albums.playlistAlbumFolder'), value: 'album' },
  ...(configuration.value?.locations.map((location) => ({
    title: location.name,
    value: `location:${location.id}`,
  })) ?? []),
])
const destination = computed(() => {
  if (!configuration.value) return ''

  if (destinationValue.value !== 'album') {
    const locationId = Number(destinationValue.value.replace('location:', ''))
    return configuration.value.locations.find((location) => location.id === locationId)?.path ?? ''
  }

  return [
    configuration.value.directory.libraryRoot,
    configuration.value.directory.relativePath,
  ].filter(Boolean).join(' / ')
})

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
      `/albums/${props.albumId}/playlist-export`,
    )
    format.value = configuration.value.defaultFormat
    filename.value = configuration.value.defaultFilename
    destinationValue.value = 'album'
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('albums.playlistLoadFailed')
  } finally {
    loading.value = false
  }
}

function changeFormat(value: 'm3u' | 'm3u8') {
  format.value = value
  const stem = filename.value.replace(/\.(m3u8|m3u)$/i, '')
  filename.value = `${stem}.${value}`
  conflict.value = false
  overwrite.value = false
}

function resetConflict() {
  conflict.value = false
  overwrite.value = false
}

async function save() {
  if (!filename.value.trim()) return

  saving.value = true
  error.value = null
  try {
    const result = await apiRequest<ExportResult>(
      `/albums/${props.albumId}/playlist-export`,
      {
        method: 'POST',
        body: JSON.stringify({
          format: format.value,
          filename: filename.value.trim(),
          overwrite: overwrite.value,
          locationId: destinationValue.value === 'album'
            ? null
            : Number(destinationValue.value.replace('location:', '')),
        }),
      },
    )
    emit('saved', result)
    dialog.value = false
  } catch (cause) {
    if (cause instanceof ApiError && cause.status === 409) {
      conflict.value = true
      error.value = t('albums.playlistExists')
    } else {
      error.value = cause instanceof Error ? cause.message : t('albums.playlistSaveFailed')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog v-model="dialog" max-width="620">
    <v-card prepend-icon="mdi-file-music-outline" :title="t('albums.exportPlaylistTitle')">
      <v-card-text>
        <p class="text-body-2 text-medium-emphasis mb-4">
          {{ t('albums.exportPlaylistDescription') }}
        </p>
        <v-alert v-if="error" class="mb-4" type="error" variant="tonal">
          {{ error }}
        </v-alert>
        <v-skeleton-loader v-if="loading" type="list-item-two-line, text, text" />
        <template v-else-if="configuration">
          <v-select
            v-if="configuration.locations.length"
            v-model="destinationValue"
            :items="destinationItems"
            :label="t('albums.playlistDestination')"
            @update:model-value="resetConflict"
          />
          <v-alert
            class="mb-4"
            icon="mdi-folder-music-outline"
            type="info"
            variant="tonal"
          >
            <div class="text-caption">
              {{ destinationValue === 'album'
                ? t('albums.playlistAlbumFolder')
                : t('albums.playlistConfiguredFolder') }}
            </div>
            <div class="font-weight-medium">{{ destination }}</div>
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
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn :disabled="saving" @click="dialog = false">
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          :disabled="loading || !configuration || !filename.trim() || (conflict && !overwrite)"
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
