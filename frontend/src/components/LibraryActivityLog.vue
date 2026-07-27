<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import type { LibraryRoot } from '@/stores/libraryRoots'
import { formatDateTime } from '@/utils/formatters'

interface ActivityEntry {
  id: number
  libraryRootId: number | null
  libraryRootName: string | null
  scanRunId: number | null
  source: 'watcher' | 'scan'
  severity: 'info' | 'warning' | 'error'
  code: string
  message: string
  path: string | null
  count: number
  createdAt: string
}

interface ActivityPage {
  items: ActivityEntry[]
  page: number
  lastPage: number
  total: number
}

const props = defineProps<{ roots: LibraryRoot[] }>()
const emit = defineEmits<{ scan: [scanId: number] }>()
const { t, locale } = useI18n()
const entries = ref<ActivityEntry[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const rootFilter = ref<number | null>(null)
const severityFilter = ref<string | null>(null)
const sourceFilter = ref<string | null>(null)
const rootOptions = computed(() => [
  { title: t('settings.activityAllRoots'), value: null },
  ...props.roots.map((root) => ({ title: root.name, value: root.id })),
])
const severityOptions = computed(() => [
  { title: t('settings.activityAllSeverities'), value: null },
  { title: t('settings.activitySeverities.info'), value: 'info' },
  { title: t('settings.activitySeverities.warning'), value: 'warning' },
  { title: t('settings.activitySeverities.error'), value: 'error' },
])
const sourceOptions = computed(() => [
  { title: t('settings.activityAllSources'), value: null },
  { title: t('settings.activitySources.watcher'), value: 'watcher' },
  { title: t('settings.activitySources.scan'), value: 'scan' },
])

onMounted(load)
watch([rootFilter, severityFilter, sourceFilter], () => {
  page.value = 1
  void load()
})

async function load() {
  loading.value = true
  error.value = null
  const parameters = new URLSearchParams({ page: String(page.value) })
  if (rootFilter.value !== null) parameters.set('libraryRoot', String(rootFilter.value))
  if (severityFilter.value) parameters.set('severity', severityFilter.value)
  if (sourceFilter.value) parameters.set('source', sourceFilter.value)

  try {
    const result = await apiRequest<ActivityPage>(`/library-activity?${parameters}`)
    entries.value = result.items
    page.value = result.page
    lastPage.value = result.lastPage
    total.value = result.total
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('settings.activityLoadError')
  } finally {
    loading.value = false
  }
}

function changePage(value: number) {
  page.value = value
  void load()
}

function severityColor(severity: ActivityEntry['severity']) {
  return { info: 'info', warning: 'warning', error: 'error' }[severity]
}

function severityIcon(severity: ActivityEntry['severity']) {
  return {
    info: 'mdi-information-outline',
    warning: 'mdi-alert-outline',
    error: 'mdi-alert-circle-outline',
  }[severity]
}
</script>

<template>
  <v-card border rounded="xl" class="mt-6">
    <v-card-item class="pa-6 pb-2">
      <v-card-title>{{ t('settings.libraryActivity') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.libraryActivityDescription') }}</v-card-subtitle>
      <template #append>
        <v-btn
          :aria-label="t('settings.refreshLibraryActivity')"
          icon="mdi-refresh"
          variant="text"
          :loading="loading"
          @click="load"
        />
      </template>
    </v-card-item>
    <v-card-text class="pa-6 pt-4">
      <div class="activity-filters mb-4">
        <v-select
          v-model="rootFilter"
          :items="rootOptions"
          :label="t('settings.activityRootFilter')"
          hide-details
        />
        <v-select
          v-model="severityFilter"
          :items="severityOptions"
          :label="t('settings.activitySeverityFilter')"
          hide-details
        />
        <v-select
          v-model="sourceFilter"
          :items="sourceOptions"
          :label="t('settings.activitySourceFilter')"
          hide-details
        />
      </div>

      <v-alert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</v-alert>
      <v-skeleton-loader v-if="loading && !entries.length" type="list-item-three-line@3" />
      <v-list v-else-if="entries.length" lines="three" density="compact">
        <v-list-item v-for="entry in entries" :key="entry.id" :prepend-icon="severityIcon(entry.severity)">
          <v-list-item-title>{{ entry.message }}</v-list-item-title>
          <v-list-item-subtitle class="activity-meta">
            <v-chip :color="severityColor(entry.severity)" size="x-small" variant="tonal">
              {{ t(`settings.activitySeverities.${entry.severity}`) }}
            </v-chip>
            <span>{{ entry.libraryRootName ?? t('settings.unknownRoot') }}</span>
            <span>{{ t(`settings.activitySources.${entry.source}`) }}</span>
            <span>{{ formatDateTime(entry.createdAt, locale) }}</span>
            <span v-if="entry.count > 1">{{ t('settings.activityOccurrences', { count: entry.count }) }}</span>
          </v-list-item-subtitle>
          <v-list-item-subtitle v-if="entry.path" class="text-mono">
            {{ entry.path }}
          </v-list-item-subtitle>
          <template v-if="entry.scanRunId" #append>
            <v-btn
              prepend-icon="mdi-text-box-search-outline"
              size="small"
              variant="text"
              @click="emit('scan', entry.scanRunId)"
            >
              {{ t('settings.activityOpenScan', { id: entry.scanRunId }) }}
            </v-btn>
          </template>
        </v-list-item>
      </v-list>
      <v-empty-state
        v-else
        icon="mdi-text-box-search-outline"
        :headline="t('settings.noLibraryActivity')"
        :text="t('settings.noLibraryActivityDescription')"
      />
      <v-pagination
        v-if="lastPage > 1"
        class="mt-4"
        :length="lastPage"
        :model-value="page"
        :total-visible="7"
        @update:model-value="changePage"
      />
      <div v-if="total" class="text-caption text-medium-emphasis text-center mt-2">
        {{ t('settings.activityTotal', { count: total }) }}
      </div>
    </v-card-text>
  </v-card>
</template>

<style scoped>
.activity-filters {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.activity-meta {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 6px 12px;
}

.text-mono {
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
}

@media (max-width: 700px) {
  .activity-filters {
    grid-template-columns: 1fr;
  }
}
</style>
