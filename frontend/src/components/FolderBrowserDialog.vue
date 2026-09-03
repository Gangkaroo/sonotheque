<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  initialPath: { type: String, default: '' },
  title: { type: String, default: '' },
  playlistFiles: { type: Boolean, default: false },
  systemBackupFiles: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'select'])
const { t } = useI18n()
const loading = ref(false)
const error = ref(/** @type {string | null} */ (null))
const listing = ref(/** @type {{
 * path: string | null,
 * parent: string | null,
 * directories: Array<{name: string, path: string}>,
 * files: Array<{name: string, path: string}>,
 * volumes: Array<{name: string, path: string}>
 * } | null} */ (null))
const entries = computed(() => {
  if (!listing.value?.path) {
    return (listing.value?.volumes ?? []).map((entry) => ({ ...entry, type: 'volume' }))
  }

  return [
    ...listing.value.directories.map((entry) => ({ ...entry, type: 'directory' })),
    ...((props.playlistFiles || props.systemBackupFiles)
      ? (listing.value.files ?? []).map((entry) => ({ ...entry, type: 'file' }))
      : []),
  ]
})
const folderListHeight = computed(() => Math.min(420, Math.max(64, entries.value.length * 64)))

watch(() => props.modelValue, (open) => {
  if (open) browse(props.initialPath || null)
})

/** @param {string | null} path */
async function browse(path) {
  loading.value = true
  error.value = null

  try {
    const parameters = new URLSearchParams()
    if (path) parameters.set('path', path)
    if (props.playlistFiles) parameters.set('playlistFiles', '1')
    if (props.systemBackupFiles) parameters.set('systemBackupFiles', '1')
    const query = parameters.size ? `?${parameters.toString()}` : ''
    listing.value = await apiRequest(`/folders${query}`)
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('settings.folderBrowserError')

    if (path) {
      listing.value = await apiRequest('/folders').catch(() => null)
    }
  } finally {
    loading.value = false
  }
}

/** @param {{path: string, type: string}} item */
function activate(item) {
  if (item.type !== 'file') {
    void browse(item.path)

    return
  }

  emit('select', item.path)
  close()
}

function close() {
  emit('update:modelValue', false)
}

function selectCurrent() {
  if (!listing.value?.path) return
  emit('select', listing.value.path)
  close()
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="760" @update:model-value="emit('update:modelValue', $event)">
    <v-card
      :title="title || t('settings.folderBrowserTitle')"
      :prepend-icon="playlistFiles ? 'mdi-file-music-outline' : systemBackupFiles ? 'mdi-backup-restore' : 'mdi-folder-search-outline'"
    >
      <v-card-text>
        <v-alert v-if="error" class="mb-4" type="error" variant="tonal">{{ error }}</v-alert>

        <div class="d-flex align-center ga-2 mb-4">
          <v-btn
            :aria-label="t('settings.folderBrowserUp')"
            :disabled="!listing?.path"
            icon="mdi-arrow-up"
            variant="tonal"
            @click="browse(listing?.parent ?? null)"
          />
          <v-text-field
            density="compact"
            hide-details
            :label="t('settings.folderBrowserLocation')"
            :model-value="listing?.path ?? t('settings.folderBrowserDrives')"
            readonly
          />
          <v-btn
            :aria-label="t('settings.folderBrowserRefresh')"
            icon="mdi-refresh"
            :loading="loading"
            variant="text"
            @click="browse(listing?.path ?? null)"
          />
        </div>

        <v-skeleton-loader v-if="loading && !listing" type="list-item@5" />
        <v-virtual-scroll
          v-else-if="entries.length"
          class="border rounded-lg"
          :height="folderListHeight"
          item-height="64"
          item-key="path"
          :items="entries"
        >
          <template #default="{ item }">
            <v-list-item
              :disabled="loading"
              :title="item.name"
              :subtitle="item.path"
              :prepend-icon="item.type === 'file' ? (systemBackupFiles ? 'mdi-archive-outline' : 'mdi-playlist-music-outline') : item.type === 'directory' ? 'mdi-folder-outline' : 'mdi-harddisk'"
              @click="activate(item)"
            >
              <template v-if="item.type !== 'file'" #append><v-icon icon="mdi-chevron-right" /></template>
            </v-list-item>
          </template>
        </v-virtual-scroll>
        <v-list v-else border rounded="lg" lines="one">
          <v-list-item :title="t('settings.folderBrowserEmpty')" />
        </v-list>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="close">{{ t('settings.cancel') }}</v-btn>
        <v-btn v-if="!playlistFiles && !systemBackupFiles" color="primary" :disabled="!listing?.path" variant="flat" @click="selectCurrent">
          {{ t('settings.folderBrowserSelect') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
