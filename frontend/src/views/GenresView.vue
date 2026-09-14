<script setup lang="ts">
import { toRefs } from 'vue'
import { useI18n } from 'vue-i18n'

import CatalogPagination from '@/components/CatalogPagination.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCatalogFilters, queryPage, querySearch } from '@/composables/useCatalogFilters'
import { useCatalogStore } from '@/stores/catalog'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'

const { t } = useI18n()
const catalog = useCatalogStore()
const libraryRootScope = useLibraryRootScopeStore()
const { filters, searchInput: search, resultsTop, changePage } = useCatalogFilters({
  routeName: 'genres',
  storageKey: 'sonotheque:genre-filters',
  scope: () => libraryRootScope.selectedRootId,
  parse: input => ({ page: queryPage(input.page), search: querySearch(input.search) }),
  load: value => { void catalog.loadGenres(value) },
})
const { page } = toRefs(filters)
</script>

<template>
  <PageHeader
    :title="t('genres.title')"
    :count="catalog.genresLoading || catalog.genresError ? undefined : catalog.genres.total"
    :description="t('genres.description')"
    icon="mdi-tag-multiple-outline"
  />
  <v-text-field v-model="search" class="mb-6" clearable hide-details prepend-inner-icon="mdi-magnify" :label="t('genres.search')" />
  <div ref="resultsTop" class="catalog-results-anchor" />
  <CatalogPagination class="mb-4" :model-value="page" :length="catalog.genres.lastPage" @update:model-value="changePage" />
  <v-alert v-if="catalog.genresError" type="error" variant="tonal">{{ catalog.genresError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.genresLoading" type="list-item-two-line@5" />
  <v-list v-else-if="catalog.genres.items.length" border rounded="xl" lines="two">
    <v-list-item v-for="genre in catalog.genres.items" :key="genre.id" prepend-icon="mdi-tag-outline">
      <v-list-item-title class="font-weight-bold">{{ genre.name }}</v-list-item-title>
      <v-list-item-subtitle>{{ t('genres.trackCount', { count: genre.trackCount }) }}</v-list-item-subtitle>
      <template #append>
        <div class="d-flex align-center ga-1">
          <TooltipIconButton
            :text="t('genres.viewAlbums', { name: genre.name })"
            :aria-label="t('genres.viewAlbums', { name: genre.name })"
            icon="mdi-album"
            size="small"
            :to="{ name: 'albums', query: { genre: genre.id, genreName: genre.name } }"
            variant="text"
          />
          <TooltipIconButton
            :text="t('genres.viewTracks', { name: genre.name })"
            :aria-label="t('genres.viewTracks', { name: genre.name })"
            icon="mdi-music-note"
            size="small"
            :to="{ name: 'tracks', query: { genre: genre.id, genreName: genre.name } }"
            variant="text"
          />
        </div>
      </template>
    </v-list-item>
  </v-list>
  <EmptyCatalogState v-else :title="t('genres.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-tag-multiple-outline" />
  <CatalogPagination class="mt-6" :model-value="page" :length="catalog.genres.lastPage" @update:model-value="changePage" />
</template>
