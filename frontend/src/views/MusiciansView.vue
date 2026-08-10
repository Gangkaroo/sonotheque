<script setup lang="ts">
import { nextTick, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import CatalogPagination from '@/components/CatalogPagination.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCachedViewActivation } from '@/composables/useCachedViewActivation'
import { useCatalogStore } from '@/stores/catalog'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { createRouteQuerySyncGuard } from '@/utils/routeQuerySyncGuard'

interface MusicianFilters {
  page: number
  search: string
  initial: string | null
}

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const catalog = useCatalogStore()
const libraryRootScope = useLibraryRootScopeStore()
const storageKey = 'sonotheque:musician-filters'
const restoredFilters = initialFilters()
const page = ref(restoredFilters.page)
const search = ref(restoredFilters.search)
const initial = ref(restoredFilters.initial)
const resultsTop = ref<HTMLElement | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | null = null
let applyingRouteFilters = false
const routeQuerySyncGuard = createRouteQuerySyncGuard()

function load() {
  void catalog.loadMusicians({ page: page.value, search: search.value, initial: initial.value })
}

function currentFilters(): MusicianFilters {
  return { page: page.value, search: querySearch(search.value), initial: initial.value }
}

function initialFilters(): MusicianFilters {
  return filtersFromQuery() ?? filtersFromStorage() ?? { page: 1, search: '', initial: null }
}

function filtersFromQuery(): MusicianFilters | null {
  if (!['page', 'search', 'initial'].some(key => route.query[key] !== undefined)) return null

  return {
    page: queryPage(route.query.page),
    search: querySearch(route.query.search),
    initial: queryInitial(route.query.initial),
  }
}

function filtersFromStorage(): MusicianFilters | null {
  try {
    const stored = window.sessionStorage.getItem(storageKey)
    if (!stored) return null

    const parsed = JSON.parse(stored) as Partial<MusicianFilters>

    return {
      page: queryPage(parsed.page),
      search: querySearch(parsed.search),
      initial: queryInitial(parsed.initial),
    }
  } catch {
    return null
  }
}

function saveFilters() {
  window.sessionStorage.setItem(storageKey, JSON.stringify(currentFilters()))
}

function filterQuery(filters: MusicianFilters) {
  const query: Record<string, string> = {}
  if (filters.page > 1) query.page = String(filters.page)
  if (filters.search.trim()) query.search = filters.search.trim()
  if (filters.initial) query.initial = filters.initial

  return query
}

function syncFiltersToRoute() {
  if (route.name !== 'musicians') return

  const query = filterQuery(currentFilters())
  if (JSON.stringify(filterQuery({
    page: queryPage(route.query.page),
    search: querySearch(route.query.search),
    initial: queryInitial(route.query.initial),
  })) === JSON.stringify(query)) return

  const pendingSync = routeQuerySyncGuard.mark(query)
  void router.replace({ name: 'musicians', query }).finally(() => routeQuerySyncGuard.release(pendingSync))
}

function applyFilters(filters: MusicianFilters) {
  applyingRouteFilters = true
  page.value = filters.page
  search.value = filters.search
  initial.value = filters.initial
  applyingRouteFilters = false
  saveFilters()
}

function selectInitial(value: string | null) {
  page.value = 1
  initial.value = value
}

function changePage(value: number) {
  if (value === page.value) return

  page.value = value
  saveFilters()
  syncFiltersToRoute()
  void nextTick(() => resultsTop.value?.scrollIntoView({ behavior: 'auto', block: 'start' }))
}

function queryPage(value: unknown) {
  const parsed = typeof value === 'number' || typeof value === 'string' ? Number(value) : NaN

  return Number.isInteger(parsed) && parsed > 0 ? parsed : 1
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

saveFilters()
useCachedViewActivation(load)

watch(() => route.query, () => {
  if (route.name !== 'musicians') return

  const normalizedQuery = filterQuery({
    page: queryPage(route.query.page),
    search: querySearch(route.query.search),
    initial: queryInitial(route.query.initial),
  })
  if (routeQuerySyncGuard.consume(normalizedQuery)) return

  const filters = filtersFromQuery()
  if (filters) applyFilters(filters)
  else syncFiltersToRoute()
})
watch([page, initial, () => libraryRootScope.selectedRootId], load, { immediate: true })
watch(initial, () => {
  if (applyingRouteFilters) return
  page.value = 1
  saveFilters()
  syncFiltersToRoute()
})
watch(search, () => {
  const wasNotFirstPage = page.value !== 1
  if (searchTimer) clearTimeout(searchTimer)
  if (!applyingRouteFilters) {
    page.value = 1
    saveFilters()
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
    :title="t('musicians.title')"
    :count="catalog.musiciansLoading || catalog.musiciansError ? undefined : catalog.musicians.total"
    :description="t('musicians.description')"
    icon="mdi-account-star-outline"
  />

  <v-card border class="mb-5 pa-4" rounded="xl">
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-2">
      <div>
        <div class="text-subtitle-1 font-weight-bold">{{ t('musicians.coverageTitle') }}</div>
        <div class="text-body-2 text-medium-emphasis">
          {{ t('musicians.coverageSummary', {
            checked: catalog.musicians.coverage.checkedAlbums,
            total: catalog.musicians.coverage.totalAlbums,
          }) }}
        </div>
      </div>
      <v-chip color="primary" prepend-icon="mdi-album" variant="tonal">
        {{ t('musicians.creditedAlbums', { count: catalog.musicians.coverage.creditedAlbums }) }}
      </v-chip>
    </div>
    <v-progress-linear
      color="primary"
      height="8"
      :indeterminate="catalog.musiciansLoading"
      :model-value="catalog.musicians.coverage.percentage"
      rounded
    />
  </v-card>

  <v-text-field
    v-model="search"
    class="mb-4"
    clearable
    hide-details
    prepend-inner-icon="mdi-magnify"
    :label="t('musicians.search')"
  />
  <div class="d-flex flex-wrap ga-1 mb-6">
    <v-btn size="small" :variant="initial === null ? 'flat' : 'tonal'" @click="selectInitial(null)">
      {{ t('musicians.all') }}
    </v-btn>
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
  <CatalogPagination class="mb-4" :model-value="page" :length="catalog.musicians.lastPage" @update:model-value="changePage" />
  <v-alert v-if="catalog.musiciansError" type="error" variant="tonal">{{ catalog.musiciansError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.musiciansLoading" type="list-item-two-line@6" />
  <v-list v-else-if="catalog.musicians.items.length" border rounded="xl" lines="two">
    <v-list-item v-for="musician in catalog.musicians.items" :key="musician.id" prepend-icon="mdi-account-star-outline">
      <v-list-item-title class="font-weight-bold">
        <RouterLink class="musician-link" :to="{ name: 'musician-detail', params: { id: musician.id } }">
          {{ musician.name }}
        </RouterLink>
      </v-list-item-title>
      <v-list-item-subtitle>
        <span v-if="musician.disambiguation">{{ musician.disambiguation }} · </span>
        {{ t('musicians.creditCounts', { albums: musician.albumCount, tracks: musician.trackCount }) }}
      </v-list-item-subtitle>
      <template #append>
        <div class="d-flex align-center ga-1">
          <TooltipIconButton
            :aria-label="t('musicians.viewAlbums', { name: musician.name })"
            :disabled="musician.albumCount === 0"
            icon="mdi-album"
            size="small"
            :text="t('musicians.viewAlbums', { name: musician.name })"
            :to="{ name: 'albums', query: { musician: musician.id, musicianName: musician.name } }"
            variant="text"
          />
          <TooltipIconButton
            :aria-label="t('musicians.viewTracks', { name: musician.name })"
            :disabled="musician.trackCount === 0"
            icon="mdi-music-note"
            size="small"
            :text="t('musicians.viewTracks', { name: musician.name })"
            :to="{ name: 'tracks', query: { musician: musician.id, musicianName: musician.name } }"
            variant="text"
          />
        </div>
      </template>
    </v-list-item>
  </v-list>
  <EmptyCatalogState
    v-else
    :title="t('musicians.emptyTitle')"
    :description="t('musicians.emptyDescription')"
    icon="mdi-account-star-outline"
  />
  <CatalogPagination class="mt-6" :model-value="page" :length="catalog.musicians.lastPage" @update:model-value="changePage" />
</template>

<style scoped>
.musician-link {
  color: inherit;
  text-decoration: none;
}

.musician-link:hover {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
}
</style>
