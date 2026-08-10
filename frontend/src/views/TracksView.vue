<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import CatalogPagination from '@/components/CatalogPagination.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TrackPlaylistMembershipMenu from '@/components/TrackPlaylistMembershipMenu.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCachedViewActivation } from '@/composables/useCachedViewActivation'
import type { Track } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { usePlayerStore, type TrackPlaybackScope } from '@/stores/player'
import { usePlaylistsStore } from '@/stores/playlists'
import { formatDateTime, formatDuration } from '@/utils/formatters'
import { createRouteQuerySyncGuard } from '@/utils/routeQuerySyncGuard'

interface TrackFilters {
  page: number
  search: string
  genre: number | null
  genreName: string
  musician: number | null
  musicianName: string
  playStatus: 'all' | 'never'
  physicalCopy: PhysicalCopyFilter
  sort: TrackSort
}

type PhysicalCopyFilter = 'all' | 'owned' | 'not_owned'
type TrackSort = 'album' | 'title' | 'year_asc' | 'year_desc' | 'plays' | 'last_played' | 'added'

const { locale, t } = useI18n()
const route = useRoute()
const router = useRouter()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const libraryRootScope = useLibraryRootScopeStore()
const libraryRoots = useLibraryRootsStore()
const player = usePlayerStore()
const playlists = usePlaylistsStore()
const storageKey = 'sonotheque:track-filters'
const restoredFilters = initialFilters()
const search = ref(restoredFilters.search)
const searchInput = ref(restoredFilters.search)
const genre = ref(restoredFilters.genre)
const genreName = ref(restoredFilters.genreName)
const musician = ref(restoredFilters.musician)
const musicianName = ref(restoredFilters.musicianName)
const playStatus = ref<'all' | 'never'>(restoredFilters.playStatus)
const physicalCopy = ref<PhysicalCopyFilter>(restoredFilters.physicalCopy)
const sort = ref<TrackSort>(restoredFilters.sort)
const page = ref(restoredFilters.page)
const resultsTop = ref<HTMLElement | null>(null)
const addToPlaylistDialog = ref(false)
const playlistTracks = ref<Track[]>([])
let applyingRouteFilters = false
const routeQuerySyncGuard = createRouteQuerySyncGuard()
const physicalCopyOptions = computed(() => [
  { title: t('albums.physicalCopyAll'), value: 'all' },
  { title: t('albums.physicalCopyOwned'), value: 'owned' },
  { title: t('albums.physicalCopyMissing'), value: 'not_owned' },
])
const sortOptions = computed(() => [
  { title: t('tracks.sortAlbum'), value: 'album' },
  { title: t('tracks.sortTitle'), value: 'title' },
  { title: t('tracks.sortYearNewest'), value: 'year_desc' },
  { title: t('tracks.sortYearOldest'), value: 'year_asc' },
  { title: t('tracks.sortMostPlayed'), value: 'plays' },
  { title: t('tracks.sortLastPlayed'), value: 'last_played' },
  { title: t('tracks.sortRecentlyAdded'), value: 'added' },
])

function currentFilters(): TrackFilters {
  return {
    page: page.value,
    search: querySearch(search.value),
    genre: genre.value,
    genreName: genreName.value,
    musician: musician.value,
    musicianName: musicianName.value,
    playStatus: playStatus.value,
    physicalCopy: physicalCopy.value,
    sort: sort.value,
  }
}

function defaultFilters(): TrackFilters {
  return {
    page: 1,
    search: '',
    genre: null,
    genreName: '',
    musician: null,
    musicianName: '',
    playStatus: 'all',
    physicalCopy: 'all',
    sort: 'album',
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
    page: queryPage(route.query.page),
    search: querySearch(route.query.search),
    genre: queryNumber(route.query.genre),
    genreName: querySearch(route.query.genreName),
    musician: queryNumber(route.query.musician),
    musicianName: querySearch(route.query.musicianName),
    playStatus: queryPlayStatus(route.query.playStatus),
    physicalCopy: queryPhysicalCopy(route.query.physicalCopy),
    sort: queryTrackSort(route.query.sort),
  }
}

function hasFilterQuery() {
  return ['page', 'search', 'genre', 'genreName', 'musician', 'musicianName', 'playStatus', 'physicalCopy', 'sort'].some((key) => route.query[key] !== undefined)
}

function filtersFromStorage(): TrackFilters | null {
  try {
    const stored = window.sessionStorage.getItem(storageKey)
    if (!stored) return null

    const parsed = JSON.parse(stored) as Partial<TrackFilters>

    return {
      page: queryPage(parsed.page),
      search: typeof parsed.search === 'string' ? parsed.search : '',
      genre: typeof parsed.genre === 'number' ? parsed.genre : null,
      genreName: typeof parsed.genreName === 'string' ? parsed.genreName : '',
      musician: typeof parsed.musician === 'number' ? parsed.musician : null,
      musicianName: typeof parsed.musicianName === 'string' ? parsed.musicianName : '',
      playStatus: queryPlayStatus(parsed.playStatus),
      physicalCopy: queryPhysicalCopy(parsed.physicalCopy),
      sort: queryTrackSort(parsed.sort),
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

  const pendingSync = routeQuerySyncGuard.mark(query)

  void router.replace({ name: 'tracks', query }).finally(() => {
    routeQuerySyncGuard.release(pendingSync)
  })
}

function applyFilters(filters: TrackFilters) {
  applyingRouteFilters = true
  page.value = filters.page
  search.value = filters.search
  searchInput.value = filters.search
  genre.value = filters.genre
  genreName.value = filters.genreName
  musician.value = filters.musician
  musicianName.value = filters.musicianName
  playStatus.value = filters.playStatus
  physicalCopy.value = filters.physicalCopy
  sort.value = filters.sort
  applyingRouteFilters = false
  saveFilters()
}

function filterQuery(filters: TrackFilters) {
  const query: Record<string, string> = {}

  if (filters.page > 1) query.page = String(filters.page)
  if (filters.search.trim()) query.search = filters.search.trim()
  if (filters.genre) query.genre = String(filters.genre)
  if (filters.genreName.trim()) query.genreName = filters.genreName.trim()
  if (filters.musician) query.musician = String(filters.musician)
  if (filters.musicianName.trim()) query.musicianName = filters.musicianName.trim()
  if (filters.playStatus === 'never') query.playStatus = filters.playStatus
  if (filters.physicalCopy !== 'all') query.physicalCopy = filters.physicalCopy
  if (filters.sort !== 'album') query.sort = filters.sort

  return query
}

function normalizedFilterQuery(query: typeof route.query) {
  return filterQuery({
    page: queryPage(query.page),
    search: querySearch(query.search),
    genre: queryNumber(query.genre),
    genreName: querySearch(query.genreName),
    musician: queryNumber(query.musician),
    musicianName: querySearch(query.musicianName),
    playStatus: queryPlayStatus(query.playStatus),
    physicalCopy: queryPhysicalCopy(query.physicalCopy),
    sort: queryTrackSort(query.sort),
  })
}

function querySearch(value: unknown) {
  return typeof value === 'string' ? value : ''
}

function queryPage(value: unknown) {
  const parsed = typeof value === 'number' || typeof value === 'string' ? Number(value) : NaN

  return Number.isInteger(parsed) && parsed > 0 ? parsed : 1
}

function queryNumber(value: unknown) {
  const parsed = typeof value === 'string' ? Number(value) : NaN

  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
}

function queryPlayStatus(value: unknown): 'all' | 'never' {
  return value === 'never' ? 'never' : 'all'
}

function queryPhysicalCopy(value: unknown): PhysicalCopyFilter {
  return value === 'owned' || value === 'not_owned' ? value : 'all'
}

function queryTrackSort(value: unknown): TrackSort {
  return ['title', 'year_asc', 'year_desc', 'plays', 'last_played', 'added'].includes(String(value))
    ? value as TrackSort
    : 'album'
}

function clearGenreFilter() {
  genre.value = null
  genreName.value = ''
  page.value = 1
}

function clearMusicianFilter() {
  musician.value = null
  musicianName.value = ''
  page.value = 1
}

function changePage(value: number) {
  if (value === page.value) return

  page.value = value
  saveFilters()
  syncFiltersToRoute()
  void nextTick(() => {
    resultsTop.value?.scrollIntoView({ behavior: 'auto', block: 'start' })
  })
}

function formatDate(value?: string | null) {
  return formatDateTime(value, locale.value)
}

function duration(milliseconds?: number) {
  return formatDuration(milliseconds, '—')
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
    musician: musician.value,
    playStatus: playStatus.value === 'never' ? playStatus.value : null,
    physicalCopy: physicalCopy.value === 'all' ? null : physicalCopy.value,
    sort: sort.value,
  })
}

function applySearch() {
  const nextSearch = searchInput.value.trim()

  page.value = 1
  search.value = nextSearch
  saveFilters()
  syncFiltersToRoute()
}

function clearSearch() {
  searchInput.value = ''
  applySearch()
}

function playRandomTrack() {
  const filters = currentFilters()
  const selectedRoot = libraryRoots.roots.find(root => root.id === libraryRootScope.selectedRootId)
  const scope: TrackPlaybackScope = {
    type: 'tracks',
    libraryRootId: libraryRootScope.selectedRootId,
    libraryRootName: selectedRoot?.name ?? null,
    search: filters.search.trim(),
    genreId: filters.genre,
    genreName: filters.genreName,
    musicianId: filters.musician,
    musicianName: filters.musicianName,
    playStatus: filters.playStatus === 'never' ? 'never' : null,
    physicalCopy: filters.physicalCopy === 'all' ? null : filters.physicalCopy,
    sort: filters.sort,
  }

  void player.playRandomTrack(scope)
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
useCachedViewActivation(load)

watch(() => route.query, () => {
  if (route.name !== 'tracks') return

  const normalizedQuery = normalizedFilterQuery(route.query)
  if (routeQuerySyncGuard.consume(normalizedQuery)) return

  const routeFilters = filtersFromQuery()
  if (routeFilters) {
    applyFilters(routeFilters)
    return
  }

  syncFiltersToRoute()
})
watch([genre, genreName, musician, musicianName, playStatus, physicalCopy, sort], () => {
  if (applyingRouteFilters) return

  page.value = 1
  saveFilters()
  syncFiltersToRoute()
})
watch([page, search, genre, musician, playStatus, physicalCopy, sort, () => libraryRootScope.selectedRootId], load, { immediate: true })
watch([
  () => catalog.tracks.items.map((track) => track.id),
  () => libraryRootScope.selectedRootId,
], ([trackIds]) => {
  if (trackIds.length) void playlists.loadMemberships(trackIds)
}, { immediate: true })
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
    <v-btn color="primary" prepend-icon="mdi-shuffle-variant" variant="tonal" @click="playRandomTrack">
      {{ t('player.playRandomTrack') }}
    </v-btn>
  </div>
  <div class="track-filter-row d-flex flex-column flex-sm-row flex-sm-wrap flex-lg-nowrap ga-3 mb-6">
    <v-text-field
      v-model="searchInput"
      append-inner-icon="mdi-magnify"
      clearable
      hide-details
      :label="t('tracks.search')"
      @click:append-inner="applySearch"
      @click:clear="clearSearch"
      @keyup.enter="applySearch"
    />
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
    <v-select
      v-model="physicalCopy"
      class="physical-copy-filter"
      density="compact"
      hide-details
      :items="physicalCopyOptions"
      prepend-inner-icon="mdi-disc"
      :label="t('albums.physicalCopyFilter')"
    />
    <v-select
      v-model="sort"
      class="track-sort"
      density="compact"
      hide-details
      :items="sortOptions"
      prepend-inner-icon="mdi-sort"
      :label="t('tracks.sortBy')"
    />
  </div>
  <div v-if="genre || musician" class="d-flex flex-wrap ga-2 mb-6">
    <v-chip v-if="genre" closable color="primary" variant="tonal" @click:close="clearGenreFilter">
      {{ t('genres.filterLabel', { name: genreName || t('genres.filterFallback', { id: genre }) }) }}
    </v-chip>
    <v-chip v-if="musician" closable color="primary" variant="tonal" @click:close="clearMusicianFilter">
      {{ t('musicians.filterLabel', { name: musicianName || t('musicians.filterFallback', { id: musician }) }) }}
    </v-chip>
  </div>
  <div ref="resultsTop" class="catalog-results-anchor" />
  <CatalogPagination class="mb-4" :model-value="page" :length="catalog.tracks.lastPage" @update:model-value="changePage" />
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
            <RouterLink class="track-meta-link" :to="{ name: 'artist-detail', params: { id: artist.id } }">
              {{ artist.name }}
            </RouterLink>
          </template>
        </template>
        <span v-else>{{ t('catalog.unknownArtist') }}</span>
      </v-list-item-subtitle>
      <v-list-item-subtitle>
        <template v-if="track.album">
          <RouterLink
            class="track-meta-link"
            :to="{ name: 'album-detail', params: { id: track.album.id }, query: { backTo: 'tracks' } }"
          >
            {{ track.album.title }}
          </RouterLink>
          <span v-if="track.year !== undefined && track.year !== null"> · {{ track.year }}</span>
        </template>
        <span v-else>{{ t('catalog.unknownAlbum') }}</span>
        <v-chip
          v-if="track.album?.personalMetadata?.hasPhysicalCopy"
          class="ms-2"
          color="primary"
          prepend-icon="mdi-disc"
          size="x-small"
          variant="tonal"
        >
          {{ t('albums.physicalCopy') }}
        </v-chip>
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
          <TrackPlaylistMembershipMenu
            icon-only
            :track-id="track.id"
            @add-to-playlist="openAddToPlaylist(track)"
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
  <CatalogPagination class="mt-6" :model-value="page" :length="catalog.tracks.lastPage" @update:model-value="changePage" />
  <AddToPlaylistDialog v-model="addToPlaylistDialog" :tracks="playlistTracks" />
</template>

<style scoped>
.never-played-filter {
  flex: 0 0 auto;
  min-width: 11rem;
}

.physical-copy-filter {
  flex: 0 0 13rem;
}

.track-sort {
  flex: 0 0 15rem;
}

@media (max-width: 599px) {
  .physical-copy-filter,
  .track-sort {
    flex-basis: auto;
    width: 100%;
  }
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
