<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  initialPath: { type: String, default: '' },
  title: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'select'])
const { t } = useI18n()
const loading = ref(false)
const error = ref(/** @type {string | null} */ (null))
const listing = ref(/** @type {{
 * path: string | null,
 * parent: string | null,
 * directories: Array<{name: string, path: string}>,
 * volumes: Array<{name: string, path: string}>
 * } | null} */ (null))
const entries = computed(() => listing.value?.path ? listing.value.directories : listing.value?.volumes ?? [])

watch(() => props.modelValue, (open) => {
  if (open) browse(props.initialPath || null)
})

/** @param {string | null} path */
async function browse(path) {
  loading.value = true
  error.value = null

  try {
    const query = path ? `?path=${encodeURIComponent(path)}` : ''
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
    <v-card :title="title || t('settings.folderBrowserTitle')" prepend-icon="mdi-folder-search-outline">
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
        <v-list v-else border rounded="lg" lines="one" max-height="420" class="overflow-y-auto">
          <v-list-item
            v-for="entry in entries"
            :key="entry.path"
            :title="entry.name"
            :subtitle="entry.path"
            :prepend-icon="listing?.path ? 'mdi-folder-outline' : 'mdi-harddisk'"
            @click="browse(entry.path)"
          >
            <template #append><v-icon icon="mdi-chevron-right" /></template>
          </v-list-item>
          <v-list-item v-if="!loading && !entries.length" :title="t('settings.folderBrowserEmpty')" />
        </v-list>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="close">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="primary" :disabled="!listing?.path" variant="flat" @click="selectCurrent">
          {{ t('settings.folderBrowserSelect') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
