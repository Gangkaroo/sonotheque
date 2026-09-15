<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PlaylistFileExportDialog from '@/components/PlaylistFileExportDialog.vue'
import PlaylistSimilarityOrderDialog from '@/components/PlaylistSimilarityOrderDialog.vue'
import PlaylistTrackList from '@/components/PlaylistTrackList.vue'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { usePlayerStore } from '@/stores/player'
import { usePlaylistsStore } from '@/stores/playlists'
import type { PlaylistFileExportResult } from '@/types/playlistExport'
import { formatDateTime, formatTotalDuration } from '@/utils/formatters'

const { locale, t } = useI18n()
const route = useRoute()
const playlists = usePlaylistsStore()
const player = usePlayerStore()
const libraryRootScope = useLibraryRootScopeStore()

const playlistId = computed(() => Number(route.params.id))
const targetPlaylistItemId = computed(() => {
  const value = typeof route.query.playlistItem === 'string' ? Number(route.query.playlistItem) : NaN

  return Number.isInteger(value) && value > 0 ? value : null
})
const playlist = computed(() => playlists.current)
const tracks = computed(() => playlist.value?.items.map(item => item.track) ?? [])
const playableTracks = computed(() => tracks.value.filter(track => track.available !== false))
const playlistPlayingTime = computed(() => {
  const total = tracks.value.reduce((sum, track) => sum + (track.durationMs ?? 0), 0)

  return total > 0 ? formatTotalDuration(total) : null
})
const distinctTracks = computed(() => [...new Map(
  tracks.value.map(track => [track.id, track]),
).values()])
const playlistPlaybackStats = computed(() => {
  const totalTrackPlays = distinctTracks.value.reduce(
    (total, track) => total + track.playStatistics.playCount,
    0,
  )
  if (!totalTrackPlays) return []

  const playedTracks = distinctTracks.value.filter(track => track.playStatistics.playCount > 0).length
  const lastPlayedAt = distinctTracks.value
    .map(track => track.playStatistics.lastPlayedAt)
    .filter((value): value is string => Boolean(value))
    .sort((left, right) => new Date(right).getTime() - new Date(left).getTime())[0] ?? null

  return [
    {
      key: 'totalTrackPlays',
      label: t('playlists.totalTrackPlays'),
      value: t('tracks.playCountTooltip', { count: totalTrackPlays }),
      icon: 'mdi-headphones-box',
    },
    {
      key: 'playedTracks',
      label: t('playlists.playedTracks'),
      value: t('playlists.playedTracksCount', { played: playedTracks, total: distinctTracks.value.length }),
      icon: 'mdi-music-note-outline',
    },
    {
      key: 'lastPlayedAt',
      label: t('tracks.lastPlayedAt'),
      value: formatDateTime(lastPlayedAt, locale.value),
      icon: 'mdi-calendar-clock',
    },
  ]
})
const exportDialog = ref(false)
const similarityOrderDialog = ref(false)
const exportMessage = ref('')
const exportMessageVisible = ref(false)
const canReorder = computed(() => libraryRootScope.selectedRootId === null)

function playPlaylist() {
  const [firstTrack] = playableTracks.value
  if (!firstTrack) return

  player.playTrack(firstTrack, playableTracks.value, 'track-list')
}

function queuePlaylist() {
  player.queueTracks(playableTracks.value, 'track-list')
}

function handlePlaylistExported(result: PlaylistFileExportResult) {
  exportMessage.value = t('playlists.fileExportSaved', {
    filename: result.filename,
    location: result.location.name,
  })
  exportMessageVisible.value = true
}

watch(playlistId, id => {
  if (Number.isInteger(id) && id > 0) void playlists.loadPlaylist(id)
}, { immediate: true })
</script>

<template>
  <v-btn class="mb-4" variant="text" prepend-icon="mdi-arrow-left" :to="{ name: 'playlists' }">
    {{ t('playlists.back') }}
  </v-btn>

  <v-alert v-if="playlists.error" type="error" variant="tonal">
    {{ playlists.error }}
  </v-alert>

  <v-skeleton-loader v-else-if="playlists.loading" type="card, list-item-three-line@8" />

  <template v-else-if="playlist">
    <v-card class="mb-6" border rounded="xl">
      <v-card-item>
        <template #prepend>
          <v-avatar color="primary" variant="tonal" size="44">
            <v-icon icon="mdi-playlist-music-outline" />
          </v-avatar>
        </template>
        <v-card-title>{{ playlist.name }}</v-card-title>
        <v-card-subtitle>
          {{ playlist.folder?.name ?? t('playlists.noFolder') }} · {{ t('playlists.trackCount', { count: playlist.trackCount }) }}<span v-if="playlistPlayingTime"> · {{ t('catalog.playingTime', { duration: playlistPlayingTime }) }}</span>
        </v-card-subtitle>
      </v-card-item>
      <v-card-text v-if="playlist.description || playlistPlaybackStats.length">
        <p v-if="playlist.description" class="text-medium-emphasis mb-0">{{ playlist.description }}</p>
        <div
          v-if="playlistPlaybackStats.length"
          class="playlist-stat-grid"
          :class="{ 'mt-4': playlist.description }"
          :title="t('playlists.playStatisticsHint')"
        >
          <div v-for="stat in playlistPlaybackStats" :key="stat.key" class="playlist-stat-tile">
            <v-icon color="primary" :icon="stat.icon" size="small" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ stat.label }}</div>
              <div class="text-body-2 font-weight-medium">{{ stat.value }}</div>
            </div>
          </div>
        </div>
      </v-card-text>
      <v-card-actions>
        <v-btn color="primary" variant="flat" prepend-icon="mdi-play" :disabled="!tracks.length" @click="playPlaylist">
          {{ t('playlists.playPlaylist') }}
        </v-btn>
        <v-btn color="primary" variant="tonal" prepend-icon="mdi-playlist-plus" :disabled="!tracks.length" @click="queuePlaylist">
          {{ t('playlists.queuePlaylist') }}
        </v-btn>
        <v-btn
          prepend-icon="mdi-file-music-outline"
          :disabled="!tracks.length"
          variant="tonal"
          @click="exportDialog = true"
        >
          {{ t('playlists.fileExportAction') }}
        </v-btn>
        <v-btn
          prepend-icon="mdi-vector-polyline"
          :disabled="!canReorder || playlist.items.length < 2"
          variant="tonal"
          @click="similarityOrderDialog = true"
        >
          {{ t('playlists.similarityOrderAction') }}
        </v-btn>
      </v-card-actions>
    </v-card>

    <PlaylistTrackList
      :can-reorder="canReorder"
      :playlist="playlist"
      :target-playlist-item-id="targetPlaylistItemId"
    />
  </template>

  <EmptyCatalogState
    v-else
    :title="t('playlists.notFoundTitle')"
    :description="t('playlists.notFoundDescription')"
    icon="mdi-playlist-remove"
  />

  <PlaylistFileExportDialog
    v-if="playlist"
    v-model="exportDialog"
    :playlist-id="playlist.id"
    @saved="handlePlaylistExported"
  />

  <PlaylistSimilarityOrderDialog
    v-if="playlist"
    v-model="similarityOrderDialog"
    :playlist="playlist"
  />

  <v-snackbar v-model="exportMessageVisible" color="success" timeout="3000">
    {{ exportMessage }}
  </v-snackbar>
</template>

<style scoped>
.playlist-stat-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
}

.playlist-stat-tile {
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  display: flex;
  gap: 10px;
  min-width: 0;
  padding: 10px;
}
</style>
