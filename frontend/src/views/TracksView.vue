<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
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
const search = ref(querySearch(route.query.search))
const genre = ref(queryNumber(route.query.genre))
const genreName = ref(querySearch(route.query.genreName))
const page = ref(1)
let searchTimer: ReturnType<typeof setTimeout> | null = null

function querySearch(value: unknown) {
  return typeof value === 'string' ? value : ''
}

function queryNumber(value: unknown) {
  const parsed = typeof value === 'string' ? Number(value) : NaN

  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
}

function clearGenreFilter() {
  const query = { ...route.query }
  delete query.genre
  delete query.genreName
  genre.value = null
  genreName.value = ''
  page.value = 1
  void router.replace({ name: 'tracks', query })
}

function duration(milliseconds?: number) {
  if (!milliseconds) return '—'
  const seconds = Math.round(milliseconds / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function load() {
  void catalog.loadTracks({ page: page.value, search: search.value, genre: genre.value })
}

function toggleTrack(track: Track) {
  if (player.currentTrack?.id === track.id && player.isPlaying) {
    player.pause()
    return
  }

  player.playTrack(track, catalog.tracks.items, 'track-list')
}

function queueTrack(track: Track) {
  player.queueTrack(track, 'track-list')
}

watch([page, genre], load, { immediate: true })
watch(() => route.query.search, (value) => {
  search.value = querySearch(value)
  page.value = 1
})
watch(() => [route.query.genre, route.query.genreName], ([genreValue, genreNameValue]) => {
  genre.value = queryNumber(genreValue)
  genreName.value = querySearch(genreNameValue)
  page.value = 1
})
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer)
  if (page.value !== 1) {
    page.value = 1
    return
  }
  searchTimer = setTimeout(load, 300)
})
onUnmounted(() => {
  if (searchTimer) clearTimeout(searchTimer)
})
</script>

<template>
  <PageHeader :title="t('tracks.title')" :description="t('tracks.description')" icon="mdi-music-note-outline" />
  <div class="d-flex flex-wrap ga-3 mb-4">
    <v-btn color="primary" prepend-icon="mdi-album" variant="flat" @click="void player.playRandomAlbum()">
      {{ t('player.playRandomAlbum') }}
    </v-btn>
    <v-btn color="primary" prepend-icon="mdi-shuffle-variant" variant="tonal" @click="void player.playRandomTrack()">
      {{ t('player.playRandomTrack') }}
    </v-btn>
  </div>
  <v-text-field v-model="search" class="mb-6" clearable hide-details prepend-inner-icon="mdi-magnify" :label="t('tracks.search')" />
  <div v-if="genre" class="mb-6">
    <v-chip closable color="primary" variant="tonal" @click:close="clearGenreFilter">
      {{ t('genres.filterLabel', { name: genreName || t('genres.filterFallback', { id: genre }) }) }}
    </v-chip>
  </div>
  <v-alert v-if="catalog.tracksError" type="error" variant="tonal">{{ catalog.tracksError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.tracksLoading" type="list-item-three-line@8" />
  <v-list v-else-if="catalog.tracks.items.length" border rounded="xl" lines="three">
    <v-list-item v-for="track in catalog.tracks.items" :key="track.id" prepend-icon="mdi-music-note">
      <v-list-item-title class="font-weight-bold">
        <RouterLink class="track-meta-link" :to="{ name: 'track-detail', params: { id: track.id } }">
          {{ track.title }}
        </RouterLink>
      </v-list-item-title>
      <v-list-item-subtitle>
        <template v-if="track.artists.length">
          <template v-for="(artist, index) in track.artists" :key="artist.id">
            <span v-if="index > 0">, </span>
            <RouterLink class="track-meta-link" :to="{ name: 'albums', query: { search: artist.name } }">
              {{ artist.name }}
            </RouterLink>
          </template>
        </template>
        <span v-else>{{ t('catalog.unknownArtist') }}</span>
      </v-list-item-subtitle>
      <v-list-item-subtitle>
        <RouterLink
          v-if="track.album"
          class="track-meta-link"
          :to="{ name: 'album-detail', params: { id: track.album.id } }"
        >
          {{ track.album.title }}
        </RouterLink>
        <span v-else>{{ t('catalog.unknownAlbum') }}</span>
      </v-list-item-subtitle>
      <template #append>
        <div class="d-flex align-center ga-2">
          <span class="text-caption text-medium-emphasis">{{ duration(track.durationMs) }}</span>
          <v-btn
            :aria-label="player.currentTrack?.id === track.id && player.isPlaying ? t('player.pause') : t('player.play')"
            :color="player.currentTrack?.id === track.id ? 'primary' : undefined"
            :icon="player.currentTrack?.id === track.id && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
            variant="text"
            @click="toggleTrack(track)"
          />
          <v-btn
            :aria-label="t('tracks.queueTrack')"
            icon="mdi-playlist-plus"
            variant="text"
            @click="queueTrack(track)"
          />
          <v-btn
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
  <EmptyCatalogState v-else :title="t('tracks.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-music-note-outline" />
  <v-pagination v-if="catalog.tracks.lastPage > 1" v-model="page" class="mt-6" :length="catalog.tracks.lastPage" />
</template>

<style scoped>
.track-meta-link {
  color: inherit;
  text-decoration: none;
}

.track-meta-link:hover {
  text-decoration: underline;
}
</style>
