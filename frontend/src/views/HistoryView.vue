<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { Track } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'
import { useStatisticsStore } from '@/stores/statistics'
import { formatDateTime, formatDuration as duration } from '@/utils/formatters'

const { locale, t } = useI18n()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const statistics = useStatisticsStore()
const activeTab = ref<'recent' | 'most-played-tracks' | 'most-played-albums'>('recent')
const recentPage = ref(1)
const mostPlayedTrackPage = ref(1)
const mostPlayedAlbumPage = ref(1)

function formatDate(value?: string | null) {
  return formatDateTime(value, locale.value)
}

function toggleTrack(track: Track, tracks: Track[]) {
  if (player.currentTrack?.id === track.id) {
    if (player.isPlaying) {
      player.pause()
    } else {
      player.resume()
    }
    return
  }

  player.playTrack(track, tracks, 'track-list')
}

function loadRecentPage(page = recentPage.value) {
  recentPage.value = page
  void statistics.loadRecentPlays(page)
}

function loadMostPlayedTrackPage(page = mostPlayedTrackPage.value) {
  mostPlayedTrackPage.value = page
  void statistics.loadMostPlayedTracks(page)
}

function loadMostPlayedAlbumPage(page = mostPlayedAlbumPage.value) {
  mostPlayedAlbumPage.value = page
  void statistics.loadMostPlayedAlbums(page)
}

onMounted(() => {
  void favorites.loadIds()
  loadRecentPage()
  loadMostPlayedTrackPage()
  loadMostPlayedAlbumPage()
})

watch(
  () => statistics.historyRevision,
  () => {
    if (activeTab.value === 'recent') loadRecentPage()
    if (activeTab.value === 'most-played-tracks') loadMostPlayedTrackPage()
    if (activeTab.value === 'most-played-albums') loadMostPlayedAlbumPage()
  },
)
</script>

<template>
  <PageHeader :title="t('history.title')" :description="t('history.description')" icon="mdi-history" />

  <v-alert v-if="statistics.error" class="mb-6" type="error" variant="tonal">{{ statistics.error }}</v-alert>

  <v-card border rounded="xl">
    <v-tabs v-model="activeTab" color="primary">
      <v-tab prepend-icon="mdi-clock-outline" value="recent">{{ t('history.recentPlays') }}</v-tab>
      <v-tab prepend-icon="mdi-headphones" value="most-played-tracks">{{ t('history.mostPlayedTracks') }}</v-tab>
      <v-tab prepend-icon="mdi-headphones-box" value="most-played-albums">{{ t('history.mostPlayedAlbums') }}</v-tab>
    </v-tabs>

    <v-divider />

    <v-window v-model="activeTab">
      <v-window-item value="recent">
        <v-skeleton-loader v-if="statistics.recentPlaysLoading" type="list-item-three-line@6" />
        <v-list v-else-if="statistics.recentPlays.items.length" lines="three">
          <v-list-item
            v-for="play in statistics.recentPlays.items"
            :key="play.id"
            class="track-list-item"
            :class="{ 'current-track': player.currentTrack?.id === play.track.id }"
            prepend-icon="mdi-clock-outline"
          >
            <v-list-item-title class="font-weight-bold">
              <RouterLink class="history-link" :to="{ name: 'track-detail', params: { id: play.track.id } }">
                {{ play.track.title }}
              </RouterLink>
            </v-list-item-title>
            <v-list-item-subtitle>
              <template v-if="play.track.artists.length">
                <template v-for="(artist, index) in play.track.artists" :key="artist.id">
                  <span v-if="index > 0">, </span>
                  <RouterLink class="history-link" :to="{ name: 'artist-detail', params: { id: artist.id } }">
                    {{ artist.name }}
                  </RouterLink>
                </template>
              </template>
              <span v-else>{{ t('catalog.unknownArtist') }}</span>
            </v-list-item-subtitle>
            <v-list-item-subtitle>
              {{ t('history.playedAt', { value: formatDate(play.playedAt) }) }}
              <span aria-hidden="true"> · </span>
              <RouterLink
                v-if="play.track.album"
                class="history-link"
                :to="{ name: 'album-detail', params: { id: play.track.album.id } }"
              >
                {{ play.track.album.title }}
              </RouterLink>
              <span v-else>{{ t('catalog.unknownAlbum') }}</span>
            </v-list-item-subtitle>
            <template #append>
              <div class="track-actions">
                <span class="text-caption text-medium-emphasis">{{ duration(play.track.durationMs) }}</span>
                <TooltipIconButton
                  :text="player.currentTrack?.id === play.track.id && player.isPlaying ? t('player.pause') : t('player.play')"
                  :aria-label="player.currentTrack?.id === play.track.id && player.isPlaying ? t('player.pause') : t('player.play')"
                  :color="player.currentTrack?.id === play.track.id ? 'primary' : undefined"
                  density="comfortable"
                  :icon="player.currentTrack?.id === play.track.id && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
                  variant="text"
                  @click="toggleTrack(play.track, statistics.recentPlays.items.map((item) => item.track))"
                />
                <TooltipIconButton
                  :text="t('tracks.queueTrack')"
                  :aria-label="t('tracks.queueTrack')"
                  density="comfortable"
                  icon="mdi-playlist-plus"
                  variant="text"
                  @click="player.queueTrack(play.track, 'track-list')"
                />
                <TooltipIconButton
                  :text="favorites.isTrackFavorite(play.track.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
                  :aria-label="favorites.isTrackFavorite(play.track.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
                  :color="favorites.isTrackFavorite(play.track.id) ? 'primary' : undefined"
                  density="comfortable"
                  :icon="favorites.isTrackFavorite(play.track.id) ? 'mdi-heart' : 'mdi-heart-outline'"
                  variant="text"
                  @click="void favorites.toggleTrack(play.track.id)"
                />
              </div>
            </template>
          </v-list-item>
        </v-list>
        <v-card-text v-else>
          <EmptyCatalogState :title="t('history.emptyRecentTitle')" :description="t('history.emptyRecentDescription')" icon="mdi-history" />
        </v-card-text>
        <v-card-actions v-if="statistics.recentPlays.lastPage > 1">
          <v-pagination v-model="recentPage" :length="statistics.recentPlays.lastPage" @update:model-value="loadRecentPage" />
        </v-card-actions>
      </v-window-item>

      <v-window-item value="most-played-tracks">
        <v-skeleton-loader v-if="statistics.mostPlayedTracksLoading" type="list-item-three-line@6" />
        <v-list v-else-if="statistics.mostPlayedTracks.items.length" lines="three">
          <v-list-item
            v-for="track in statistics.mostPlayedTracks.items"
            :key="track.id"
            class="track-list-item"
            :class="{ 'current-track': player.currentTrack?.id === track.id }"
            prepend-icon="mdi-headphones"
          >
            <v-list-item-title class="font-weight-bold">
              <RouterLink class="history-link" :to="{ name: 'track-detail', params: { id: track.id } }">
                {{ track.title }}
              </RouterLink>
            </v-list-item-title>
            <v-list-item-subtitle>
              <template v-if="track.artists.length">
                <template v-for="(artist, index) in track.artists" :key="artist.id">
                  <span v-if="index > 0">, </span>
                  <RouterLink class="history-link" :to="{ name: 'artist-detail', params: { id: artist.id } }">
                    {{ artist.name }}
                  </RouterLink>
                </template>
              </template>
              <span v-else>{{ t('catalog.unknownArtist') }}</span>
            </v-list-item-subtitle>
            <v-list-item-subtitle>
              {{ t('tracks.playCountTooltip', { count: track.playStatistics.playCount }) }}
              <span aria-hidden="true"> · </span>
              <RouterLink
                v-if="track.album"
                class="history-link"
                :to="{ name: 'album-detail', params: { id: track.album.id } }"
              >
                {{ track.album.title }}
              </RouterLink>
              <span v-else>{{ t('catalog.unknownAlbum') }}</span>
            </v-list-item-subtitle>
            <template #append>
              <div class="track-actions">
                <span class="text-caption text-medium-emphasis">{{ duration(track.durationMs) }}</span>
                <TooltipIconButton
                  :text="player.currentTrack?.id === track.id && player.isPlaying ? t('player.pause') : t('player.play')"
                  :aria-label="player.currentTrack?.id === track.id && player.isPlaying ? t('player.pause') : t('player.play')"
                  :color="player.currentTrack?.id === track.id ? 'primary' : undefined"
                  density="comfortable"
                  :icon="player.currentTrack?.id === track.id && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
                  variant="text"
                  @click="toggleTrack(track, statistics.mostPlayedTracks.items)"
                />
                <TooltipIconButton
                  :text="t('tracks.queueTrack')"
                  :aria-label="t('tracks.queueTrack')"
                  density="comfortable"
                  icon="mdi-playlist-plus"
                  variant="text"
                  @click="player.queueTrack(track, 'track-list')"
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
        <v-card-text v-else>
          <EmptyCatalogState :title="t('history.emptyMostPlayedTitle')" :description="t('history.emptyMostPlayedDescription')" icon="mdi-chart-bar" />
        </v-card-text>
        <v-card-actions v-if="statistics.mostPlayedTracks.lastPage > 1">
          <v-pagination v-model="mostPlayedTrackPage" :length="statistics.mostPlayedTracks.lastPage" @update:model-value="loadMostPlayedTrackPage" />
        </v-card-actions>
      </v-window-item>

      <v-window-item value="most-played-albums">
        <v-skeleton-loader v-if="statistics.mostPlayedAlbumsLoading" type="list-item-three-line@6" />
        <v-list v-else-if="statistics.mostPlayedAlbums.items.length" lines="two">
          <v-list-item
            v-for="album in statistics.mostPlayedAlbums.items"
            :key="album.id"
            class="track-list-item"
            prepend-icon="mdi-album"
            :to="{ name: 'album-detail', params: { id: album.id } }"
          >
            <v-list-item-title class="font-weight-bold">{{ album.title }}</v-list-item-title>
            <v-list-item-subtitle>
              {{ album.primaryArtist?.name ?? t('catalog.unknownArtist') }}
            </v-list-item-subtitle>
            <v-list-item-subtitle>
              {{ t('history.albumPlayCount', { count: album.playCount }) }}
              <template v-if="album.lastPlayedAt">
                <span aria-hidden="true"> · </span>
                {{ t('tracks.lastPlayedAtTooltip', { value: formatDate(album.lastPlayedAt) }) }}
              </template>
            </v-list-item-subtitle>
          </v-list-item>
        </v-list>
        <v-card-text v-else>
          <EmptyCatalogState :title="t('history.emptyMostPlayedAlbumsTitle')" :description="t('history.emptyMostPlayedAlbumsDescription')" icon="mdi-album" />
        </v-card-text>
        <v-card-actions v-if="statistics.mostPlayedAlbums.lastPage > 1">
          <v-pagination v-model="mostPlayedAlbumPage" :length="statistics.mostPlayedAlbums.lastPage" @update:model-value="loadMostPlayedAlbumPage" />
        </v-card-actions>
      </v-window-item>
    </v-window>
  </v-card>
</template>

<style scoped>
.history-link {
  color: inherit;
  text-decoration: none;
}

.history-link:hover {
  text-decoration: underline;
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

.track-actions {
  align-items: center;
  display: flex;
  gap: 4px;
}

.track-actions :deep(.v-btn) {
  min-width: 34px;
  padding-inline: 0;
}
</style>
