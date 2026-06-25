<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { Album, Track } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'

const { t } = useI18n()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const activeTab = ref<'albums' | 'tracks'>('albums')
const albumPage = ref(1)
const trackPage = ref(1)

function duration(milliseconds?: number) {
  if (!milliseconds) return '-'
  const seconds = Math.round(milliseconds / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function albumDetails(album: Album) {
  if (album.originalReleaseYear === undefined || album.originalReleaseYear === null) {
    return t('albums.trackCount', { count: album.trackCount })
  }

  return t('albums.details', { year: album.originalReleaseYear, count: album.trackCount })
}

function toggleTrack(track: Track) {
  if (player.currentTrack?.id === track.id && player.isPlaying) {
    player.pause()
    return
  }

  player.playTrack(track, favorites.tracks.items, 'track-list')
}

function loadAlbumPage(page = albumPage.value) {
  albumPage.value = page
  void favorites.loadAlbums(page)
}

function loadTrackPage(page = trackPage.value) {
  trackPage.value = page
  void favorites.loadTracks(page)
}

onMounted(() => {
  void favorites.loadIds()
  loadAlbumPage()
  loadTrackPage()
})
</script>

<template>
  <PageHeader :title="t('favorites.title')" :description="t('favorites.description')" icon="mdi-heart-outline" />

  <v-alert v-if="favorites.error" class="mb-6" type="error" variant="tonal">{{ favorites.error }}</v-alert>

  <v-card border rounded="xl">
    <v-tabs v-model="activeTab" color="primary">
      <v-tab prepend-icon="mdi-album" value="albums">{{ t('favorites.albums') }}</v-tab>
      <v-tab prepend-icon="mdi-music-note-outline" value="tracks">{{ t('favorites.tracks') }}</v-tab>
    </v-tabs>

    <v-divider />

    <v-window v-model="activeTab">
      <v-window-item value="albums">
        <v-skeleton-loader v-if="favorites.albumsLoading" type="list-item-three-line@4" />
        <v-list v-else-if="favorites.albums.items.length" lines="two">
          <v-list-item
            v-for="album in favorites.albums.items"
            :key="album.id"
            :to="{ name: 'album-detail', params: { id: album.id } }"
          >
            <template #prepend>
              <v-avatar rounded="lg">
                <v-img v-if="album.artworkThumbnailUrl" :src="album.artworkThumbnailUrl" cover />
                <v-icon v-else icon="mdi-album" />
              </v-avatar>
            </template>
            <v-list-item-title class="font-weight-bold">{{ album.title }}</v-list-item-title>
            <v-list-item-subtitle>{{ album.primaryArtist?.name ?? t('catalog.unknownArtist') }}</v-list-item-subtitle>
            <v-list-item-subtitle>{{ albumDetails(album) }}</v-list-item-subtitle>
            <template #append>
              <TooltipIconButton
                :text="t('favorites.removeAlbum')"
                :aria-label="t('favorites.removeAlbum')"
                color="primary"
                icon="mdi-heart"
                variant="text"
                @click.prevent.stop="void favorites.toggleAlbum(album.id)"
              />
            </template>
          </v-list-item>
        </v-list>
        <v-card-text v-else>
          <EmptyCatalogState :title="t('favorites.emptyAlbumsTitle')" :description="t('favorites.emptyAlbumsDescription')" icon="mdi-heart-outline" />
        </v-card-text>
        <v-card-actions v-if="favorites.albums.lastPage > 1">
          <v-pagination v-model="albumPage" :length="favorites.albums.lastPage" @update:model-value="loadAlbumPage" />
        </v-card-actions>
      </v-window-item>

      <v-window-item value="tracks">
        <v-skeleton-loader v-if="favorites.tracksLoading" type="list-item-three-line@6" />
        <v-list v-else-if="favorites.tracks.items.length" lines="three">
          <v-list-item v-for="track in favorites.tracks.items" :key="track.id">
            <v-list-item-title class="font-weight-bold">
              <RouterLink class="favorite-link" :to="{ name: 'track-detail', params: { id: track.id } }">
                {{ track.title }}
              </RouterLink>
            </v-list-item-title>
            <v-list-item-subtitle>
              {{ track.artists.map((artist) => artist.name).join(', ') || t('catalog.unknownArtist') }}
            </v-list-item-subtitle>
            <v-list-item-subtitle>
              <RouterLink
                v-if="track.album"
                class="favorite-link"
                :to="{ name: 'album-detail', params: { id: track.album.id } }"
              >
                {{ track.album.title }}
              </RouterLink>
              <span v-else>{{ t('catalog.unknownAlbum') }}</span>
            </v-list-item-subtitle>
            <template #append>
              <div class="d-flex align-center ga-1">
                <span class="text-caption text-medium-emphasis">{{ duration(track.durationMs) }}</span>
                <TooltipIconButton
                  :text="player.currentTrack?.id === track.id && player.isPlaying ? t('player.pause') : t('player.play')"
                  :aria-label="player.currentTrack?.id === track.id && player.isPlaying ? t('player.pause') : t('player.play')"
                  :color="player.currentTrack?.id === track.id ? 'primary' : undefined"
                  :icon="player.currentTrack?.id === track.id && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
                  variant="text"
                  @click="toggleTrack(track)"
                />
                <TooltipIconButton
                  :text="t('tracks.queueTrack')"
                  :aria-label="t('tracks.queueTrack')"
                  icon="mdi-playlist-plus"
                  variant="text"
                  @click="player.queueTrack(track, 'track-list')"
                />
                <TooltipIconButton
                  :text="t('favorites.removeTrack')"
                  :aria-label="t('favorites.removeTrack')"
                  color="primary"
                  icon="mdi-heart"
                  variant="text"
                  @click="void favorites.toggleTrack(track.id)"
                />
              </div>
            </template>
          </v-list-item>
        </v-list>
        <v-card-text v-else>
          <EmptyCatalogState :title="t('favorites.emptyTracksTitle')" :description="t('favorites.emptyTracksDescription')" icon="mdi-heart-outline" />
        </v-card-text>
        <v-card-actions v-if="favorites.tracks.lastPage > 1">
          <v-pagination v-model="trackPage" :length="favorites.tracks.lastPage" @update:model-value="loadTrackPage" />
        </v-card-actions>
      </v-window-item>
    </v-window>
  </v-card>
</template>

<style scoped>
.favorite-link {
  color: inherit;
  text-decoration: none;
}

.favorite-link:hover {
  text-decoration: underline;
}
</style>
