<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import FolderBrowserDialog from '@/components/FolderBrowserDialog.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { usePlaybackStatisticsSettingsStore } from '@/stores/playbackStatisticsSettings'
import { useScanRunsStore } from '@/stores/scanRuns'

/** @typedef {import('@/stores/libraryRoots').LibraryRoot} LibraryRoot */
/** @typedef {import('@/stores/scanRuns').ScanRun} ScanRun */
/** @typedef {import('@/stores/scanRuns').ScanIssue} ScanIssue */

const { t, locale } = useI18n()
const libraryRoots = useLibraryRootsStore()
const playbackStatisticsSettings = usePlaybackStatisticsSettingsStore()
const scanRuns = useScanRunsStore()
const rootRows = computed(() => libraryRoots.roots.map((root) => ({
  root,
  scan: scanRuns.latestForRoot(root.id),
})))
const rootDialog = ref(false)
const folderBrowserDialog = ref(false)
const deleteDialog = ref(false)
const scanDetailsDialog = ref(false)
const rootToDelete = ref(/** @type {LibraryRoot | null} */ (null))
const rootToEdit = ref(/** @type {LibraryRoot | null} */ (null))
const selectedScan = ref(/** @type {ScanRun | null} */ (null))
const form = reactive({ name: '', path: '', coverImagePath: 'cover.jpg' })
const fieldErrors = reactive(/** @type {Record<string, string[]>} */ ({}))
const submitError = ref(/** @type {string | null} */ (null))
let pollTimer = /** @type {ReturnType<typeof setTimeout> | null} */ (null)

onMounted(async () => {
  await Promise.all([libraryRoots.load(), playbackStatisticsSettings.load(), scanRuns.load()])
  schedulePolling()
})

onUnmounted(() => {
  if (pollTimer) clearTimeout(pollTimer)
})

function schedulePolling() {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = scanRuns.hasActiveScans ? setTimeout(pollScans, 2000) : null
}

async function refreshScans() {
  await scanRuns.load()
  schedulePolling()
}

async function pollScans() {
  const activeScanIds = new Set(
    scanRuns.scans.filter((scan) => isActive(scan)).map((scan) => scan.id),
  )
  await scanRuns.load({ silent: true })
  const completedScanWithIssues = scanRuns.scans.find((scan) =>
    activeScanIds.has(scan.id)
    && !isActive(scan)
    && Boolean(scan.summary?.issues?.length),
  )

  if (completedScanWithIssues) openScanDetails(completedScanWithIssues)
  schedulePolling()
}

/** @param {number} rootId */
async function startScan(rootId) {
  try {
    await scanRuns.start(rootId)
  } finally {
    schedulePolling()
  }
}

/** @param {number} scanId */
async function cancelScan(scanId) {
  try {
    await scanRuns.cancel(scanId)
  } finally {
    schedulePolling()
  }
}

/** @param {ScanRun | null} scan */
function isActive(scan) {
  return scan ? ['pending', 'running'].includes(scan.status) : false
}

/** @param {ScanRun} scan */
function scanProgress(scan) {
  if (!scan.filesDiscovered) return 0
  return Math.min(100, Math.round((scan.filesProcessed / scan.filesDiscovered) * 100))
}

/** @param {ScanRun['status']} status */
function statusColor(status) {
  return {
    pending: 'info',
    running: 'primary',
    completed: 'success',
    failed: 'error',
    cancelled: 'warning',
  }[status]
}

/** @param {string | null} value */
function formatDate(value) {
  return value
    ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '—'
}

/** @param {number} rootId */
function rootName(rootId) {
  return libraryRoots.roots.find((root) => root.id === rootId)?.name ?? t('settings.unknownRoot')
}

/** @param {ScanRun} scan */
function openScanDetails(scan) {
  selectedScan.value = scan
  scanDetailsDialog.value = true
}

/** @param {ScanIssue} issue */
function issueText(issue) {
  const key = `settings.scanIssueCodes.${issue.code}`

  return t(key, {
    count: issue.count ?? 1,
    path: issue.path ?? '',
    message: issue.message,
  }, { default: issue.message })
}

function openAddDialog() {
  rootToEdit.value = null
  Object.assign(form, { name: '', path: '', coverImagePath: 'cover.jpg' })
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  submitError.value = null
  rootDialog.value = true
}

/** @param {LibraryRoot} root */
function openEditDialog(root) {
  rootToEdit.value = root
  Object.assign(form, {
    name: root.name,
    path: root.path,
    coverImagePath: root.coverImagePath,
  })
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  submitError.value = null
  rootDialog.value = true
}

/** @param {string} path */
function selectFolder(path) {
  form.path = path
  fieldErrors.path = []
}

async function saveRoot() {
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  submitError.value = null

  try {
    if (rootToEdit.value) {
      await libraryRoots.update(rootToEdit.value.id, {
        name: form.name,
        coverImagePath: form.coverImagePath,
      })
    } else {
      await libraryRoots.create({ ...form })
    }
    rootDialog.value = false
  } catch (cause) {
    if (cause instanceof ApiError) {
      Object.assign(fieldErrors, cause.violations)
      submitError.value = cause.message
      return
    }
    submitError.value = cause instanceof Error ? cause.message : t('settings.saveError')
  }
}

/** @param {LibraryRoot} root */
function confirmRemove(root) {
  rootToDelete.value = root
  deleteDialog.value = true
}

async function removeRoot() {
  if (!rootToDelete.value) return
  await libraryRoots.remove(rootToDelete.value.id)
  deleteDialog.value = false
  rootToDelete.value = null
}

/** @param {boolean | null} enabled */
async function setImportPlayStatistics(enabled) {
  if (enabled === null) return

  await playbackStatisticsSettings.setImportFromFileTags(enabled)
}
</script>

<template>
  <PageHeader :title="t('settings.title')" :description="t('settings.description')" icon="mdi-cog-outline" />
  <v-card border rounded="xl">
    <v-card-item class="library-roots-header pa-6 pb-2">
      <v-card-title>{{ t('settings.libraryRoots') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.libraryRootsDescription') }}</v-card-subtitle>
      <template #append>
        <v-btn color="primary" prepend-icon="mdi-folder-plus-outline" variant="flat" @click="openAddDialog">
          {{ t('settings.addRoot') }}
        </v-btn>
      </template>
    </v-card-item>

    <v-card-text class="pa-6 pt-4">
      <v-alert v-if="libraryRoots.error" type="error" variant="tonal" class="mb-4">
        {{ libraryRoots.error }}
      </v-alert>
      <v-alert v-if="scanRuns.error" type="error" variant="tonal" class="mb-4">
        {{ scanRuns.error }}
      </v-alert>
      <v-skeleton-loader v-if="libraryRoots.loading" type="list-item-two-line@2" />
      <v-list v-else-if="libraryRoots.hasRoots" lines="three">
        <v-list-item v-for="row in rootRows" :key="row.root.id" prepend-icon="mdi-harddisk">
          <v-list-item-title class="font-weight-bold">{{ row.root.name }}</v-list-item-title>
          <v-list-item-subtitle>{{ row.root.path }}</v-list-item-subtitle>
          <v-list-item-subtitle>{{ t('settings.coverPath') }}: {{ row.root.coverImagePath }}</v-list-item-subtitle>
          <div v-if="row.scan" class="mt-2">
            <div class="d-flex flex-wrap align-center ga-2">
              <v-chip :color="statusColor(row.scan.status)" size="small" variant="tonal">
                {{ t(`settings.scanStatuses.${row.scan.status}`) }}
              </v-chip>
              <span class="text-caption text-medium-emphasis">
                <template v-if="row.scan.summary?.phase === 'counting'">
                  {{ t('settings.scanCounting', { count: row.scan.filesDiscovered }) }}
                </template>
                <span v-else class="scan-counts">
                  <span>{{ t('settings.scanFiles', { processed: row.scan.filesProcessed, discovered: row.scan.filesDiscovered }) }} ·</span>
                  <span>{{ t('settings.scanAdded', { count: row.scan.filesAdded }) }} ·</span>
                  <span>{{ t('settings.scanUpdated', { count: row.scan.filesUpdated }) }}</span>
                </span>
              </span>
            </div>
            <v-progress-linear
              v-if="isActive(row.scan)"
              class="mt-2"
              color="primary"
              :indeterminate="row.scan.summary?.phase === 'counting' || !row.scan.filesDiscovered"
              :model-value="scanProgress(row.scan)"
              rounded
            />
          </div>
          <template #append>
            <div class="d-flex align-center ga-1">
              <v-btn
                v-if="row.scan && isActive(row.scan)"
                color="warning"
                prepend-icon="mdi-stop-circle-outline"
                size="small"
                variant="text"
                :loading="scanRuns.cancellingScanId === row.scan.id"
                @click="cancelScan(row.scan.id)"
              >
                {{ t('settings.cancelScan') }}
              </v-btn>
              <v-btn
                v-else
                color="primary"
                :loading="scanRuns.startingRootId === row.root.id"
                prepend-icon="mdi-folder-search-outline"
                size="small"
                variant="text"
                @click="startScan(row.root.id)"
              >
                {{ row.scan ? t('settings.scanAgain') : t('settings.startScan') }}
              </v-btn>
              <v-btn
                :aria-label="t('settings.editRoot', { name: row.root.name })"
                :disabled="isActive(row.scan)"
                icon="mdi-pencil-outline"
                variant="text"
                @click="openEditDialog(row.root)"
              />
              <v-btn
                :aria-label="t('settings.removeRoot', { name: row.root.name })"
                color="error"
                :disabled="isActive(row.scan)"
                icon="mdi-delete-outline"
                variant="text"
                @click="confirmRemove(row.root)"
              />
            </div>
          </template>
        </v-list-item>
      </v-list>
      <v-empty-state
        v-else
        icon="mdi-folder-music-outline"
        :headline="t('settings.noRoots')"
        :text="t('settings.noRootsDescription')"
      />
    </v-card-text>
  </v-card>

  <v-card border rounded="xl" class="mt-6">
    <v-card-item class="pa-6 pb-2" prepend-icon="mdi-headphones">
      <v-card-title>{{ t('settings.listeningStatistics') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.listeningStatisticsDescription') }}</v-card-subtitle>
    </v-card-item>
    <v-card-text class="pa-6 pt-4">
      <v-alert v-if="playbackStatisticsSettings.error" class="mb-4" type="error" variant="tonal">
        {{ playbackStatisticsSettings.error }}
      </v-alert>
      <v-skeleton-loader v-if="playbackStatisticsSettings.loading" type="list-item-two-line" />
      <v-switch
        v-else
        color="primary"
        :disabled="playbackStatisticsSettings.saving"
        :hint="t('settings.importPlayStatisticsHint')"
        :label="t('settings.importPlayStatistics')"
        :loading="playbackStatisticsSettings.saving"
        :model-value="playbackStatisticsSettings.settings.importFromFileTags"
        persistent-hint
        @update:model-value="setImportPlayStatistics"
      />
    </v-card-text>
  </v-card>

  <v-card border rounded="xl" class="mt-6">
    <v-card-item class="pa-6 pb-2">
      <v-card-title>{{ t('settings.recentScans') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.recentScansDescription') }}</v-card-subtitle>
      <template #append>
        <v-btn
          :aria-label="t('settings.refreshScans')"
          icon="mdi-refresh"
          :loading="scanRuns.loading"
          variant="text"
          @click="refreshScans"
        />
      </template>
    </v-card-item>
    <v-card-text class="pa-6 pt-4">
      <v-skeleton-loader v-if="scanRuns.loading && !scanRuns.scans.length" type="list-item-two-line@3" />
      <v-list v-else-if="scanRuns.recentScans.length" lines="three">
        <v-list-item v-for="scan in scanRuns.recentScans" :key="scan.id" prepend-icon="mdi-folder-search-outline">
          <v-list-item-title class="d-flex align-center ga-2">
            <span class="font-weight-bold">{{ rootName(scan.libraryRootId) }}</span>
            <v-chip :color="statusColor(scan.status)" size="x-small" variant="tonal">
              {{ t(`settings.scanStatuses.${scan.status}`) }}
            </v-chip>
          </v-list-item-title>
          <v-list-item-subtitle>
            {{ formatDate(scan.startedAt ?? scan.createdAt) }} ·
            {{ t('settings.scanCounts', {
              processed: scan.filesProcessed,
              discovered: scan.filesDiscovered,
              added: scan.filesAdded,
              updated: scan.filesUpdated,
            }) }}
          </v-list-item-subtitle>
          <v-list-item-subtitle v-if="scan.warningCount || scan.errorCount">
            {{ t('settings.scanIssues', { warnings: scan.warningCount, errors: scan.errorCount }) }}
          </v-list-item-subtitle>
          <v-list-item-subtitle v-if="scan.summary?.error" class="text-error">
            {{ scan.summary.error }}
          </v-list-item-subtitle>
          <v-list-item-subtitle v-if="scan.summary?.playStatisticsImported">
            {{ t('settings.playStatisticsImported', { count: scan.summary.playStatisticsImported }) }}
          </v-list-item-subtitle>
          <template v-if="scan.summary?.issues?.length" #append>
            <v-btn
              prepend-icon="mdi-alert-circle-outline"
              size="small"
              variant="text"
              @click="openScanDetails(scan)"
            >
              {{ t('settings.scanDetails') }}
            </v-btn>
          </template>
        </v-list-item>
      </v-list>
      <v-empty-state
        v-else
        icon="mdi-history"
        :headline="t('settings.noScans')"
        :text="t('settings.noScansDescription')"
      />
    </v-card-text>
  </v-card>

  <v-dialog v-model="rootDialog" max-width="640">
    <v-card
      :title="rootToEdit ? t('settings.editRootTitle') : t('settings.addRoot')"
      :prepend-icon="rootToEdit ? 'mdi-pencil-outline' : 'mdi-folder-plus-outline'"
    >
      <v-card-text>
        <v-alert v-if="submitError" type="error" variant="tonal" class="mb-4">{{ submitError }}</v-alert>
        <v-text-field
          v-model="form.name"
          autofocus
          :error-messages="fieldErrors.name"
          :label="t('settings.rootName')"
          :placeholder="t('settings.rootNameExample')"
        />
        <div class="d-flex align-start ga-2">
          <v-text-field
            v-model="form.path"
            :error-messages="fieldErrors.path"
            :hint="t('settings.rootPathHint')"
            :label="t('settings.rootPath')"
            persistent-hint
            placeholder="D:/Music"
            :readonly="Boolean(rootToEdit)"
          />
          <v-btn
            v-if="!rootToEdit"
            class="mt-1"
            prepend-icon="mdi-folder-open-outline"
            variant="tonal"
            @click="folderBrowserDialog = true"
          >
            {{ t('settings.browseFolders') }}
          </v-btn>
        </div>
        <v-text-field
          v-model="form.coverImagePath"
          :error-messages="fieldErrors.coverImagePath"
          :hint="t('settings.coverPathHint')"
          :label="t('settings.coverPath')"
          persistent-hint
          placeholder="cover.jpg"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="rootDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="primary" variant="flat" :loading="libraryRoots.saving" @click="saveRoot">
          {{ rootToEdit ? t('settings.saveChanges') : t('settings.saveRoot') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <FolderBrowserDialog
    v-model="folderBrowserDialog"
    :initial-path="form.path"
    @select="selectFolder"
  />

  <v-dialog v-model="scanDetailsDialog" max-width="760" scrollable>
    <v-card>
      <v-card-item prepend-icon="mdi-alert-circle-outline">
        <v-card-title>{{ t('settings.scanDetailsTitle') }}</v-card-title>
        <template #append>
          <v-btn
            :aria-label="t('settings.close')"
            icon="mdi-close"
            variant="text"
            @click="scanDetailsDialog = false"
          />
        </template>
      </v-card-item>
      <v-card-text v-if="selectedScan">
        <div class="d-flex flex-wrap align-center ga-2 mb-4">
          <strong>{{ rootName(selectedScan.libraryRootId) }}</strong>
          <v-chip :color="statusColor(selectedScan.status)" size="small" variant="tonal">
            {{ t(`settings.scanStatuses.${selectedScan.status}`) }}
          </v-chip>
          <span class="text-medium-emphasis">{{ formatDate(selectedScan.startedAt ?? selectedScan.createdAt) }}</span>
        </div>
        <v-alert
          v-for="(issue, index) in selectedScan.summary?.issues ?? []"
          :key="`${issue.code}-${issue.path ?? index}`"
          class="mb-3"
          density="compact"
          :type="issue.severity"
          variant="tonal"
        >
          <div>{{ issueText(issue) }}</div>
          <code v-if="issue.path" class="d-block mt-1 text-wrap">{{ issue.path }}</code>
        </v-alert>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="scanDetailsDialog = false">{{ t('settings.close') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="deleteDialog" max-width="520">
    <v-card :title="t('settings.removeRootTitle')" prepend-icon="mdi-alert-outline">
      <v-card-text>{{ t('settings.removeRootWarning', { name: rootToDelete?.name }) }}</v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="deleteDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="error" variant="flat" @click="removeRoot">{{ t('settings.remove') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.library-roots-header :deep(.v-card-subtitle) {
  white-space: normal;
  overflow: visible;
  text-overflow: clip;
}

.scan-counts {
  display: inline-flex;
  flex-wrap: wrap;
}

.scan-counts > span {
  white-space: nowrap;
}

.scan-counts > span:not(:last-child) {
  margin-inline-end: 0.35rem;
}
</style>
