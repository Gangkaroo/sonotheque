<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { Track } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'

interface TrackFilters {
  search: string
  genre: number | null
  genreName: string
}

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const storageKey = 'music-library:track-filters'
const restoredFilters = initialFilters()
const search = ref(restoredFilters.search)
const genre = ref(restoredFilters.genre)
const genreName = ref(restoredFilters.genreName)
const page = ref(1)
const addToPlaylistDialog = ref(false)
const playlistTracks = ref<Track[]>([])
let searchTimer: ReturnType<typeof setTimeout> | null = null
let applyingRouteFilters = false

function currentFilters(): TrackFilters {
  return {
    search: querySearch(search.value),
    genre: genre.value,
    genreName: genreName.value,
  }
}

function defaultFilters(): TrackFilters {
  return {
    search: '',
    genre: null,
    genreName: '',
  }
}

function initialFilters(): TrackFilters {
  const routeFilters = filtersFromQuery()

  if (routeFilters) return routeFilters

  return filtersFromStorage() ?? defaultFilters()
}

function filtersFromQuery(): TrackFilters | null {
  if (!hasFilterQuery()) return null

  return {
    search: querySearch(route.query.search),
    genre: queryNumber(route.query.genre),
    genreName: querySearch(route.query.genreName),
  }
}

function hasFilterQuery() {
  return ['search', 'genre', 'genreName'].some((key) => route.query[key] !== undefined)
}

function filtersFromStorage(): TrackFilters | null {
  try {
    const stored = window.sessionStorage.getItem(storageKey)
    if (!stored) return null

    const parsed = JSON.parse(stored) as Partial<TrackFilters>

    return {
      search: typeof parsed.search === 'string' ? parsed.search : '',
      genre: typeof parsed.genre === 'number' ? parsed.genre : null,
      genreName: typeof parsed.genreName === 'string' ? parsed.genreName : '',
    }
  } catch {
    return null
  }
}

function saveFilters() {
  window.sessionStorage.setItem(storageKey, JSON.stringify(currentFilters()))
}

function syncFiltersToRoute() {
  const query = filterQuery(currentFilters())

  if (JSON.stringify(normalizedFilterQuery(route.query)) === JSON.stringify(query)) return

  void router.replace({ name: 'tracks', query })
}

function applyFilters(filters: TrackFilters) {
  applyingRouteFilters = true
  search.value = filters.search
  genre.value = filters.genre
  genreName.value = filters.genreName
  page.value = 1
  applyingRouteFilters = false
  saveFilters()
}

function filterQuery(filters: TrackFilters) {
  const query: Record<string, string> = {}

  if (filters.search.trim()) query.search = filters.search.trim()
  if (filters.genre) query.genre = String(filters.genre)
  if (filters.genreName.trim()) query.genreName = filters.genreName.trim()

  return query
}

function normalizedFilterQuery(query: typeof route.query) {
  return filterQuery({
    search: querySearch(query.search),
    genre: queryNumber(query.genre),
    genreName: querySearch(query.genreName),
  })
}

function querySearch(value: unknown) {
  return typeof value === 'string' ? value : ''
}

function queryNumber(value: unknown) {
  const parsed = typeof value === 'string' ? Number(value) : NaN

  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
}

function clearGenreFilter() {
  genre.value = null
  genreName.value = ''
  page.value = 1
}

function duration(milliseconds?: number) {
  if (!milliseconds) return '—'
  const seconds = Math.round(milliseconds / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function load() {
  void catalog.loadTracks({ page: page.value, search: querySearch(search.value), genre: genre.value })
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

function openAddToPlaylist(track: Track) {
  playlistTracks.value = [track]
  addToPlaylistDialog.value = true
}

saveFilters()

watch(() => route.query, () => {
  const routeFilters = filtersFromQuery()
  if (routeFilters) {
    applyFilters(routeFilters)
    return
  }

  syncFiltersToRoute()
})
watch([genre, genreName], () => {
  if (applyingRouteFilters) return

  page.value = 1
  saveFilters()
  syncFiltersToRoute()
})
watch([page, genre], load, { immediate: true })
watch(search, () => {
  const wasNotFirstPage = page.value !== 1
  if (searchTimer) clearTimeout(searchTimer)

  if (!applyingRouteFilters) {
    page.value = 1
    saveFilters()
    syncFiltersToRoute()
  } else if (wasNotFirstPage) {
    page.value = 1
  }

  if (wasNotFirstPage) return

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
            @click="queueTrack(track)"
          />
          <TooltipIconButton
            :text="t('playlists.addTrackToPlaylist')"
            :aria-label="t('playlists.addTrackToPlaylist')"
            icon="mdi-playlist-music"
            variant="text"
            @click="openAddToPlaylist(track)"
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
  <EmptyCatalogState v-else :title="t('tracks.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-music-note-outline" />
  <v-pagination v-if="catalog.tracks.lastPage > 1" v-model="page" class="mt-6" :length="catalog.tracks.lastPage" />
  <AddToPlaylistDialog v-model="addToPlaylistDialog" :tracks="playlistTracks" />
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
