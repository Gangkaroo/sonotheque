<script setup lang="ts">
import { computed, ref, watch } from 'vue'
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

async function useFolderTracks(path: string | null, action: 'play' | 'queue' | 'playlist') {
  const rootId = selectedRootId.value
  if (rootId === null) return

  actionLoading.value = `${action}:${path ?? ''}`
  try {
    const result = await folders.loadTracks(rootId, path)
    if (!result.tracks.length) {
      showNotice(t('folders.noPlayableTracks'))
      return
    }

    if (action === 'play') {
      player.playTrack(result.tracks[0]!, result.tracks, 'track-list')
    } else if (action === 'queue') {
      player.queueTracks(result.tracks, 'track-list')
      showNotice(t('folders.tracksQueued', { count: result.total }))
    } else {
      playlistTracks.value = result.tracks
      playlistDialog.value = true
    }
  } catch (cause) {
    showNotice(cause instanceof Error ? cause.message : t('folders.actionFailed'))
  } finally {
    actionLoading.value = ''
  }
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
  }
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
            icon="mdi-folder-refresh-outline"
            variant="text"
            @click="void rescan(listing?.path ?? null)"
          />
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
