<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import { formatDateTime } from '@/utils/formatters'

type SystemHealthStatus = 'ok' | 'warning' | 'error' | 'unknown'

interface HealthStatus {
  status: SystemHealthStatus
  checkedAt: string
  app: {
    environment: string
    url: string
    lanEnabled: boolean
    localProxyEnabled: boolean
    queueConnection: string
    cacheStore: string
  }
  database: {
    status: SystemHealthStatus
    connection: string
    message: string | null
  }
  queue: {
    status: SystemHealthStatus
    connection: string
    pending: number | null
    reserved: number | null
    delayed: number | null
    failed: number | null
    latestFailed: FailedQueueJob[]
    message: string | null
  }
  scheduler: {
    status: SystemHealthStatus
    observable: boolean
    message: string
  }
  storage: StorageCheck[]
  libraryRoots: LibraryRootCheck[]
  scans: {
    active: number
    latestFailed: FailedScan[]
  }
}

interface StorageCheck {
  name: string
  path: string
  exists: boolean
  writable: boolean
  status: HealthStatus['status']
}

interface LibraryRootCheck {
  id: number
  name: string
  path: string
  enabled: boolean
  exists: boolean
  readable: boolean
  writable: boolean
  albums: number
  tracks: number
  lastScannedAt: string | null
  status: HealthStatus['status']
}

interface FailedQueueJob {
  id: number
  queue: string
  failedAt: string
  message: string
}

interface FailedScan {
  id: number
  libraryRootId: number
  createdAt: string | null
  startedAt: string | null
  finishedAt: string | null
  message: string | null
}

const { t, locale } = useI18n()
const health = ref<HealthStatus | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const rootNames = computed(() => new Map(
  health.value?.libraryRoots.map((root) => [root.id, root.name]) ?? [],
))

onMounted(loadHealth)

async function loadHealth() {
  loading.value = true
  error.value = null
  try {
    health.value = await apiRequest<HealthStatus>('/settings/system-health')
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('settings.systemLoadError')
  } finally {
    loading.value = false
  }
}

function statusColor(status: SystemHealthStatus) {
  return {
    ok: 'success',
    warning: 'warning',
    error: 'error',
    unknown: 'info',
  }[status]
}

function boolText(value: boolean) {
  return value ? t('settings.yes') : t('settings.no')
}

function formatDate(value: string | null | undefined) {
  return formatDateTime(value, locale.value, t('settings.notAvailable'))
}

function rootName(id: number) {
  return rootNames.value.get(id) ?? t('settings.unknownRoot')
}
</script>

<template>
  <v-card border rounded="xl" class="mt-6">
    <v-card-item class="pa-6 pb-2">
      <v-card-title>{{ t('settings.systemHealth') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.systemHealthDescription') }}</v-card-subtitle>
      <template #append>
        <v-btn
          :aria-label="t('settings.refreshSystemHealth')"
          icon="mdi-refresh"
          :loading="loading"
          variant="text"
          @click="loadHealth"
        />
      </template>
    </v-card-item>

    <v-card-text class="pa-6 pt-4">
      <v-alert v-if="error" type="error" variant="tonal" class="mb-4">
        {{ error }}
      </v-alert>

      <v-skeleton-loader v-if="loading && !health" type="article" />

      <template v-else-if="health">
        <div class="d-flex flex-wrap align-center ga-3 mb-5">
          <v-chip :color="statusColor(health.status)" variant="tonal">
            {{ t(`settings.systemStatuses.${health.status}`) }}
          </v-chip>
          <span class="text-body-2 text-medium-emphasis">
            {{ t('settings.lastChecked', { date: formatDate(health.checkedAt) }) }}
          </span>
        </div>

        <v-row>
          <v-col cols="12" md="4">
            <v-card variant="tonal" rounded="lg">
              <v-card-item prepend-icon="mdi-database-check-outline">
                <v-card-title class="text-subtitle-1">{{ t('settings.database') }}</v-card-title>
                <template #append>
                  <v-chip :color="statusColor(health.database.status)" size="small" variant="flat">
                    {{ t(`settings.systemStatuses.${health.database.status}`) }}
                  </v-chip>
                </template>
              </v-card-item>
              <v-card-text>
                <div>{{ t('settings.connection') }}: {{ health.database.connection }}</div>
                <div v-if="health.database.message" class="text-error mt-1">{{ health.database.message }}</div>
              </v-card-text>
            </v-card>
          </v-col>

          <v-col cols="12" md="4">
            <v-card variant="tonal" rounded="lg">
              <v-card-item prepend-icon="mdi-tray-full">
                <v-card-title class="text-subtitle-1">{{ t('settings.queue') }}</v-card-title>
                <template #append>
                  <v-chip :color="statusColor(health.queue.status)" size="small" variant="flat">
                    {{ t(`settings.systemStatuses.${health.queue.status}`) }}
                  </v-chip>
                </template>
              </v-card-item>
              <v-card-text>
                <div>{{ t('settings.connection') }}: {{ health.queue.connection }}</div>
                <div>
                  {{ t('settings.queueSummary', {
                    pending: health.queue.pending ?? 0,
                    reserved: health.queue.reserved ?? 0,
                    delayed: health.queue.delayed ?? 0,
                    failed: health.queue.failed ?? 0,
                  }) }}
                </div>
                <div v-if="health.queue.message" class="text-medium-emphasis mt-1">{{ health.queue.message }}</div>
              </v-card-text>
            </v-card>
          </v-col>

          <v-col cols="12" md="4">
            <v-card variant="tonal" rounded="lg">
              <v-card-item prepend-icon="mdi-server-outline">
                <v-card-title class="text-subtitle-1">{{ t('settings.runtime') }}</v-card-title>
              </v-card-item>
              <v-card-text>
                <div>{{ t('settings.environment') }}: {{ health.app.environment }}</div>
                <div>{{ t('settings.lanMode') }}: {{ boolText(health.app.lanEnabled) }}</div>
                <div>{{ t('settings.localProxy') }}: {{ boolText(health.app.localProxyEnabled) }}</div>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>

        <v-alert class="mt-5" type="info" variant="tonal">
          {{ health.scheduler.message }}
        </v-alert>

        <v-row class="mt-2">
          <v-col cols="12" lg="6">
            <v-card border rounded="lg">
              <v-card-title>{{ t('settings.storage') }}</v-card-title>
              <v-table density="comfortable">
                <thead>
                  <tr>
                    <th>{{ t('settings.name') }}</th>
                    <th>{{ t('settings.path') }}</th>
                    <th>{{ t('settings.status') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="entry in health.storage" :key="entry.name">
                    <td>{{ entry.name }}</td>
                    <td class="text-truncate system-path-cell">{{ entry.path }}</td>
                    <td>
                      <v-chip :color="statusColor(entry.status)" size="small" variant="tonal">
                        {{ entry.exists && entry.writable ? t('settings.writable') : t('settings.notWritable') }}
                      </v-chip>
                    </td>
                  </tr>
                </tbody>
              </v-table>
            </v-card>
          </v-col>

          <v-col cols="12" lg="6">
            <v-card border rounded="lg">
              <v-card-title>{{ t('settings.configuredRoots') }}</v-card-title>
              <v-table density="comfortable">
                <thead>
                  <tr>
                    <th>{{ t('settings.name') }}</th>
                    <th>{{ t('settings.items') }}</th>
                    <th>{{ t('settings.status') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="root in health.libraryRoots" :key="root.id">
                    <td>
                      <div class="font-weight-medium">{{ root.name }}</div>
                      <div class="text-caption text-medium-emphasis text-truncate system-path-cell">{{ root.path }}</div>
                    </td>
                    <td>{{ t('settings.rootItemCounts', { albums: root.albums, tracks: root.tracks }) }}</td>
                    <td>
                      <v-chip :color="statusColor(root.status)" size="small" variant="tonal">
                        {{ root.readable ? t('settings.readable') : t('settings.notReadable') }}
                      </v-chip>
                    </td>
                  </tr>
                  <tr v-if="!health.libraryRoots.length">
                    <td colspan="3" class="text-medium-emphasis">{{ t('settings.noRoots') }}</td>
                  </tr>
                </tbody>
              </v-table>
            </v-card>
          </v-col>
        </v-row>

        <v-row class="mt-2">
          <v-col cols="12" lg="6">
            <v-card border rounded="lg">
              <v-card-title>{{ t('settings.scanRuntime') }}</v-card-title>
              <v-card-text>
                <div>{{ t('settings.activeScans', { count: health.scans.active }) }}</div>
                <v-list v-if="health.scans.latestFailed.length" class="mt-2" density="compact">
                  <v-list-subheader>{{ t('settings.latestFailedScans') }}</v-list-subheader>
                  <v-list-item v-for="scan in health.scans.latestFailed" :key="scan.id" prepend-icon="mdi-alert-circle-outline">
                    <v-list-item-title>{{ rootName(scan.libraryRootId) }}</v-list-item-title>
                    <v-list-item-subtitle>{{ formatDate(scan.finishedAt ?? scan.startedAt ?? scan.createdAt) }}</v-list-item-subtitle>
                    <v-list-item-subtitle v-if="scan.message" class="text-error">{{ scan.message }}</v-list-item-subtitle>
                  </v-list-item>
                </v-list>
              </v-card-text>
            </v-card>
          </v-col>

          <v-col cols="12" lg="6">
            <v-card border rounded="lg">
              <v-card-title>{{ t('settings.failedQueueJobs') }}</v-card-title>
              <v-card-text>
                <v-list v-if="health.queue.latestFailed.length" density="compact">
                  <v-list-item v-for="job in health.queue.latestFailed" :key="job.id" prepend-icon="mdi-alert-outline">
                    <v-list-item-title>{{ job.queue }} · {{ formatDate(job.failedAt) }}</v-list-item-title>
                    <v-list-item-subtitle class="text-error">{{ job.message }}</v-list-item-subtitle>
                  </v-list-item>
                </v-list>
                <div v-else class="text-medium-emphasis">{{ t('settings.noFailedQueueJobs') }}</div>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>
      </template>
    </v-card-text>
  </v-card>
</template>

<style scoped>
.system-path-cell {
  max-width: 20rem;
}
</style>
