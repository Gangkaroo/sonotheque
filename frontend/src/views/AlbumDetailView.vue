<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import AlbumMetadataDialog from '@/components/AlbumMetadataDialog.vue'
import AlbumOnlineInformation from '@/components/AlbumOnlineInformation.vue'
import AlbumPlaylistExportDialog from '@/components/AlbumPlaylistExportDialog.vue'
import AlbumSummaryCard from '@/components/AlbumSummaryCard.vue'
import AlbumTrackList from '@/components/AlbumTrackList.vue'
import type { Track } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { usePlayerStore } from '@/stores/player'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const catalog = useCatalogStore()
const player = usePlayerStore()
const artworkDialog = ref(false)
const addToPlaylistDialog = ref(false)
const playlistExportDialog = ref(false)
const playlistTracks = ref<Track[]>([])
const metadataDialog = ref(false)
const metadataSuccess = ref(false)
const onlineInformationRevision = ref(0)
const playlistExportMessage = ref('')
const playlistExportMessageVisible = ref(false)
const artistCountry = ref<string | null>(null)
const showOriginalArtwork = ref(false)
const thumbnailArtworkFailed = ref(false)
const originalArtworkFailed = ref(false)

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

function positiveQueryPage(value: unknown, key: 'albumPage' | 'trackPage') {
  const parsed = typeof value === 'string' ? Number(value) : NaN
  return Number.isInteger(parsed) && parsed > 1 ? { [key]: String(parsed) } : {}
}
const artworkUrl = computed(() => album.value?.artworkUrl ?? album.value?.artworkThumbnailUrl ?? null)
const artworkDisplayUrl = computed(() => (
  showOriginalArtwork.value && !originalArtworkFailed.value
    ? artworkUrl.value
    : !thumbnailArtworkFailed.value && album.value?.artworkThumbnailUrl
      ? album.value.artworkThumbnailUrl
      : originalArtworkFailed.value ? null : artworkUrl.value
))
const artworkStyle = computed(() => ({
  maxWidth: `${album.value?.artworkWidth ?? 1200}px`,
  maxHeight: `min(92vh, ${album.value?.artworkHeight ?? 1200}px)`,
}))
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
  openTracksPlaylistDialog(tracks.value)
}

function openTracksPlaylistDialog(selectedTracks: Track[]) {
  playlistTracks.value = [...selectedTracks]
  addToPlaylistDialog.value = true
}

function playlistExportSaved(result: { filename: string }) {
  playlistExportMessage.value = t('albums.playlistSaved', { filename: result.filename })
  playlistExportMessageVisible.value = true
}

async function batchMetadataCompleted() {
  catalog.invalidateCatalog()
  await catalog.loadAlbum(albumId.value)
  if (catalog.albumDetail) player.refreshQueuedTracks(catalog.albumDetail.tracks)
}

function openMetadataEditor() {
  if (album.value) metadataDialog.value = true
}

function updateAlbumRating(value: number | null) {
  if (album.value) album.value.rating = value
}

async function metadataCompleted() {
  metadataSuccess.value = true
  catalog.invalidateCatalog()
  await catalog.loadAlbum(albumId.value)
  if (catalog.albumDetail) player.refreshQueuedTracks(catalog.albumDetail.tracks)
}

function metadataReleaseResolved() {
  onlineInformationRevision.value++
}

function openArtwork() {
  if (!artworkDisplayUrl.value) return

  artworkDialog.value = true
}

function artworkLoaded() {
  if (!showOriginalArtwork.value && album.value?.artworkUrl && !originalArtworkFailed.value) {
    showOriginalArtwork.value = true
  }
}

function artworkLoadFailed() {
  if (!showOriginalArtwork.value && album.value?.artworkThumbnailUrl) {
    thumbnailArtworkFailed.value = true
    showOriginalArtwork.value = true
  } else {
    originalArtworkFailed.value = true
  }
}

watch(albumId, (id) => {
  artistCountry.value = null
  showOriginalArtwork.value = false
  thumbnailArtworkFailed.value = false
  originalArtworkFailed.value = false
  if (Number.isInteger(id) && id > 0) void catalog.loadAlbum(id)
}, { immediate: true })

watch(() => player.currentTrack?.album?.id, (id) => {
  if (!id || player.playbackContext !== 'album' || route.name !== 'album-detail' || id === albumId.value) return

  void router.replace({ name: 'album-detail', params: { id }, query: route.query })
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
          v-if="artworkDisplayUrl"
          border
          rounded="xl"
          class="overflow-hidden album-cover-card"
          @click="openArtwork"
        >
          <v-img
            :key="album.id"
            :alt="album.title"
            :src="artworkDisplayUrl"
            :lazy-src="showOriginalArtwork ? album.artworkThumbnailUrl ?? undefined : undefined"
            aspect-ratio="1"
            cover
            @error="artworkLoadFailed"
            @load="artworkLoaded"
          >
            <template #placeholder>
              <div class="album-cover-loading">
                <v-progress-linear color="primary" height="3" indeterminate />
              </div>
            </template>
            <template #error>
              <div class="d-flex align-center justify-center bg-surface-bright album-cover-placeholder">
                <v-icon icon="mdi-album" size="88" color="medium-emphasis" />
              </div>
            </template>
          </v-img>
        </v-card>
        <v-card v-else border rounded="xl" class="overflow-hidden">
          <div class="d-flex align-center justify-center bg-surface-bright album-cover-placeholder">
            <v-icon icon="mdi-album" size="88" color="medium-emphasis" />
          </div>
        </v-card>
      </v-col>

      <v-col cols="12" md="8" lg="9">
        <AlbumSummaryCard
          :album="album"
          :artist-country="artistCountry"
          @add-to-playlist="addAlbumToPlaylist"
          @edit-metadata="openMetadataEditor"
          @play="playAlbum"
          @queue="queueAlbum"
          @rating-changed="updateAlbumRating"
          @save-playlist="playlistExportDialog = true"
        />
      </v-col>
    </v-row>

    <AlbumTrackList
      :album-id="album.id"
      :tracks="tracks"
      @add-to-playlist="openTracksPlaylistDialog"
      @metadata-completed="batchMetadataCompleted"
    />

    <AlbumOnlineInformation
      v-if="tracks[0]"
      :key="`album-online-${albumId}-${onlineInformationRevision}`"
      :album-id="albumId"
      class="mt-8"
      :track-id="tracks[0].id"
      @artist-country="artistCountry = $event"
    />
  </template>

  <v-dialog v-model="artworkDialog" class="album-artwork-dialog" max-width="none">
    <div class="album-artwork-overlay" @click="artworkDialog = false">
      <v-img
        v-if="artworkDisplayUrl"
        :src="artworkDisplayUrl"
        :lazy-src="showOriginalArtwork ? album?.artworkThumbnailUrl ?? undefined : undefined"
        :style="artworkStyle"
        class="album-artwork-full"
      >
        <template #placeholder>
          <div class="album-cover-loading">
            <v-progress-linear color="primary" height="3" indeterminate />
          </div>
        </template>
      </v-img>
    </div>
  </v-dialog>

  <AddToPlaylistDialog v-model="addToPlaylistDialog" :tracks="playlistTracks" />
  <AlbumPlaylistExportDialog
    v-if="album"
    v-model="playlistExportDialog"
    :album-id="album.id"
    @saved="playlistExportSaved"
  />

  <AlbumMetadataDialog
    v-if="album"
    v-model="metadataDialog"
    :album="album"
    @completed="metadataCompleted"
    @release-resolved="metadataReleaseResolved"
  />

  <v-snackbar v-model="metadataSuccess" color="success" timeout="3000">
    {{ t('albums.metadataCompleted') }}
  </v-snackbar>
  <v-snackbar v-model="playlistExportMessageVisible" color="success" timeout="3000">
    {{ playlistExportMessage }}
  </v-snackbar>
</template>

<style scoped>
.album-cover-card {
  cursor: zoom-in;
}

.album-cover-placeholder {
  aspect-ratio: 1;
}

.album-cover-loading {
  align-items: flex-end;
  display: flex;
  height: 100%;
  pointer-events: none;
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

</style>
