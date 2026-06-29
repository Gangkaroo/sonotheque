<script setup lang="ts">
import { computed, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import { apiRequest } from '@/api/client'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'
import { useStatisticsStore } from '@/stores/statistics'

const { locale, t } = useI18n()
const route = useRoute()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const statistics = useStatisticsStore()
const addToPlaylistDialog = ref(false)
const recentPlaysPage = ref(1)
const metadataDialog = ref(false)
const metadataStep = ref<'form' | 'preview' | 'queued'>('form')
const metadataLoading = ref(false)
const metadataError = ref<string | null>(null)
const metadataSuccess = ref(false)
const metadataPreview = ref<MetadataPreview | null>(null)
const metadataJob = ref<MetadataEditJob | null>(null)
const metadataForm = reactive({
  title: '',
  artistNames: [] as string[],
  composers: [] as string[],
  performers: [] as string[],
  comment: '',
  trackNumber: '',
  discNumber: '',
  year: '',
})
let metadataPollTimer: ReturnType<typeof setTimeout> | null = null

interface MetadataChange {
  field: 'title' | 'artistNames' | 'composers' | 'performers' | 'comment' | 'trackNumber' | 'discNumber' | 'year'
  current: string | number | string[] | null
  proposed: string | number | string[] | null
}

interface MetadataPreview {
  trackId: number
  file: string
  format: string
  supported: boolean
  fingerprint: string
  values: MetadataValues
  changes: MetadataChange[]
}

interface MetadataValues {
  title: string
  artistNames: string[]
  composers: string[]
  performers: string[]
  comment: string | null
  trackNumber: number | null
  discNumber: number | null
  year: number | null
}

interface MetadataEditJob {
  id: number
  trackId: number
  status: 'pending' | 'running' | 'completed' | 'failed'
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
const canEditMetadata = computed(() => track.value?.mediaFile?.relativePath.toLowerCase().endsWith('.mp3') ?? false)
const artistNames = computed(() => track.value?.artists.map((artist) => artist.name).join(', ') || t('catalog.unknownArtist'))
const trackDetailRows = computed(() => {
  if (!track.value) return []

  return [
    { label: t('tracks.artists'), value: artistNames.value },
    { label: t('tracks.composers'), value: track.value.composers.join(', ') || null },
    { label: t('tracks.performers'), value: track.value.performers.join(', ') || null },
    { label: t('tracks.album'), value: track.value.album?.title ?? t('catalog.unknownAlbum') },
    { label: t('tracks.duration'), value: duration(track.value.durationMs) },
    { label: t('tracks.trackNumber'), value: trackNumber() },
    { label: t('tracks.year'), value: track.value.year ? String(track.value.year) : null },
    { label: t('tracks.comment'), value: track.value.comment },
  ].filter((row) => row.value)
})
const playbackStatTiles = computed(() => {
  if (!track.value) return []

  return [
    {
      key: 'playCount',
      title: t('tracks.playCount'),
      value: String(track.value.playStatistics.playCount),
      icon: 'mdi-headphones',
    },
    {
      key: 'firstPlayedAt',
      title: t('tracks.firstPlayedAt'),
      value: formatDate(track.value.playStatistics.firstPlayedAt) ?? '-',
      icon: 'mdi-calendar-start',
    },
    {
      key: 'lastPlayedAt',
      title: t('tracks.lastPlayedAt'),
      value: formatDate(track.value.playStatistics.lastPlayedAt) ?? '-',
      icon: 'mdi-calendar-clock',
    },
  ]
})
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

  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

function playTrack() {
  if (!track.value) return

  player.playTrack(track.value, [track.value], 'track-list')
}

function queueTrack() {
  if (!track.value) return

  player.queueTrack(track.value, 'track-list')
}

function openMetadataEditor() {
  if (!track.value) return

  Object.assign(metadataForm, {
    title: track.value.title,
    artistNames: track.value.artists.map((artist) => artist.name),
    composers: [...track.value.composers],
    performers: [...track.value.performers],
    comment: track.value.comment ?? '',
    trackNumber: track.value.trackNumber?.toString() ?? '',
    discNumber: track.value.discNumber?.toString() ?? '',
    year: track.value.year?.toString() ?? '',
  })
  metadataStep.value = 'form'
  metadataPreview.value = null
  metadataJob.value = null
  metadataError.value = null
  metadataDialog.value = true
}

function metadataValues(): MetadataValues {
  return {
    title: metadataForm.title.trim(),
    artistNames: cleanNames(metadataForm.artistNames),
    composers: cleanNames(metadataForm.composers),
    performers: cleanNames(metadataForm.performers),
    comment: metadataForm.comment.trim() || null,
    trackNumber: nullableInteger(metadataForm.trackNumber),
    discNumber: nullableInteger(metadataForm.discNumber),
    year: nullableInteger(metadataForm.year),
  }
}

function cleanNames(names: string[]) {
  return names.map((name) => name.trim()).filter(Boolean)
}

function nullableInteger(value: string) {
  const trimmed = value.trim()
  return trimmed === '' ? null : Number(trimmed)
}

async function previewMetadataEdit() {
  if (!track.value) return

  metadataLoading.value = true
  metadataError.value = null
  try {
    metadataPreview.value = await apiRequest<MetadataPreview>(`/tracks/${track.value.id}/metadata/preview`, {
      method: 'POST',
      body: JSON.stringify(metadataValues()),
    })
    metadataStep.value = 'preview'
  } catch (cause) {
    metadataError.value = cause instanceof Error ? cause.message : t('tracks.metadataEditFailed')
  } finally {
    metadataLoading.value = false
  }
}

async function queueMetadataEdit() {
  if (!track.value || !metadataPreview.value) return

  metadataLoading.value = true
  metadataError.value = null
  try {
    metadataJob.value = await apiRequest<MetadataEditJob>(`/tracks/${track.value.id}/metadata-edits`, {
      method: 'POST',
      body: JSON.stringify({
        ...metadataPreview.value.values,
        fingerprint: metadataPreview.value.fingerprint,
      }),
    })
    metadataStep.value = 'queued'
    scheduleMetadataPoll()
  } catch (cause) {
    metadataError.value = cause instanceof Error ? cause.message : t('tracks.metadataEditFailed')
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
    metadataJob.value = await apiRequest<MetadataEditJob>(`/metadata-edits/${metadataJob.value.id}`)
    if (metadataJob.value.status === 'completed') {
      metadataDialog.value = false
      metadataSuccess.value = true
      await catalog.loadTrack(trackId.value)
      return
    }
    if (metadataJob.value.status === 'failed') {
      metadataError.value = metadataJob.value.error ?? t('tracks.metadataEditFailed')
      return
    }
    scheduleMetadataPoll()
  } catch (cause) {
    metadataError.value = cause instanceof Error ? cause.message : t('tracks.metadataEditFailed')
  }
}

function metadataFieldLabel(field: MetadataChange['field']) {
  return {
    title: t('tracks.metadataTitle'),
    artistNames: t('tracks.artists'),
    composers: t('tracks.composers'),
    performers: t('tracks.performers'),
    comment: t('tracks.comment'),
    trackNumber: t('tracks.trackNumber'),
    discNumber: t('tracks.discNumber'),
    year: t('tracks.year'),
  }[field]
}

function metadataValue(value: MetadataChange['current']) {
  if (Array.isArray(value)) return value.length ? value.join(', ') : '-'
  return value === null || value === '' ? '-' : String(value)
}

function toggleTrack() {
  if (!track.value) return

  if (isCurrentTrack.value) {
    if (player.isPlaying) {
      player.pause()
    } else {
      player.resume()
    }
    return
  }

  playTrack()
}

function loadRecentPlays(page = recentPlaysPage.value) {
  if (!Number.isInteger(trackId.value) || trackId.value <= 0) return

  recentPlaysPage.value = page
  void statistics.loadTrackRecentPlays(trackId.value, page)
}

watch(trackId, (id) => {
  if (!Number.isInteger(id) || id <= 0) return

  recentPlaysPage.value = 1
  void catalog.loadTrack(id)
  loadRecentPlays(1)
}, { immediate: true })

onUnmounted(() => {
  if (metadataPollTimer) clearTimeout(metadataPollTimer)
})
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
      <v-card-actions class="track-actions">
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
          color="primary"
          prepend-icon="mdi-playlist-music"
          variant="tonal"
          @click="addToPlaylistDialog = true"
        >
          {{ t('playlists.addTrackToPlaylist') }}
        </v-btn>
        <v-btn
          color="primary"
          prepend-icon="mdi-tag-edit-outline"
          variant="tonal"
          @click="openMetadataEditor"
        >
          {{ t('tracks.editMetadata') }}
        </v-btn>
        <v-btn
          color="primary"
          :prepend-icon="favorites.isTrackFavorite(track.id) ? 'mdi-heart' : 'mdi-heart-outline'"
          variant="tonal"
          @click="void favorites.toggleTrack(track.id)"
        >
          {{ favorites.isTrackFavorite(track.id) ? t('favorites.removeTrack') : t('favorites.addTrack') }}
        </v-btn>
      </v-card-actions>
    </v-card>

    <v-row>
      <v-col cols="12" md="5">
        <v-card border rounded="xl" height="100%">
          <v-card-title>{{ t('tracks.detailTitle') }}</v-card-title>
          <v-card-text>
            <div class="detail-grid">
              <div v-for="row in trackDetailRows" :key="row.label" class="detail-field">
                <div class="text-caption text-medium-emphasis">{{ row.label }}</div>
                <div class="text-body-2 font-weight-medium detail-value">{{ row.value }}</div>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="7">
        <v-card border rounded="xl" height="100%">
          <v-card-title>{{ t('tracks.playbackStatistics') }}</v-card-title>
          <v-card-text>
            <v-row dense>
              <v-col v-for="stat in playbackStatTiles" :key="stat.key" cols="12" sm="4">
                <div class="stat-tile">
                  <v-icon color="primary" :icon="stat.icon" />
                  <div>
                    <div class="text-caption text-medium-emphasis">{{ stat.title }}</div>
                    <div class="text-body-2 font-weight-bold">{{ stat.value }}</div>
                  </div>
                </div>
              </v-col>
            </v-row>
          </v-card-text>
          <v-divider />
          <v-card-title class="text-subtitle-1">{{ t('tracks.recentPlays') }}</v-card-title>
          <v-skeleton-loader v-if="statistics.trackRecentPlaysLoading" type="list-item-two-line@3" />
          <v-alert v-else-if="statistics.trackRecentPlaysError" class="mx-4 mb-4" type="error" variant="tonal">
            {{ statistics.trackRecentPlaysError }}
          </v-alert>
          <v-list v-else-if="statistics.trackRecentPlays.items.length" density="comfortable">
            <v-list-item
              v-for="play in statistics.trackRecentPlays.items"
              :key="play.id"
              prepend-icon="mdi-clock-outline"
              :title="formatDate(play.playedAt) ?? '-'"
            />
          </v-list>
          <v-card-text v-else class="text-medium-emphasis">
            {{ t('tracks.noRecentPlays') }}
          </v-card-text>
          <v-card-actions v-if="statistics.trackRecentPlays.lastPage > 1">
            <v-pagination
              v-model="recentPlaysPage"
              density="comfortable"
              :length="statistics.trackRecentPlays.lastPage"
              @update:model-value="loadRecentPlays"
            />
          </v-card-actions>
        </v-card>
      </v-col>

      <v-col cols="12">
        <v-card border rounded="xl" height="100%">
          <v-card-title>{{ t('tracks.technicalDetails') }}</v-card-title>
          <v-card-text v-if="technicalRows.length">
            <div class="detail-grid technical-grid">
              <div v-for="row in technicalRows" :key="row.label" class="detail-field">
                <div class="text-caption text-medium-emphasis">{{ row.label }}</div>
                <div class="text-body-2 font-weight-medium detail-value">{{ row.value }}</div>
              </div>
            </div>
          </v-card-text>
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

  <v-dialog v-model="metadataDialog" max-width="680" persistent>
    <v-card prepend-icon="mdi-tag-edit-outline" :title="t('tracks.editMetadata')">
      <v-card-text>
        <v-alert v-if="metadataError" class="mb-4" type="error" variant="tonal">
          {{ metadataError }}
        </v-alert>

        <template v-if="metadataStep === 'form'">
          <v-alert v-if="!canEditMetadata" class="mb-4" type="warning" variant="tonal">
            {{ t('tracks.metadataMp3Only') }}
          </v-alert>
          <v-text-field v-model="metadataForm.title" :label="t('tracks.metadataTitle')" maxlength="512" />
          <v-combobox
            v-model="metadataForm.artistNames"
            chips
            closable-chips
            :label="t('tracks.artists')"
            multiple
          />
          <v-combobox
            v-model="metadataForm.composers"
            chips
            closable-chips
            clearable
            :label="t('tracks.composers')"
            multiple
          />
          <v-combobox
            v-model="metadataForm.performers"
            chips
            closable-chips
            clearable
            :label="t('tracks.performers')"
            multiple
          />
          <v-textarea v-model="metadataForm.comment" auto-grow :label="t('tracks.comment')" maxlength="10000" rows="2" />
          <v-row>
            <v-col cols="12" sm="4">
              <v-text-field v-model="metadataForm.trackNumber" inputmode="numeric" :label="t('tracks.trackNumber')" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="metadataForm.discNumber" inputmode="numeric" :label="t('tracks.discNumber')" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="metadataForm.year" inputmode="numeric" :label="t('tracks.year')" />
            </v-col>
          </v-row>
          <div class="text-caption text-medium-emphasis">{{ t('tracks.metadataPreviewHint') }}</div>
        </template>

        <template v-else-if="metadataStep === 'preview' && metadataPreview">
          <v-alert v-if="!metadataPreview.supported" class="mb-4" type="warning" variant="tonal">
            {{ t('tracks.metadataMp3Only') }}
          </v-alert>
          <div class="text-body-2 text-medium-emphasis mb-3">{{ metadataPreview.file }}</div>
          <v-list v-if="metadataPreview.changes.length" border rounded>
            <v-list-item v-for="change in metadataPreview.changes" :key="change.field">
              <v-list-item-title>{{ metadataFieldLabel(change.field) }}</v-list-item-title>
              <v-list-item-subtitle class="metadata-change">
                <span>{{ metadataValue(change.current) }}</span>
                <v-icon icon="mdi-arrow-right" size="small" />
                <strong>{{ metadataValue(change.proposed) }}</strong>
              </v-list-item-subtitle>
            </v-list-item>
          </v-list>
          <v-alert v-else type="info" variant="tonal">{{ t('tracks.metadataNoChanges') }}</v-alert>
        </template>

        <template v-else-if="metadataStep === 'queued' && metadataJob">
          <div class="d-flex align-center ga-3">
            <v-progress-circular v-if="metadataJob.status !== 'failed'" color="primary" indeterminate size="28" />
            <div>
              <div class="font-weight-bold">{{ t(`tracks.metadataStatuses.${metadataJob.status}`) }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ t('tracks.metadataQueuedHint') }}</div>
            </div>
          </div>
          <v-alert v-if="metadataJob.backup" class="mt-4" type="info" variant="tonal">
            {{ t('settings.metadataBackupRecovery', { id: metadataJob.backup.id, path: metadataJob.backup.path }) }}
          </v-alert>
        </template>
      </v-card-text>
      <v-card-actions>
        <v-btn
          v-if="metadataStep === 'preview'"
          :disabled="metadataLoading"
          @click="metadataStep = 'form'"
        >
          {{ t('tracks.metadataBack') }}
        </v-btn>
        <v-spacer />
        <v-btn
          :disabled="metadataLoading || (metadataStep === 'queued' && metadataJob?.status !== 'failed')"
          @click="metadataDialog = false"
        >
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn
          v-if="metadataStep === 'form'"
          color="primary"
          :disabled="!canEditMetadata || !metadataForm.title.trim() || !cleanNames(metadataForm.artistNames).length"
          :loading="metadataLoading"
          variant="flat"
          @click="previewMetadataEdit"
        >
          {{ t('tracks.metadataReview') }}
        </v-btn>
        <v-btn
          v-else-if="metadataStep === 'preview'"
          color="primary"
          :disabled="!metadataPreview?.supported || !metadataPreview?.changes.length"
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
    {{ t('tracks.metadataCompleted') }}
  </v-snackbar>
</template>

<style scoped>
.detail-grid {
  display: grid;
  gap: 12px 18px;
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
}

.technical-grid {
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
}

.detail-field {
  min-width: 0;
}

.detail-value {
  overflow-wrap: anywhere;
}

.stat-tile {
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 16px;
  display: flex;
  gap: 12px;
  height: 100%;
  padding: 12px;
}

.metadata-change {
  align-items: center;
  display: flex;
  gap: 8px;
}

.track-actions {
  flex-wrap: wrap;
  gap: 8px;
}

.track-actions :deep(.v-btn) {
  flex: 0 0 auto;
  margin-inline-start: 0 !important;
}

</style>
