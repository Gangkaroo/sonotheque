<script setup lang="ts">
import { computed, ref, toRefs, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import CatalogPagination from '@/components/CatalogPagination.vue'
import CatalogRating from '@/components/CatalogRating.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TrackPlaylistMembershipMenu from '@/components/TrackPlaylistMembershipMenu.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCatalogFilters, queryNumber, queryPage, querySearch } from '@/composables/useCatalogFilters'
import type { Track } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { usePlayerStore, type TrackPlaybackScope } from '@/stores/player'
import { usePlaylistsStore } from '@/stores/playlists'
import { formatDateTime, formatDuration } from '@/utils/formatters'

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
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const libraryRootScope = useLibraryRootScopeStore()
const libraryRoots = useLibraryRootsStore()
const player = usePlayerStore()
const playlists = usePlaylistsStore()
const { filters, searchInput, resultsTop, isActive, currentFilters, changePage, applySearch, clearSearch } = useCatalogFilters<TrackFilters>({
  routeName: 'tracks',
  storageKey: 'sonotheque:track-filters',
  scope: () => libraryRootScope.selectedRootId,
  parse: input => ({
    page: queryPage(input.page),
    search: querySearch(input.search),
    genre: queryNumber(input.genre),
    genreName: querySearch(input.genreName),
    musician: queryNumber(input.musician),
    musicianName: querySearch(input.musicianName),
    playStatus: queryPlayStatus(input.playStatus),
    physicalCopy: queryPhysicalCopy(input.physicalCopy),
    sort: queryTrackSort(input.sort),
  }),
  load: value => {
    void catalog.loadTracks({
      ...value,
      playStatus: value.playStatus === 'never' ? 'never' : null,
      physicalCopy: value.physicalCopy === 'all' ? null : value.physicalCopy,
    })
  },
})
const { page, genre, genreName, musician, musicianName, playStatus, physicalCopy, sort } = toRefs(filters)
const addToPlaylistDialog = ref(false)
const playlistTracks = ref<Track[]>([])
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
}

function clearMusicianFilter() {
  musician.value = null
  musicianName.value = ''
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

function playRandomTrack() {
  const filters = currentFilters()
  const selectedRoot = libraryRoots.roots.find(root => root.id === libraryRootScope.selectedRootId)
  const scope: TrackPlaybackScope = {
    type: 'tracks',
    libraryRootId: libraryRootScope.selectedRootId,
    libraryRootName: selectedRoot?.name ?? null,
    search: filters.search.trim(),
    artistId: null,
    artistName: '',
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

watch([
  isActive,
  () => catalog.tracks.items.map(track => track.id),
], ([active, trackIds]) => {
  if (active && trackIds.length) void playlists.loadMemberships(trackIds)
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
          <CatalogRating
            :entity-id="track.id"
            entity-type="track"
            compact
            :model-value="track.rating"
            responsive
            size="18"
            @update:model-value="track.rating = $event"
          />
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
