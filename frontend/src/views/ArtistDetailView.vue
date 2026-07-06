<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import AlbumOnlineInformation from '@/components/AlbumOnlineInformation.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { Track } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'

const { locale, t } = useI18n()
const route = useRoute()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const activeTab = ref<'albums' | 'tracks'>('albums')
const albumPage = ref(1)
const trackPage = ref(1)
const artistImageUrl = ref<string | null>(null)

const artistId = computed(() => Number(route.params.id))
const artist = computed(() => catalog.artistDetail)

watch(artistId, (id) => {
  artistImageUrl.value = null
  albumPage.value = 1
  trackPage.value = 1
  void catalog.loadArtist(id)
  void catalog.loadAlbums({ artist: id, page: 1 })
  void catalog.loadTracks({ artist: id, page: 1 })
}, { immediate: true })

watch(albumPage, (page, previousPage) => {
  if (page !== previousPage) void catalog.loadAlbums({ artist: artistId.value, page })
})

watch(trackPage, (page, previousPage) => {
  if (page !== previousPage) void catalog.loadTracks({ artist: artistId.value, page })
})

function formatDate(value?: string | null) {
  if (!value) return t('artists.neverPlayed')
  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

function duration(milliseconds?: number) {
  if (!milliseconds) return '-'
  const seconds = Math.round(milliseconds / 1000)

  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
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

  player.playTrack(track, catalog.tracks.items, 'track-list')
}
</script>

<template>
  <v-btn class="mb-4" variant="text" prepend-icon="mdi-arrow-left" :to="{ name: 'artists' }">
    {{ t('artists.back') }}
  </v-btn>

  <v-alert v-if="catalog.artistDetailError" type="error" variant="tonal">
    {{ catalog.artistDetailError }}
  </v-alert>

  <v-skeleton-loader v-else-if="catalog.artistDetailLoading" type="card, table-heading, list-item-two-line@5" />

  <template v-else-if="artist">
    <v-card border rounded="xl" class="mb-8">
      <v-card-item>
        <template #prepend>
          <v-avatar color="primary" variant="tonal" size="88">
            <v-img
              v-if="artistImageUrl"
              :alt="artist.name"
              cover
              :src="artistImageUrl"
              @error="artistImageUrl = null"
            />
            <v-icon v-else icon="mdi-account-music-outline" size="40" />
          </v-avatar>
        </template>
        <v-card-title>{{ artist.name }}</v-card-title>
        <v-card-subtitle>{{ t('artists.detailDescription') }}</v-card-subtitle>
      </v-card-item>

      <v-card-text>
        <div class="artist-stat-grid">
          <div class="artist-stat-tile">
            <v-icon color="primary" icon="mdi-album" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('artists.albums') }}</div>
              <div class="text-h6">{{ artist.albumCount }}</div>
            </div>
          </div>
          <div class="artist-stat-tile">
            <v-icon color="primary" icon="mdi-music-note" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('artists.tracks') }}</div>
              <div class="text-h6">{{ artist.trackCount }}</div>
            </div>
          </div>
          <div class="artist-stat-tile">
            <v-icon color="primary" icon="mdi-headphones" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('artists.playCount') }}</div>
              <div class="text-h6">{{ artist.playStatistics.playCount }}</div>
            </div>
          </div>
          <div class="artist-stat-tile">
            <v-icon color="primary" icon="mdi-calendar-clock" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('artists.lastPlayed') }}</div>
              <div class="text-body-2 font-weight-medium">{{ formatDate(artist.playStatistics.lastPlayedAt) }}</div>
            </div>
          </div>
        </div>
      </v-card-text>

    </v-card>

    <v-card border rounded="xl" class="mb-8">
      <v-tabs v-model="activeTab" color="primary" grow>
        <v-tab prepend-icon="mdi-album" value="albums">{{ t('artists.albums') }} ({{ artist.albumCount }})</v-tab>
        <v-tab prepend-icon="mdi-music-note" value="tracks">{{ t('artists.tracks') }} ({{ artist.trackCount }})</v-tab>
      </v-tabs>
      <v-divider />

      <v-window v-model="activeTab">
        <v-window-item value="albums">
          <v-card-text>
            <v-alert v-if="catalog.albumsError" type="error" variant="tonal">{{ catalog.albumsError }}</v-alert>
            <v-skeleton-loader v-else-if="catalog.albumsLoading" type="image@3" />
            <v-row v-else-if="catalog.albums.items.length" dense>
              <v-col v-for="album in catalog.albums.items" :key="album.id" cols="12" sm="6" lg="4">
                <v-card :to="{ name: 'album-detail', params: { id: album.id } }" variant="tonal" height="100%">
                  <div class="d-flex align-center pa-3 ga-3">
                    <v-avatar rounded="lg" size="64" color="surface-bright">
                      <v-img v-if="album.artworkThumbnailUrl" :src="album.artworkThumbnailUrl" cover />
                      <v-icon v-else icon="mdi-album" />
                    </v-avatar>
                    <div class="min-width-0">
                      <div class="text-body-1 font-weight-bold text-truncate">{{ album.title }}</div>
                      <div class="text-caption text-medium-emphasis">
                        <template v-if="album.originalReleaseYear">{{ album.originalReleaseYear }} · </template>
                        {{ t('albums.trackCount', { count: album.trackCount }) }}
                      </div>
                    </div>
                  </div>
                </v-card>
              </v-col>
            </v-row>
            <EmptyCatalogState v-else :title="t('artists.noAlbums')" :description="t('catalog.scanPrompt')" icon="mdi-album" />
            <v-pagination v-if="catalog.albums.lastPage > 1" v-model="albumPage" class="mt-4" :length="catalog.albums.lastPage" />
          </v-card-text>
        </v-window-item>

        <v-window-item value="tracks">
          <v-card-text>
            <v-alert v-if="catalog.tracksError" type="error" variant="tonal">{{ catalog.tracksError }}</v-alert>
            <v-skeleton-loader v-else-if="catalog.tracksLoading" type="list-item-two-line@6" />
            <v-list v-else-if="catalog.tracks.items.length" lines="two">
              <v-list-item v-for="track in catalog.tracks.items" :key="track.id" class="artist-track-item">
                <v-list-item-title class="font-weight-bold">
                  <RouterLink class="catalog-link" :to="{ name: 'track-detail', params: { id: track.id } }">
                    {{ track.title }}
                  </RouterLink>
                </v-list-item-title>
                <v-list-item-subtitle>
                  <RouterLink v-if="track.album" class="catalog-link" :to="{ name: 'album-detail', params: { id: track.album.id } }">
                    {{ track.album.title }}
                  </RouterLink>
                  <span class="ml-2">{{ duration(track.durationMs) }}</span>
                </v-list-item-subtitle>
                <template #append>
                  <div class="d-flex align-center ga-1">
                    <TooltipIconButton
                      :text="player.currentTrack?.id === track.id && player.isPlaying ? t('player.pause') : t('player.play')"
                      :aria-label="player.currentTrack?.id === track.id && player.isPlaying ? t('player.pause') : t('player.play')"
                      :color="player.currentTrack?.id === track.id ? 'primary' : undefined"
                      :icon="player.currentTrack?.id === track.id && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
                      variant="text"
                      @click="toggleTrack(track)"
                    />
                    <TooltipIconButton
                      :text="t('player.queue')"
                      :aria-label="t('player.queue')"
                      icon="mdi-playlist-plus"
                      variant="text"
                      @click="player.queueTrack(track, 'track-list')"
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
            <EmptyCatalogState v-else :title="t('artists.noTracks')" :description="t('catalog.scanPrompt')" icon="mdi-music-note" />
            <v-pagination v-if="catalog.tracks.lastPage > 1" v-model="trackPage" class="mt-4" :length="catalog.tracks.lastPage" />
          </v-card-text>
        </v-window-item>
      </v-window>
    </v-card>

    <AlbumOnlineInformation
      v-if="artist.representativeTrackId"
      :track-id="artist.representativeTrackId"
      content="artist"
      @artist-image="artistImageUrl = $event"
    />
  </template>
</template>

<style scoped>
.artist-stat-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.artist-stat-tile {
  align-items: center;
  background: rgb(var(--v-theme-surface-bright));
  border-radius: 0.75rem;
  display: flex;
  gap: 0.75rem;
  min-height: 72px;
  padding: 0.75rem;
}

.artist-track-item:hover {
  background: rgba(var(--v-theme-primary), 0.08);
}

.catalog-link {
  color: inherit;
  text-decoration: none;
}

.catalog-link:hover {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
}

.min-width-0 {
  min-width: 0;
}

@media (max-width: 959px) {
  .artist-stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 480px) {
  .artist-stat-grid {
    grid-template-columns: 1fr;
  }
}
</style>
