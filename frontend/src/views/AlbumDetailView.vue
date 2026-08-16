<script setup lang="ts">
import { computed, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import AlbumOnlineInformation from '@/components/AlbumOnlineInformation.vue'
import AlbumPlaylistExportDialog from '@/components/AlbumPlaylistExportDialog.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import OwnedAlbumCopies from '@/components/OwnedAlbumCopies.vue'
import RichTextContent from '@/components/RichTextContent.vue'
import RichTextEditor from '@/components/RichTextEditor.vue'
import TrackBatchMetadataDialog from '@/components/TrackBatchMetadataDialog.vue'
import TrackPlaylistMembershipMenu from '@/components/TrackPlaylistMembershipMenu.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { apiRequest } from '@/api/client'
import type { AlbumPersonalMetadata, Track } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { usePlayerStore } from '@/stores/player'
import { usePlaylistsStore } from '@/stores/playlists'
import {
  formatDateTime,
  formatDuration as duration,
  formatTotalDuration,
} from '@/utils/formatters'

const { locale, t } = useI18n()
const route = useRoute()
const router = useRouter()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const libraryRoots = useLibraryRootsStore()
const player = usePlayerStore()
const playlists = usePlaylistsStore()
const artworkDialog = ref(false)
const addToPlaylistDialog = ref(false)
const playlistExportDialog = ref(false)
const playlistTracks = ref<Track[]>([])
const metadataDialog = ref(false)
const metadataStep = ref<'form' | 'preview' | 'queued'>('form')
const metadataLoading = ref(false)
const metadataError = ref<string | null>(null)
const metadataSuccess = ref(false)
const metadataPreview = ref<AlbumMetadataPreview | null>(null)
const metadataJob = ref<AlbumMetadataJob | null>(null)
const metadataForm = reactive({
  albumTitle: '',
  albumArtist: '',
  releaseYear: '',
  totalDiscs: '',
  genres: [] as string[],
  comment: '',
})
const metadataCommentEnabled = ref(false)
const metadataCommentsMixed = ref(false)
const metadataUpdateTrackArtists = ref(true)
let metadataPollTimer: ReturnType<typeof setTimeout> | null = null
const selectionMode = ref(false)
const selectedTrackIds = ref<number[]>([])
const selectionMessage = ref('')
const selectionMessageVisible = ref(false)
const selectionMessageColor = ref<'error' | 'success'>('success')
const batchMetadataDialog = ref(false)
const personalDialog = ref(false)
const personalLoading = ref(false)
const personalError = ref<string | null>(null)
const personalSuccess = ref(false)
const personalForm = reactive({
  notes: '',
})

interface AlbumMetadataValues {
  albumTitle: string
  albumArtist: string
  updateTrackArtists: boolean
  releaseYear: number | null
  totalDiscs: number | null
  genres: string[]
  comment?: string | null
}

interface AlbumMetadataChange {
  field: 'albumTitle' | 'albumArtist' | 'releaseYear' | 'totalDiscs' | 'genres' | 'comment'
  current: string | number | string[] | null
  proposed: string | number | string[] | null
  fileValuesDiffer?: boolean
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
  trackArtistsWillChange: boolean
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
  failureReason?: string | null
}

const albumId = computed(() => Number(route.params.id))
const backArtistId = computed(() => {
  const parsed = typeof route.query.backArtist === 'string' ? Number(route.query.backArtist) : NaN
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
})
const backMusicianId = computed(() => {
  const parsed = typeof route.query.backMusician === 'string' ? Number(route.query.backMusician) : NaN
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
})
const backToTracks = computed(() => route.query.backTo === 'tracks')
const backToAudioIntelligence = computed(() => route.query.backTo === 'audio-intelligence')
const backToMusicianReview = computed(() => route.query.backTo === 'musician-review')
const backArtistDetailQuery = computed(() => ({
  ...(route.query.backArtistTab === 'tracks' ? { tab: 'tracks' } : {}),
  ...(positiveQueryPage(route.query.backArtistAlbumPage, 'albumPage')),
  ...(positiveQueryPage(route.query.backArtistTrackPage, 'trackPage')),
  ...(route.query.backArtistBackTo === 'audio-intelligence' ? { backTo: 'audio-intelligence' } : {}),
}))
const backRoute = computed(() => {
  if (backArtistId.value) {
    return {
      name: 'artist-detail',
      params: { id: backArtistId.value },
      query: backArtistDetailQuery.value,
    }
  }
  if (backMusicianId.value) return { name: 'musician-detail', params: { id: backMusicianId.value } }
  if (backToTracks.value) return { name: 'tracks' }
  if (backToAudioIntelligence.value) return { name: 'settings', query: { tab: 'intelligence' } }
  if (backToMusicianReview.value) {
    return {
      name: 'musician-review',
      query: {
        ...(route.query.reviewStatus && route.query.reviewStatus !== 'ambiguous'
          ? { status: route.query.reviewStatus }
          : {}),
        ...(route.query.reviewPage ? { page: route.query.reviewPage } : {}),
      },
    }
  }

  return { name: 'albums' }
})
const backLabel = computed(() => {
  if (backArtistId.value) return t('albums.backToArtist')
  if (backMusicianId.value) return t('albums.backToMusician')
  if (backToTracks.value) return t('albums.backToTracks')
  if (backToAudioIntelligence.value) return t('albums.backToAudioIntelligence')
  if (backToMusicianReview.value) return t('albums.backToMusicianReview')

  return t('albums.back')
})
const album = computed(() => catalog.albumDetail)
const tracks = computed(() => album.value?.tracks ?? [])
const albumPlayingTime = computed(() => {
  const total = tracks.value.reduce((sum, track) => sum + (track.durationMs ?? 0), 0)

  return total > 0 ? formatTotalDuration(total) : null
})
const showLibraryRoot = computed(() => libraryRoots.roots.filter((root) => root.enabled).length > 1)
const selectedTracks = computed(() => {
  const selected = new Set(selectedTrackIds.value)
  return tracks.value.filter((track) => selected.has(track.id))
})

function positiveQueryPage(value: unknown, key: 'albumPage' | 'trackPage') {
  const parsed = typeof value === 'string' ? Number(value) : NaN
  return Number.isInteger(parsed) && parsed > 1 ? { [key]: String(parsed) } : {}
}
const allTracksSelected = computed(() => tracks.value.length > 0 && selectedTrackIds.value.length === tracks.value.length)
const albumGenres = computed(() => album.value?.genres ?? [])
const albumTechnicalChips = computed(() => {
  const technical = album.value?.technical
  if (!technical) return []

  const chips: Array<{ key: string, icon: string, label: string, title: string }> = []
  if (technical.fileTypes.length) {
    chips.push({
      key: 'fileTypes',
      icon: 'mdi-file-music-outline',
      label: technical.fileTypes.join(' / '),
      title: t('albums.fileTypes'),
    })
  }

  const bitrate = formatAlbumBitrate(
    technical.bitrateMinimum,
    technical.bitrateMaximum,
    technical.bitrateModes,
  )
  if (bitrate) {
    chips.push({
      key: 'bitrate',
      icon: 'mdi-speedometer',
      label: bitrate,
      title: t('tracks.bitrate'),
    })
  }

  const encoderSettings = technical.encoderSettings
    .map(formatEncoderSettings)
    .filter(value => !isRedundantCbrSetting(
      value,
      technical.bitrateMinimum,
      technical.bitrateMaximum,
      technical.bitrateModes,
    ))
    .filter((value, index, values) => values.indexOf(value) === index)
  if (encoderSettings.length) {
    chips.push({
      key: 'encoderSettings',
      icon: 'mdi-tune-variant',
      label: encoderSettings.join(' / '),
      title: t('tracks.encoderSettings'),
    })
  }

  return chips
})
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
const albumPersonalMetadata = computed<AlbumPersonalMetadata>(() => album.value?.personalMetadata ?? {
  purchaseSource: null,
  purchaseDate: null,
  hasPhysicalCopy: false,
  physicalFormat: null,
  notes: null,
})

function formatDate(value?: string | null) {
  return formatDateTime(value, locale.value)
}

function formatAlbumBitrate(
  minimum?: number | null,
  maximum?: number | null,
  modes: string[] = [],
) {
  if (!minimum || !maximum) return null

  const minimumKbps = Math.round(minimum / 1000)
  const maximumKbps = Math.round(maximum / 1000)
  const bitrate = minimumKbps === maximumKbps
    ? `${minimumKbps} kbps`
    : `${minimumKbps}–${maximumKbps} kbps`
  const normalizedModes = modes.map(mode => mode.toUpperCase())

  return normalizedModes.length ? `${normalizedModes.join('/')} · ${bitrate}` : bitrate
}

function formatEncoderSettings(value: string) {
  const quality = value.match(/(?:^|\s)-?V\s*(\d+(?:\.\d+)?)(?:\s|$)/i)

  return quality ? `V${quality[1]}` : value
}

function isRedundantCbrSetting(
  value: string,
  minimum?: number | null,
  maximum?: number | null,
  modes: string[] = [],
) {
  if (!minimum || minimum !== maximum || !modes.some(mode => mode.toLowerCase() === 'cbr')) return false

  const cbrBitrate = value.match(/^(?:CBR\s*|-B\s*)(\d+)$/i)

  return cbrBitrate ? Number(cbrBitrate[1]) === Math.round(minimum / 1000) : false
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

function playlistExportSaved(result: { filename: string }) {
  selectionMessageColor.value = 'success'
  selectionMessage.value = t('albums.playlistSaved', { filename: result.filename })
  selectionMessageVisible.value = true
}

function enterSelectionMode() {
  selectionMode.value = true
  selectedTrackIds.value = []
}

function exitSelectionMode() {
  selectionMode.value = false
  selectedTrackIds.value = []
}

function toggleTrackSelection(trackId: number) {
  selectedTrackIds.value = selectedTrackIds.value.includes(trackId)
    ? selectedTrackIds.value.filter((id) => id !== trackId)
    : [...selectedTrackIds.value, trackId]
}

function toggleAllTracks() {
  selectedTrackIds.value = allTracksSelected.value ? [] : tracks.value.map((track) => track.id)
}

function playSelectedTracks() {
  const [firstTrack] = selectedTracks.value
  if (!firstTrack) return

  player.playTrack(firstTrack, selectedTracks.value, 'album')
}

function queueSelectedTracks() {
  player.queueTracks(selectedTracks.value, 'album')
}

function addSelectedTracksToPlaylist() {
  playlistTracks.value = [...selectedTracks.value]
  addToPlaylistDialog.value = true
}

async function addSelectedTracksToFavorites() {
  if (!selectedTrackIds.value.length) return

  try {
    await favorites.setTracksFavorite(selectedTrackIds.value)
    selectionMessageColor.value = 'success'
    selectionMessage.value = t('albums.selectedTracksFavorited', { count: selectedTrackIds.value.length })
    selectionMessageVisible.value = true
  } catch (cause) {
    selectionMessageColor.value = 'error'
    selectionMessage.value = cause instanceof Error ? cause.message : t('albums.selectionActionFailed')
    selectionMessageVisible.value = true
  }
}

function openBatchMetadataEditor() {
  if (selectedTrackIds.value.length) batchMetadataDialog.value = true
}

function openPersonalEditor() {
  personalForm.notes = albumPersonalMetadata.value.notes ?? ''
  personalError.value = null
  personalDialog.value = true
}

async function savePersonalMetadata() {
  if (!album.value) return

  personalLoading.value = true
  personalError.value = null
  try {
    await catalog.updateAlbumPersonalNotes(album.value.id, personalForm.notes.trim() || null)
    personalDialog.value = false
    personalSuccess.value = true
  } catch (cause) {
    personalError.value = cause instanceof Error ? cause.message : t('albums.personalNotesFailed')
  } finally {
    personalLoading.value = false
  }
}

async function batchMetadataCompleted(count: number) {
  selectionMessageColor.value = 'success'
  selectionMessage.value = t('albums.batchMetadataCompleted', { count })
  selectionMessageVisible.value = true
  exitSelectionMode()
  catalog.invalidateCatalog()
  await catalog.loadAlbum(albumId.value)
  if (catalog.albumDetail) player.refreshQueuedTracks(catalog.albumDetail.tracks)
}

function openMetadataEditor() {
  if (!album.value) return

  const comments = [...new Set(tracks.value.map((track) => track.comment ?? null))]
  Object.assign(metadataForm, {
    albumTitle: album.value.title,
    albumArtist: album.value.primaryArtist?.name ?? '',
    releaseYear: album.value.originalReleaseYear?.toString() ?? '',
    totalDiscs: album.value.discTotal?.toString() ?? '',
    genres: albumGenres.value.map((genre) => genre.name),
    comment: comments.length === 1 ? comments[0] ?? '' : '',
  })
  metadataCommentEnabled.value = false
  metadataCommentsMixed.value = comments.length > 1
  metadataUpdateTrackArtists.value = true
  metadataStep.value = 'form'
  metadataPreview.value = null
  metadataJob.value = null
  metadataError.value = null
  metadataDialog.value = true
}

function metadataValues(): AlbumMetadataValues {
  const year = metadataForm.releaseYear.trim()
  const totalDiscs = metadataForm.totalDiscs.trim()

  const values: AlbumMetadataValues = {
    albumTitle: metadataForm.albumTitle.trim(),
    albumArtist: metadataForm.albumArtist.trim(),
    updateTrackArtists: metadataUpdateTrackArtists.value,
    releaseYear: year === '' ? null : Number(year),
    totalDiscs: totalDiscs === '' ? null : Number(totalDiscs),
    genres: metadataForm.genres.map((genre) => genre.trim()).filter(Boolean),
  }
  if (metadataCommentEnabled.value) values.comment = metadataForm.comment.trim() || null

  return values
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
      catalog.invalidateCatalog()
      await catalog.loadAlbum(albumId.value)
      if (catalog.albumDetail) player.refreshQueuedTracks(catalog.albumDetail.tracks)
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
    comment: t('tracks.comment'),
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
  exitSelectionMode()
  if (Number.isInteger(id) && id > 0) void catalog.loadAlbum(id)
}, { immediate: true })

watch(() => tracks.value.map((track) => track.id), (trackIds) => {
  if (trackIds.length) void playlists.loadMemberships(trackIds)
}, { immediate: true })

watch(() => player.currentTrack?.album?.id, (id) => {
  if (!id || player.playbackContext !== 'album' || route.name !== 'album-detail' || id === albumId.value) return

  void router.replace({ name: 'album-detail', params: { id }, query: route.query })
})

onUnmounted(() => {
  if (metadataPollTimer) clearTimeout(metadataPollTimer)
})
</script>

<template>
  <v-btn class="mb-4" variant="text" prepend-icon="mdi-arrow-left" :to="backRoute">
    {{ backLabel }}
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
            <v-card-subtitle>
              <RouterLink
                v-if="album.primaryArtist"
                class="artist-link"
                :to="{ name: 'artist-detail', params: { id: album.primaryArtist.id } }"
              >
                {{ album.primaryArtist.name }}
              </RouterLink>
              <span v-else>{{ t('catalog.unknownArtist') }}</span>
            </v-card-subtitle>
          </v-card-item>
          <v-card-text class="text-medium-emphasis">
            {{ albumDetails }}<span v-if="albumPlayingTime"> · {{ t('catalog.playingTime', { duration: albumPlayingTime }) }}</span>
            <span v-if="showLibraryRoot && album.libraryRoot"> · {{ album.libraryRoot.name }}</span>
            <div v-if="albumGenres.length || albumTechnicalChips.length" class="album-classification mt-4">
              <div v-if="albumGenres.length" class="d-flex flex-wrap ga-2">
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
              <div v-if="albumTechnicalChips.length" class="d-flex flex-wrap ga-2">
                <v-chip
                  v-for="chip in albumTechnicalChips"
                  :key="chip.key"
                  class="album-technical-chip text-medium-emphasis"
                  :prepend-icon="chip.icon"
                  size="small"
                  :title="chip.title"
                  variant="outlined"
                >
                  {{ chip.label }}
                </v-chip>
              </div>
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
            <div class="personal-information mt-4">
              <div class="d-flex align-center justify-space-between ga-2 mb-2">
                <div class="text-subtitle-2 text-high-emphasis">{{ t('albums.personalInformation') }}</div>
                <v-btn
                  color="primary"
                  prepend-icon="mdi-note-edit-outline"
                  size="small"
                  variant="tonal"
                  @click="openPersonalEditor"
                >
                  {{ t('albums.editPersonalNotes') }}
                </v-btn>
              </div>
              <div v-if="albumPersonalMetadata.notes" class="personal-information-tile personal-notes mb-3">
                <v-icon color="primary" icon="mdi-note-text-outline" size="small" />
                <div>
                  <div class="text-caption text-medium-emphasis">{{ t('albums.personalNotes') }}</div>
                  <RichTextContent class="text-body-2" :html="albumPersonalMetadata.notes" />
                </div>
              </div>
              <OwnedAlbumCopies
                v-if="album.primaryArtist"
                :album-id="album.id"
                :album-title="album.title"
                :artist-name="album.primaryArtist.name"
                :copies="albumPersonalMetadata.ownedCopies ?? []"
                :release-year="album.originalReleaseYear"
              />
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
            <v-btn
              color="primary"
              variant="tonal"
              prepend-icon="mdi-file-music-outline"
              :disabled="!tracks.length"
              @click="playlistExportDialog = true"
            >
              {{ t('albums.savePlaylist') }}
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

    <div v-if="tracks.length" class="selection-toolbar mb-2">
      <v-btn
        v-if="!selectionMode"
        prepend-icon="mdi-checkbox-multiple-marked-outline"
        size="small"
        variant="tonal"
        @click="enterSelectionMode"
      >
        {{ t('albums.selectTracks') }}
      </v-btn>
      <template v-else>
        <v-chip color="primary" prepend-icon="mdi-checkbox-marked-circle-outline" size="small" variant="tonal">
          {{ t('albums.selectedTracks', { count: selectedTrackIds.length }) }}
        </v-chip>
        <v-btn size="small" variant="text" @click="toggleAllTracks">
          {{ allTracksSelected ? t('albums.clearSelection') : t('albums.selectAllTracks') }}
        </v-btn>
        <v-spacer />
        <TooltipIconButton
          :text="t('albums.playSelected')"
          :aria-label="t('albums.playSelected')"
          :disabled="!selectedTrackIds.length"
          icon="mdi-play"
          size="small"
          variant="tonal"
          @click="playSelectedTracks"
        />
        <TooltipIconButton
          :text="t('albums.queueSelected')"
          :aria-label="t('albums.queueSelected')"
          :disabled="!selectedTrackIds.length"
          icon="mdi-playlist-plus"
          size="small"
          variant="tonal"
          @click="queueSelectedTracks"
        />
        <TooltipIconButton
          :text="t('playlists.addTracksTitle', { count: selectedTrackIds.length })"
          :aria-label="t('playlists.addTracksTitle', { count: selectedTrackIds.length })"
          :disabled="!selectedTrackIds.length"
          icon="mdi-playlist-music"
          size="small"
          variant="tonal"
          @click="addSelectedTracksToPlaylist"
        />
        <TooltipIconButton
          :text="t('albums.addSelectedToFavorites')"
          :aria-label="t('albums.addSelectedToFavorites')"
          :disabled="!selectedTrackIds.length"
          icon="mdi-heart-plus-outline"
          size="small"
          variant="tonal"
          @click="void addSelectedTracksToFavorites()"
        />
        <v-btn
          color="primary"
          :disabled="!selectedTrackIds.length"
          prepend-icon="mdi-tag-edit-outline"
          size="small"
          variant="tonal"
          @click="void openBatchMetadataEditor()"
        >
          {{ t('albums.editSelectedMetadata') }}
        </v-btn>
        <TooltipIconButton
          :text="t('settings.cancel')"
          :aria-label="t('settings.cancel')"
          icon="mdi-close"
          size="small"
          variant="text"
          @click="exitSelectionMode"
        />
      </template>
    </div>

    <v-list v-if="tracks.length" border rounded="xl" lines="two">
      <v-list-item
        v-for="track in tracks"
        :key="track.id"
        class="track-list-item"
        :class="{ 'current-track': isCurrentTrack(track), 'selection-active': selectionMode }"
        @click="selectionMode && toggleTrackSelection(track.id)"
      >
        <template #prepend>
          <v-checkbox-btn
            v-if="selectionMode"
            class="track-selection-checkbox"
            color="primary"
            :model-value="selectedTrackIds.includes(track.id)"
            @click.stop
            @update:model-value="toggleTrackSelection(track.id)"
          />
          <span
            class="track-number"
            :class="isCurrentTrack(track) ? 'text-primary font-weight-bold' : 'text-medium-emphasis'"
          >
            {{ trackNumber(track) }}
          </span>
        </template>
        <v-list-item-title class="font-weight-bold" :class="{ 'text-primary': isCurrentTrack(track) }">
          <span v-if="selectionMode">{{ track.title }}</span>
          <RouterLink
            v-else
            class="track-detail-link"
            :to="{ name: 'track-detail', params: { id: track.id }, query: { backAlbum: album.id } }"
          >
            {{ track.title }}
          </RouterLink>
        </v-list-item-title>
        <v-list-item-subtitle>
          <template v-if="track.artists.length">
            <template v-for="(artist, artistIndex) in track.artists" :key="artist.id">
              <span v-if="artistIndex > 0">, </span>
              <RouterLink
                class="artist-link"
                :to="{ name: 'artist-detail', params: { id: artist.id } }"
                @click.stop
              >
                {{ artist.name }}
              </RouterLink>
            </template>
          </template>
          <span v-else>{{ t('catalog.unknownArtist') }}</span>
        </v-list-item-subtitle>
        <template v-if="!selectionMode" #append>
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
            <TrackPlaylistMembershipMenu
              icon-only
              :track-id="track.id"
              @add-to-playlist="addTrackToPlaylist(track)"
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

    <AlbumOnlineInformation
      v-if="tracks[0]"
      :album-id="albumId"
      class="mt-8"
      :track-id="tracks[0].id"
    />
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
  <AlbumPlaylistExportDialog
    v-if="album"
    v-model="playlistExportDialog"
    :album-id="album.id"
    @saved="playlistExportSaved"
  />

  <v-dialog v-model="personalDialog" max-width="620">
    <v-card prepend-icon="mdi-note-edit-outline" :title="t('albums.editPersonalNotes')">
      <v-card-text>
        <v-alert v-if="personalError" class="mb-4" type="error" variant="tonal">
          {{ personalError }}
        </v-alert>
        <RichTextEditor
          v-model="personalForm.notes"
          :disabled="personalLoading"
          :label="t('albums.personalNotes')"
          :max-length="10000"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn :disabled="personalLoading" @click="personalDialog = false">
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn color="primary" :loading="personalLoading" variant="flat" @click="savePersonalMetadata">
          {{ t('albums.savePersonalNotes') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="metadataDialog" max-width="760" persistent scrollable>
    <v-card prepend-icon="mdi-tag-edit-outline" :title="t('albums.editMetadata')">
      <v-card-text>
        <v-alert v-if="metadataError" class="mb-4" type="error" variant="tonal">
          {{ metadataError }}
        </v-alert>

        <template v-if="metadataStep === 'form'">
          <v-text-field v-model="metadataForm.albumTitle" :label="t('albums.metadataAlbumTitle')" maxlength="512" />
          <v-text-field v-model="metadataForm.albumArtist" :label="t('albums.metadataAlbumArtist')" maxlength="512" />
          <v-switch
            v-model="metadataUpdateTrackArtists"
            class="mt-n2"
            color="primary"
            density="compact"
            hide-details
            :label="t('albums.metadataUpdateTrackArtists')"
          />
          <div class="text-caption text-medium-emphasis mb-3">
            {{ t('albums.metadataUpdateTrackArtistsHint') }}
          </div>
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
          <v-switch
            v-model="metadataCommentEnabled"
            color="primary"
            density="compact"
            hide-details
            :label="t('albums.metadataChangeComment')"
          />
          <v-textarea
            v-model="metadataForm.comment"
            clearable
            :disabled="!metadataCommentEnabled"
            :hint="metadataCommentsMixed ? t('albums.metadataCommentMixedHint') : t('albums.metadataCommentHint')"
            :label="t('tracks.comment')"
            maxlength="10000"
            persistent-hint
            rows="3"
          />
          <div class="text-caption text-medium-emphasis">{{ t('albums.metadataPreviewHint') }}</div>
        </template>

        <template v-else-if="metadataStep === 'preview' && metadataPreview">
          <v-alert v-if="metadataPreview.trackArtistsWillChange" class="mb-4" type="info" variant="tonal">
            {{ t('albums.metadataTrackArtistsWillChange') }}
          </v-alert>
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
              <v-list-item-subtitle v-if="change.fileValuesDiffer" class="text-medium-emphasis">
                {{ t('albums.metadataFileValuesDiffer') }}
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
          <v-alert
            v-if="['partial', 'failed'].includes(metadataJob.status)"
            class="mb-4"
            type="error"
            variant="tonal"
          >
            {{ metadataJob.failureReason ?? metadataJob.error ?? t('albums.metadataEditFailed') }}
          </v-alert>
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

  <TrackBatchMetadataDialog
    v-model="batchMetadataDialog"
    :album-id="albumId"
    :tracks="selectedTracks"
    @completed="batchMetadataCompleted"
  />

  <v-snackbar v-model="metadataSuccess" color="success" timeout="3000">
    {{ t('albums.metadataCompleted') }}
  </v-snackbar>
  <v-snackbar v-model="personalSuccess" color="success" timeout="3000">
    {{ t('albums.personalNotesSaved') }}
  </v-snackbar>
  <v-snackbar v-model="selectionMessageVisible" :color="selectionMessageColor" timeout="3000">
    {{ selectionMessage }}
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

.album-classification {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 1.5rem;
}

.album-technical-chip {
  max-width: 18rem;
}

.album-technical-chip :deep(.v-chip__content) {
  overflow: hidden;
  text-overflow: ellipsis;
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

.personal-information {
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  padding-top: 14px;
}

.personal-information-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
}

.personal-information-tile {
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  display: flex;
  gap: 10px;
  min-width: 0;
  padding: 10px;
}

.personal-notes {
  align-items: flex-start;
  grid-column: 1 / -1;
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

.selection-toolbar {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
  min-height: 36px;
}

.selection-active {
  cursor: pointer;
}

.track-selection-checkbox {
  margin-inline-end: 4px;
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

.artist-link {
  color: inherit;
  text-decoration: none;
}

.artist-link:hover {
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
