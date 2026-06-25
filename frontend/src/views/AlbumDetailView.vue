<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { Track } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const artworkDialog = ref(false)
const addToPlaylistDialog = ref(false)
const playlistTracks = ref<Track[]>([])

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

  if (album.value.originalReleaseYear === undefined || album.value.originalReleaseYear === null) {
    return t('albums.trackCount', { count: album.value.trackCount })
  }

  return t('albums.details', { year: album.value.originalReleaseYear, count: album.value.trackCount })
})

function duration(milliseconds?: number) {
  if (!milliseconds) return '-'
  const seconds = Math.round(milliseconds / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
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

function openArtwork() {
  if (!artworkUrl.value) return

  artworkDialog.value = true
}

function toggleTrack(track: Track) {
  if (player.currentTrack?.id === track.id && player.isPlaying) {
    player.pause()
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
              <v-chip v-for="genre in albumGenres" :key="genre.id" size="small" variant="tonal">
                {{ genre.name }}
              </v-chip>
            </div>
          </v-card-text>
          <v-card-actions class="album-actions">
            <v-btn color="primary" variant="flat" prepend-icon="mdi-play" :disabled="!tracks.length" @click="playAlbum">
              {{ t('albums.playAlbum') }}
            </v-btn>
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-playlist-plus" :disabled="!tracks.length" @click="queueAlbum">
              {{ t('albums.queueAlbum') }}
            </v-btn>
            <v-btn color="primary" variant="text" prepend-icon="mdi-playlist-music" :disabled="!tracks.length" @click="addAlbumToPlaylist">
              {{ t('playlists.addAlbumToPlaylist') }}
            </v-btn>
            <v-btn
              :color="favorites.isAlbumFavorite(album.id) ? 'primary' : undefined"
              :prepend-icon="favorites.isAlbumFavorite(album.id) ? 'mdi-heart' : 'mdi-heart-outline'"
              variant="text"
              @click="void favorites.toggleAlbum(album.id)"
            >
              {{ favorites.isAlbumFavorite(album.id) ? t('favorites.removeAlbum') : t('favorites.addAlbum') }}
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-col>
    </v-row>

    <h2 class="text-h5 font-weight-bold mb-4">{{ t('albums.trackList') }}</h2>
    <v-list v-if="tracks.length" border rounded="xl" lines="two">
      <v-list-item
        v-for="track in tracks"
        :key="track.id"
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
          <div class="d-flex align-center ga-2">
            <span class="text-caption text-medium-emphasis">{{ duration(track.durationMs) }}</span>
            <TooltipIconButton
              :text="isCurrentTrack(track) && player.isPlaying ? t('player.pause') : t('player.play')"
              :aria-label="isCurrentTrack(track) && player.isPlaying ? t('player.pause') : t('player.play')"
              :color="isCurrentTrack(track) ? 'primary' : undefined"
              :icon="isCurrentTrack(track) && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
              variant="text"
              @click="toggleTrack(track)"
            />
            <TooltipIconButton
              :text="t('playlists.addTrackToPlaylist')"
              :aria-label="t('playlists.addTrackToPlaylist')"
              icon="mdi-playlist-music"
              variant="text"
              @click="addTrackToPlaylist(track)"
            />
            <TooltipIconButton
              :text="favorites.isTrackFavorite(track.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
              :aria-label="favorites.isTrackFavorite(track.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
              :color="favorites.isTrackFavorite(track.id) ? 'primary' : undefined"
              :icon="favorites.isTrackFavorite(track.id) ? 'mdi-heart' : 'mdi-heart-outline'"
              variant="text"
              @click="void favorites.toggleTrack(track.id)"
            />
          </div>
        </template>
      </v-list-item>
    </v-list>
    <EmptyCatalogState v-else :title="t('albums.noTracksTitle')" :description="t('catalog.scanPrompt')" icon="mdi-music-note-outline" />
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
</style>
