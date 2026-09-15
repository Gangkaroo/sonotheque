<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import FolderBrowserDialog from '@/components/FolderBrowserDialog.vue'
import { formatDateTime } from '@/utils/formatters'

interface BackupStatus {
  available: boolean
  operation: 'backup' | 'restore' | null
  status: string | null
  mode: string | null
  completedAt: string | null
  bundleName: string | null
  bytes: number | null
}

interface SystemBackupOperation {
  id: string
  type: 'backup' | 'restore'
  status: 'queued' | 'running' | 'completed' | 'failed'
  progress: number
  phase: string | null
  message: string | null
  archiveName: string | null
  createdAt: string
  startedAt: string | null
  finishedAt: string | null
  safetyBackupName?: string | null
}

interface SystemBackupInspection {
  path: string
  name: string
  createdAt: string | null
  mode: string | null
  database: string | null
  bytes: number
  modeMatches: boolean
  appKeyMatches: boolean
}

const props = defineProps<{
  backup: BackupStatus
  environment: string
}>()
const emit = defineEmits<{
  completed: []
}>()

const { locale, t } = useI18n()
const backupFolderDialog = ref(false)
const restoreFileDialog = ref(false)
const restoreConfirmDialog = ref(false)
const backupOperation = ref<SystemBackupOperation | null>(null)
const restoreArchive = ref<SystemBackupInspection | null>(null)
const backupActionError = ref<string | null>(null)
const preparingRestore = ref(false)
let operationPollTimer: ReturnType<typeof setTimeout> | null = null

onBeforeUnmount(stopOperationPolling)

function defaultBackupPath() {
  return props.environment === 'production' ? '/backups' : ''
}

function formatDate(value: string | null | undefined) {
  return formatDateTime(value, locale.value, t('settings.notAvailable'))
    ?? t('settings.notAvailable')
}

async function createBackup(destination: string) {
  backupActionError.value = null
  try {
    backupOperation.value = await apiRequest<SystemBackupOperation>('/settings/system-backups', {
      method: 'POST',
      body: JSON.stringify({ destination }),
    })
    scheduleOperationPolling()
  } catch (cause) {
    backupActionError.value = cause instanceof Error ? cause.message : t('settings.systemBackupStartFailed')
  }
}

async function selectRestoreArchive(path: string) {
  backupActionError.value = null
  preparingRestore.value = true
  try {
    restoreArchive.value = await apiRequest<SystemBackupInspection>('/settings/system-backups/inspect', {
      method: 'POST',
      body: JSON.stringify({ path }),
    })
    restoreConfirmDialog.value = true
  } catch (cause) {
    backupActionError.value = cause instanceof Error ? cause.message : t('settings.systemRestoreInspectFailed')
  } finally {
    preparingRestore.value = false
  }
}

async function restoreBackup() {
  if (!restoreArchive.value) return

  backupActionError.value = null
  try {
    backupOperation.value = await apiRequest<SystemBackupOperation>('/settings/system-backups/restore', {
      method: 'POST',
      body: JSON.stringify({ path: restoreArchive.value.path, confirmed: true }),
    })
    restoreConfirmDialog.value = false
    scheduleOperationPolling()
  } catch (cause) {
    backupActionError.value = cause instanceof Error ? cause.message : t('settings.systemRestoreStartFailed')
  }
}

function scheduleOperationPolling() {
  stopOperationPolling()
  if (!backupOperation.value || ['completed', 'failed'].includes(backupOperation.value.status)) return

  operationPollTimer = setTimeout(pollOperation, 3000)
}

async function pollOperation() {
  if (!backupOperation.value) return

  try {
    backupOperation.value = await apiRequest<SystemBackupOperation>(
      `/settings/system-backups/operations/${backupOperation.value.id}`,
    )
    if (backupOperation.value.status === 'completed') emit('completed')
  } catch {
    // Restore temporarily places the API in maintenance mode. Keep polling until it returns.
  }
  scheduleOperationPolling()
}

function stopOperationPolling() {
  if (operationPollTimer !== null) {
    clearTimeout(operationPollTimer)
    operationPollTimer = null
  }
}

function operationText(operation: SystemBackupOperation) {
  if (operation.status === 'failed') return operation.message ?? t('settings.systemBackupOperationFailed')
  if (operation.status === 'completed') {
    return operation.type === 'backup'
      ? t('settings.systemBackupCompleted', { name: operation.archiveName })
      : t('settings.systemRestoreCompleted', { name: operation.archiveName })
  }
  if (operation.status === 'queued') return t('settings.systemBackupQueued')

  return operation.phase
    ? t(`settings.systemBackupPhases.${operation.phase}`)
    : t('settings.systemBackupRunning')
}

function formatBytes(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`
  if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`

  return `${(bytes / 1024 ** 3).toFixed(1)} GB`
}
</script>

<template>
  <v-card class="mt-5" border rounded="lg">
    <v-card-item prepend-icon="mdi-backup-restore">
      <v-card-title class="text-subtitle-1">{{ t('settings.systemBackup') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.systemBackupDescription') }}</v-card-subtitle>
    </v-card-item>
    <v-card-text>
      <v-alert v-if="backup.available" type="success" variant="tonal" class="mb-4">
        {{ t('settings.latestSystemBackup', {
          operation: t(`settings.backupOperations.${backup.operation}`),
          date: formatDate(backup.completedAt),
          name: backup.bundleName,
          mode: backup.mode,
        }) }}
      </v-alert>
      <v-alert v-else type="info" variant="tonal" class="mb-4">
        {{ t('settings.noSystemBackup') }}
      </v-alert>
      <v-alert v-if="backupActionError" type="error" variant="tonal" class="mb-4">
        {{ backupActionError }}
      </v-alert>
      <v-alert
        v-if="backupOperation"
        :type="backupOperation.status === 'failed' ? 'error' : backupOperation.status === 'completed' ? 'success' : 'info'"
        variant="tonal"
        class="mb-4"
      >
        <div>{{ operationText(backupOperation) }}</div>
        <v-progress-linear
          v-if="!['completed', 'failed'].includes(backupOperation.status)"
          class="mt-3"
          color="primary"
          height="8"
          :model-value="backupOperation.progress"
          rounded
        />
      </v-alert>
      <p class="text-body-2 mb-4">{{ t('settings.systemBackupGuidance') }}</p>
      <div class="d-flex flex-wrap ga-3">
        <v-btn
          color="primary"
          :disabled="Boolean(backupOperation && !['completed', 'failed'].includes(backupOperation.status))"
          prepend-icon="mdi-database-export-outline"
          variant="flat"
          @click="backupFolderDialog = true"
        >
          {{ t('settings.createSystemBackup') }}
        </v-btn>
        <v-btn
          :disabled="Boolean(backupOperation && !['completed', 'failed'].includes(backupOperation.status))"
          :loading="preparingRestore"
          prepend-icon="mdi-database-import-outline"
          variant="tonal"
          @click="restoreFileDialog = true"
        >
          {{ t('settings.restoreSystemBackup') }}
        </v-btn>
      </div>
    </v-card-text>
  </v-card>

  <FolderBrowserDialog
    v-model="backupFolderDialog"
    :initial-path="defaultBackupPath()"
    :title="t('settings.systemBackupFolderTitle')"
    @select="createBackup"
  />

  <FolderBrowserDialog
    v-model="restoreFileDialog"
    :initial-path="defaultBackupPath()"
    :title="t('settings.systemRestoreFileTitle')"
    system-backup-files
    @select="selectRestoreArchive"
  />

  <v-dialog v-model="restoreConfirmDialog" max-width="620" persistent>
    <v-card rounded="xl">
      <v-card-title class="pa-6 pb-2">{{ t('settings.systemRestoreConfirmTitle') }}</v-card-title>
      <v-card-text class="pa-6 pt-4">
        <v-alert type="warning" variant="tonal" class="mb-4">
          {{ t('settings.systemRestoreWarning') }}
        </v-alert>
        <v-list v-if="restoreArchive" density="compact" lines="two">
          <v-list-item :title="t('settings.file')" :subtitle="restoreArchive.name" />
          <v-list-item
            :title="t('settings.createdAt')"
            :subtitle="formatDate(restoreArchive.createdAt)"
          />
          <v-list-item :title="t('settings.size')" :subtitle="formatBytes(restoreArchive.bytes)" />
          <v-list-item
            :title="t('settings.runtimeMode')"
            :subtitle="restoreArchive.modeMatches ? t('settings.compatible') : t('settings.notCompatibleRuntime')"
          />
          <v-list-item
            :title="t('settings.encryptionKey')"
            :subtitle="restoreArchive.appKeyMatches ? t('settings.compatible') : t('settings.notCompatible')"
          />
        </v-list>
      </v-card-text>
      <v-card-actions class="px-6 pb-6">
        <v-spacer />
        <v-btn @click="restoreConfirmDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn
          color="error"
          :disabled="!restoreArchive?.appKeyMatches || !restoreArchive?.modeMatches"
          prepend-icon="mdi-backup-restore"
          variant="flat"
          @click="restoreBackup"
        >
          {{ t('settings.confirmSystemRestore') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
