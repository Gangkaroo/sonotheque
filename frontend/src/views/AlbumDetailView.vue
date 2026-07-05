<script setup lang="ts">
import { computed, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import AlbumOnlineInformation from '@/components/AlbumOnlineInformation.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { apiRequest } from '@/api/client'
import type { Track } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'

const { locale, t } = useI18n()
const route = useRoute()
const router = useRouter()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const artworkDialog = ref(false)
const addToPlaylistDialog = ref(false)
const playlistTracks = ref<Track[]>([])
const metadataDialog = ref(false)
const metadataStep = ref<'form' | 'preview' | 'queued'>('form')
const metadataLoading = ref(false)
const metadataError = ref<string | null>(null)
const metadataSuccess = ref(false)
const metadataPreview = ref<AlbumMetadataPreview | null>(null)
const metadataJob = ref<AlbumMetadataJob | null>(null)
const metadataForm = reactive({ albumTitle: '', albumArtist: '', releaseYear: '', totalDiscs: '', genres: [] as string[] })
let metadataPollTimer: ReturnType<typeof setTimeout> | null = null

interface AlbumMetadataValues {
  albumTitle: string
  albumArtist: string
  releaseYear: number | null
  totalDiscs: number | null
  genres: string[]
}

interface AlbumMetadataChange {
  field: 'albumTitle' | 'albumArtist' | 'releaseYear' | 'totalDiscs' | 'genres'
  current: string | number | string[] | null
  proposed: string | number | string[] | null
}

interface AlbumMetadataFile {
  trackId: number
  trackTitle: string
  file: string | null
  format: string | null
  supported: boolean
  supportIssue?: string | null
}

interface AlbumMetadataPreview {
  albumId: number
  fingerprint: string
  values: AlbumMetadataValues
  changes: AlbumMetadataChange[]
  files: AlbumMetadataFile[]
  supportedFiles: number
  unsupportedFiles: number
}

interface AlbumMetadataJobItem {
  id: number
  trackId: number
  status: string
  file?: string | null
  trackTitle?: string | null
  error?: string | null
  backup?: MetadataBackup | null
}

interface MetadataBackup {
  id: number
  path: string
  expiresAt: string | null
  restoredAt: string | null
  deletedAt: string | null
}

interface AlbumMetadataJob {
  id: number
  status: 'pending' | 'running' | 'completed' | 'partial' | 'failed'
  totalItems: number
  processedItems: number
  succeededItems: number
  failedItems: number
  items: AlbumMetadataJobItem[]
  error?: string | null
}

const albumId = computed(() => Number(route.params.id))
const album = computed(() => catalog.albumDetail)
const tracks = computed(() => album.value?.tracks ?? [])
const albumGenres = computed(() => album.value?.genres ?? [])
const artworkUrl = computed(() => album.value?.artworkUrl ?? album.value?.artworkThumbnailUrl ?? null)
const artworkStyle = computed(() => ({
  maxWidth: `${album.value?.artworkWidth ?? 1200}px`,
  maxHeight: `min(92vh, ${album.value?.artworkHeight ?? 1200}px)`,
}))
const albumDetails = computed(() => {
  if (!album.value) return ''

  const details = album.value.originalReleaseYear === undefined || album.value.originalReleaseYear === null
    ? t('albums.trackCount', { count: album.value.trackCount })
    : t('albums.details', { year: album.value.originalReleaseYear, count: album.value.trackCount })

  return album.value.discTotal
    ? `${details} · ${t('albums.discCount', { count: album.value.discTotal })}`
    : details
})
const albumPlaybackStats = computed(() => {
  const totalTrackPlays = tracks.value.reduce((total, track) => total + track.playStatistics.playCount, 0)
  if (!totalTrackPlays) return []

  const playedTracks = tracks.value.filter((track) => track.playStatistics.playCount > 0).length
  const lastPlayedAt = tracks.value
    .map((track) => track.playStatistics.lastPlayedAt)
    .filter((value): value is string => Boolean(value))
    .sort((left, right) => new Date(right).getTime() - new Date(left).getTime())[0] ?? null

  return [
    {
      key: 'totalTrackPlays',
      label: t('albums.totalTrackPlays'),
      value: t('tracks.playCountTooltip', { count: totalTrackPlays }),
      icon: 'mdi-headphones-box',
    },
    {
      key: 'playedTracks',
      label: t('albums.playedTracks'),
      value: t('albums.playedTracksCount', { played: playedTracks, total: tracks.value.length }),
      icon: 'mdi-music-note-outline',
    },
    {
      key: 'lastPlayedAt',
      label: t('tracks.lastPlayedAt'),
      value: formatDate(lastPlayedAt),
      icon: 'mdi-calendar-clock',
    },
  ]
})

function duration(milliseconds?: number) {
  if (!milliseconds) return '-'
  const seconds = Math.round(milliseconds / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function formatDate(value?: string | null) {
  if (!value) return '-'
  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

function playCountTooltip(track: Track) {
  return [
    t('tracks.playCountTooltip', { count: track.playStatistics.playCount }),
    t('tracks.firstPlayedAtTooltip', { value: formatDate(track.playStatistics.firstPlayedAt) }),
    t('tracks.lastPlayedAtTooltip', { value: formatDate(track.playStatistics.lastPlayedAt) }),
  ]
}

function trackNumber(track: Track) {
  const parts = [track.discNumber, track.trackNumber].filter((part) => part !== undefined && part !== null)
  return parts.length ? parts.join('.') : '-'
}

function isCurrentTrack(track: Track) {
  return player.currentTrack?.id === track.id
}

function playAlbum() {
  const [firstTrack] = tracks.value
  if (!firstTrack) return

  player.playTrack(firstTrack, tracks.value, 'album')
}

function queueAlbum() {
  if (!album.value) return

  player.queueAlbum(album.value)
}

function addAlbumToPlaylist() {
  playlistTracks.value = [...tracks.value]
  addToPlaylistDialog.value = true
}

function addTrackToPlaylist(track: Track) {
  playlistTracks.value = [track]
  addToPlaylistDialog.value = true
}

function openMetadataEditor() {
  if (!album.value) return

  Object.assign(metadataForm, {
    albumTitle: album.value.title,
    albumArtist: album.value.primaryArtist?.name ?? '',
    releaseYear: album.value.originalReleaseYear?.toString() ?? '',
    totalDiscs: album.value.discTotal?.toString() ?? '',
    genres: albumGenres.value.map((genre) => genre.name),
  })
  metadataStep.value = 'form'
  metadataPreview.value = null
  metadataJob.value = null
  metadataError.value = null
  metadataDialog.value = true
}

function metadataValues(): AlbumMetadataValues {
  const year = metadataForm.releaseYear.trim()
  const totalDiscs = metadataForm.totalDiscs.trim()

  return {
    albumTitle: metadataForm.albumTitle.trim(),
    albumArtist: metadataForm.albumArtist.trim(),
    releaseYear: year === '' ? null : Number(year),
    totalDiscs: totalDiscs === '' ? null : Number(totalDiscs),
    genres: metadataForm.genres.map((genre) => genre.trim()).filter(Boolean),
  }
}

async function previewMetadataEdit() {
  if (!album.value) return

  metadataLoading.value = true
  metadataError.value = null
  try {
    metadataPreview.value = await apiRequest<AlbumMetadataPreview>(`/albums/${album.value.id}/metadata/preview`, {
      method: 'POST',
      body: JSON.stringify(metadataValues()),
    })
    metadataStep.value = 'preview'
  } catch (cause) {
    metadataError.value = cause instanceof Error ? cause.message : t('albums.metadataEditFailed')
  } finally {
    metadataLoading.value = false
  }
}

async function queueMetadataEdit() {
  if (!album.value || !metadataPreview.value) return

  metadataLoading.value = true
  metadataError.value = null
  try {
    metadataJob.value = await apiRequest<AlbumMetadataJob>(`/albums/${album.value.id}/metadata-edits`, {
      method: 'POST',
      body: JSON.stringify({
        ...metadataPreview.value.values,
        fingerprint: metadataPreview.value.fingerprint,
      }),
    })
    metadataStep.value = 'queued'
    scheduleMetadataPoll()
  } catch (cause) {
    metadataError.value = cause instanceof Error ? cause.message : t('albums.metadataEditFailed')
  } finally {
    metadataLoading.value = false
  }
}

function scheduleMetadataPoll() {
  if (metadataPollTimer) clearTimeout(metadataPollTimer)
  metadataPollTimer = setTimeout(pollMetadataEdit, 1000)
}

async function pollMetadataEdit() {
  if (!metadataJob.value) return

  try {
    metadataJob.value = await apiRequest<AlbumMetadataJob>(`/metadata-edits/${metadataJob.value.id}`)
    if (metadataJob.value.status === 'completed') {
      metadataDialog.value = false
      metadataSuccess.value = true
      catalog.invalidateMetrics()
      await catalog.loadAlbum(albumId.value)
      return
    }
    if (['partial', 'failed'].includes(metadataJob.value.status)) return
    scheduleMetadataPoll()
  } catch (cause) {
    metadataError.value = cause instanceof Error ? cause.message : t('albums.metadataEditFailed')
  }
}

function metadataFieldLabel(field: AlbumMetadataChange['field']) {
  return {
    albumTitle: t('albums.metadataAlbumTitle'),
    albumArtist: t('albums.metadataAlbumArtist'),
    releaseYear: t('albums.releaseYear'),
    totalDiscs: t('albums.totalDiscs'),
    genres: t('tracks.genres'),
  }[field]
}

function metadataValue(value: AlbumMetadataChange['current']) {
  if (Array.isArray(value)) return value.length ? value.join(', ') : '-'
  return value === null || value === '' ? '-' : String(value)
}

function metadataProgress() {
  if (!metadataJob.value?.totalItems) return 0
  return Math.round((metadataJob.value.processedItems / metadataJob.value.totalItems) * 100)
}

function metadataUnsupportedSummary() {
  const unsupportedFiles = metadataPreview.value?.files.filter((file) => !file.supported) ?? []
  const issues = unsupportedFiles.map((file) => file.supportIssue)
  const count = unsupportedFiles.length

  if (issues.every((issue) => issue?.startsWith('id3v2_'))) {
    return t('albums.metadataUnsupportedTagFiles', { count })
  }
  if (issues.every((issue) => issue === 'unsupported_format')) {
    return t('albums.metadataUnsupportedFiles', { count })
  }

  return t('albums.metadataUnsupportedMixedFiles', { count })
}

function openArtwork() {
  if (!artworkUrl.value) return

  artworkDialog.value = true
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

  player.playTrack(track, tracks.value, 'album')
}

watch(albumId, (id) => {
  if (Number.isInteger(id) && id > 0) void catalog.loadAlbum(id)
}, { immediate: true })

watch(() => player.currentTrack?.album?.id, (id) => {
  if (!id || player.playbackContext !== 'album' || route.name !== 'album-detail' || id === albumId.value) return

  void router.replace({ name: 'album-detail', params: { id } })
})

onUnmounted(() => {
  if (metadataPollTimer) clearTimeout(metadataPollTimer)
})
</script>

<template>
  <v-btn class="mb-4" variant="text" prepend-icon="mdi-arrow-left" :to="{ name: 'albums' }">
    {{ t('albums.back') }}
  </v-btn>

  <v-alert v-if="catalog.albumDetailError" type="error" variant="tonal">
    {{ catalog.albumDetailError }}
  </v-alert>

  <v-skeleton-loader v-else-if="catalog.albumDetailLoading" type="card, list-item-three-line@6" />

  <template v-else-if="album">
    <v-row class="mb-8">
      <v-col cols="12" md="4" lg="3">
        <v-card
          v-if="artworkUrl"
          border
          rounded="xl"
          class="overflow-hidden album-cover-card"
          @click="openArtwork"
        >
          <v-img :src="artworkUrl" aspect-ratio="1" cover />
        </v-card>
        <v-card v-else border rounded="xl" class="overflow-hidden">
          <div class="d-flex align-center justify-center bg-surface-bright album-cover-placeholder">
            <v-icon icon="mdi-album" size="88" color="medium-emphasis" />
          </div>
        </v-card>
      </v-col>

      <v-col cols="12" md="8" lg="9">
        <v-card border rounded="xl" height="100%">
          <v-card-item>
            <template #prepend>
              <v-avatar color="primary" variant="tonal" size="44">
                <v-icon icon="mdi-album" />
              </v-avatar>
            </template>
            <v-card-title>{{ album.title }}</v-card-title>
            <v-card-subtitle>{{ album.primaryArtist?.name ?? t('catalog.unknownArtist') }}</v-card-subtitle>
          </v-card-item>
          <v-card-text class="text-medium-emphasis">
            {{ albumDetails }}
            <div v-if="albumGenres.length" class="d-flex flex-wrap ga-2 mt-4">
              <v-chip
                v-for="genre in albumGenres"
                :key="genre.id"
                :to="{ name: 'albums', query: { genre: genre.id, genreName: genre.name } }"
                size="small"
                variant="tonal"
              >
                {{ genre.name }}
              </v-chip>
            </div>
            <div v-if="albumPlaybackStats.length" class="album-stat-grid mt-4">
              <div v-for="stat in albumPlaybackStats" :key="stat.key" class="album-stat-tile">
                <v-icon color="primary" :icon="stat.icon" size="small" />
                <div>
                  <div class="text-caption text-medium-emphasis">{{ stat.label }}</div>
                  <div class="text-body-2 font-weight-medium">{{ stat.value }}</div>
                </div>
              </div>
            </div>
          </v-card-text>
          <v-card-actions class="album-actions">
            <v-btn color="primary" variant="flat" prepend-icon="mdi-play" :disabled="!tracks.length" @click="playAlbum">
              {{ t('albums.playAlbum') }}
            </v-btn>
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-playlist-plus" :disabled="!tracks.length" @click="queueAlbum">
              {{ t('albums.queueAlbum') }}
            </v-btn>
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-playlist-music" :disabled="!tracks.length" @click="addAlbumToPlaylist">
              {{ t('playlists.addAlbumToPlaylist') }}
            </v-btn>
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-tag-edit-outline" :disabled="!tracks.length" @click="openMetadataEditor">
              {{ t('albums.editMetadata') }}
            </v-btn>
            <v-btn
              color="primary"
              :prepend-icon="favorites.isAlbumFavorite(album.id) ? 'mdi-heart' : 'mdi-heart-outline'"
              variant="tonal"
              @click="void favorites.toggleAlbum(album.id)"
            >
              {{ favorites.isAlbumFavorite(album.id) ? t('favorites.removeAlbum') : t('favorites.addAlbum') }}
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-col>
    </v-row>

    <v-list v-if="tracks.length" border rounded="xl" lines="two">
      <v-list-item
        v-for="track in tracks"
        :key="track.id"
        class="track-list-item"
        :class="{ 'current-track': isCurrentTrack(track) }"
      >
        <template #prepend>
          <span
            class="track-number"
            :class="isCurrentTrack(track) ? 'text-primary font-weight-bold' : 'text-medium-emphasis'"
          >
            {{ trackNumber(track) }}
          </span>
        </template>
        <v-list-item-title class="font-weight-bold" :class="{ 'text-primary': isCurrentTrack(track) }">
          <RouterLink
            class="track-detail-link"
            :to="{ name: 'track-detail', params: { id: track.id }, query: { backAlbum: album.id } }"
          >
            {{ track.title }}
          </RouterLink>
        </v-list-item-title>
        <v-list-item-subtitle>
          {{ track.artists.map((artist) => artist.name).join(', ') || t('catalog.unknownArtist') }}
        </v-list-item-subtitle>
        <template #append>
          <div class="track-actions">
            <span class="text-caption text-medium-emphasis">{{ duration(track.durationMs) }}</span>
            <v-tooltip location="top">
              <template #activator="{ props }">
                <span v-bind="props" class="play-count text-caption text-medium-emphasis">
                  <v-icon class="play-count-icon" icon="mdi-headphones" size="x-small" />
                  {{ track.playStatistics.playCount }}
                </span>
              </template>
              <div class="play-count-tooltip">
                <div v-for="(line, index) in playCountTooltip(track)" :key="index">{{ line }}</div>
              </div>
            </v-tooltip>
            <TooltipIconButton
              :text="isCurrentTrack(track) && player.isPlaying ? t('player.pause') : t('player.play')"
              :aria-label="isCurrentTrack(track) && player.isPlaying ? t('player.pause') : t('player.play')"
              :color="isCurrentTrack(track) ? 'primary' : undefined"
              density="comfortable"
              :icon="isCurrentTrack(track) && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
              variant="text"
              @click="toggleTrack(track)"
            />
            <TooltipIconButton
              :text="t('playlists.addTrackToPlaylist')"
              :aria-label="t('playlists.addTrackToPlaylist')"
              density="comfortable"
              icon="mdi-playlist-music"
              variant="text"
              @click="addTrackToPlaylist(track)"
            />
            <TooltipIconButton
              :text="favorites.isTrackFavorite(track.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
              :aria-label="favorites.isTrackFavorite(track.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
              :color="favorites.isTrackFavorite(track.id) ? 'primary' : undefined"
              density="comfortable"
              :icon="favorites.isTrackFavorite(track.id) ? 'mdi-heart' : 'mdi-heart-outline'"
              variant="text"
              @click="void favorites.toggleTrack(track.id)"
            />
          </div>
        </template>
      </v-list-item>
    </v-list>
    <EmptyCatalogState v-else :title="t('albums.noTracksTitle')" :description="t('catalog.scanPrompt')" icon="mdi-music-note-outline" />

    <AlbumOnlineInformation v-if="tracks[0]" class="mt-8" :track-id="tracks[0].id" />
  </template>

  <v-dialog v-model="artworkDialog" class="album-artwork-dialog" max-width="none">
    <div class="album-artwork-overlay" @click="artworkDialog = false">
      <v-img
        v-if="artworkUrl"
        :src="artworkUrl"
        :style="artworkStyle"
        class="album-artwork-full"
      />
    </div>
  </v-dialog>

  <AddToPlaylistDialog v-model="addToPlaylistDialog" :tracks="playlistTracks" />

  <v-dialog v-model="metadataDialog" max-width="760" persistent scrollable>
    <v-card prepend-icon="mdi-tag-edit-outline" :title="t('albums.editMetadata')">
      <v-card-text>
        <v-alert v-if="metadataError" class="mb-4" type="error" variant="tonal">
          {{ metadataError }}
        </v-alert>

        <template v-if="metadataStep === 'form'">
          <v-text-field v-model="metadataForm.albumTitle" :label="t('albums.metadataAlbumTitle')" maxlength="512" />
          <v-text-field v-model="metadataForm.albumArtist" :label="t('albums.metadataAlbumArtist')" maxlength="512" />
          <v-text-field v-model="metadataForm.releaseYear" inputmode="numeric" :label="t('albums.releaseYear')" />
          <v-text-field v-model="metadataForm.totalDiscs" inputmode="numeric" :label="t('albums.totalDiscs')" />
          <v-combobox
            v-model="metadataForm.genres"
            chips
            closable-chips
            clearable
            :hint="t('albums.metadataGenresHint')"
            :label="t('tracks.genres')"
            multiple
            persistent-hint
          />
          <div class="text-caption text-medium-emphasis">{{ t('albums.metadataPreviewHint') }}</div>
        </template>

        <template v-else-if="metadataStep === 'preview' && metadataPreview">
          <v-alert v-if="metadataPreview.unsupportedFiles" class="mb-4" type="warning" variant="tonal">
            {{ metadataUnsupportedSummary() }}
          </v-alert>
          <v-list v-if="metadataPreview.changes.length" border rounded class="mb-4">
            <v-list-item v-for="change in metadataPreview.changes" :key="change.field">
              <v-list-item-title>{{ metadataFieldLabel(change.field) }}</v-list-item-title>
              <v-list-item-subtitle class="metadata-change">
                <span>{{ metadataValue(change.current) }}</span>
                <v-icon icon="mdi-arrow-right" size="small" />
                <strong>{{ metadataValue(change.proposed) }}</strong>
              </v-list-item-subtitle>
            </v-list-item>
          </v-list>
          <v-alert v-else class="mb-4" type="info" variant="tonal">{{ t('albums.metadataNoChanges') }}</v-alert>

          <div class="text-subtitle-2 mb-2">{{ t('albums.metadataAffectedFiles', { count: metadataPreview.files.length }) }}</div>
          <v-list border rounded class="metadata-file-list" density="compact">
            <v-list-item
              v-for="file in metadataPreview.files"
              :key="file.trackId"
              :prepend-icon="file.supported ? 'mdi-file-music-outline' : 'mdi-alert-outline'"
              :title="file.trackTitle"
            >
              <v-list-item-subtitle>
                <div>{{ file.file ?? '-' }}</div>
                <div v-if="file.supportIssue" class="text-warning">
                  {{ t(`tracks.metadataSupportIssues.${file.supportIssue}`) }}
                </div>
              </v-list-item-subtitle>
              <template #append>
                <v-chip :color="file.supported ? 'success' : 'warning'" size="x-small" variant="tonal">
                  {{ file.format ?? '-' }}
                </v-chip>
              </template>
            </v-list-item>
          </v-list>
        </template>

        <template v-else-if="metadataStep === 'queued' && metadataJob">
          <div class="font-weight-bold mb-2">{{ t(`albums.metadataStatuses.${metadataJob.status}`) }}</div>
          <v-progress-linear
            class="mb-2"
            color="primary"
            :model-value="metadataProgress()"
            rounded
          />
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('albums.metadataProgress', {
              processed: metadataJob.processedItems,
              total: metadataJob.totalItems,
              failed: metadataJob.failedItems,
            }) }}
          </div>
          <v-list v-if="metadataJob.items.some((item) => item.status === 'failed')" border rounded class="metadata-file-list" density="compact">
            <v-list-item
              v-for="item in metadataJob.items.filter((entry) => entry.status === 'failed')"
              :key="item.id"
              prepend-icon="mdi-alert-circle-outline"
              :title="item.trackTitle ?? item.file ?? String(item.trackId)"
            >
              <v-list-item-subtitle>{{ item.error ?? t('albums.metadataEditFailed') }}</v-list-item-subtitle>
              <v-list-item-subtitle v-if="item.backup">
                {{ t('settings.metadataBackupRecovery', { id: item.backup.id, path: item.backup.path }) }}
              </v-list-item-subtitle>
            </v-list-item>
          </v-list>
        </template>
      </v-card-text>
      <v-card-actions>
        <v-btn v-if="metadataStep === 'preview'" :disabled="metadataLoading" @click="metadataStep = 'form'">
          {{ t('tracks.metadataBack') }}
        </v-btn>
        <v-spacer />
        <v-btn
          :disabled="metadataLoading || (metadataStep === 'queued' && !['partial', 'failed'].includes(metadataJob?.status ?? ''))"
          @click="metadataDialog = false"
        >
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn
          v-if="metadataStep === 'form'"
          color="primary"
          :disabled="!metadataForm.albumTitle.trim() || !metadataForm.albumArtist.trim()"
          :loading="metadataLoading"
          variant="flat"
          @click="previewMetadataEdit"
        >
          {{ t('tracks.metadataReview') }}
        </v-btn>
        <v-btn
          v-else-if="metadataStep === 'preview'"
          color="primary"
          :disabled="!metadataPreview?.changes.length || Boolean(metadataPreview?.unsupportedFiles)"
          :loading="metadataLoading"
          variant="flat"
          @click="queueMetadataEdit"
        >
          {{ t('tracks.metadataApply') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-snackbar v-model="metadataSuccess" color="success" timeout="3000">
    {{ t('albums.metadataCompleted') }}
  </v-snackbar>
</template>

<style scoped>
.album-cover-card {
  cursor: zoom-in;
}

.album-cover-placeholder {
  aspect-ratio: 1;
}

.album-actions {
  flex-wrap: wrap;
  gap: 0.5rem;
}

.metadata-change {
  align-items: center;
  display: flex;
  gap: 8px;
}

.metadata-file-list {
  max-height: 320px;
  overflow-y: auto;
}

.album-stat-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
}

.album-stat-tile {
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  display: flex;
  gap: 10px;
  min-width: 0;
  padding: 10px;
}

@media (max-width: 480px) {
  .album-actions :deep(.v-btn) {
    flex: 1 1 auto;
  }
}

.album-artwork-overlay {
  align-items: center;
  cursor: zoom-out;
  display: flex;
  justify-content: center;
  min-height: 100vh;
  padding: 24px;
}

.album-artwork-full {
  width: 92vw;
}

.current-track {
  background: rgba(var(--v-theme-primary), 0.08);
}

.track-list-item {
  transition: background-color 120ms ease;
}

.track-list-item:hover {
  background: rgba(var(--v-theme-on-surface), 0.04);
}

.track-list-item.current-track:hover {
  background: rgba(var(--v-theme-primary), 0.12);
}

.track-number {
  display: inline-flex;
  justify-content: end;
  min-width: 2.5rem;
  padding-inline-end: 0.75rem;
  font-variant-numeric: tabular-nums;
}

.track-detail-link {
  color: inherit;
  text-decoration: none;
}

.track-detail-link:hover {
  text-decoration: underline;
}

.play-count {
  align-items: center;
  display: inline-flex;
  gap: 0.2rem;
  font-variant-numeric: tabular-nums;
  line-height: 1;
}

.play-count-icon {
  align-self: center;
  transform: translateY(1px);
}

.play-count-tooltip {
  line-height: 1.5;
}

.track-actions {
  align-items: center;
  display: flex;
  gap: 4px;
}

.track-actions :deep(.v-btn) {
  min-width: 34px;
  padding-inline: 0;
}

@media (max-width: 480px) {
  .play-count {
    display: none;
  }
}
</style>
