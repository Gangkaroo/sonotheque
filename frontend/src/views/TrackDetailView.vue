<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'

const { t } = useI18n()
const route = useRoute()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const addToPlaylistDialog = ref(false)

const trackId = computed(() => Number(route.params.id))
const backAlbumId = computed(() => {
  const parsed = typeof route.query.backAlbum === 'string' ? Number(route.query.backAlbum) : NaN

  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
})
const backRoute = computed(() => {
  return backAlbumId.value
    ? { name: 'album-detail', params: { id: backAlbumId.value } }
    : { name: 'tracks' }
})
const backLabel = computed(() => backAlbumId.value ? t('tracks.backToAlbum') : t('tracks.back'))
const track = computed(() => catalog.trackDetail)
const playlistTracks = computed(() => track.value ? [track.value] : [])
const isCurrentTrack = computed(() => player.currentTrack?.id === track.value?.id)
const artistNames = computed(() => track.value?.artists.map((artist) => artist.name).join(', ') || t('catalog.unknownArtist'))
const technicalRows = computed(() => {
  const mediaFile = track.value?.mediaFile
  if (!mediaFile) return []

  return [
    { label: t('tracks.filePath'), value: mediaFile.relativePath },
    { label: t('tracks.fileSize'), value: formatFileSize(mediaFile.fileSize) },
    { label: t('tracks.modifiedAt'), value: formatDate(mediaFile.modifiedAt) },
    { label: t('tracks.status'), value: mediaFile.status },
    { label: t('tracks.codec'), value: mediaFile.codec },
    { label: t('tracks.container'), value: mediaFile.container },
    { label: t('tracks.mimeType'), value: mediaFile.mimeType },
    { label: t('tracks.bitrate'), value: formatBitrate(mediaFile.bitrate) },
    { label: t('tracks.sampleRate'), value: formatSampleRate(mediaFile.sampleRate) },
    { label: t('tracks.channels'), value: mediaFile.channels },
  ].filter((row) => row.value !== undefined && row.value !== null && row.value !== '')
})

function duration(milliseconds?: number) {
  if (!milliseconds) return '-'
  const seconds = Math.round(milliseconds / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function trackNumber() {
  const parts = [track.value?.discNumber, track.value?.trackNumber].filter((part) => part !== undefined && part !== null)
  return parts.length ? parts.join('.') : '-'
}

function formatFileSize(bytes?: number | null) {
  if (!bytes && bytes !== 0) return null
  const units = ['B', 'KB', 'MB', 'GB']
  let value = bytes
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit += 1
  }

  return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`
}

function formatBitrate(value?: number | null) {
  return value ? `${Math.round(value / 1000)} kbps` : null
}

function formatSampleRate(value?: number | null) {
  return value ? `${(value / 1000).toFixed(1)} kHz` : null
}

function formatDate(value?: string | null) {
  if (!value) return null
  const date = new Date(value)

  return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
}

function playTrack() {
  if (!track.value) return

  player.playTrack(track.value, [track.value], 'track-list')
}

function queueTrack() {
  if (!track.value) return

  player.queueTrack(track.value, 'track-list')
}

function toggleTrack() {
  if (!track.value) return

  if (isCurrentTrack.value && player.isPlaying) {
    player.pause()
    return
  }

  playTrack()
}

watch(trackId, (id) => {
  if (Number.isInteger(id) && id > 0) void catalog.loadTrack(id)
}, { immediate: true })
</script>

<template>
  <v-btn class="mb-4" variant="text" prepend-icon="mdi-arrow-left" :to="backRoute">
    {{ backLabel }}
  </v-btn>

  <v-alert v-if="catalog.trackDetailError" type="error" variant="tonal">
    {{ catalog.trackDetailError }}
  </v-alert>

  <v-skeleton-loader v-else-if="catalog.trackDetailLoading" type="card, list-item-two-line@8" />

  <template v-else-if="track">
    <v-card class="mb-6" border rounded="xl">
      <v-card-item>
        <template #prepend>
          <v-avatar color="primary" variant="tonal" size="44">
            <v-icon icon="mdi-music-note" />
          </v-avatar>
        </template>
        <v-card-title>{{ track.title }}</v-card-title>
        <v-card-subtitle>{{ artistNames }}</v-card-subtitle>
      </v-card-item>
      <v-card-text>
        <div class="d-flex flex-wrap ga-2">
          <v-chip v-if="track.album" prepend-icon="mdi-album" :to="{ name: 'album-detail', params: { id: track.album.id } }">
            {{ track.album.title }}
          </v-chip>
          <v-chip v-for="genre in track.genres" :key="genre.id" prepend-icon="mdi-tag-outline" variant="tonal">
            {{ genre.name }}
          </v-chip>
        </div>
      </v-card-text>
      <v-card-actions>
        <v-btn
          color="primary"
          variant="flat"
          :prepend-icon="isCurrentTrack && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
          @click="toggleTrack"
        >
          {{ isCurrentTrack && player.isPlaying ? t('player.pause') : t('tracks.playTrack') }}
        </v-btn>
        <v-btn
          color="primary"
          prepend-icon="mdi-playlist-plus"
          variant="tonal"
          @click="queueTrack"
        >
          {{ t('tracks.queueTrack') }}
        </v-btn>
        <v-btn
          :color="favorites.isTrackFavorite(track.id) ? 'primary' : undefined"
          :prepend-icon="favorites.isTrackFavorite(track.id) ? 'mdi-heart' : 'mdi-heart-outline'"
          variant="text"
          @click="void favorites.toggleTrack(track.id)"
        >
          {{ favorites.isTrackFavorite(track.id) ? t('favorites.removeTrack') : t('favorites.addTrack') }}
        </v-btn>
        <v-btn
          color="primary"
          prepend-icon="mdi-playlist-music"
          variant="text"
          @click="addToPlaylistDialog = true"
        >
          {{ t('playlists.addTrackToPlaylist') }}
        </v-btn>
      </v-card-actions>
    </v-card>

    <v-row>
      <v-col cols="12" md="5">
        <v-card border rounded="xl" height="100%">
          <v-card-title>{{ t('tracks.detailTitle') }}</v-card-title>
          <v-list lines="two">
            <v-list-item :title="t('tracks.artists')" :subtitle="artistNames" />
            <v-list-item
              :title="t('tracks.album')"
              :subtitle="track.album?.title ?? t('catalog.unknownAlbum')"
            />
            <v-list-item :title="t('tracks.duration')" :subtitle="duration(track.durationMs)" />
            <v-list-item :title="t('tracks.trackNumber')" :subtitle="trackNumber()" />
            <v-list-item v-if="track.year" :title="t('tracks.year')" :subtitle="String(track.year)" />
            <v-list-item :title="t('tracks.playCount')" :subtitle="String(track.playStatistics.playCount)" />
            <v-list-item
              v-if="track.playStatistics.firstPlayedAt"
              :title="t('tracks.firstPlayedAt')"
              :subtitle="formatDate(track.playStatistics.firstPlayedAt) ?? ''"
            />
            <v-list-item
              v-if="track.playStatistics.lastPlayedAt"
              :title="t('tracks.lastPlayedAt')"
              :subtitle="formatDate(track.playStatistics.lastPlayedAt) ?? ''"
            />
          </v-list>
        </v-card>
      </v-col>

      <v-col cols="12" md="7">
        <v-card border rounded="xl" height="100%">
          <v-card-title>{{ t('tracks.technicalDetails') }}</v-card-title>
          <v-list v-if="technicalRows.length" lines="two">
            <v-list-item v-for="row in technicalRows" :key="row.label" :title="row.label">
              <template #subtitle>
                <span class="technical-value">{{ row.value }}</span>
              </template>
            </v-list-item>
          </v-list>
          <v-card-text v-else class="text-medium-emphasis">
            {{ t('tracks.noTechnicalMetadata') }}
          </v-card-text>
          <v-alert v-if="track.mediaFile?.scanError" class="ma-4" type="warning" variant="tonal">
            {{ track.mediaFile.scanError }}
          </v-alert>
        </v-card>
      </v-col>
    </v-row>
  </template>

  <EmptyCatalogState v-else :title="t('tracks.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-music-note-outline" />

  <AddToPlaylistDialog v-model="addToPlaylistDialog" :tracks="playlistTracks" />
</template>

<style scoped>
.technical-value {
  overflow-wrap: anywhere;
}
</style>
