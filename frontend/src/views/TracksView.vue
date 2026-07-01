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
  artist: number | null
  artistName: string
  playStatus: 'all' | 'never'
}

const { locale, t } = useI18n()
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
const artist = ref(restoredFilters.artist)
const artistName = ref(restoredFilters.artistName)
const playStatus = ref<'all' | 'never'>(restoredFilters.playStatus)
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
    artist: artist.value,
    artistName: artistName.value,
    playStatus: playStatus.value,
  }
}

function defaultFilters(): TrackFilters {
  return {
    search: '',
    genre: null,
    genreName: '',
    artist: null,
    artistName: '',
    playStatus: 'all',
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
    artist: queryNumber(route.query.artist),
    artistName: querySearch(route.query.artistName),
    playStatus: queryPlayStatus(route.query.playStatus),
  }
}

function hasFilterQuery() {
  return ['search', 'genre', 'genreName', 'artist', 'artistName', 'playStatus'].some((key) => route.query[key] !== undefined)
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
      artist: typeof parsed.artist === 'number' ? parsed.artist : null,
      artistName: typeof parsed.artistName === 'string' ? parsed.artistName : '',
      playStatus: queryPlayStatus(parsed.playStatus),
    }
  } catch {
    return null
  }
}

function saveFilters() {
  window.sessionStorage.setItem(storageKey, JSON.stringify(currentFilters()))
}

function syncFiltersToRoute() {
  if (route.name !== 'tracks') return

  const query = filterQuery(currentFilters())

  if (JSON.stringify(normalizedFilterQuery(route.query)) === JSON.stringify(query)) return

  void router.replace({ name: 'tracks', query })
}

function applyFilters(filters: TrackFilters) {
  applyingRouteFilters = true
  search.value = filters.search
  genre.value = filters.genre
  genreName.value = filters.genreName
  artist.value = filters.artist
  artistName.value = filters.artistName
  playStatus.value = filters.playStatus
  page.value = 1
  applyingRouteFilters = false
  saveFilters()
}

function filterQuery(filters: TrackFilters) {
  const query: Record<string, string> = {}

  if (filters.search.trim()) query.search = filters.search.trim()
  if (filters.genre) query.genre = String(filters.genre)
  if (filters.genreName.trim()) query.genreName = filters.genreName.trim()
  if (filters.artist) query.artist = String(filters.artist)
  if (filters.artistName.trim()) query.artistName = filters.artistName.trim()
  if (filters.playStatus === 'never') query.playStatus = filters.playStatus

  return query
}

function normalizedFilterQuery(query: typeof route.query) {
  return filterQuery({
    search: querySearch(query.search),
    genre: queryNumber(query.genre),
    genreName: querySearch(query.genreName),
    artist: queryNumber(query.artist),
    artistName: querySearch(query.artistName),
    playStatus: queryPlayStatus(query.playStatus),
  })
}

function querySearch(value: unknown) {
  return typeof value === 'string' ? value : ''
}

function queryNumber(value: unknown) {
  const parsed = typeof value === 'string' ? Number(value) : NaN

  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
}

function queryPlayStatus(value: unknown): 'all' | 'never' {
  return value === 'never' ? 'never' : 'all'
}

function clearGenreFilter() {
  genre.value = null
  genreName.value = ''
  page.value = 1
}

function clearArtistFilter() {
  artist.value = null
  artistName.value = ''
  page.value = 1
}

function duration(milliseconds?: number) {
  if (!milliseconds) return '—'
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

function load() {
  void catalog.loadTracks({
    page: page.value,
    search: querySearch(search.value),
    genre: genre.value,
    artist: artist.value,
    playStatus: playStatus.value === 'never' ? playStatus.value : null,
  })
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

function queueTrack(track: Track) {
  player.queueTrack(track, 'track-list')
}

function openAddToPlaylist(track: Track) {
  playlistTracks.value = [track]
  addToPlaylistDialog.value = true
}

saveFilters()

watch(() => route.query, () => {
  if (route.name !== 'tracks') return

  const routeFilters = filtersFromQuery()
  if (routeFilters) {
    applyFilters(routeFilters)
    return
  }

  syncFiltersToRoute()
})
watch([genre, genreName, artist, artistName, playStatus], () => {
  if (applyingRouteFilters) return

  page.value = 1
  saveFilters()
  syncFiltersToRoute()
})
watch([page, genre, artist, playStatus], load, { immediate: true })
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
  <PageHeader
    :title="t('tracks.title')"
    :count="catalog.tracksLoading || catalog.tracksError ? undefined : catalog.tracks.total"
    :description="t('tracks.description')"
    icon="mdi-music-note-outline"
  />
  <div class="d-flex flex-wrap ga-3 mb-4">
    <v-btn color="primary" prepend-icon="mdi-album" variant="flat" @click="void player.playRandomAlbum()">
      {{ t('player.playRandomAlbum') }}
    </v-btn>
    <v-btn color="primary" prepend-icon="mdi-shuffle-variant" variant="tonal" @click="void player.playRandomTrack()">
      {{ t('player.playRandomTrack') }}
    </v-btn>
  </div>
  <div class="track-filter-row d-flex flex-column flex-sm-row ga-3 mb-6">
    <v-text-field v-model="search" clearable hide-details prepend-inner-icon="mdi-magnify" :label="t('tracks.search')" />
    <v-switch
      v-model="playStatus"
      class="never-played-filter"
      color="primary"
      density="compact"
      false-value="all"
      hide-details
      :label="t('tracks.neverPlayed')"
      true-value="never"
    />
  </div>
  <div v-if="genre || artist" class="d-flex flex-wrap ga-2 mb-6">
    <v-chip v-if="genre" closable color="primary" variant="tonal" @click:close="clearGenreFilter">
      {{ t('genres.filterLabel', { name: genreName || t('genres.filterFallback', { id: genre }) }) }}
    </v-chip>
    <v-chip v-if="artist" closable color="primary" variant="tonal" @click:close="clearArtistFilter">
      {{ t('artists.filterLabel', { name: artistName || t('artists.filterFallback', { id: artist }) }) }}
    </v-chip>
  </div>
  <v-alert v-if="catalog.tracksError" type="error" variant="tonal">{{ catalog.tracksError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.tracksLoading" type="list-item-three-line@8" />
  <v-list v-else-if="catalog.tracks.items.length" border rounded="xl" lines="three">
    <v-list-item
      v-for="track in catalog.tracks.items"
      :key="track.id"
      class="track-list-item"
      :class="{ 'current-track': player.currentTrack?.id === track.id }"
      prepend-icon="mdi-music-note"
    >
      <v-list-item-title class="font-weight-bold">
        <RouterLink class="track-meta-link" :to="{ name: 'track-detail', params: { id: track.id } }">
          {{ track.title }}
        </RouterLink>
      </v-list-item-title>
      <v-list-item-subtitle>
        <template v-if="track.artists.length">
          <template v-for="(artist, index) in track.artists" :key="artist.id">
            <span v-if="index > 0">, </span>
            <RouterLink class="track-meta-link" :to="{ name: 'albums', query: { artist: artist.id, artistName: artist.name } }">
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
            :text="player.currentTrack?.id === track.id && player.isPlaying ? t('player.pause') : t('player.play')"
            :aria-label="player.currentTrack?.id === track.id && player.isPlaying ? t('player.pause') : t('player.play')"
            :color="player.currentTrack?.id === track.id ? 'primary' : undefined"
            density="comfortable"
            :icon="player.currentTrack?.id === track.id && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
            variant="text"
            @click="toggleTrack(track)"
          />
          <TooltipIconButton
            :text="t('tracks.queueTrack')"
            :aria-label="t('tracks.queueTrack')"
            density="comfortable"
            icon="mdi-playlist-plus"
            variant="text"
            @click="queueTrack(track)"
          />
          <TooltipIconButton
            :text="t('playlists.addTrackToPlaylist')"
            :aria-label="t('playlists.addTrackToPlaylist')"
            density="comfortable"
            icon="mdi-playlist-music"
            variant="text"
            @click="openAddToPlaylist(track)"
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
  <EmptyCatalogState v-else :title="t('tracks.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-music-note-outline" />
  <v-pagination v-if="catalog.tracks.lastPage > 1" v-model="page" class="mt-6" :length="catalog.tracks.lastPage" />
  <AddToPlaylistDialog v-model="addToPlaylistDialog" :tracks="playlistTracks" />
</template>

<style scoped>
.never-played-filter {
  flex: 0 0 auto;
  min-width: 11rem;
}

.track-meta-link {
  color: inherit;
  text-decoration: none;
}

.track-meta-link:hover {
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
