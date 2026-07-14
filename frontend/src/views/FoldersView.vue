<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { Track } from '@/stores/catalog'
import { useLibraryFoldersStore } from '@/stores/libraryFolders'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { usePlayerStore } from '@/stores/player'
import { useScanRunsStore } from '@/stores/scanRuns'

type FolderAction = 'play' | 'queue' | 'playlist'

const LARGE_FOLDER_ACTION_TRACK_COUNT = 500

interface PendingFolderAction {
  action: FolderAction
  folder: string
  path: string | null
  rootId: number
  total: number
}

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const folders = useLibraryFoldersStore()
const libraryRootScope = useLibraryRootScopeStore()
const libraryRoots = useLibraryRootsStore()
const player = usePlayerStore()
const scanRuns = useScanRunsStore()

const playlistDialog = ref(false)
const playlistTracks = ref<Track[]>([])
const largeActionDialog = ref(false)
const largeActionLoading = ref(false)
const pendingFolderAction = ref<PendingFolderAction | null>(null)
const actionLoading = ref('')
const notice = ref('')
const noticeVisible = ref(false)
const selectedRootId = computed(() => libraryRootScope.selectedRootId)
const selectedRoot = computed(() => libraryRoots.roots.find((root) => root.id === selectedRootId.value) ?? null)
const rootOptions = computed(() => libraryRoots.roots
  .filter((root) => root.enabled)
  .map((root) => ({ title: root.name, value: root.id })))
const currentPath = computed(() => typeof route.query.path === 'string' && route.query.path.trim()
  ? route.query.path
  : null)
const listing = computed(() => folders.listing)
const browserEntries = computed(() => [
  ...(listing.value?.directories.map((directory) => ({ kind: 'directory' as const, ...directory })) ?? []),
  ...(listing.value?.files.map((file) => ({ kind: 'file' as const, ...file })) ?? []),
])
const browserListHeight = computed(() => Math.min(720, Math.max(72, browserEntries.value.length * 72)))
const currentFolderName = computed(() => listing.value?.breadcrumbs.at(-1)?.name ?? selectedRoot.value?.name ?? '')
const activeRootScan = computed(() => selectedRootId.value === null
  ? null
  : scanRuns.activeForRoot(selectedRootId.value))
const activeScanProgress = computed(() => {
  const scan = activeRootScan.value
  if (!scan?.filesDiscovered) return 0

  return Math.min(100, Math.round((scan.filesProcessed / scan.filesDiscovered) * 100))
})
const activeScanIndeterminate = computed(() => (
  activeRootScan.value?.summary?.phase === 'counting' || !activeRootScan.value?.filesDiscovered
))
let scanPollTimer: ReturnType<typeof setTimeout> | null = null

onMounted(async () => {
  await scanRuns.load({ silent: true })
  scheduleScanPolling()
})

onUnmounted(() => {
  if (scanPollTimer) clearTimeout(scanPollTimer)
})

watch(
  [selectedRootId, currentPath],
  ([rootId, path]) => {
    if (rootId === null) {
      folders.clear()
      return
    }

    void folders.load(rootId, path)
  },
  { immediate: true },
)

function selectRoot(rootId: number | null) {
  libraryRootScope.select(rootId)
  void router.replace({ name: 'folders' })
  scheduleScanPolling()
}

function openFolder(path: string | null) {
  void router.push({
    name: 'folders',
    query: path ? { path } : {},
  })
}

function toggleTrack(track: Track) {
  if (player.currentTrack?.id === track.id) {
    if (player.isPlaying) {
      player.pause()
    } else {
      player.resume()
    }
    return
  }

  player.playTrack(track, [track], 'track-list')
}

function queueTrack(track: Track) {
  player.queueTrack(track, 'track-list')
  showNotice(t('folders.trackQueued', { title: track.title }))
}

function addTrackToPlaylist(track: Track) {
  playlistTracks.value = [track]
  playlistDialog.value = true
}

async function useFolderTracks(path: string | null, action: FolderAction) {
  const rootId = selectedRootId.value
  if (rootId === null) return

  actionLoading.value = `${action}:${path ?? ''}`
  try {
    const result = await folders.loadTracks(rootId, path, LARGE_FOLDER_ACTION_TRACK_COUNT)
    if (result.requiresConfirmation) {
      pendingFolderAction.value = {
        action,
        folder: folderName(path),
        path,
        rootId,
        total: result.total,
      }
      largeActionDialog.value = true
      return
    }

    if (!result.tracks.length) {
      showNotice(t('folders.noPlayableTracks'))
      return
    }

    applyFolderAction(action, result.tracks, result.total)
  } catch (cause) {
    showNotice(cause instanceof Error ? cause.message : t('folders.actionFailed'))
  } finally {
    actionLoading.value = ''
  }
}

function applyFolderAction(action: FolderAction, tracks: Track[], total: number) {
  if (action === 'play') {
    player.playTrack(tracks[0]!, tracks, 'track-list')
  } else if (action === 'queue') {
    player.queueTracks(tracks, 'track-list')
    showNotice(t('folders.tracksQueued', { count: total }))
  } else {
    playlistTracks.value = tracks
    playlistDialog.value = true
  }
}

async function confirmLargeFolderAction() {
  const pending = pendingFolderAction.value
  if (!pending) return

  largeActionLoading.value = true
  try {
    const result = await folders.loadTracks(pending.rootId, pending.path)
    if (!result.tracks.length) {
      resetLargeActionDialog()
      showNotice(t('folders.noPlayableTracks'))
      return
    }

    resetLargeActionDialog()
    applyFolderAction(pending.action, result.tracks, result.total)
  } catch (cause) {
    showNotice(cause instanceof Error ? cause.message : t('folders.actionFailed'))
  } finally {
    largeActionLoading.value = false
  }
}

function closeLargeActionDialog() {
  if (largeActionLoading.value) return

  resetLargeActionDialog()
}

function resetLargeActionDialog() {
  largeActionDialog.value = false
  pendingFolderAction.value = null
}

function folderName(path: string | null) {
  return path?.split('/').at(-1) ?? selectedRoot.value?.name ?? t('folders.title')
}

function largeActionMessage(action: FolderAction) {
  return t(`folders.largeActions.${action}`, {
    count: pendingFolderAction.value?.total ?? 0,
    folder: pendingFolderAction.value?.folder ?? '',
  })
}

async function rescan(path: string | null) {
  const rootId = selectedRootId.value
  if (rootId === null) return

  actionLoading.value = `scan:${path ?? ''}`
  try {
    await scanRuns.start(rootId, path)
    showNotice(path ? t('folders.subtreeScanStarted', { path }) : t('folders.rootScanStarted'))
  } catch (cause) {
    showNotice(cause instanceof Error ? cause.message : t('folders.scanFailed'))
  } finally {
    actionLoading.value = ''
    scheduleScanPolling()
  }
}

async function cancelActiveScan() {
  const scan = activeRootScan.value
  if (!scan) return

  try {
    await scanRuns.cancel(scan.id)
    showNotice(t('folders.scanCancellationRequested'))
  } catch (cause) {
    showNotice(cause instanceof Error ? cause.message : t('folders.cancelScanFailed'))
  } finally {
    scheduleScanPolling()
  }
}

function scheduleScanPolling() {
  if (scanPollTimer) clearTimeout(scanPollTimer)
  scanPollTimer = activeRootScan.value ? setTimeout(pollScan, 2000) : null
}

async function pollScan() {
  const activeScanId = activeRootScan.value?.id
  const rootId = selectedRootId.value
  await scanRuns.load({ silent: true })

  const finishedScan = activeScanId
    ? scanRuns.scans.find((scan) => scan.id === activeScanId && !['pending', 'running'].includes(scan.status))
    : null
  if (finishedScan) {
    showNotice(t('folders.scanFinished', { status: t(`settings.scanStatuses.${finishedScan.status}`) }))
    if (rootId !== null && rootId === selectedRootId.value) {
      await folders.load(rootId, currentPath.value)
    }
  }

  scheduleScanPolling()
}

function showNotice(message: string) {
  notice.value = message
  noticeVisible.value = true
}
</script>

<template>
  <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-6">
    <div>
      <h1 class="text-h4 font-weight-bold">{{ t('folders.title') }}</h1>
      <p class="text-medium-emphasis mt-1">{{ t('folders.description') }}</p>
    </div>
  </div>

  <v-card v-if="selectedRootId === null" max-width="680" rounded="xl" variant="tonal">
    <v-card-text class="pa-6">
      <div class="d-flex ga-4 align-start">
        <v-icon color="primary" icon="mdi-folder-music-outline" size="36" />
        <div class="flex-grow-1">
          <div class="text-h6 font-weight-bold">{{ t('folders.chooseRootTitle') }}</div>
          <p class="text-medium-emphasis mt-1 mb-4">{{ t('folders.chooseRootDescription') }}</p>
          <v-select
            :items="rootOptions"
            :label="t('libraryScope.label')"
            prepend-inner-icon="mdi-harddisk"
            variant="outlined"
            @update:model-value="selectRoot"
          />
        </div>
      </div>
    </v-card-text>
  </v-card>

  <template v-else>
    <v-alert v-if="folders.error" class="mb-4" type="error" variant="tonal">
      {{ folders.error }}
    </v-alert>

    <v-card class="mb-4" rounded="xl" variant="outlined">
      <v-card-text class="folder-toolbar d-flex flex-wrap align-center ga-2 pa-3">
        <div class="breadcrumbs d-flex flex-wrap align-center flex-grow-1">
          <template v-for="(breadcrumb, index) in listing?.breadcrumbs ?? []" :key="breadcrumb.path ?? 'root'">
            <v-icon v-if="index" class="mx-1" icon="mdi-chevron-right" size="small" />
            <v-menu v-if="index === 0">
              <template #activator="{ props: menuProps }">
                <v-btn
                  v-bind="menuProps"
                  :aria-label="t('folders.switchRoot')"
                  append-icon="mdi-menu-down"
                  :disabled="folders.loading"
                  size="small"
                  :variant="(listing?.breadcrumbs.length ?? 0) === 1 ? 'tonal' : 'text'"
                >
                  {{ breadcrumb.name }}
                </v-btn>
              </template>
              <v-list density="compact" min-width="240">
                <v-list-item
                  v-for="option in rootOptions"
                  :key="option.value"
                  :active="option.value === selectedRootId"
                  :prepend-icon="option.value === selectedRootId ? 'mdi-check' : 'mdi-harddisk'"
                  :title="option.title"
                  @click="selectRoot(option.value)"
                />
              </v-list>
            </v-menu>
            <v-btn
              v-else
              :disabled="folders.loading"
              size="small"
              :variant="index === (listing?.breadcrumbs.length ?? 0) - 1 ? 'tonal' : 'text'"
              @click="openFolder(breadcrumb.path)"
            >
              {{ breadcrumb.name }}
            </v-btn>
          </template>
        </div>
        <div class="folder-actions d-flex align-center ga-1">
          <TooltipIconButton
            :text="t('folders.playFolder')"
            :aria-label="t('folders.playFolder')"
            :loading="actionLoading === `play:${listing?.path ?? ''}`"
            icon="mdi-play"
            variant="text"
            @click="void useFolderTracks(listing?.path ?? null, 'play')"
          />
          <TooltipIconButton
            :text="t('folders.queueFolder')"
            :aria-label="t('folders.queueFolder')"
            :loading="actionLoading === `queue:${listing?.path ?? ''}`"
            icon="mdi-playlist-plus"
            variant="text"
            @click="void useFolderTracks(listing?.path ?? null, 'queue')"
          />
          <TooltipIconButton
            :text="t('folders.addFolderToPlaylist')"
            :aria-label="t('folders.addFolderToPlaylist')"
            :loading="actionLoading === `playlist:${listing?.path ?? ''}`"
            icon="mdi-playlist-music"
            variant="text"
            @click="void useFolderTracks(listing?.path ?? null, 'playlist')"
          />
          <TooltipIconButton
            :text="t('folders.rescanFolder')"
            :aria-label="t('folders.rescanFolder')"
            :loading="actionLoading === `scan:${listing?.path ?? ''}`"
            :disabled="Boolean(activeRootScan)"
            icon="mdi-folder-refresh-outline"
            variant="text"
            @click="void rescan(listing?.path ?? null)"
          />
        </div>
      </v-card-text>
    </v-card>

    <v-card v-if="activeRootScan" class="mb-4" color="primary" rounded="xl" variant="tonal">
      <v-card-text class="pa-4">
        <div class="d-flex align-start ga-3">
          <v-icon class="mt-1" icon="mdi-folder-sync-outline" />
          <div class="flex-grow-1 min-width-0">
            <div class="d-flex flex-wrap align-center justify-space-between ga-2">
              <div>
                <div class="font-weight-bold">
                  {{ activeRootScan.subtreePath
                    ? t('folders.scanningSubtree', { path: activeRootScan.subtreePath })
                    : t('folders.scanningRoot', { root: selectedRoot?.name }) }}
                </div>
                <div class="text-caption text-medium-emphasis mt-1">
                  <template v-if="activeRootScan.cancelRequestedAt">
                    {{ t('folders.scanCancellationRequested') }}
                  </template>
                  <template v-else-if="activeRootScan.summary?.phase === 'counting'">
                    {{ t('settings.scanCounting', { count: activeRootScan.filesDiscovered }) }}
                  </template>
                  <template v-else>
                    {{ t('settings.scanFiles', {
                      processed: activeRootScan.filesProcessed,
                      discovered: activeRootScan.filesDiscovered,
                    }) }}
                  </template>
                </div>
              </div>
              <v-btn
                color="warning"
                :loading="scanRuns.cancellingScanId === activeRootScan.id"
                prepend-icon="mdi-stop-circle-outline"
                size="small"
                variant="text"
                @click="void cancelActiveScan()"
              >
                {{ t('settings.cancelScan') }}
              </v-btn>
            </div>
            <v-progress-linear
              class="mt-3"
              color="primary"
              :indeterminate="activeScanIndeterminate"
              :model-value="activeScanProgress"
              rounded
            />
            <div class="d-flex flex-wrap ga-2 mt-2 text-caption text-medium-emphasis">
              <span>{{ t('settings.scanAdded', { count: activeRootScan.filesAdded }) }}</span>
              <span>·</span>
              <span>{{ t('settings.scanUpdated', { count: activeRootScan.filesUpdated }) }}</span>
              <span>·</span>
              <span>{{ t('settings.scanRemoved', { count: activeRootScan.filesRemoved }) }}</span>
            </div>
          </div>
        </div>
      </v-card-text>
    </v-card>

    <v-skeleton-loader v-if="folders.loading" type="list-item-two-line@8" />
    <div v-else-if="listing && browserEntries.length" class="folder-list">
      <v-list v-if="listing.path !== null" lines="one">
        <v-list-item
          prepend-icon="mdi-arrow-up"
          :title="t('folders.parentFolder')"
          @click="openFolder(listing.parentPath)"
        />
        <v-divider />
      </v-list>

      <v-virtual-scroll
        :height="browserListHeight"
        item-height="72"
        item-key="path"
        :items="browserEntries"
      >
        <template #default="{ item }">
          <v-list-item
            v-if="item.kind === 'directory'"
            class="folder-row"
            prepend-icon="mdi-folder-outline"
            :subtitle="item.path"
            :title="item.name"
            @click="openFolder(item.path)"
          >
            <template #append>
              <div class="folder-actions d-flex align-center ga-1" @click.stop>
                <TooltipIconButton
                  :text="t('folders.playFolder')"
                  :aria-label="t('folders.playFolder')"
                  :loading="actionLoading === `play:${item.path}`"
                  density="comfortable"
                  icon="mdi-play"
                  variant="text"
                  @click="void useFolderTracks(item.path, 'play')"
                />
                <TooltipIconButton
                  :text="t('folders.queueFolder')"
                  :aria-label="t('folders.queueFolder')"
                  :loading="actionLoading === `queue:${item.path}`"
                  density="comfortable"
                  icon="mdi-playlist-plus"
                  variant="text"
                  @click="void useFolderTracks(item.path, 'queue')"
                />
                <TooltipIconButton
                  :text="t('folders.addFolderToPlaylist')"
                  :aria-label="t('folders.addFolderToPlaylist')"
                  :loading="actionLoading === `playlist:${item.path}`"
                  density="comfortable"
                  icon="mdi-playlist-music"
                  variant="text"
                  @click="void useFolderTracks(item.path, 'playlist')"
                />
                <TooltipIconButton
                  :text="t('folders.rescanFolder')"
                  :aria-label="t('folders.rescanFolder')"
                  :loading="actionLoading === `scan:${item.path}`"
                  :disabled="Boolean(activeRootScan)"
                  density="comfortable"
                  icon="mdi-folder-refresh-outline"
                  variant="text"
                  @click="void rescan(item.path)"
                />
              </div>
            </template>
          </v-list-item>

          <v-list-item
            v-else
            class="folder-row"
            :class="{ 'current-track': item.track && player.currentTrack?.id === item.track.id }"
            :prepend-icon="item.indexed ? 'mdi-music-note' : 'mdi-file-music-outline'"
            :subtitle="item.track ? [item.track.artists.map((artist) => artist.name).join(', '), item.track.album?.title].filter(Boolean).join(' · ') : t('folders.notIndexed')"
            :title="item.track?.title ?? item.name"
          >
            <template #append>
              <div class="folder-actions d-flex align-center ga-1">
                <TooltipIconButton
                  :text="player.currentTrack?.id === item.track?.id && player.isPlaying ? t('player.pause') : t('player.play')"
                  :aria-label="player.currentTrack?.id === item.track?.id && player.isPlaying ? t('player.pause') : t('player.play')"
                  :color="player.currentTrack?.id === item.track?.id ? 'primary' : undefined"
                  density="comfortable"
                  :disabled="!item.track || !item.available"
                  :icon="player.currentTrack?.id === item.track?.id && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
                  variant="text"
                  @click="item.track && toggleTrack(item.track)"
                />
                <TooltipIconButton
                  :text="t('tracks.queueTrack')"
                  :aria-label="t('tracks.queueTrack')"
                  density="comfortable"
                  :disabled="!item.track || !item.available"
                  icon="mdi-playlist-plus"
                  variant="text"
                  @click="item.track && queueTrack(item.track)"
                />
                <TooltipIconButton
                  :text="t('playlists.addTrackToPlaylist')"
                  :aria-label="t('playlists.addTrackToPlaylist')"
                  density="comfortable"
                  :disabled="!item.track || !item.available"
                  icon="mdi-playlist-music"
                  variant="text"
                  @click="item.track && addTrackToPlaylist(item.track)"
                />
              </div>
            </template>
          </v-list-item>
        </template>
      </v-virtual-scroll>
    </div>

    <v-card v-else-if="listing" rounded="xl" variant="tonal">
      <v-card-text class="pa-8 text-center">
        <v-icon color="primary" icon="mdi-folder-open-outline" size="48" />
        <div class="text-h6 font-weight-bold mt-3">{{ t('folders.emptyTitle') }}</div>
        <div class="text-medium-emphasis mt-1">{{ t('folders.emptyDescription', { name: currentFolderName }) }}</div>
      </v-card-text>
    </v-card>
  </template>

  <v-dialog
    v-model="largeActionDialog"
    max-width="540"
    :persistent="largeActionLoading"
    @after-leave="pendingFolderAction = null"
  >
    <v-card rounded="xl">
      <v-card-title class="d-flex align-center ga-2 pa-5 pb-2">
        <v-icon color="warning" icon="mdi-folder-alert-outline" />
        {{ t('folders.largeActionTitle') }}
      </v-card-title>
      <v-card-text class="px-5">
        {{ pendingFolderAction ? largeActionMessage(pendingFolderAction.action) : '' }}
      </v-card-text>
      <v-card-actions class="px-5 pb-5">
        <v-spacer />
        <v-btn :disabled="largeActionLoading" variant="text" @click="closeLargeActionDialog">
          {{ t('folders.cancel') }}
        </v-btn>
        <v-btn color="primary" :loading="largeActionLoading" variant="flat" @click="void confirmLargeFolderAction()">
          {{ t('folders.continueAction') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
  <AddToPlaylistDialog v-model="playlistDialog" :tracks="playlistTracks" />
  <v-snackbar v-model="noticeVisible" timeout="3500">{{ notice }}</v-snackbar>
</template>

<style scoped>
.folder-list {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 16px;
  overflow: hidden;
}

.folder-row {
  transition: background-color 120ms ease;
}

.folder-row:hover {
  background: rgba(var(--v-theme-on-surface), 0.04);
}

.current-track {
  background: rgba(var(--v-theme-primary), 0.08);
}

@media (max-width: 599px) {
  .folder-toolbar {
    align-items: flex-start !important;
  }

  .breadcrumbs,
  .folder-toolbar .folder-actions {
    width: 100%;
  }

  .folder-toolbar .folder-actions {
    justify-content: flex-end;
  }

  .folder-row :deep(.v-list-item__append) {
    align-self: center;
  }
}
</style>
