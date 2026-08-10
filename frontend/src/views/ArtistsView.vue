<script setup lang="ts">
import { nextTick, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import CatalogPagination from '@/components/CatalogPagination.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useCachedViewActivation } from '@/composables/useCachedViewActivation'
import { useCatalogStore } from '@/stores/catalog'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { formatDateTime } from '@/utils/formatters'
import { createRouteQuerySyncGuard } from '@/utils/routeQuerySyncGuard'

interface ArtistFilters {
  search: string
  initial: string | null
}

const { locale, t } = useI18n()
const route = useRoute()
const router = useRouter()
const catalog = useCatalogStore()
const libraryRootScope = useLibraryRootScopeStore()
const storageKey = 'sonotheque:artist-filters'
const restoredFilters = initialFilters()
const initial = ref<string | null>(restoredFilters.initial)
const search = ref(restoredFilters.search)
const page = ref(1)
const resultsTop = ref<HTMLElement | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | null = null
let applyingRouteFilters = false
const routeQuerySyncGuard = createRouteQuerySyncGuard()

function load() {
  void catalog.loadArtists({ page: page.value, search: querySearch(search.value), initial: initial.value })
}

function selectInitial(value: string | null) {
  page.value = 1
  initial.value = value
}

function changePage(value: number) {
  if (value === page.value) return

  page.value = value
  void nextTick(() => {
    resultsTop.value?.scrollIntoView({ behavior: 'auto', block: 'start' })
  })
}

function currentFilters(): ArtistFilters {
  return {
    search: querySearch(search.value),
    initial: initial.value,
  }
}

function defaultFilters(): ArtistFilters {
  return {
    search: '',
    initial: null,
  }
}

function initialFilters(): ArtistFilters {
  const routeFilters = filtersFromQuery()

  if (routeFilters) return routeFilters

  return filtersFromStorage() ?? defaultFilters()
}

function filtersFromQuery(): ArtistFilters | null {
  if (!hasFilterQuery()) return null

  return {
    search: querySearch(route.query.search),
    initial: queryInitial(route.query.initial),
  }
}

function hasFilterQuery() {
  return ['search', 'initial'].some((key) => route.query[key] !== undefined)
}

function filtersFromStorage(): ArtistFilters | null {
  try {
    const stored = window.sessionStorage.getItem(storageKey)
    if (!stored) return null

    const parsed = JSON.parse(stored) as Partial<ArtistFilters>

    return {
      search: typeof parsed.search === 'string' ? parsed.search : '',
      initial: queryInitial(parsed.initial),
    }
  } catch {
    return null
  }
}

function saveFilters() {
  window.sessionStorage.setItem(storageKey, JSON.stringify(currentFilters()))
}

function syncFiltersToRoute() {
  if (route.name !== 'artists') return

  const query = filterQuery(currentFilters())

  if (JSON.stringify(normalizedFilterQuery(route.query)) === JSON.stringify(query)) return

  const pendingSync = routeQuerySyncGuard.mark(query)

  void router.replace({ name: 'artists', query }).finally(() => {
    routeQuerySyncGuard.release(pendingSync)
  })
}

function applyFilters(filters: ArtistFilters) {
  applyingRouteFilters = true
  search.value = filters.search
  initial.value = filters.initial
  page.value = 1
  applyingRouteFilters = false
  saveFilters()
}

function filterQuery(filters: ArtistFilters) {
  const query: Record<string, string> = {}

  if (filters.search.trim()) query.search = filters.search.trim()
  if (filters.initial) query.initial = filters.initial

  return query
}

function normalizedFilterQuery(query: typeof route.query) {
  return filterQuery({
    search: querySearch(query.search),
    initial: queryInitial(query.initial),
  })
}

function querySearch(value: unknown) {
  return typeof value === 'string' ? value : ''
}

function queryInitial(value: unknown) {
  if (typeof value !== 'string') return null
  if (value === '#') return value

  const normalized = value.toUpperCase()

  return /^[A-Z]$/.test(normalized) ? normalized : null
}

function formatDate(value?: string | null) {
  return formatDateTime(value, locale.value)
}

function playCountTooltip(artist: typeof catalog.artists.items[number]) {
  return [
    t('tracks.playCountTooltip', { count: artist.playStatistics.playCount }),
    t('artists.playedTracksTooltip', {
      played: artist.playStatistics.playedTrackCount,
      total: artist.trackCount,
    }),
    t('tracks.lastPlayedAtTooltip', { value: formatDate(artist.playStatistics.lastPlayedAt) }),
  ]
}

saveFilters()
useCachedViewActivation(load)

watch(() => route.query, () => {
  if (route.name !== 'artists') return

  const normalizedQuery = normalizedFilterQuery(route.query)
  if (routeQuerySyncGuard.consume(normalizedQuery)) return

  const routeFilters = filtersFromQuery()
  if (routeFilters) {
    applyFilters(routeFilters)
    return
  }

  syncFiltersToRoute()
})
watch(initial, () => {
  if (applyingRouteFilters) return

  page.value = 1
  saveFilters()
  syncFiltersToRoute()
})
watch([page, initial, () => libraryRootScope.selectedRootId], load, { immediate: true })
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
    :title="t('artists.title')"
    :count="catalog.artistsLoading || catalog.artistsError ? undefined : catalog.artists.total"
    :description="t('artists.description')"
    icon="mdi-account-music-outline"
  />
  <v-text-field v-model="search" class="mb-4" clearable hide-details prepend-inner-icon="mdi-magnify" :label="t('artists.search')" />
  <div class="d-flex flex-wrap ga-1 mb-6">
    <v-btn size="small" :variant="initial === null ? 'flat' : 'tonal'" @click="selectInitial(null)">{{ t('artists.all') }}</v-btn>
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
  <div ref="resultsTop" class="catalog-results-anchor" />
  <CatalogPagination class="mb-4" :model-value="page" :length="catalog.artists.lastPage" @update:model-value="changePage" />
  <v-alert v-if="catalog.artistsError" type="error" variant="tonal">{{ catalog.artistsError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.artistsLoading" type="list-item-two-line@6" />
  <v-list v-else-if="catalog.artists.items.length" border rounded="xl" lines="two">
    <v-list-item
      v-for="artist in catalog.artists.items"
      :key="artist.id"
      prepend-icon="mdi-account-music-outline"
      :to="{ name: 'artist-detail', params: { id: artist.id } }"
    >
      <v-list-item-title class="font-weight-bold">{{ artist.name }}</v-list-item-title>
      <v-list-item-subtitle>{{ t('artists.albumCount', { count: artist.albumCount }) }}</v-list-item-subtitle>
      <template #append>
        <div class="d-flex align-center ga-1">
          <v-tooltip location="top">
            <template #activator="{ props }">
              <span v-bind="props" class="artist-play-count text-caption text-medium-emphasis">
                <v-icon class="play-count-icon" icon="mdi-headphones" size="x-small" />
                {{ artist.playStatistics.playCount }}
              </span>
            </template>
            <div class="play-count-tooltip">
              <div v-for="(line, index) in playCountTooltip(artist)" :key="index">{{ line }}</div>
            </div>
          </v-tooltip>
          <v-icon icon="mdi-chevron-right" size="small" />
        </div>
      </template>
    </v-list-item>
  </v-list>
  <EmptyCatalogState v-else :title="t('artists.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-account-music-outline" />
  <CatalogPagination class="mt-6" :model-value="page" :length="catalog.artists.lastPage" @update:model-value="changePage" />
</template>

<style scoped>
.artist-play-count {
  align-items: center;
  display: inline-flex;
  font-variant-numeric: tabular-nums;
  gap: 0.2rem;
  line-height: 1;
  margin-inline-end: 0.25rem;
}

.play-count-icon {
  align-self: center;
  transform: translateY(1px);
}

.play-count-tooltip {
  line-height: 1.5;
}

@media (max-width: 480px) {
  .artist-play-count {
    display: none;
  }
}
</style>
