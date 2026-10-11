<script setup lang="ts">
import { computed, toRefs } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import CatalogPagination from '@/components/CatalogPagination.vue'
import CatalogRating from '@/components/CatalogRating.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCatalogFilters, queryInitial, queryNumber, queryPage, querySearch } from '@/composables/useCatalogFilters'
import type { Album } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { usePlayerStore, type AlbumPlaybackScope } from '@/stores/player'

interface AlbumFilters {
  page: number
  search: string
  initial: string | null
  year: number | null
  genre: number | null
  genreName: string
  label: number | null
  labelName: string
  musician: number | null
  musicianName: string
  physicalCopy: PhysicalCopyFilter
  sort: AlbumSort
}

type PhysicalCopyFilter = 'all' | 'owned' | 'not_owned'
type AlbumSort = 'artist' | 'title' | 'year_asc' | 'year_desc' | 'plays' | 'last_played' | 'added'

const { t } = useI18n()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const libraryRootScope = useLibraryRootScopeStore()
const libraryRoots = useLibraryRootsStore()
const player = usePlayerStore()
const { filters, searchInput: search, searchField, resultsTop, currentFilters, changePage, clearSearch } = useCatalogFilters<AlbumFilters>({
  routeName: 'albums',
  storageKey: 'sonotheque:album-filters',
  scope: () => libraryRootScope.selectedRootId,
  parse: input => ({
    page: queryPage(input.page),
    search: querySearch(input.search),
    initial: queryInitial(input.initial),
    year: queryNumber(input.year),
    genre: queryNumber(input.genre),
    genreName: querySearch(input.genreName),
    label: queryNumber(input.label),
    labelName: querySearch(input.labelName),
    musician: queryNumber(input.musician),
    musicianName: querySearch(input.musicianName),
    physicalCopy: queryPhysicalCopy(input.physicalCopy),
    sort: queryAlbumSort(input.sort),
  }),
  load: value => {
    void catalog.loadAlbums({
      ...value,
      physicalCopy: value.physicalCopy === 'all' ? null : value.physicalCopy,
    })
  },
})
const { page, initial, year, genre, genreName, label, labelName, musician, musicianName, physicalCopy, sort } = toRefs(filters)
const releaseYears = computed(() => {
  const currentYear = new Date().getFullYear()

  return Array.from({ length: currentYear - 1950 + 1 }, (_, index) => currentYear - index)
})
const physicalCopyOptions = computed(() => [
  { title: t('albums.physicalCopyAll'), value: 'all' },
  { title: t('albums.physicalCopyOwned'), value: 'owned' },
  { title: t('albums.physicalCopyMissing'), value: 'not_owned' },
])
const sortOptions = computed(() => [
  { title: t('albums.sortArtist'), value: 'artist' },
  { title: t('albums.sortTitle'), value: 'title' },
  { title: t('albums.sortYearNewest'), value: 'year_desc' },
  { title: t('albums.sortYearOldest'), value: 'year_asc' },
  { title: t('albums.sortMostPlayed'), value: 'plays' },
  { title: t('albums.sortLastPlayed'), value: 'last_played' },
  { title: t('albums.sortRecentlyAdded'), value: 'added' },
])

function playRandomAlbum() {
  const filters = currentFilters()
  const selectedRoot = libraryRoots.roots.find(root => root.id === libraryRootScope.selectedRootId)
  const scope: AlbumPlaybackScope = {
    type: 'albums',
    libraryRootId: libraryRootScope.selectedRootId,
    libraryRootName: selectedRoot?.name ?? null,
    search: filters.search.trim(),
    initial: filters.initial,
    year: filters.year,
    genreId: filters.genre,
    genreName: filters.genreName,
    labelId: filters.label,
    labelName: filters.labelName,
    musicianId: filters.musician,
    musicianName: filters.musicianName,
    physicalCopy: filters.physicalCopy === 'all' ? null : filters.physicalCopy,
    sort: filters.sort,
  }

  void player.playRandomAlbum(scope)
}

function selectInitial(value: string | null) {
  initial.value = value
}

function queryPhysicalCopy(value: unknown): PhysicalCopyFilter {
  return value === 'owned' || value === 'not_owned' ? value : 'all'
}

function queryAlbumSort(value: unknown): AlbumSort {
  return ['title', 'year_asc', 'year_desc', 'plays', 'last_played', 'added'].includes(String(value))
    ? value as AlbumSort
    : 'artist'
}

function clearGenreFilter() {
  genre.value = null
  genreName.value = ''
}

function clearMusicianFilter() {
  musician.value = null
  musicianName.value = ''
}

function clearLabelFilter() {
  label.value = null
  labelName.value = ''
}

function albumDetails(album: Album) {
  if (album.originalReleaseYear === undefined || album.originalReleaseYear === null) {
    return t('albums.trackCount', { count: album.trackCount })
  }

  return t('albums.details', { year: album.originalReleaseYear, count: album.trackCount })
}

</script>

<template>
  <PageHeader
    :title="t('albums.title')"
    :count="catalog.albumsLoading || catalog.albumsError ? undefined : catalog.albums.total"
    :description="t('albums.description')"
    icon="mdi-album"
  />
  <div class="d-flex flex-wrap ga-3 mb-4">
    <v-btn color="primary" prepend-icon="mdi-album" variant="flat" @click="playRandomAlbum">
      {{ t('player.playRandomAlbum') }}
    </v-btn>
    <v-btn color="primary" prepend-icon="mdi-shuffle-variant" variant="tonal" @click="void player.playRandomTrack()">
      {{ t('player.playRandomTrack') }}
    </v-btn>
  </div>
  <div class="album-filter-row d-flex flex-column flex-sm-row flex-sm-wrap flex-lg-nowrap ga-3 mb-4">
    <v-text-field
      ref="searchField"
      v-model="search"
      clearable
      density="compact"
      hide-details
      prepend-inner-icon="mdi-magnify"
      :label="t('albums.search')"
      @keydown.esc.prevent.stop="clearSearch"
    />
    <v-autocomplete
      v-model="year"
      class="album-year-filter"
      clearable
      density="compact"
      hide-details
      :items="releaseYears"
      prepend-inner-icon="mdi-calendar"
      :label="t('albums.releaseYear')"
    />
    <v-select
      v-model="physicalCopy"
      class="album-physical-copy-filter"
      density="compact"
      hide-details
      :items="physicalCopyOptions"
      prepend-inner-icon="mdi-disc"
      :label="t('albums.physicalCopyFilter')"
    />
    <v-select
      v-model="sort"
      class="album-sort"
      density="compact"
      hide-details
      :items="sortOptions"
      prepend-inner-icon="mdi-sort"
      :label="t('albums.sortBy')"
    />
  </div>
  <div class="d-flex flex-wrap ga-1 mb-6">
    <v-btn size="small" :variant="initial === null ? 'flat' : 'tonal'" @click="selectInitial(null)">{{ t('albums.all') }}</v-btn>
    <v-btn
      v-for="letter in ['#', ...'ABCDEFGHIJKLMNOPQRSTUVWXYZ']"
      :key="letter"
      size="small"
      :variant="initial === letter ? 'flat' : 'tonal'"
      @click="selectInitial(letter)"
    >
      {{ letter }}
    </v-btn>
  </div>
  <div v-if="genre || label || musician" class="d-flex flex-wrap ga-2 mb-6">
    <v-chip v-if="genre" closable color="primary" variant="tonal" @click:close="clearGenreFilter">
      {{ t('genres.filterLabel', { name: genreName || t('genres.filterFallback', { id: genre }) }) }}
    </v-chip>
    <v-chip v-if="label" closable color="primary" variant="tonal" @click:close="clearLabelFilter">
      {{ t('albums.labelFilter', { name: labelName || t('albums.labelFilterFallback', { id: label }) }) }}
    </v-chip>
    <v-chip v-if="musician" closable color="primary" variant="tonal" @click:close="clearMusicianFilter">
      {{ t('musicians.filterLabel', { name: musicianName || t('musicians.filterFallback', { id: musician }) }) }}
    </v-chip>
  </div>
  <div ref="resultsTop" class="catalog-results-anchor" />
  <CatalogPagination class="mb-4" :model-value="page" :length="catalog.albums.lastPage" @update:model-value="changePage" />
  <v-alert v-if="catalog.albumsError" type="error" variant="tonal">{{ catalog.albumsError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.albumsLoading" type="card@3" />
  <v-row v-else-if="catalog.albums.items.length">
    <v-col v-for="album in catalog.albums.items" :key="album.id" cols="12" sm="6" lg="4" xl="3">
      <v-card border rounded="xl" height="100%" hover :to="{ name: 'album-detail', params: { id: album.id } }">
        <div class="album-card-media">
          <v-img v-if="album.artworkThumbnailUrl" :src="album.artworkThumbnailUrl" aspect-ratio="1" cover />
          <div v-else class="d-flex align-center justify-center bg-surface-bright" style="aspect-ratio: 1">
            <v-icon icon="mdi-album" size="72" color="medium-emphasis" />
          </div>
          <TooltipIconButton
            wrapper-class="favorite-album-button"
            :text="favorites.isAlbumFavorite(album.id) ? t('favorites.removeAlbum') : t('favorites.addAlbum')"
            :aria-label="favorites.isAlbumFavorite(album.id) ? t('favorites.removeAlbum') : t('favorites.addAlbum')"
            :color="favorites.isAlbumFavorite(album.id) ? 'primary' : undefined"
            :icon="favorites.isAlbumFavorite(album.id) ? 'mdi-heart' : 'mdi-heart-outline'"
            variant="flat"
            @click.prevent.stop="void favorites.toggleAlbum(album.id)"
          />
        </div>
        <v-card-item>
          <v-card-title>{{ album.title }}</v-card-title>
          <v-card-subtitle>{{ album.primaryArtist?.name ?? t('catalog.unknownArtist') }}</v-card-subtitle>
        </v-card-item>
        <v-card-text class="album-card-details pt-0 text-medium-emphasis">
          <div class="album-card-summary">
            <span class="album-card-detail-text">
              {{ albumDetails(album) }}
              <v-chip
                v-if="album.personalMetadata.hasPhysicalCopy"
                class="ms-2"
                color="primary"
                prepend-icon="mdi-disc"
                size="x-small"
                variant="tonal"
              >
                {{ t('albums.physicalCopy') }}
              </v-chip>
            </span>
            <CatalogRating
              :entity-id="album.id"
              entity-type="album"
              card
              :model-value="album.rating"
              size="18"
              @click.prevent.stop
              @update:model-value="album.rating = $event"
            />
          </div>
          <TooltipIconButton
            :text="t('albums.playAlbum')"
            :aria-label="t('albums.playAlbum')"
            density="compact"
            icon="mdi-play"
            size="small"
            variant="text"
            @click.prevent.stop="void player.playAlbumById(album.id)"
          />
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>
  <EmptyCatalogState v-else :title="t('albums.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-album" />
  <CatalogPagination class="mt-6" :model-value="page" :length="catalog.albums.lastPage" @update:model-value="changePage" />
</template>

<style scoped>
.album-year-filter {
  flex: 0 0 12rem;
}

.album-physical-copy-filter {
  flex: 0 0 13rem;
}

.album-sort {
  flex: 0 0 15rem;
}

@media (max-width: 599px) {
  .album-filter-row {
    gap: 8px !important;
  }

  .album-year-filter {
    flex-basis: auto;
    width: 100%;
  }

  .album-physical-copy-filter,
  .album-sort {
    flex-basis: auto;
    width: 100%;
  }
}

.album-card-media {
  position: relative;
}

.album-card-media :deep(.favorite-album-button) {
  position: absolute;
  right: 12px;
  top: 12px;
}

.album-card-details {
  align-items: center;
  display: flex;
  gap: 8px;
  justify-content: space-between;
}

.album-card-detail-text {
  min-width: 0;
}

.album-card-summary {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 12px;
  min-width: 0;
}
</style>
