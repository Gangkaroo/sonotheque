<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import CatalogPagination from '@/components/CatalogPagination.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { Album } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { usePlayerStore, type AlbumPlaybackScope } from '@/stores/player'
import { createRouteQuerySyncGuard } from '@/utils/routeQuerySyncGuard'

interface AlbumFilters {
  page: number
  search: string
  initial: string | null
  year: number | null
  genre: number | null
  genreName: string
  physicalCopy: PhysicalCopyFilter
  sort: AlbumSort
}

type PhysicalCopyFilter = 'all' | 'owned' | 'not_owned'
type AlbumSort = 'artist' | 'title' | 'year_asc' | 'year_desc' | 'plays' | 'last_played' | 'added'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const libraryRootScope = useLibraryRootScopeStore()
const libraryRoots = useLibraryRootsStore()
const player = usePlayerStore()
const storageKey = 'sonotheque:album-filters'
const restoredFilters = initialFilters()
const initial = ref<string | null>(restoredFilters.initial)
const search = ref(restoredFilters.search)
const genre = ref(restoredFilters.genre)
const genreName = ref(restoredFilters.genreName)
const year = ref<number | null>(restoredFilters.year)
const physicalCopy = ref<PhysicalCopyFilter>(restoredFilters.physicalCopy)
const sort = ref<AlbumSort>(restoredFilters.sort)
const page = ref(restoredFilters.page)
const resultsTop = ref<HTMLElement | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | null = null
let applyingRouteFilters = false
const routeQuerySyncGuard = createRouteQuerySyncGuard()
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

function load() {
  void catalog.loadAlbums({
    page: page.value,
    search: querySearch(search.value),
    initial: initial.value,
    year: year.value,
    genre: genre.value,
    physicalCopy: physicalCopy.value === 'all' ? null : physicalCopy.value,
    sort: sort.value,
  })
}

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
    physicalCopy: filters.physicalCopy === 'all' ? null : filters.physicalCopy,
    sort: filters.sort,
  }

  void player.playRandomAlbum(scope)
}

function selectInitial(value: string | null) {
  page.value = 1
  initial.value = value
}

function currentFilters(): AlbumFilters {
  return {
    page: page.value,
    search: querySearch(search.value),
    initial: initial.value,
    year: year.value,
    genre: genre.value,
    genreName: genreName.value,
    physicalCopy: physicalCopy.value,
    sort: sort.value,
  }
}

function defaultFilters(): AlbumFilters {
  return {
    page: 1,
    search: '',
    initial: null,
    year: null,
    genre: null,
    genreName: '',
    physicalCopy: 'all',
    sort: 'artist',
  }
}

function initialFilters(): AlbumFilters {
  const routeFilters = filtersFromQuery()

  if (routeFilters) return routeFilters

  return filtersFromStorage() ?? defaultFilters()
}

function filtersFromQuery(): AlbumFilters | null {
  if (!hasFilterQuery()) return null

  return {
    page: queryPage(route.query.page),
    search: querySearch(route.query.search),
    initial: queryInitial(route.query.initial),
    year: queryNumber(route.query.year),
    genre: queryNumber(route.query.genre),
    genreName: querySearch(route.query.genreName),
    physicalCopy: queryPhysicalCopy(route.query.physicalCopy),
    sort: queryAlbumSort(route.query.sort),
  }
}

function hasFilterQuery() {
  return ['page', 'search', 'initial', 'year', 'genre', 'genreName', 'physicalCopy', 'sort'].some((key) => route.query[key] !== undefined)
}

function filtersFromStorage(): AlbumFilters | null {
  try {
    const stored = window.sessionStorage.getItem(storageKey)
    if (!stored) return null

    const parsed = JSON.parse(stored) as Partial<AlbumFilters>

    return {
      page: queryPage(parsed.page),
      search: typeof parsed.search === 'string' ? parsed.search : '',
      initial: queryInitial(parsed.initial),
      year: typeof parsed.year === 'number' ? parsed.year : null,
      genre: typeof parsed.genre === 'number' ? parsed.genre : null,
      genreName: typeof parsed.genreName === 'string' ? parsed.genreName : '',
      physicalCopy: queryPhysicalCopy(parsed.physicalCopy),
      sort: queryAlbumSort(parsed.sort),
    }
  } catch {
    return null
  }
}

function saveFilters() {
  window.sessionStorage.setItem(storageKey, JSON.stringify(currentFilters()))
}

function syncFiltersToRoute() {
  if (route.name !== 'albums') return

  const query = filterQuery(currentFilters())

  if (JSON.stringify(normalizedFilterQuery(route.query)) === JSON.stringify(query)) return

  const pendingSync = routeQuerySyncGuard.mark(query)

  void router.replace({ name: 'albums', query }).finally(() => {
    routeQuerySyncGuard.release(pendingSync)
  })
}

function applyFilters(filters: AlbumFilters) {
  applyingRouteFilters = true
  page.value = filters.page
  search.value = filters.search
  initial.value = filters.initial
  year.value = filters.year
  genre.value = filters.genre
  genreName.value = filters.genreName
  physicalCopy.value = filters.physicalCopy
  sort.value = filters.sort
  applyingRouteFilters = false
  saveFilters()
}

function filterQuery(filters: AlbumFilters) {
  const query: Record<string, string> = {}

  if (filters.page > 1) query.page = String(filters.page)
  if (filters.search.trim()) query.search = filters.search.trim()
  if (filters.initial) query.initial = filters.initial
  if (filters.year) query.year = String(filters.year)
  if (filters.genre) query.genre = String(filters.genre)
  if (filters.genreName.trim()) query.genreName = filters.genreName.trim()
  if (filters.physicalCopy !== 'all') query.physicalCopy = filters.physicalCopy
  if (filters.sort !== 'artist') query.sort = filters.sort

  return query
}

function normalizedFilterQuery(query: typeof route.query) {
  return filterQuery({
    page: queryPage(query.page),
    search: querySearch(query.search),
    initial: queryInitial(query.initial),
    year: queryNumber(query.year),
    genre: queryNumber(query.genre),
    genreName: querySearch(query.genreName),
    physicalCopy: queryPhysicalCopy(query.physicalCopy),
    sort: queryAlbumSort(query.sort),
  })
}

function querySearch(value: unknown) {
  return typeof value === 'string' ? value : ''
}

function queryPage(value: unknown) {
  const parsed = typeof value === 'number' || typeof value === 'string' ? Number(value) : NaN

  return Number.isInteger(parsed) && parsed > 0 ? parsed : 1
}

function queryInitial(value: unknown) {
  if (typeof value !== 'string') return null
  if (value === '#') return value

  const normalized = value.toUpperCase()

  return /^[A-Z]$/.test(normalized) ? normalized : null
}

function queryNumber(value: unknown) {
  const parsed = typeof value === 'string' ? Number(value) : NaN

  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
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

function albumDetails(album: Album) {
  if (album.originalReleaseYear === undefined || album.originalReleaseYear === null) {
    return t('albums.trackCount', { count: album.trackCount })
  }

  return t('albums.details', { year: album.originalReleaseYear, count: album.trackCount })
}

saveFilters()

watch(() => route.query, () => {
  if (route.name !== 'albums') return

  const normalizedQuery = normalizedFilterQuery(route.query)
  if (routeQuerySyncGuard.consume(normalizedQuery)) return

  const routeFilters = filtersFromQuery()
  if (routeFilters) {
    applyFilters(routeFilters)
    return
  }

  syncFiltersToRoute()
})
watch([initial, year, genre, genreName, physicalCopy, sort], () => {
  if (applyingRouteFilters) return

  page.value = 1
  saveFilters()
  syncFiltersToRoute()
})
watch([page, initial, year, genre, physicalCopy, sort, () => libraryRootScope.selectedRootId], load, { immediate: true })
watch(search, () => {
  const wasNotFirstPage = page.value !== 1
  if (searchTimer) clearTimeout(searchTimer)

  if (!applyingRouteFilters) {
    page.value = 1
    saveFilters()
  } else if (wasNotFirstPage) {
    page.value = 1
  }

  if (wasNotFirstPage) return

  searchTimer = setTimeout(() => {
    syncFiltersToRoute()
    load()
  }, 300)
})
onUnmounted(() => {
  if (searchTimer) clearTimeout(searchTimer)
})
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
      v-model="search"
      clearable
      density="compact"
      hide-details
      prepend-inner-icon="mdi-magnify"
      :label="t('albums.search')"
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
  <div v-if="genre" class="d-flex flex-wrap ga-2 mb-6">
    <v-chip v-if="genre" closable color="primary" variant="tonal" @click:close="clearGenreFilter">
      {{ t('genres.filterLabel', { name: genreName || t('genres.filterFallback', { id: genre }) }) }}
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
</style>
