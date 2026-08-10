<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import DiscogsSettings from '@/components/DiscogsSettings.vue'
import AudioIntelligenceSettings from '@/components/AudioIntelligenceSettings.vue'
import GeneralSettings from '@/components/GeneralSettings.vue'
import LibraryActivityLog from '@/components/LibraryActivityLog.vue'
import LibraryRootDialog from '@/components/LibraryRootDialog.vue'
import LanAccessSettings from '@/components/LanAccessSettings.vue'
import LastFmDeliveryLog from '@/components/LastFmDeliveryLog.vue'
import LastFmSettings from '@/components/LastFmSettings.vue'
import MetadataSettings from '@/components/MetadataSettings.vue'
import OnlineEnrichmentSettings from '@/components/OnlineEnrichmentSettings.vue'
import PageHeader from '@/components/PageHeader.vue'
import PlaylistExportSettings from '@/components/PlaylistExportSettings.vue'
import SystemHealthSettings from '@/components/SystemHealthSettings.vue'
import { useAdminAccessStore } from '@/stores/adminAccess'
import { useCatalogStore } from '@/stores/catalog'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { useScanRunsStore } from '@/stores/scanRuns'
import { formatDateTime } from '@/utils/formatters'

/** @typedef {import('@/stores/libraryRoots').LibraryRoot} LibraryRoot */
/** @typedef {import('@/stores/scanRuns').ScanRun} ScanRun */
/** @typedef {import('@/stores/scanRuns').ScanIssue} ScanIssue */

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const adminAccess = useAdminAccessStore()
const catalog = useCatalogStore()
const libraryRoots = useLibraryRootsStore()
const scanRuns = useScanRunsStore()
const localBrowser = ['localhost', '127.0.0.1', '::1', '[::1]'].includes(window.location.hostname)
const canAccessProtectedSettings = computed(() => localBrowser || adminAccess.hasToken)
const settingsTabs = new Set(['general', 'media-library', 'metadata', 'playlists', 'connections', 'intelligence', 'system', 'security'])
const protectedSettingsTabs = new Set(['media-library', 'metadata', 'playlists', 'connections', 'intelligence', 'system'])
const activeSettingsTab = computed({
  get: () => availableSettingsTab(route.query.tab),
  set: (tab) => {
    if (!settingsTabs.has(tab) || tab === route.query.tab) return

    void router.push({
      name: 'settings',
      query: { ...route.query, tab },
    })
  },
})
const rootRows = computed(() => libraryRoots.roots.map((root) => ({
  root,
  scan: scanRuns.latestForRoot(root.id),
})))
const hasWatchedRoots = computed(() => libraryRoots.roots.some((root) => root.watchEnabled))
const rootDialog = ref(false)
const deleteDialog = ref(false)
const scanDetailsDialog = ref(false)
const scanIssuesLoading = ref(false)
const scanIssuesError = ref(/** @type {string | null} */ (null))
const scanIssuePage = ref(1)
const selectedScanIssues = ref(/** @type {ScanIssue[]} */ ([]))
const selectedScanIssueOccurrences = ref(0)
const rootToDelete = ref(/** @type {LibraryRoot | null} */ (null))
const rootToEdit = ref(/** @type {LibraryRoot | null} */ (null))
const selectedScan = ref(/** @type {ScanRun | null} */ (null))
const scanIssuePageSize = 50
const displayedScanIssues = computed(() => {
  const offset = (scanIssuePage.value - 1) * scanIssuePageSize

  return selectedScanIssues.value.slice(offset, offset + scanIssuePageSize)
})
const scanIssuePageCount = computed(() => Math.ceil(selectedScanIssues.value.length / scanIssuePageSize))
const expectedScanIssueOccurrences = computed(() => {
  if (!selectedScan.value) return 0

  return selectedScan.value.warningCount + selectedScan.value.errorCount
})
const scanIssueHistoryIncomplete = computed(() => (
  !scanIssuesLoading.value
  && selectedScanIssueOccurrences.value < expectedScanIssueOccurrences.value
))
let pollTimer = /** @type {ReturnType<typeof setTimeout> | null} */ (null)

onMounted(async () => {
  await normalizeSettingsTabAddress()
  if (canAccessProtectedSettings.value) await loadProtectedSettings()
  schedulePolling()
})

onUnmounted(() => {
  if (pollTimer) clearTimeout(pollTimer)
})

function schedulePolling() {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = scanRuns.hasActiveScans
    ? setTimeout(pollScans, 2000)
    : hasWatchedRoots.value
      ? setTimeout(pollScans, 30000)
      : null
}

async function loadProtectedSettings() {
  await Promise.all([libraryRoots.load(), scanRuns.load()])
  if (scanRuns.hasActiveScans) catalog.invalidateCatalog()
}

async function handleAdminAccessChanged() {
  if (!canAccessProtectedSettings.value) {
    libraryRoots.clear()
    scanRuns.clear()
    activeSettingsTab.value = 'security'
    schedulePolling()
    return
  }

  await loadProtectedSettings()
  schedulePolling()
}

async function refreshScans() {
  await scanRuns.load()
  schedulePolling()
}

async function pollScans() {
  const activeScanIds = new Set(
    scanRuns.scans.filter((scan) => isActive(scan)).map((scan) => scan.id),
  )
  await Promise.all([
    scanRuns.load({ silent: true }),
    libraryRoots.load({ silent: true }),
  ])
  const completedScan = scanRuns.scans.find((scan) =>
    activeScanIds.has(scan.id)
    && !isActive(scan)
  )
  if (completedScan) catalog.invalidateCatalog()

  const completedScanWithIssues = scanRuns.scans.find((scan) =>
    activeScanIds.has(scan.id)
    && !isActive(scan)
    && Boolean(scan.warningCount || scan.errorCount),
  )

  if (completedScanWithIssues) openScanDetails(completedScanWithIssues)
  schedulePolling()
}

/** @param {number} rootId */
async function startScan(rootId) {
  try {
    await scanRuns.start(rootId)
    catalog.invalidateCatalog()
  } finally {
    schedulePolling()
  }
}

/** @param {number} scanId */
async function cancelScan(scanId) {
  try {
    await scanRuns.cancel(scanId)
    catalog.invalidateCatalog()
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
  return formatDateTime(value, locale.value, '—')
}

/** @param {LibraryRoot['watchStatus']} status */
function watchStatusColor(status) {
  return {
    disabled: 'default',
    pending: 'warning',
    watching: 'success',
    scanning: 'primary',
    unavailable: 'error',
    error: 'error',
  }[status]
}

/** @param {unknown} value */
function availableSettingsTab(value) {
  const tab = typeof value === 'string' && settingsTabs.has(value) ? value : 'general'

  return protectedSettingsTabs.has(tab) && !canAccessProtectedSettings.value ? 'security' : tab
}

async function normalizeSettingsTabAddress() {
  if (route.query.tab === undefined || route.query.tab === activeSettingsTab.value) return

  await router.replace({
    name: 'settings',
    query: { ...route.query, tab: activeSettingsTab.value },
  })
}

/** @param {number} rootId */
function rootName(rootId) {
  return libraryRoots.roots.find((root) => root.id === rootId)?.name ?? t('settings.unknownRoot')
}

/** @param {ScanRun} scan */
async function openScanDetails(scan) {
  selectedScan.value = scan
  selectedScanIssues.value = scan.summary?.issues ?? []
  selectedScanIssueOccurrences.value = selectedScanIssues.value.reduce(
    (total, issue) => total + (issue.count ?? 1),
    0,
  )
  scanIssuePage.value = 1
  scanIssuesError.value = null
  scanIssuesLoading.value = true
  scanDetailsDialog.value = true

  try {
    const result = await scanRuns.loadIssues(scan.id)
    if (selectedScan.value?.id !== scan.id) return

    selectedScanIssues.value = result.items
    selectedScanIssueOccurrences.value = result.totalOccurrences
  } catch (cause) {
    if (selectedScan.value?.id !== scan.id) return

    scanIssuesError.value = cause instanceof Error ? cause.message : t('settings.scanIssuesLoadFailed')
  } finally {
    if (selectedScan.value?.id === scan.id) scanIssuesLoading.value = false
  }
}

/** @param {number} scanId */
async function openActivityScan(scanId) {
  openScanDetails(await scanRuns.loadOne(scanId))
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
  rootDialog.value = true
}

/** @param {LibraryRoot} root */
function openEditDialog(root) {
  rootToEdit.value = root
  rootDialog.value = true
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

</script>

<template>
  <PageHeader :title="t('settings.title')" :description="t('settings.description')" icon="mdi-cog-outline" />
  <v-tabs
    v-model="activeSettingsTab"
    :aria-label="t('settings.title')"
    color="primary"
    show-arrows
  >
    <v-tab prepend-icon="mdi-tune-variant" value="general">
      {{ t('settings.generalTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-folder-music-outline" value="media-library">
      {{ t('settings.mediaLibraryTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-tag-multiple-outline" value="metadata">
      {{ t('settings.metadataTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-playlist-music-outline" value="playlists">
      {{ t('settings.playlistsTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-connection" value="connections">
      {{ t('settings.connectionsTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-brain" value="intelligence">
      {{ t('settings.intelligenceTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-heart-pulse" value="system">
      {{ t('settings.systemTab') }}
    </v-tab>
    <v-tab prepend-icon="mdi-shield-lock-outline" value="security">
      {{ t('settings.securityTab') }}
    </v-tab>
  </v-tabs>

  <GeneralSettings v-if="activeSettingsTab === 'general'" />

  <LanAccessSettings
    v-if="activeSettingsTab === 'security'"
    @changed="handleAdminAccessChanged"
  />

  <v-card v-show="activeSettingsTab === 'media-library'" border rounded="xl" class="mt-6">
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
          <v-list-item-subtitle>
            {{ t('settings.coverPaths') }}:
            {{ row.root.coverImagePaths.join(', ') }}
          </v-list-item-subtitle>
          <v-list-item-subtitle v-if="row.root.excludedDirectories?.length">
            {{ t('settings.excludedFolders') }}: {{ row.root.excludedDirectories.join(', ') }}
          </v-list-item-subtitle>
          <div v-if="row.root.watchEnabled" class="d-flex flex-wrap align-center ga-2 mt-2">
            <v-chip
              :color="watchStatusColor(row.root.watchStatus)"
              prepend-icon="mdi-folder-sync-outline"
              size="small"
              variant="tonal"
            >
              {{ t(`settings.watchStatuses.${row.root.watchStatus}`) }}
            </v-chip>
            <span v-if="row.root.watchCheckedAt" class="text-caption text-medium-emphasis">
              {{ t('settings.watchLastChecked', { date: formatDate(row.root.watchCheckedAt) }) }}
            </span>
            <span v-if="row.root.watchLastPath" class="text-caption text-medium-emphasis">
              {{ t('settings.watchLastPath', { path: row.root.watchLastPath }) }}
            </span>
          </div>
          <v-alert
            v-if="row.root.watchEnabled && row.root.watchError"
            class="mt-2"
            density="compact"
            type="error"
            variant="tonal"
          >
            {{ row.root.watchError }}
          </v-alert>
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
                  <span>{{ t('settings.scanUpdated', { count: row.scan.filesUpdated }) }} ·</span>
                  <span>{{ t('settings.scanRemoved', { count: row.scan.filesRemoved }) }}</span>
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

  <LibraryActivityLog
    v-if="activeSettingsTab === 'media-library' && canAccessProtectedSettings"
    :roots="libraryRoots.roots"
    @scan="openActivityScan"
  />

  <MetadataSettings
    v-if="activeSettingsTab === 'metadata' && canAccessProtectedSettings"
    :key="adminAccess.revision"
  />

  <PlaylistExportSettings
    v-if="activeSettingsTab === 'playlists' && canAccessProtectedSettings"
    :key="adminAccess.revision"
  />

  <template v-if="activeSettingsTab === 'connections' && canAccessProtectedSettings">
    <OnlineEnrichmentSettings :key="`enrichment-${adminAccess.revision}`" />
    <DiscogsSettings :key="`discogs-${adminAccess.revision}`" />
    <LastFmSettings :key="`lastfm-${adminAccess.revision}`" />
    <LastFmDeliveryLog :key="`lastfm-deliveries-${adminAccess.revision}`" />
  </template>

  <AudioIntelligenceSettings
    v-if="activeSettingsTab === 'intelligence' && canAccessProtectedSettings"
    :key="adminAccess.revision"
  />

  <SystemHealthSettings
    v-if="activeSettingsTab === 'system' && canAccessProtectedSettings"
    :key="adminAccess.revision"
  />

  <v-card v-show="activeSettingsTab === 'media-library'" border rounded="xl" class="mt-6">
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
              removed: scan.filesRemoved,
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
          <template v-if="scan.warningCount || scan.errorCount" #append>
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

  <LibraryRootDialog v-model="rootDialog" :root="rootToEdit" @saved="schedulePolling" />

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
        <v-progress-linear v-if="scanIssuesLoading" class="mb-4" color="primary" indeterminate />
        <v-alert v-if="scanIssuesError" class="mb-4" type="error" variant="tonal">
          {{ scanIssuesError }}
        </v-alert>
        <v-alert v-if="scanIssueHistoryIncomplete" class="mb-4" type="warning" variant="tonal">
          {{ t('settings.scanIssueHistoryIncomplete', {
            available: selectedScanIssueOccurrences,
            total: expectedScanIssueOccurrences,
          }) }}
        </v-alert>
        <div v-if="selectedScanIssues.length" class="mb-3 text-medium-emphasis">
          {{ t('settings.scanIssueTotal', { count: selectedScanIssues.length }) }}
        </div>
        <v-alert
          v-for="(issue, index) in displayedScanIssues"
          :key="issue.id ?? `${issue.code}-${issue.path ?? index}`"
          class="mb-3"
          density="compact"
          :type="issue.severity"
          variant="tonal"
        >
          <div>{{ issueText(issue) }}</div>
          <code v-if="issue.path" class="d-block mt-1 text-wrap">{{ issue.path }}</code>
        </v-alert>
        <v-pagination
          v-if="scanIssuePageCount > 1"
          v-model="scanIssuePage"
          :length="scanIssuePageCount"
          :total-visible="7"
        />
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
